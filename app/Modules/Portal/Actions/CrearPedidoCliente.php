<?php

namespace App\Modules\Portal\Actions;

use App\Models\Contacto;
use App\Models\NotificacionErp;
use App\Modules\Cartera\Actions\ConsultarCredito;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Modules\Portal\Models\ReglaRuteoCiudad;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea un pedido B2B, venga del portal del cliente o del vendedor en la calle.
 *
 * Esto vivía dentro de `PortalCarritoController::confirmar()`. Se sacó acá
 * cuando se construyó la pantalla del vendedor (LOG-J1): el pedido que levanta
 * el vendedor tiene que pasar por EXACTAMENTE las mismas reglas que el que
 * arma el cliente —precios de su lista, compuerta de cartera, ruteo por
 * ciudad, consecutivo— y una segunda copia de todo eso se habría ido
 * separando de la primera sin que nadie lo notara.
 *
 * Lo único que cambia entre los dos casos es quién lo levantó:
 * `$vendedorId` queda en el pedido para comisiones y trazabilidad, y es null
 * cuando el propio cliente se autogestionó.
 */
class CrearPedidoCliente
{
    /**
     * @param  list<array{variante_id?:int|null, producto_id?:int|null, cantidad:int}>  $items
     */
    public function ejecutar(Contacto $cliente, array $items, ?string $notas = null, ?int $vendedorId = null): PedidoCliente
    {
        $listaId = (int) ($cliente->lista_precios_id ?? 0);
        abort_if(! $listaId, 422, 'El cliente no tiene lista de precios asignada.');

        [$precios, $variantes, $productosAgg] = $this->resolverPrecios($items, $listaId);
        [$retenido, $motivos] = $this->evaluarCartera(
            $cliente,
            $this->totalEstimado($items, $precios, $variantes, $productosAgg, $listaId)
        );

        $pedido = DB::transaction(function () use (
            $cliente, $items, $notas, $vendedorId, $listaId,
            $precios, $variantes, $productosAgg, $retenido, $motivos
        ) {
            // LOG-J4 · ruteo automático · si hay regla para la ciudad del
            // cliente, el pedido nace apuntando a esa bodega origen y la Cola
            // Jorge lo filtra para la sede correcta.
            $ped = PedidoCliente::create([
                'numero' => $this->siguienteNumero(),
                'contacto_id' => $cliente->id,
                'vendedor_id' => $vendedorId,
                'lista_precios_id' => $listaId,
                'ubicacion_origen_id' => ReglaRuteoCiudad::resolver($cliente->ciudad),
                'estado' => $retenido ? 'retenido' : 'enviado',
                'motivo_retencion' => $retenido ? implode(' · ', $motivos) : null,
                'notas_cliente' => $notas,
                'enviado_at' => now(),
                'subtotal' => 0, 'iva' => 0, 'total' => 0,
            ]);

            $subtotal = 0.0;
            $iva = 0.0;
            $lineasValidas = 0;

            foreach ($items as $it) {
                $linea = $this->resolverLinea($it, $precios, $variantes, $productosAgg, $listaId);
                if (! $linea) continue;

                $cantidad = (int) $it['cantidad'];
                $lineaSub = round($linea['precio'] * $cantidad, 2);
                $lineaIva = round($lineaSub * ($linea['ivaPct'] / 100), 2);
                $lineasValidas++;

                PedidoClienteItem::create([
                    'pedido_id' => $ped->id,
                    'variante_id' => $linea['variante_id'],
                    'producto_id' => $linea['producto_id'],
                    'sku_snapshot' => $linea['sku'],
                    'descripcion_snapshot' => $linea['desc'],
                    'cantidad' => $cantidad,
                    'precio_unitario' => $linea['precio'],
                    'iva_porcentaje' => $linea['ivaPct'],
                    'subtotal' => $lineaSub,
                    'iva_valor' => $lineaIva,
                    'total' => round($lineaSub + $lineaIva, 2),
                ]);

                $subtotal += $lineaSub;
                $iva += $lineaIva;
            }

            if ($lineasValidas === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Ninguno de los productos tiene precio en la lista del cliente.',
                ]);
            }

            $ped->update([
                'subtotal' => round($subtotal, 2),
                'iva' => round($iva, 2),
                'total' => round($subtotal + $iva, 2),
            ]);

            return $ped;
        });

        Cache::forget("portal.kpis.{$cliente->id}");

        // LOG-J3 · Si el semáforo retuvo el pedido, Gerencia necesita saberlo
        // para liberarlo o rechazarlo.
        if ($pedido->estado === 'retenido') {
            NotificacionErp::crear([
                'tipo' => 'pedido_retenido_cartera',
                'titulo' => "Pedido {$pedido->numero} RETENIDO · requiere Gerencia",
                'mensaje' => $pedido->motivo_retencion ?: 'El semáforo de cartera lo bloqueó.',
                'color' => 'warning',
                'icono' => 'heroicon-o-pause-circle',
                'url' => "/app/pedidos-b2b/{$pedido->id}",
            ]);
        }

        return $pedido;
    }

    /**
     * Precios vigentes de la lista del cliente, más los modelos que hacen falta
     * para armar cada línea.
     *
     * @return array{0: array<int,float>, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     */
    private function resolverPrecios(array $items, int $listaId): array
    {
        $varianteIds = collect($items)->pluck('variante_id')->filter()->unique()->all();
        $productoIds = collect($items)
            ->filter(fn ($i) => empty($i['variante_id']) && ! empty($i['producto_id']))
            ->pluck('producto_id')->unique()->all();

        $vigente = fn ($w) => $w->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', now()->toDateString());

        $precios = $varianteIds
            ? PrecioVariante::whereIn('variante_id', $varianteIds)
                ->where('lista_id', $listaId)
                ->where($vigente)
                ->pluck('precio', 'variante_id')->all()
            : [];

        $variantes = $varianteIds
            ? ProductoVariante::with(['producto' => fn ($q) => $q
                    ->select('id', 'referencia', 'nombre', 'impuesto_id', 'desglose_stock', 'activo')
                    ->with('impuesto:id,porcentaje')])
                ->whereIn('id', $varianteIds)
                ->whereHas('producto', fn ($q) => $q->where('activo', true)->where('desglose_stock', true))
                ->get()->keyBy('id')
            : collect();

        $productosAgg = $productoIds
            ? Producto::with('impuesto:id,porcentaje')
                ->whereIn('id', $productoIds)
                ->where('activo', true)
                ->where('desglose_stock', false)
                ->get()->keyBy('id')
            : collect();

        return [$precios, $variantes, $productosAgg];
    }

    /**
     * LOG-J2 · El semáforo de cartera decide si el pedido nace retenido.
     *
     * Antes todo pedido pasaba a «enviado» y gerencia tenía que leer la cartera
     * del cliente a mano. Ahora, si hay mora crítica, facturas vencidas o cupo
     * excedido, nace «retenido» con el motivo visible.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function evaluarCartera(Contacto $cliente, float $totalEstimado): array
    {
        $credito = ConsultarCredito::run($cliente->id);

        $motivos = [];
        if ($credito['tiene_mora_critica']) {
            $motivos[] = "mora crítica ({$credito['dias_mora_max']} días)";
        }
        if ($credito['facturas_vencidas'] > 0) {
            $motivos[] = "{$credito['facturas_vencidas']} factura(s) vencida(s)";
        }
        $cupo = (float) ($credito['cupo'] ?? 0);
        if ($cupo > 0 && ($credito['saldo_cartera'] + $totalEstimado) > $cupo) {
            $excedido = round(($credito['saldo_cartera'] + $totalEstimado) - $cupo, 2);
            $motivos[] = 'cupo excedido en $'.number_format($excedido, 0);
        }

        return [! empty($motivos), $motivos];
    }

    /**
     * Total con IVA que va a tener el pedido, calculado igual que al crearlo.
     *
     * Antes el cupo sólo sumaba `$precios[variante_id]`: un producto agregado
     * (sin variante) pesaba $0, así que un pedido de puros agregados nunca
     * excedía el cupo. Además se comparaba sin IVA contra una cartera que sí
     * lo trae, y una línea sin precio se contaba aunque después se descarte.
     */
    private function totalEstimado(array $items, array $precios, $variantes, $productosAgg, int $listaId): float
    {
        $total = 0.0;
        foreach ($items as $it) {
            $linea = $this->resolverLinea($it, $precios, $variantes, $productosAgg, $listaId);
            if (! $linea) continue;

            $lineaSub = round($linea['precio'] * (int) $it['cantidad'], 2);
            $total += $lineaSub + round($lineaSub * ($linea['ivaPct'] / 100), 2);
        }

        return round($total, 2);
    }

    /**
     * Datos de una línea, o null si no se puede vender.
     *
     * Una línea sin precio en la lista del cliente se descarta a propósito:
     * `precio_proveedor` es el COSTO, no el precio de venta, y caer en él le
     * vendería al cliente B2B a costo.
     *
     * @return array{variante_id:?int, producto_id:?int, sku:string, desc:string, precio:float, ivaPct:float}|null
     */
    private function resolverLinea(array $it, array $precios, $variantes, $productosAgg, int $listaId): ?array
    {
        if (! empty($it['variante_id'])) {
            $var = $variantes[$it['variante_id']] ?? null;
            $precio = (float) ($precios[$it['variante_id']] ?? 0);
            if (! $var || $precio <= 0) return null;

            return [
                'variante_id' => $var->id,
                'producto_id' => $var->producto_id,
                'sku' => $var->codigo_barras ?: ($var->producto?->referencia.'-'.$var->id),
                'desc' => trim(($var->producto?->nombre ?? '').' · '.($var->color_nombre ?? '').' '.($var->talla ?? '')),
                'precio' => $precio,
                'ivaPct' => (float) ($var->producto?->impuesto?->porcentaje ?? 0),
            ];
        }

        if (empty($it['producto_id'])) return null;

        $p = $productosAgg[$it['producto_id']] ?? null;
        if (! $p) return null;

        // Precio del agregado desde su propia lista.
        //
        // Antes esto se consultaba con `class_exists()` sobre un modelo que no
        // existía, así que siempre daba 0 y la línea se descartaba en silencio:
        // ningún producto agregado se podía vender. La tabla se creó el
        // 2026-10-08.
        //
        // Si no tiene precio en esta lista la línea se sigue descartando, pero
        // a propósito: no se cae al `precio_proveedor` porque ese es el COSTO
        // y le vendería al cliente B2B a precio de compra.
        $precio = (float) (PrecioProducto::query()
            ->where('producto_id', $p->id)
            ->where('lista_id', $listaId)
            ->vigentes()
            ->value('precio') ?? 0);

        if ($precio <= 0) return null;

        return [
            'variante_id' => null,
            'producto_id' => $p->id,
            'sku' => $p->referencia,
            'desc' => $p->nombre.' · colores surtidos',
            'precio' => $precio,
            'ivaPct' => (float) ($p->impuesto?->porcentaje ?? 0),
        ];
    }

    /** Consecutivo diario PB-YYMMDD-#### atómico, bajo la transacción abierta. */
    private function siguienteNumero(): string
    {
        $prefijo = 'PB-'.now()->format('ymd').'-';
        $ultimo = PedidoCliente::where('numero', 'like', $prefijo.'%')
            ->lockForUpdate()
            ->orderByDesc('id')->value('numero');
        $sig = $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1;

        return $prefijo.str_pad((string) $sig, 4, '0', STR_PAD_LEFT);
    }
}
