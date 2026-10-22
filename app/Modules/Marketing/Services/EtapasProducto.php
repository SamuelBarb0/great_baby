<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MKT-4 · En qué parte del camino va cada producto, de la compra a la
 * publicación. Lo pidió Marketing: «a veces cuando ya llega a marketing ya
 * pasó mucho tiempo».
 *
 *   compra     · está en una orden de compra que aún no va en un contenedor.
 *   contenedor · su orden viaja en un contenedor (en tránsito, puerto o DIAN).
 *   bodega     · ya llegó (recepción confirmada o contenedor liquidado) y
 *                Marketing todavía no empieza.
 *   contenido  · Marketing ya grabó, editó o subió creativos.
 *   publicado  · listo para publicar y visible para Ventas.
 *
 * Se mira la orden de compra MÁS RECIENTE del producto: un producto de línea
 * que vuelve a venir en otro contenedor vuelve a contar desde esa compra.
 * Todo se calcula en pocas consultas para la página entera, no por producto.
 */
class EtapasProducto
{
    public const ETAPAS = [
        'compra' => 'Compra',
        'contenedor' => 'Contenedor',
        'bodega' => 'En bodega',
        'contenido' => 'Contenido',
        'publicado' => 'Publicado',
    ];

    /**
     * @param  Collection<int, Producto>  $productos
     * @return array<int, array{etapa: ?string, contenedor: ?array, orden: ?string, llego_en: ?string, dias_desde_llegada: ?int, piezas: array}>
     */
    public function para(Collection $productos): array
    {
        $ids = $productos->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        $compras = $this->ultimaCompra($ids);
        $llegadas = $this->llegadas(collect($compras)->pluck('orden_id')->filter()->unique()->all());
        $contenido = $this->contenido($ids);

        $resultado = [];
        foreach ($productos as $p) {
            $compra = $compras[$p->id] ?? null;
            $llego = $compra ? $this->fechaLlegada($compra, $llegadas) : null;
            $hechas = $contenido['piezas'][$p->id] ?? ['video' => 0, 'reel' => 0, 'imagen' => 0];
            $conContenido = ($contenido['posts'][$p->id] ?? 0) > 0 || array_sum($hechas) > 0;

            $etapa = match (true) {
                $p->listo_publicar && $p->publicado => 'publicado',
                $conContenido => 'contenido',
                $compra && $llego !== null => 'bodega',
                $compra && $compra->importacion_id !== null => 'contenedor',
                $compra !== null => 'compra',
                default => null,
            };

            $resultado[$p->id] = [
                'etapa' => $etapa,
                'orden' => $compra?->orden_numero,
                'contenedor' => $compra && $compra->importacion_id ? [
                    'id' => (int) $compra->importacion_id,
                    'numero' => $compra->importacion_numero,
                    'contenedor' => $compra->contenedor,
                    'estado' => $compra->importacion_estado,
                    'eta' => $compra->eta,
                ] : null,
                'llego_en' => $llego?->toDateString(),
                'dias_desde_llegada' => $llego ? (int) $llego->startOfDay()->diffInDays(now()->startOfDay()) : null,
                'piezas' => $this->piezas($p, $hechas),
            ];
        }

        return $resultado;
    }

    /**
     * Piezas pedidas (en la ficha) contra las subidas. `completas` es null si
     * el producto no tiene piezas marcadas.
     */
    public function piezas(Producto $p, array $hechas): array
    {
        $pedidas = [
            'video' => $p->piezas_video ? 1 : 0,
            'reel' => $p->piezas_reel ? 1 : 0,
            'imagen' => (int) $p->piezas_imagenes,
        ];
        $faltan = [];
        foreach ($pedidas as $tipo => $n) {
            if ($n > ($hechas[$tipo] ?? 0)) {
                $faltan[$tipo] = $n - ($hechas[$tipo] ?? 0);
            }
        }

        return [
            'pedidas' => $pedidas,
            'hechas' => $hechas,
            'faltan' => $faltan,
            'completas' => array_sum($pedidas) === 0 ? null : $faltan === [],
        ];
    }

    /** Productos de un contenedor: los de las órdenes de compra que viajan en él. */
    public function productosDelContenedor(int $importacionId): array
    {
        return DB::table('compra_orden_items as i')
            ->join('compras_importacion_ordenes as io', 'io.orden_id', '=', 'i.orden_id')
            ->where('io.importacion_id', $importacionId)
            ->whereNotNull('i.producto_id')
            ->groupBy('i.producto_id')
            ->selectRaw('i.producto_id, SUM(i.cantidad) as cantidad')
            ->pluck('cantidad', 'producto_id')
            ->map(fn ($c) => (float) $c)
            ->all();
    }

    /** La orden de compra vigente más reciente de cada producto, con su contenedor. */
    private function ultimaCompra(array $ids): array
    {
        $filas = DB::table('compra_orden_items as i')
            ->join('compras_ordenes as o', 'o.id', '=', 'i.orden_id')
            ->leftJoin('compras_importacion_ordenes as io', 'io.orden_id', '=', 'o.id')
            ->leftJoin('compras_importaciones as imp', function ($j) {
                $j->on('imp.id', '=', 'io.importacion_id')->whereNull('imp.deleted_at');
            })
            ->whereIn('i.producto_id', $ids)
            ->whereNotIn('o.estado', [EstadoOrdenCompra::Anulada->value, EstadoOrdenCompra::Borrador->value])
            ->whereNull('o.deleted_at')
            ->orderByDesc('o.fecha_emision')->orderByDesc('o.id')
            ->get([
                'i.producto_id', 'o.id as orden_id', 'o.numero as orden_numero',
                'imp.id as importacion_id', 'imp.numero as importacion_numero', 'imp.contenedor',
                'imp.estado as importacion_estado', 'imp.eta', 'imp.fecha_llegada',
            ]);

        $porProducto = [];
        foreach ($filas as $f) {
            $porProducto[$f->producto_id] ??= $f;
        }

        return $porProducto;
    }

    /** Primera recepción confirmada de cada orden. */
    private function llegadas(array $ordenIds): array
    {
        if (! $ordenIds) {
            return [];
        }

        return DB::table('compras_recepciones')
            ->whereIn('orden_id', $ordenIds)
            ->whereNotNull('confirmada_at')
            ->groupBy('orden_id')
            ->selectRaw('orden_id, MIN(confirmada_at) as llegada')
            ->pluck('llegada', 'orden_id')
            ->all();
    }

    private function fechaLlegada(object $compra, array $llegadas): ?Carbon
    {
        if (isset($llegadas[$compra->orden_id])) {
            return Carbon::parse($llegadas[$compra->orden_id]);
        }

        $estado = EstadoImportacion::tryFrom((string) $compra->importacion_estado);
        if (in_array($estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true)) {
            return Carbon::parse($compra->fecha_llegada ?? now());
        }

        return null;
    }

    /** Posts en producción y creativos subidos, por producto y tipo. */
    private function contenido(array $ids): array
    {
        $posts = DB::table('marketing_posts')
            ->whereIn('producto_id', $ids)
            ->whereNull('deleted_at')
            ->whereNotNull('etapa')
            ->groupBy('producto_id')
            ->selectRaw('producto_id, COUNT(*) as n')
            ->pluck('n', 'producto_id')
            ->all();

        $piezas = [];
        DB::table('marketing_creativos')
            ->whereIn('producto_id', $ids)
            ->whereNull('deleted_at')
            ->groupBy('producto_id', 'tipo')
            ->selectRaw('producto_id, tipo, COUNT(*) as n')
            ->get()
            ->each(function ($f) use (&$piezas) {
                $piezas[$f->producto_id] ??= ['video' => 0, 'reel' => 0, 'imagen' => 0];
                if (isset($piezas[$f->producto_id][$f->tipo])) {
                    $piezas[$f->producto_id][$f->tipo] += (int) $f->n;
                }
            });

        return ['posts' => $posts, 'piezas' => $piezas];
    }
}
