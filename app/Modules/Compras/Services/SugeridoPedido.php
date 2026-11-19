<?php

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Compras\Models\RecepcionCompraItem;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Qué reforzar en el próximo pedido. Alejandro lo saca mirando el movimiento de los
 * últimos contenedores; aquí sale del kardex: lo vendido, lo que hay y lo que viene.
 *
 * Su cuenta (Word, sección 2): vendido desde que llegó / días desde que llegó × 90.
 * Llegaron el 15, van 300 vendidas el 28: 300 / 13 × 90 = 2.077. Lo que nunca ha
 * llegado por importación se mide con la ventana de días que se elija.
 *
 * Es una sugerencia para decidir, no una orden: la novedad y la negociación siguen
 * siendo suyas.
 */
class SugeridoPedido
{
    /** Salidas por venta. Traslados, ajustes y devoluciones a proveedor no son demanda. */
    private const TIPOS_VENTA = ['salida_venta', 'egreso'];

    private const LIMITE = 300;

    /**
     * @return Collection<int, array<string, mixed>> lo más vendido primero
     */
    public function handle(int $diasVenta = 90, int $diasObjetivo = 90, string $busqueda = ''): Collection
    {
        $hoy = now('America/Bogota')->startOfDay();
        $desde = $hoy->copy()->subDays($diasVenta);

        $llegadas = RecepcionCompraItem::query()
            ->join('compras_recepciones as r', 'r.id', '=', 'compras_recepcion_items.recepcion_id')
            ->join('compras_ordenes as o', 'o.id', '=', 'r.orden_id')
            ->where('r.estado', 'confirmada')
            ->where('o.tipo', 'importacion')
            ->whereNotNull('compras_recepcion_items.producto_id')
            ->selectRaw('compras_recepcion_items.producto_id as producto_id, MAX(r.confirmada_at) as llegada')
            ->groupBy('compras_recepcion_items.producto_id');

        $llegoEn = (clone $llegadas)->get()->pluck('llegada', 'producto_id');

        $vendido = InventarioMovimiento::query()
            ->leftJoinSub($llegadas, 'll', 'll.producto_id', '=', 'inventario_movimientos.producto_id')
            ->whereIn('inventario_movimientos.tipo', self::TIPOS_VENTA)
            ->whereRaw('inventario_movimientos.created_at >= COALESCE(ll.llegada, ?)', [$desde->toDateTimeString()])
            ->selectRaw('inventario_movimientos.producto_id as producto_id, -SUM(inventario_movimientos.cantidad) as total')
            ->groupBy('inventario_movimientos.producto_id')
            ->pluck('total', 'producto_id');

        $stock = InventarioMovimiento::query()
            ->whereHas('ubicacion', fn ($q) => $q->where('categoria', CategoriaUbicacion::Venta->value))
            ->selectRaw('producto_id, SUM(cantidad) as total')
            ->groupBy('producto_id')
            ->pluck('total', 'producto_id');

        $porLlegar = OrdenCompraItem::query()
            ->whereHas('orden', fn ($q) => $q->whereIn('estado', ['aprobada', 'parcial']))
            ->whereNotNull('producto_id')
            ->selectRaw('producto_id, SUM(cantidad - cantidad_recibida) as total, MAX(unidades_por_caja) as por_caja')
            ->groupBy('producto_id')
            ->get()
            ->keyBy('producto_id');

        $ultimaCaja = OrdenCompraItem::query()
            ->whereNotNull('producto_id')
            ->where('unidades_por_caja', '>', 0)
            ->orderBy('id')
            ->pluck('unidades_por_caja', 'producto_id');

        return Producto::query()
            ->where('activo', true)
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w->where('referencia', 'like', "%{$busqueda}%")->orWhere('nombre', 'like', "%{$busqueda}%")))
            ->where(fn ($q) => $q->whereIn('id', $vendido->keys())->orWhereIn('id', $stock->keys())->orWhereIn('id', $porLlegar->keys()))
            ->get(['id', 'referencia', 'nombre', 'stock_minimo', 'es_de_linea'])
            ->map(function (Producto $p) use ($vendido, $stock, $porLlegar, $ultimaCaja, $llegoEn, $hoy, $diasVenta, $diasObjetivo) {
                $vendidas = max(0.0, (float) ($vendido[$p->id] ?? 0));
                $hay = (float) ($stock[$p->id] ?? 0);
                $viene = max(0.0, (float) ($porLlegar[$p->id]->total ?? 0));
                $llego = isset($llegoEn[$p->id]) ? Carbon::parse($llegoEn[$p->id], 'America/Bogota')->startOfDay() : null;
                $dias = $llego ? max(1, (int) $llego->diffInDays($hoy)) : max(1, $diasVenta);
                $porDia = $vendidas / $dias;
                $porCaja = (int) ($ultimaCaja[$p->id] ?? 0);
                $deLinea = (bool) $p->es_de_linea;

                $necesita = $porDia * $diasObjetivo;
                $falta = max(0.0, $necesita - $hay - $viene);
                // De línea siempre debe haber: rota despacio, pero se mantiene su mínimo.
                if ($deLinea) {
                    $falta = max($falta, (float) $p->stock_minimo - $hay - $viene);
                }
                // Se pide por cajas: lo sugerido se sube a la caja completa.
                $sugerido = $porCaja > 0 ? ceil($falta / $porCaja) * $porCaja : ceil($falta);

                return [
                    'id' => $p->id,
                    'referencia' => $p->referencia,
                    'nombre' => $p->nombre,
                    'vendido' => $vendidas,
                    'llego_en' => $llego?->toDateString(),
                    'dias_medidos' => $dias,
                    'necesita' => round($necesita, 1),
                    'de_linea' => $deLinea,
                    'por_mes' => round($porDia * 30, 1),
                    'stock' => $hay,
                    'por_llegar' => $viene,
                    'cobertura_dias' => $porDia > 0 ? (int) floor(($hay + $viene) / $porDia) : null,
                    'minimo' => (int) $p->stock_minimo,
                    'bajo_minimo' => (int) $p->stock_minimo > 0 && $hay < (int) $p->stock_minimo,
                    'rotacion' => $this->rotacion($porDia, $hay),
                    'sugerido' => (float) $sugerido,
                    'cajas' => $porCaja > 0 ? (int) ceil($falta / $porCaja) : null,
                ];
            })
            ->sortByDesc('vendido')
            ->take(self::LIMITE)
            ->values();
    }

    /** @return array<string, int> */
    public function resumen(Collection $filas): array
    {
        return [
            'por_reforzar' => $filas->where('sugerido', '>', 0)->count(),
            'bajo_minimo' => $filas->where('bajo_minimo', true)->count(),
            'sin_movimiento' => $filas->where('rotacion', 'sin_movimiento')->count(),
        ];
    }

    private function rotacion(float $porDia, float $stock): string
    {
        return match (true) {
            $porDia <= 0 => $stock > 0 ? 'sin_movimiento' : 'sin_datos',
            $porDia >= 1 => 'alta',
            $porDia >= 0.2 => 'media',
            default => 'baja',
        };
    }
}
