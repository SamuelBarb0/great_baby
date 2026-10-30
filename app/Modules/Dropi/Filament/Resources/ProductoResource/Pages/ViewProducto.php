<?php

namespace App\Modules\Dropi\Filament\Resources\ProductoResource\Pages;

use App\Modules\Dropi\Filament\Resources\ProductoResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProducto extends ViewRecord
{
    protected static string $resource = ProductoResource::class;

    protected string $view = 'catalogo.pages.view-producto';

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function getVariantes()
    {
        return $this->record->variantes()
            ->with(['color', 'diseno', 'tallaMaestra'])
            ->orderBy('codigo_barras')
            ->get();
    }

    public function getStockPorUbicacion(): array
    {
        // C-F3 FIX ALTO auditor · producto agregado (desglose_stock=false) NO tiene
        //   variantes → el JOIN a producto_variantes lo dejaba fuera. Ahora dos
        //   caminos claros según el modo del producto.
        if ($this->record->desglose_stock) {
            // Granular: agregado por variante (comportamiento original).
            $stock = \DB::table('inventario_movimientos as m')
                ->join('producto_variantes as v', 'm.variante_id', '=', 'v.id')
                ->join('inventario_ubicaciones as u', 'm.ubicacion_id', '=', 'u.id')
                ->where('v.producto_id', $this->record->id)
                ->whereNull('m.deleted_at')   // INV-A6 · lo anulado no es stock
                ->groupBy('v.codigo_barras', 'u.codigo', 'u.nombre')
                ->selectRaw('v.codigo_barras as sku, u.codigo as ubi, u.nombre as ubi_nombre, SUM(m.cantidad) as saldo')
                ->having('saldo', '!=', 0)
                ->get();
            return $stock->groupBy('sku')->map->all()->toArray();
        }

        // Agregado: por producto directo (variante_id IS NULL).
        //   Devolvemos misma estructura para que la vista no distinga: sku vacío,
        //   ubicación + saldo. La vista puede pintar "Stock agregado" en el header.
        $stock = \DB::table('inventario_movimientos as m')
            ->join('inventario_ubicaciones as u', 'm.ubicacion_id', '=', 'u.id')
            ->where('m.producto_id', $this->record->id)
            ->whereNull('m.variante_id')
            ->whereNull('m.deleted_at')   // INV-A6 · lo anulado no es stock
            ->groupBy('u.codigo', 'u.nombre')
            ->selectRaw("'AGREGADO' as sku, u.codigo as ubi, u.nombre as ubi_nombre, SUM(m.cantidad) as saldo")
            ->having('saldo', '!=', 0)
            ->get();
        return $stock->groupBy('sku')->map->all()->toArray();
    }
}
