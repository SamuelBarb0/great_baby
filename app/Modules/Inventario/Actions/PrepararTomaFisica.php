<?php

namespace App\Modules\Inventario\Actions;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Inventario\Models\TomaFisicaItem;
use App\Modules\Inventario\Services\StockService;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Congela el saldo actual del sistema para una ubicación en items snapshots.
 * El operario luego va llenando cantidad_contada y se calcula diferencia.
 *
 * Re-audit M3 PATRÓN ε + M18 + β:
 *   - Cambio de estado se hace AL FINAL (antes: si el proceso moría cargando
 *     variantes, la toma quedaba EnConteo sin items — falso-verde al cerrar).
 *   - Costo por línea = COSTO PROMEDIO PONDERADO del kardex (no `precio_proveedor`
 *     del maestro). Antes: un usuario con permiso al catálogo subía
 *     `precio_proveedor` antes de iniciar toma, inflaba `valor_ajuste`,
 *     cerraba y ganaba asiento manipulado.
 *   - Fallback a `precio_proveedor` SOLO si el kardex no tiene datos (variante
 *     nunca movida) — se marca `costo_unit_fuente` para trazabilidad.
 */
class PrepararTomaFisica
{
    use AsAction;

    public function __construct(protected StockService $stock)
    {
    }

    public function handle(TomaFisica $toma): TomaFisica
    {
        return DB::transaction(function () use ($toma) {
            if ($toma->estado !== EstadoTomaFisica::Borrador) {
                // El mensaje no puede reventar al construirse: si `estado`
                // viene nulo (modelo recién creado que todavía no leyó el
                // default de la base), `->value` lanzaba un ErrorException y
                // tapaba el motivo real con un error de PHP.
                $actual = $toma->estado instanceof EstadoTomaFisica
                    ? $toma->estado->value
                    : (string) ($toma->estado ?? 'sin estado');

                throw new \InvalidArgumentException(
                    "PrepararTomaFisica solo procesa Borradores. Estado actual: {$actual}."
                );
            }

            // C-F2 R2 · Toma física ahora cubre AMBOS modos.
            //   Bloque 1: iteración sobre variantes de productos granulares
            //   Bloque 2: iteración sobre productos agregados (variante_id NULL en item)
            //   El PMP se calcula con la API adecuada (por variante O por producto).

            $itemsCreados = 0;

            // ─── Bloque 1: variantes de productos granulares ─────────
            $itemsCreados += $this->prepararGranulares($toma);

            // ─── Bloque 2: productos agregados ───────────────────────
            $itemsCreados += $this->prepararAgregados($toma);

            if ($itemsCreados === 0) {
                throw new \InvalidArgumentException(
                    'No se crearon items (alcance sin saldos > 0). Cambia tipo o alcance.'
                );
            }

            // ESTADO al FINAL — si algo revienta arriba, la toma queda Borrador
            // (puede reintentarse) en vez de EnConteo huérfana.
            $toma->estado = EstadoTomaFisica::EnConteo;
            $toma->save();

            return $toma->fresh(['items']);
        });
    }

    /**
     * C-F2 R2 · Siembra items de variantes granulares (comportamiento clásico).
     */
    protected function prepararGranulares(TomaFisica $toma): int
    {
        $variantesQ = ProductoVariante::query()
            ->with('producto:id,precio_proveedor,marca_id,desglose_stock')
            ->whereHas('producto', fn ($p) => $p->where('desglose_stock', true));

        if ($toma->alcance && preg_match('/^(marca|categoria):(\d+)$/', $toma->alcance, $m)) {
            $columna = $m[1] === 'marca' ? 'marca_id' : 'categoria_id';
            $variantesQ = $variantesQ->whereHas('producto', fn ($q) => $q->where($columna, (int) $m[2]));
        } elseif ($toma->alcance) {
            throw new \InvalidArgumentException(
                "Alcance no válido: '{$toma->alcance}'. Usa 'marca:N' o 'categoria:N' con N como ID numérico."
            );
        }

        $variantes = $variantesQ->get(['id', 'producto_id']);
        if ($variantes->isEmpty()) return 0;

        $saldos = $this->stock->saldosMasivos($variantes->pluck('id')->all());
        $n = 0;
        foreach ($variantes as $v) {
            $saldo = (int) ($saldos["{$v->id}-{$toma->ubicacion_id}"]->saldo ?? 0);
            if ($saldo <= 0 && $toma->tipo === 'ciclico') continue;

            $costo = $this->calcularCostoPromedio($v->id, $toma->ubicacion_id)
                ?? (float) ($v->producto?->precio_proveedor ?? 0);

            // Re-audit M3 α · asignación por propiedades (no fill()) para
            //   respetar $guarded en TomaFisicaItem sin volver a exponer
            //   saldo_sistema/costo_unit a mass-assign en Filament.
            $item = TomaFisicaItem::firstOrNew([
                'toma_id' => $toma->id, 'variante_id' => $v->id,
            ]);
            $item->saldo_sistema = $saldo;
            $item->costo_unit = $costo;
            $item->producto_id = $v->producto_id; // C-F2 R2 · popula por consistencia
            $item->save();
            $n++;
        }
        return $n;
    }

    /**
     * C-F2 R2 · Siembra items de productos agregados (variante_id NULL, producto_id set).
     */
    protected function prepararAgregados(TomaFisica $toma): int
    {
        /*
         * Lo que manda es el KARDEX, no la bandera.
         *
         * Esto pedía `desglose_stock = false`, o sea «los que el catálogo
         * declara agregados». Pero la bandera dice cómo se PIENSA manejar el
         * producto y el kardex dice cómo entró de verdad, y los dos se
         * separan: un producto marcado granular al que le cargaron el saldo
         * sin variante quedaba fuera de los dos bloques —el de variantes no lo
         * encuentra porque sus movimientos tienen `variante_id` nulo, y éste
         * lo excluía por la bandera—. Sus unidades no existían para el conteo.
         *
         * Medido el 29-oct en BOG-DEMO: 20 de 39 unidades invisibles. Y un
         * conteo que omite saldo es peor que no contar, porque devuelve una
         * diferencia inventada y alguien ajusta el inventario con ella.
         *
         * Ahora entra todo lo que tenga movimientos sin variante en ESTA
         * ubicación, lo diga la bandera o no, más los declarados agregados
         * (que en una toma total hay que listar aunque estén en cero).
         */
        $conSaldoAgregado = InventarioMovimiento::query()
            ->whereNull('variante_id')
            ->whereNotNull('producto_id')
            ->where('ubicacion_id', $toma->ubicacion_id)
            ->distinct()
            ->pluck('producto_id');

        $productosQ = Producto::query()
            ->where('activo', true)
            ->where(fn ($q) => $q
                ->where('desglose_stock', false)
                ->orWhereIn('id', $conSaldoAgregado));

        if ($toma->alcance && preg_match('/^(marca|categoria):(\d+)$/', $toma->alcance, $m)) {
            $columna = $m[1] === 'marca' ? 'marca_id' : 'categoria_id';
            $productosQ = $productosQ->where($columna, (int) $m[2]);
        }

        $productos = $productosQ->get(['id', 'precio_proveedor']);
        if ($productos->isEmpty()) return 0;

        $saldos = $this->stock->saldosMasivosProducto($productos->pluck('id')->all());
        $n = 0;
        foreach ($productos as $p) {
            $saldo = (int) ($saldos["{$p->id}-{$toma->ubicacion_id}"]->saldo ?? 0);
            if ($saldo <= 0 && $toma->tipo === 'ciclico') continue;

            $costo = $this->calcularCostoPromedioProducto($p->id, $toma->ubicacion_id)
                ?? (float) ($p->precio_proveedor ?? 0);

            $item = TomaFisicaItem::firstOrNew([
                'toma_id' => $toma->id,
                'producto_id' => $p->id,
                'variante_id' => null,
            ]);
            $item->saldo_sistema = $saldo;
            $item->costo_unit = $costo;
            $item->save();
            $n++;
        }
        return $n;
    }

    /**
     * Calcula PMP por (variante, ubicación) desde el kardex.
     *
     * Re-audit M3 PATRÓN μ (FUNC-C1) · antes retornaba `null` INCONDICIONAL
     *   dejando el fix ε en no-op. Ahora la columna `inventario_movimientos.costo_unit`
     *   (migración ronda 2) sí guarda el costo por movimiento, así que el PMP
     *   es real:
     *     PMP = Σ(cantidad_entrada × costo_unit) / Σ(cantidad_entrada)
     *
     *   Sólo se ponderan movimientos con cantidad>0 Y costo_unit no nulo
     *   (movimientos históricos previos a la migración no tienen costo_unit;
     *   quedan fuera del PMP y se cae al fallback del llamador). Se retorna
     *   null si no hay base de cálculo → el llamador decide (fallback o abort).
     */
    protected function calcularCostoPromedio(int $varianteId, int $ubicacionId): ?float
    {
        $agg = InventarioMovimiento::query()
            ->where('variante_id', $varianteId)
            ->where('ubicacion_id', $ubicacionId)
            ->where('cantidad', '>', 0)
            ->whereNotNull('costo_unit')
            ->selectRaw('SUM(cantidad * costo_unit) as valor, SUM(cantidad) as qty')
            ->first();

        $qty = (float) ($agg->qty ?? 0);
        if ($qty <= 0) return null;

        return round(((float) $agg->valor) / $qty, 4);
    }

    /**
     * C-F2 R2 · PMP para productos agregados (variante_id IS NULL en movs).
     */
    protected function calcularCostoPromedioProducto(int $productoId, int $ubicacionId): ?float
    {
        $agg = InventarioMovimiento::query()
            ->where('producto_id', $productoId)
            ->whereNull('variante_id')
            ->where('ubicacion_id', $ubicacionId)
            ->where('cantidad', '>', 0)
            ->whereNotNull('costo_unit')
            ->selectRaw('SUM(cantidad * costo_unit) as valor, SUM(cantidad) as qty')
            ->first();

        $qty = (float) ($agg->qty ?? 0);
        if ($qty <= 0) return null;

        return round(((float) $agg->valor) / $qty, 4);
    }
}
