<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use App\Modules\Inventario\Support\CostoPromedio;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * §12 Diseño Dropi — Registrar devolución de pedido completo.
 *  - El alistador escanea la guía o la busca.
 *  - Sistema trae contexto: productos, cliente, remisión/factura.
 *  - Alistador decide destino de inventario (§12).
 *  - Pedido queda marcado como "devuelto" y "no cobrar" (elimina falso pendiente en conciliación §15).
 *  - Si tiene factura, se emite la nota crédito (EmitirNotaCreditoDropi).
 *
 * 5.2 / 5.4 · Re-escaneo: la bodega escanea las unidades que de verdad llegaron
 * (`$recibidos`, clave `v:{variante}` / `p:{producto}` => unidades) y sólo eso
 * vuelve al kardex, a la bodega de la que salió y con el costo con que salió.
 * Antes se reingresaba el pedido entero aunque la caja llegara a medias, se
 * saltaban los productos agregados y todo caía en «la primera bodega» de la
 * categoría. Lo que falta queda anotado en la devolución.
 */
class RegistrarDevolucion
{
    use AsAction;

    /**
     * @param  array<string, int>|null  $recibidos  lo escaneado; null = sin re-escaneo (se asume el pedido completo)
     * @return array{devolucion:DropiDevolucion, movimientos:int, faltantes:array<string,int>}
     */
    public function handle(
        string $guia,
        DestinoDevolucion $destino,
        int $userId,
        ?string $notas = null,
        ?array $recibidos = null,
    ): array {
        // Re-audit DR-ε (FUNC-C5) · normalizar guía (upper+trim). Pistolas con
        //   mayúsculas/espacios/lowercase fallaban el match. Sync guarda UPPER.
        $guia = strtoupper(trim($guia));
        if ($guia === '') {
            throw new RuntimeException('Guía vacía.');
        }

        return DB::transaction(function () use ($guia, $destino, $userId, $notas, $recibidos) {
            $pedido = DropiPedido::with(['items.variante.producto', 'items.producto'])->where('guia', $guia)->firstOrFail();

            if ($pedido->devolucion) {
                throw new RuntimeException("La guía {$guia} ya tiene una devolución registrada.");
            }

            $esperados = self::esperados($pedido);
            $reescaneada = $recibidos !== null;
            $recibidos = $reescaneada ? self::limpiar($recibidos, $esperados) : $esperados;

            $totalRecibido = array_sum($recibidos);
            if ($reescaneada && $destino->reingresaInventario() && $esperados && $totalRecibido === 0) {
                throw new RuntimeException(
                    'No se escaneó ninguna unidad. Escanea lo que llegó o, si no llegó nada, usa «NO llegó físicamente».'
                );
            }

            $faltantes = [];
            foreach ($esperados as $clave => $n) {
                if (($recibidos[$clave] ?? 0) < $n) {
                    $faltantes[$clave] = $n - ($recibidos[$clave] ?? 0);
                }
            }
            if ($faltantes) {
                $refs = self::referencias($pedido);
                $detalle = [];
                foreach ($faltantes as $clave => $n) {
                    $detalle[] = ($refs[$clave] ?? $clave)." faltan {$n}";
                }
                $notas = trim(($notas ?? '')."\n[Faltante al re-escanear] ".implode(' · ', $detalle));
            }

            // NO pre-setear genero_nota_credito — el observer EmitirNotaCreditoDropi
            // lo activa recién cuando confirma dispatch a SIIGO. Antes mentíamos.
            // `recibidos` va en el create: el asiento (ContabilizarDevolucionDropi,
            // en el `created`) reversa el costo sólo de lo que volvió.
            $devolucion = DropiDevolucion::create([
                'pedido_id' => $pedido->id,
                'recibido_at' => now(),
                'destino_inventario' => $destino->value,
                'recibidos' => $recibidos,
                'unidades_esperadas' => array_sum($esperados),
                'unidades_recibidas' => $totalRecibido,
                'reescaneada' => $reescaneada,
                'decision_por' => $userId,
                'genero_nota_credito' => false,
                'notas' => $notas,
            ]);

            // Reingresar inventario si aplica §12.
            // Re-audit DR-β UX (UX-C4) · NoLlegoFisicamente y BajaTotal NO
            //   generan movimiento de kardex. `reingresaInventario()`
            //   centraliza la decisión.
            $movimientos = $destino->reingresaInventario()
                ? $this->reingresarInventario($pedido, $destino, $userId, $recibidos)
                : 0;

            // Cambio de estado del pedido a través de la state machine central.
            // Fuente 'sistema': esta Action ES la autoridad para pasar cualquier
            // pedido (Despachado, Entregado, Empacado) a Devuelto. El registro
            // manual del alistador dispara la Action; la Action es sistema.
            $pedido->transicionar(
                EstadoPedidoDropi::Devuelto,
                'sistema',
                $userId,
                ['motivo' => 'devolucion_recibida', 'destino' => $destino->value, 'user_id' => $userId],
                ['devuelto_at' => now()],
            );

            return ['devolucion' => $devolucion, 'movimientos' => $movimientos, 'faltantes' => $faltantes];
        });
    }

    /**
     * Unidades que deberían volver, por sujeto (variante o producto agregado).
     *
     * @return array<string, int>
     */
    public static function esperados(DropiPedido $pedido): array
    {
        $out = [];
        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) continue;
            $n = (int) ($item->cantidad ?? 0);
            if ($n <= 0) continue;
            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);
            $out[$clave] = ($out[$clave] ?? 0) + $n;
        }

        return $out;
    }

    /** @return array<string, string> clave => referencia legible */
    public static function referencias(DropiPedido $pedido): array
    {
        $out = [];
        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) continue;
            $out[CostoPromedio::clave($item->variante_id, $item->producto_id)] =
                $item->variante?->producto?->referencia ?? $item->producto?->referencia ?? $item->sku_dropi ?? "ítem #{$item->id}";
        }

        return $out;
    }

    /**
     * Sólo sujetos del pedido, enteros, sin pasar de lo que se despachó.
     *
     * @param  array<string, mixed>  $recibidos
     * @param  array<string, int>  $esperados
     * @return array<string, int>
     */
    private static function limpiar(array $recibidos, array $esperados): array
    {
        $out = [];
        foreach ($esperados as $clave => $max) {
            $out[$clave] = max(0, min((int) ($recibidos[$clave] ?? 0), $max));
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $recibidos
     */
    protected function reingresarInventario(DropiPedido $pedido, DestinoDevolucion $destino, int $userId, array $recibidos): int
    {
        $categoria = $destino->categoriaUbicacion()->value;
        $porDefecto = InventarioUbicacion::query()
            ->where('categoria', $categoria)
            ->where('activa', true)
            ->orderBy('id')
            ->value('id');

        // Lo que vuelve a la venta regresa a la bodega de la que salió (si sigue
        // activa y es de esa categoría); a averías va a la bodega de averías.
        $salida = SalidaInventarioDropi::bodegasDeSalida($pedido->id);
        $validas = InventarioUbicacion::query()
            ->whereIn('id', array_values($salida) ?: [0])
            ->where('categoria', $categoria)->where('activa', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Vuelve con el costo con que salió: sin él la entrada valía $0 y
        // bajaba el costo promedio de todo lo que quedaba en esa bodega.
        $costos = SalidaInventarioDropi::costosDeSalida($pedido->id);
        $refs = self::referencias($pedido);

        $movimientos = 0;
        foreach ($recibidos as $clave => $cantidad) {
            if ($cantidad <= 0) continue;

            $ubicacionId = in_array($salida[$clave] ?? null, $validas, true) ? $salida[$clave] : $porDefecto;
            if (! $ubicacionId) continue;

            [$tipo, $id] = explode(':', $clave);
            $sujeto = $tipo === 'v'
                ? ['variante_id' => (int) $id]
                : ['variante_id' => null, 'producto_id' => (int) $id];

            // Re-audit DR-ε (FUNC-C5) · idempotencia por (sujeto, pedido): un
            //   reintento no duplica el ingreso.
            $creado = InventarioMovimiento::firstOrCreate(
                ['referencia_tipo' => 'devolucion', 'referencia_id' => $pedido->id] + $sujeto,
                [
                    'ubicacion_id' => $ubicacionId,
                    'tipo' => 'ingreso',
                    'cantidad' => $cantidad,
                    'costo_unit' => $costos[$clave] ?? null,
                    'user_id' => $userId,
                    'notas' => "Devolución guía {$pedido->guia} → {$destino->label()}"
                        .($tipo === 'p' ? ' · AGREGADO' : '').' · '.($refs[$clave] ?? ''),
                ]
            );
            if ($creado->wasRecentlyCreated) $movimientos++;
        }

        return $movimientos;
    }
}
