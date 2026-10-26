<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Dropi\Models\DropiDevolucion;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Al registrar una devolución Dropi, escribe el asiento inverso balanceado
 * usando el helper atómico (partida doble validada antes de commit):
 *
 *   4175 (Devoluciones en ventas)   DÉBITO  = valor (por-item)
 *   1305 (CxC del vendedor Dropi)   HABER   = valor total    ← con tercero_id
 *   1435 (Inventario)               DÉBITO  = costo de salida (si reingresa)
 *   6135 (Costo mercancía vendida)  HABER   = costo de salida (si reingresa)
 *
 * Re-audit fixes:
 *   DATOS C6 · si `totalPeso <= 0` (items sin cantidad/precio) → skip completo
 *     con Log crítico. Antes escribía la contrapartida CxC pero cero débitos =
 *     asiento roto (helper atómico ahora abortaría, pero prevenimos aquí).
 *   FUNC C3 · contrapartida 1305 incluye `tercero_type`/`tercero_id` del
 *     Contacto vendedor Dropi. Antes el mayor por cliente quedaba inflado.
 */
class ContabilizarDevolucionDropi
{
    use AsAction;

    public function handle(DropiDevolucion $devolucion): int
    {
        // Fix H-3 CRÍTICO re-audit · items agregados pierden cuentas contables.
        //   Antes solo cargábamos variante.producto → para item con variante_id
        //   NULL (agregado, ahora legal por fix QA1) $producto quedaba null y
        //   las cuentas (cta_devolucion, cta_inventario, cta_costo) caían al
        //   default global. Descuadraba reportes por cuenta/centro-de-costo.
        $devolucion->loadMissing(['pedido.items.variante.producto', 'pedido.items.producto']);
        $pedido = $devolucion->pedido;
        if (! $pedido) return 0;

        $valor = (float) $pedido->monto_esperado_proveedor;
        if ($valor <= 0) {
            $devolucion->update([
                'notas' => trim(($devolucion->notas ?? '') . "\n[Contab skip] pedido con monto=0, sin asiento"),
            ]);
            Log::info('[Contab-Dev] skip monto=0', ['pedido' => $pedido->guia]);
            return 0;
        }

        // Re-audit R3-D · si ya existe una NotaCredito local para esta devolución,
        // el asiento contable de "reverso venta" pertenece al documento fiscal NC,
        // no a la DropiDevolucion. Antes ambos apuntaban a DropiDevolucion → el
        // libro DIAN quedaba huérfano de origen fiscal, y al eliminar la NC
        // (forceDelete cascada) los movs se quedaban vivos apuntando a un
        // origen inexistente. Ahora origen_type=NotaCredito cuando existe.
        $nc = NotaCredito::query()
            ->where('devolucion_dropi_id', $devolucion->id)
            ->whereNotIn('estado', ['rechazada', 'anulada'])
            ->first();
        $origenType = $nc ? NotaCredito::class : DropiDevolucion::class;
        $origenId = $nc?->id ?? $devolucion->id;

        if (MovimientoContable::where('origen_type', $origenType)
            ->where('origen_id', $origenId)->exists()) {
            return 0;
        }

        $fecha = $devolucion->recibido_at?->toDateString() ?? now()->toDateString();
        $userId = auth()->id();

        // ---------- Prorrateo con ajuste de residuo ----------
        $items = $pedido->items->filter(fn ($it) => (float) ($it->cantidad ?? 1) > 0)->values();
        $totalPeso = (float) $items->sum(fn ($it) => (float) ($it->cantidad ?? 1) * (float) ($it->precio_unitario ?? 0));
        if ($totalPeso <= 0) {
            // Fallback: peso por cantidad simple.
            $totalPeso = (float) $items->sum(fn ($it) => (float) ($it->cantidad ?? 1));
            $pesoItem = fn ($it) => (float) ($it->cantidad ?? 1);
        } else {
            $pesoItem = fn ($it) => (float) ($it->cantidad ?? 1) * (float) ($it->precio_unitario ?? 0);
        }

        // Re-audit DATOS C6 · guard total: sin peso positivo NO se puede
        // prorratear NADA → asiento sería 100% contrapartida sin débitos.
        // Aborta y loguea para investigación.
        if ($totalPeso <= 0) {
            Log::critical('[Contab-Dev] items sin cantidad ni precio, imposible prorratear', [
                'devolucion_id' => $devolucion->id, 'pedido' => $pedido->guia, 'items_count' => $items->count(),
            ]);
            $devolucion->update([
                'notas' => trim(($devolucion->notas ?? '') . "\n[Contab skip] items sin cantidad ni precio — revisar upstream Dropi"),
            ]);
            return 0;
        }

        $porciones = [];
        $acumulado = 0.0;
        $n = $items->count();
        foreach ($items as $idx => $item) {
            $peso = $pesoItem($item);
            if ($peso <= 0) { $porciones[$idx] = 0.0; continue; }
            $esUltimo = ($idx === $n - 1);
            $porciones[$idx] = $esUltimo
                ? round($valor - $acumulado, 2)
                : round($valor * ($peso / $totalPeso), 2);
            $acumulado += $porciones[$idx];
        }

        // ---------- Contacto tercero (FUNC C3) ----------
        // El pedido Dropi apunta a un `vendedor_identificacion` → localizamos
        // el Contacto B2B. Si no existe, la contrapartida CxC queda sin tercero
        // pero balanceada — se logea para revisión.
        $terceroTipo = 'App\\Models\\Contacto';
        $terceroId = null;
        if (! empty($pedido->vendedor_identificacion)) {
            $terceroId = \App\Models\Contacto::where('numero_documento', $pedido->vendedor_identificacion)->value('id');
            if (! $terceroId) {
                Log::warning('[Contab-Dev] contrapartida 1305 sin tercero_id', [
                    'devolucion_id' => $devolucion->id, 'pedido' => $pedido->guia,
                    'vendedor_doc' => $pedido->vendedor_identificacion,
                ]);
            }
        }

        // Costo con que salió cada ítem (egreso del empaque). El reverso del
        // costo va a ESE valor: antes se reversaba al precio de venta, y como
        // la venta Dropi nunca había causado costo, cada devolución dejaba el
        // 6135 en negativo y el inventario inflado por el margen.
        $costos = \App\Modules\Dropi\Services\SalidaInventarioDropi::costosDeSalida($pedido->id);

        // ---------- Armar líneas ----------
        $lineas = [];
        $totalReingresado = 0.0;
        $quedan = [];
        foreach ($items as $idx => $item) {
            $porcion = $porciones[$idx] ?? 0.0;
            if ($porcion <= 0) continue;

            // Fix H-3 · fallback polimórfico item agregado (sin variante).
            $producto = $item->variante?->producto ?? $item->producto;
            $ctaDev = $producto?->cta('devolucion') ?? setting('contable.cta_devolucion_default', '4175');
            $ctaInv = $producto?->cta('inventario') ?? setting('contable.cta_inventario_default', '1435');
            $ctaCosto = $producto?->cta('costo') ?? setting('contable.cta_costo_default', '6135');
            $cc = $producto?->centroCosto();
            $desc = "Dev guía {$pedido->guia} · " . ($producto?->referencia ?? 'sin-ref') . ($cc ? " [CC:{$cc}]" : '');

            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaDev,
                'debe' => $porcion, 'haber' => 0,
                'origen_type' => $origenType, 'origen_id' => $origenId,
                'descripcion' => $desc, 'user_id' => $userId,
            ];

            // Se reversa el costo sólo de lo que volvió al kardex: lo escaneado
            // al recibir (5.4), repartido entre las líneas del mismo producto,
            // y sólo si ese costo de verdad se causó al salir.
            $clave = \App\Modules\Inventario\Support\CostoPromedio::clave($item->variante_id, $item->producto_id);
            $vuelven = 0;
            if ($item->variante_id || $item->producto_id) {
                $quedan[$clave] ??= is_array($devolucion->recibidos)
                    ? (int) ($devolucion->recibidos[$clave] ?? 0)
                    : PHP_INT_MAX;
                $vuelven = min((int) ($item->cantidad ?? 1), $quedan[$clave]);
                $quedan[$clave] -= $vuelven;
            }
            $costoItem = round(($costos[$clave] ?? 0) * $vuelven, 2);

            if ($devolucion->destino_inventario === 'reingreso' && $costoItem > 0) {
                $lineas[] = [
                    'fecha' => $fecha, 'cuenta_puc' => $ctaInv,
                    'debe' => $costoItem, 'haber' => 0,
                    'origen_type' => $origenType, 'origen_id' => $origenId,
                    'descripcion' => "Reingreso inv " . ($producto?->referencia ?? '—') . " · {$pedido->guia}",
                    'user_id' => $userId,
                ];
                $lineas[] = [
                    'fecha' => $fecha, 'cuenta_puc' => $ctaCosto,
                    'debe' => 0, 'haber' => $costoItem,
                    'origen_type' => $origenType, 'origen_id' => $origenId,
                    'descripcion' => "Reverso costo " . ($producto?->referencia ?? '—') . " · {$pedido->guia}",
                    'user_id' => $userId,
                ];
                $totalReingresado += $costoItem;
            }
        }

        // Contrapartida CxC (FUNC C3 · con tercero_id).
        $ctaCxC = setting('contable.cta_cxc_default', '1305');
        $lineas[] = [
            'fecha' => $fecha, 'cuenta_puc' => $ctaCxC,
            'tercero_type' => $terceroId ? $terceroTipo : null, 'tercero_id' => $terceroId,
            'debe' => 0, 'haber' => $valor,
            'origen_type' => $origenType, 'origen_id' => $origenId,
            'descripcion' => "Contrapartida devolución guía {$pedido->guia} (CxC)",
            'user_id' => $userId,
        ];

        // Helper atómico valida partida doble antes de commit.
        return MovimientoContable::registrarAsientoAtomico($lineas);
    }
}
