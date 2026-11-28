<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Models\Producto;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «Buscar producto» (27-nov-2026, antes «Catálogo de productos»): el lugar donde
 * cualquiera encuentra un producto —por nombre del ERP, referencia, código de
 * barras o nombre en Dropi— y saca sus etiquetas. Lo de Dropi que todavía no está
 * vinculado sale arriba, con cómo vincularlo.
 *
 * «Catálogo de productos» DENTRO del panel de Dropi (23-nov-2026).
 *
 * El menú llevaba al catálogo del ERP (/app/catalogo/productos) y la clienta
 * terminaba en el ERP principal: «que no se me vaya al ERP». Acá es sólo de
 * consulta (referencia, nombre, costo, stock); el producto se sigue creando y
 * editando donde siempre.
 */
class CatalogoProductosDropi extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Buscar producto';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Buscar producto';

    protected static ?string $slug = 'catalogo-productos';

    protected string $view = 'dropi.pages.tabla-dropi';

    public static function canAccess(): bool
    {
        return Permisos::puede(auth()->user(), 'productos') || Permisos::puede(auth()->user(), 'dropi_productos');
    }

    /** La vista compartida de tablas pinta tarjetas si hay resumen. */
    public function resumen(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Producto::query()->with('categoriaMaestra:id,nombre'))
            ->columns([
                TextColumn::make('referencia')->label('Referencia')->searchable()->sortable()->weight('bold')->copyable(),
                // También por el nombre que tiene en Dropi (27-nov-2026: en bodega buscaban
                //   «abejita» y en el ERP se llama «ABEJA BAILARINA»). Debajo, ese nombre.
                TextColumn::make('nombre')->label('Nombre')->limit(45)->tooltip(fn (Producto $record) => $record->nombre)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('nombre', 'like', "%{$search}%")
                        ->orWhereIn('id', self::idsPorNombreEnDropi($search))
                        ->orWhereIn('id', self::idsPorCatalogoDropi($search))
                        ->orWhereIn('id', self::idsPorCodigo($search))))
                    ->description(fn (Producto $record) => $this->nombreEnDropi($record)),
                TextColumn::make('categoriaMaestra.nombre')->label('Categoría')->badge()->toggleable(),
                TextColumn::make('precio_proveedor')->label('Costo')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('stock')->label('Stock')->alignEnd()
                    ->state(fn (Producto $record) => $record->stockEn()),
                TextColumn::make('dropi_sku')->label('En Dropi')->placeholder('—')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Activos')->default(true),
                TernaryFilter::make('en_dropi')->label('Vinculado con Dropi')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('dropi_sku'),
                        false: fn ($q) => $q->whereNull('dropi_sku'),
                    ),
            ])
            // Producto creado en el ERP → también en Dropi, y quedan conectados.
            ->recordActions([
                // Escoger cuáles etiquetas y cuántas de cada una (reunión con la señora Lahida, 24-nov-2026).
                \App\Modules\Dropi\Filament\Support\ImprimirEtiquetasAccion::make()->label('Etiquetas'),
                \Filament\Actions\Action::make('crearEnDropi')->label('Crear en Dropi')->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (Producto $record) => ! $record->dropi_sku && Permisos::puede(auth()->user(), 'dropi_productos'))
                    ->requiresConfirmation()
                    ->modalDescription('Abre la carga a Dropi con el nombre y la referencia de este producto. Ahí se completan precio, categoría y medidas; al crearse en Dropi queda conectado con esta ficha.')
                    ->action(function (Producto $record, $livewire) {
                        $resp = app(\App\Http\Controllers\App\DropiCargaProductosController::class)->desdeErp($record);
                        $livewire->redirect($resp->getTargetUrl());
                    }),
            ])
            ->defaultSort('referencia')
            ->searchPlaceholder('Nombre, referencia, código de barras o nombre en Dropi')
            // Lo de Dropi sin vincular que coincide con la búsqueda, arriba de la tabla.
            ->header(fn () => ($b = trim((string) $this->getTableSearch())) !== ''
                ? view('dropi.pages.partials.buscar-sin-vincular', [
                    'buscar' => $b,
                    'coincidencias' => self::sinVincularEnDropi($b),
                    'puedeVincular' => ProductosDeDropi::canAccess(),
                ])
                : null)
            ->emptyStateHeading(fn () => ($b = trim((string) $this->getTableSearch())) !== '' ? "No encontramos «{$b}» en el ERP" : 'No hay productos')
            ->emptyStateDescription(fn () => trim((string) $this->getTableSearch()) !== ''
                ? 'Prueba con la referencia, el código de barras o parte del nombre. Si es de Dropi y no está vinculado, sale arriba.'
                : null);
    }

    /**
     * Productos del ERP vinculados a algo del catálogo de Dropi (la última lectura)
     * con ese nombre, SKU o código: por el id de Dropi del producto o por las
     * llaves de sus variaciones.
     *
     * @return list<int>
     */
    public static function idsPorCatalogoDropi(string $search): array
    {
        $t = mb_strtolower(trim($search));
        if (mb_strlen($t) < 3) {
            return [];
        }
        $coinciden = collect(app(\App\Modules\Dropi\Services\CatalogoDropi::class)->foto()['productos'] ?? [])
            ->filter(fn ($p) => str_contains(mb_strtolower($p['nombre'] . ' ' . ($p['sku'] ?? '') . ' ' . $p['id']), $t));
        if ($coinciden->isEmpty()) {
            return [];
        }
        $llaves = $coinciden->flatMap(fn ($p) => array_column($p['llaves'] ?? [], 'llave'))->all();

        return Producto::query()->whereIn('dropi_sku', $coinciden->pluck('id')->map(fn ($id) => (string) $id))->pluck('id')
            ->merge(DB::table('dropi_vinculos_producto as v')->leftJoin('producto_variantes as pv', 'pv.id', '=', 'v.variante_id')
                ->whereIn('v.sku_dropi', $llaves)->selectRaw('COALESCE(v.producto_id, pv.producto_id) as id')->pluck('id'))
            ->filter()->unique()->values()->all();
    }

    /**
     * Por el código de barras de una variante o el código corto de la etiqueta
     * (27-nov-2026: «que se pueda pistolear para encontrarlo»).
     *
     * @return list<int>
     */
    public static function idsPorCodigo(string $search): array
    {
        $codigo = strtoupper(trim($search));
        $ids = \App\Modules\Dropi\Models\ProductoVariante::where('codigo_barras', $codigo)->pluck('producto_id');
        if ($corto = \App\Support\CodigoCortoEtiqueta::leer($codigo)) {
            $ids->push($corto['tipo'] === 'producto'
                ? $corto['id']
                : \App\Modules\Dropi\Models\ProductoVariante::whereKey($corto['id'])->value('producto_id'));
        }

        return $ids->filter()->unique()->values()->all();
    }

    /**
     * Lo del catálogo de Dropi que coincide con la búsqueda y todavía no está (del
     * todo) vinculado: el ERP no lo encuentra porque no sabe qué es.
     *
     * @return list<array{nombre:string, codigo:string, vinculo:string, faltan:int, total:int}>
     */
    public static function sinVincularEnDropi(string $search): array
    {
        $t = mb_strtolower(trim($search));
        if (mb_strlen($t) < 3) {
            return [];
        }

        return collect(app(\App\Modules\Dropi\Services\CatalogoDropi::class)->misProductos()['productos'] ?? [])
            ->filter(fn ($p) => $p['vinculo'] !== 'si' && str_contains(mb_strtolower($p['nombre'] . ' ' . ($p['sku'] ?? '') . ' ' . $p['id']), $t))
            ->take(5)
            ->map(fn ($p) => [
                'nombre' => (string) $p['nombre'],
                'codigo' => (string) ($p['variaciones'][0]['llave'] ?? $p['id']),
                'vinculo' => $p['vinculo'],
                'faltan' => collect($p['variaciones'])->where('vinculado', false)->count(),
                'total' => count($p['variaciones']),
            ])->values()->all();
    }

    /** Las etiquetas armadas, a la vista con «Imprimir» (ver ImprimirEtiquetasAccion). */
    public function verEtiquetasAction(): \Filament\Actions\Action
    {
        return \App\Modules\Dropi\Filament\Support\ImprimirEtiquetasAccion::vistaPrevia();
    }

    /** @var array<int, string>|null producto del ERP => sus nombres en Dropi */
    private ?array $nombresEnDropi = null;

    /**
     * Los productos del ERP vinculados a algo de Dropi con ese nombre: directo
     * (producto agregado) o por una de sus variantes.
     *
     * @return \Illuminate\Database\Query\Builder ids de productos
     */
    public static function idsPorNombreEnDropi(string $search): \Illuminate\Database\Query\Builder
    {
        return DB::table('dropi_vinculos_producto as v')
            ->leftJoin('producto_variantes as pv', 'pv.id', '=', 'v.variante_id')
            ->where('v.producto_nombre', 'like', "%{$search}%")
            ->selectRaw('COALESCE(v.producto_id, pv.producto_id)');
    }

    /** «En Dropi: …» cuando el nombre allá no es el mismo del ERP. */
    public function nombreEnDropi(Producto $p): ?string
    {
        $this->nombresEnDropi ??= DB::table('dropi_vinculos_producto as v')
            ->leftJoin('producto_variantes as pv', 'pv.id', '=', 'v.variante_id')
            ->whereNotNull('v.producto_nombre')
            ->selectRaw('COALESCE(v.producto_id, pv.producto_id) as producto, v.producto_nombre as nombre')
            ->get()
            ->groupBy('producto')
            ->map(fn ($g) => $g->pluck('nombre')->unique()->implode(' · '))
            ->all();

        $nombre = $this->nombresEnDropi[$p->id] ?? null;

        return $nombre && mb_strtolower(trim($nombre)) !== mb_strtolower(trim((string) $p->nombre)) ? 'En Dropi: ' . $nombre : null;
    }
}
