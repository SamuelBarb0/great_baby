<?php

namespace App\Modules\Dropi\Support;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;

/**
 * Buscador de «¿con qué producto del ERP vinculo esta llave de Dropi?».
 *
 * Devuelve variantes Y productos agregados (sin variantes, todo el stock en un
 * total), que es casi todo el catálogo. Valor `v:<id>` = variante, `p:<id>` =
 * producto agregado. Lo usan «Productos de Dropi» en /admin y en /app, para
 * que las dos puertas ofrezcan exactamente lo mismo.
 *
 * Vivía en la página «Productos sin vincular», que se quitó el 6-nov: lo que
 * hacía lo cubre «Productos de Dropi» (grupo «Con pedidos esperando»).
 */
final class DestinosVinculo
{
    /** @return array<string, string> valor → etiqueta */
    public static function buscar(string $search): array
    {
        $t = '%' . trim($search) . '%';

        $agregados = Producto::query()
            ->where('desglose_stock', false)
            ->where(fn ($q) => $q->where('nombre', 'like', $t)->orWhere('referencia', 'like', $t))
            ->limit(25)
            ->get(['id', 'referencia', 'nombre'])
            ->mapWithKeys(fn ($p) => ['p:' . $p->id => self::etiquetaAgregado($p)]);

        $variantes = ProductoVariante::query()
            ->with('producto')
            ->where(fn ($q) => $q->where('codigo_barras', 'like', $t)
                ->orWhere('color_nombre', 'like', $t)
                ->orWhere('diseno_nombre', 'like', $t)
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $t)->orWhere('referencia', 'like', $t)))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductoVariante $v) => ['v:' . $v->id => self::etiquetaVariante($v)]);

        return $agregados->union($variantes)->all();
    }

    public static function etiqueta(string $valor): string
    {
        [$tipo, $id] = array_pad(explode(':', $valor, 2), 2, null);

        return $tipo === 'p'
            ? self::etiquetaAgregado(Producto::find((int) $id))
            : self::etiquetaVariante(ProductoVariante::with('producto')->find((int) $id));
    }

    public static function etiquetaAgregado(?Producto $p): string
    {
        return $p ? "{$p->nombre} · {$p->referencia} · stock total (sin variantes)" : '';
    }

    public static function etiquetaVariante(?ProductoVariante $v): string
    {
        if (! $v) {
            return '';
        }

        $detalle = collect([$v->color_nombre, $v->diseno_nombre, $v->talla])->filter()->implode(' / ');

        return trim(($v->producto?->nombre ?? 'Sin producto') . ($detalle ? " · {$detalle}" : '') . " · {$v->codigo_barras}");
    }
}
