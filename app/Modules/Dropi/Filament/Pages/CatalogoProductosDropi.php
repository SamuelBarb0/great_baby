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

/**
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

    protected static ?string $navigationLabel = 'Catálogo de productos';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Catálogo de productos';

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
                TextColumn::make('nombre')->label('Nombre')->searchable()->limit(45)->tooltip(fn (Producto $record) => $record->nombre),
                TextColumn::make('categoriaMaestra.nombre')->label('Categoría')->badge()->toggleable(),
                TextColumn::make('precio_proveedor')->label('Costo')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('stock')->label('Stock')->alignEnd()
                    ->state(fn (Producto $record) => $record->stockEn()),
                TextColumn::make('dropi_sku')->label('En Dropi')->placeholder('—')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Activos')->default(true),
                TernaryFilter::make('en_dropi')->label('Conectado con Dropi')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('dropi_sku'),
                        false: fn ($q) => $q->whereNull('dropi_sku'),
                    ),
            ])
            // Producto creado en el ERP → también en Dropi, y quedan conectados.
            ->recordActions([
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
            ->emptyStateHeading('No hay productos');
    }
}
