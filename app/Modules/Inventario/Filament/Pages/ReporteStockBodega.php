<?php

namespace App\Modules\Inventario\Filament\Pages;

use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Services\StockService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class ReporteStockBodega extends Page
{
    protected string $view = 'inventario.pages.reporte-stock';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationLabel = 'Stock por bodega';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario y Logística';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'stock-bodegas';

    public ?int $ubicacionId = null;

    public function mount(): void
    {
        // Fix regresión post-QA5 · el gate lo hace canAccess() con la matriz Permisos.
        //   Antes: abort_unless esAracely bloqueaba Gerente/Contador/Alistador que la
        //   matriz sí autoriza → sidebar los dejaba entrar y la página tiraba 403.
        abort_unless(\App\Auth\Permisos::puede(auth()->user(), 'stock_bodegas'), 403);
        $this->ubicacionId = InventarioUbicacion::where('activa', true)->orderBy('id')->value('id');
    }

    public static function canAccess(): bool
    {
        return \App\Auth\Permisos::puede(auth()->user(), 'stock_bodegas');
    }

    public function bodegas()
    {
        return InventarioUbicacion::where('activa', true)->orderBy('nombre')->get();
    }

    public function saldos(): array
    {
        if (! $this->ubicacionId) {
            return [];
        }

        // C-F3 FIX ALTO auditor · reporte incluye SAMBOS modos.
        //   Antes: INNER JOIN a producto_variantes → productos agregados
        //   (variante_id NULL) quedaban fuera. Aracely no veía los 134
        //   productos importados del cliente en este reporte.
        //   Ahora: UNION de granulares (por variante) + agregados (por producto).

        // 1) Saldos granulares (por variante) — comportamiento original.
        $granulares = DB::table('inventario_movimientos as m')
            ->join('producto_variantes as v', 'v.id', '=', 'm.variante_id')
            ->leftJoin('productos as p', 'p.id', '=', 'v.producto_id')
            ->where('m.ubicacion_id', $this->ubicacionId)
            ->whereNull('m.deleted_at')   // INV-A6 · lo anulado no es stock
            ->selectRaw('
                v.id as variante_id,
                NULL as producto_agregado_id,
                v.codigo_barras,
                v.color_nombre,
                v.talla,
                p.referencia,
                p.nombre as producto_nombre,
                "granular" as modo,
                SUM(m.cantidad) as saldo
            ')
            ->groupBy('v.id', 'v.codigo_barras', 'v.color_nombre', 'v.talla', 'p.referencia', 'p.nombre')
            ->havingRaw('saldo != 0');

        // 2) Saldos agregados (por producto, variante_id IS NULL).
        $agregados = DB::table('inventario_movimientos as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.ubicacion_id', $this->ubicacionId)
            ->whereNull('m.variante_id')
            ->whereNull('m.deleted_at')   // INV-A6 · lo anulado no es stock
            ->selectRaw('
                NULL as variante_id,
                p.id as producto_agregado_id,
                NULL as codigo_barras,
                p.descripcion as color_nombre,
                NULL as talla,
                p.referencia,
                p.nombre as producto_nombre,
                "agregado" as modo,
                SUM(m.cantidad) as saldo
            ')
            ->groupBy('p.id', 'p.descripcion', 'p.referencia', 'p.nombre')
            ->havingRaw('saldo != 0');

        return $granulares->union($agregados)
            ->orderByDesc('saldo')
            ->get()
            ->toArray();
    }
}
