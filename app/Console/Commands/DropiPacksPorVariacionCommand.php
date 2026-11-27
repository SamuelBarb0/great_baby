<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Services\CatalogoDropi;
use Illuminate\Console\Command;

/**
 * Las variaciones de Dropi que son packs (27-nov-2026: «X 2 CAJAS» vinculada a
 * la caja suelta del ERP). Sin opciones sólo lista lo que propone según el
 * nombre de la variación; con --aplicar lo guarda. --sku y --unidades corrigen
 * una a mano.
 */
class DropiPacksPorVariacionCommand extends Command
{
    protected $signature = 'dropi:packs-por-variacion
        {--aplicar : Guardar las unidades propuestas}
        {--sku= : Una llave de Dropi (DV-…) para fijarle las unidades a mano}
        {--unidades= : Las unidades del ERP de esa llave}';

    protected $description = 'Lista (y con --aplicar guarda) cuántas unidades del ERP trae cada variación de Dropi que es un pack';

    public function handle(CatalogoDropi $catalogo): int
    {
        if ($sku = $this->option('sku')) {
            $unidades = (int) $this->option('unidades');
            if ($unidades < 1) {
                $this->error('Falta --unidades (1 o más).');

                return self::FAILURE;
            }
            $n = DropiVinculoProducto::where('sku_dropi', $sku)->update(['unidades' => $unidades]);
            $this->info($n ? "{$sku}: {$unidades} unidad(es) del ERP." : "{$sku} no está vinculada.");

            return $n ? self::SUCCESS : self::FAILURE;
        }

        // El nombre de cada variación: el del vínculo, el de la lectura del catálogo o el de un pedido.
        $nombres = collect($catalogo->misProductos()['productos'] ?? [])
            ->flatMap(fn ($p) => collect($p['variaciones'])->mapWithKeys(fn ($v) => [$v['llave'] => [$p['nombre'], $v['variacion'] ?? null]]));

        $filas = [];
        foreach (DropiVinculoProducto::query()->with(['variante.producto', 'producto'])->get() as $v) {
            [$producto, $variacion] = $nombres[$v->sku_dropi] ?? [$v->producto_nombre, $v->variacion];
            $variacion ??= DropiPedidoItem::where('sku_dropi', $v->sku_dropi)->whereNotNull('variacion')->latest('id')->value('variacion');
            $propuesta = DropiVinculoProducto::detectarUnidades($variacion);
            if ($propuesta === (int) $v->unidades) {
                continue;
            }
            $filas[] = [$v->sku_dropi, (string) $producto, (string) $variacion, ($v->variante?->producto ?? $v->producto)?->referencia ?? '—', $v->unidades, $propuesta];
            if ($this->option('aplicar')) {
                $v->update(['unidades' => $propuesta]);
            }
        }

        if ($filas === []) {
            $this->info('No hay variaciones por ajustar.');

            return self::SUCCESS;
        }
        $this->table(['Llave', 'Producto en Dropi', 'Variación', 'Ref. ERP', 'Hoy', 'Propuesta'], $filas);
        $this->line($this->option('aplicar') ? count($filas) . ' guardada(s).' : count($filas) . ' por revisar. Con --aplicar se guardan.');

        return self::SUCCESS;
    }
}
