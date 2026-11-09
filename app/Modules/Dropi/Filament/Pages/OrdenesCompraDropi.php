<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** «Órdenes de compra» de la línea Dropi. Se crean, aprueban y reciben desde este panel. */
class OrdenesCompraDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Órdenes de compra';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Órdenes de compra · Dropi';

    protected static ?string $slug = 'compras-ordenes-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/compras';
    }

    protected static function puedeVer(User $u): bool
    {
        // ComprasController.
        return $u->puedeArmarOrdenes();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('nueva')->label('Nueva OC')->icon('heroicon-o-plus')
                ->visible(fn () => \App\Modules\Dropi\Filament\Support\ComprasDropi::puedeArmar(auth()->user()))
                ->url(fn () => NuevaOrdenCompraDropi::getUrl()),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::ejesDropi()->aplicar(OrdenCompra::query()->with('proveedor:id,nombre_completo')))
            ->columns([
                TextColumn::make('numero')->label('Orden')->searchable()->sortable(),
                TextColumn::make('proveedor.nombre_completo')->label('Proveedor')->searchable(),
                TextColumn::make('fecha_emision')->label('Fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('tipo')->label('Tipo')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('total')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('estado')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof EstadoOrdenCompra ? $state->label() : (string) $state),
            ])
            ->defaultSort('fecha_emision', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(collect(EstadoOrdenCompra::cases())
                    ->mapWithKeys(fn (EstadoOrdenCompra $e) => [$e->value => $e->label()])->all()),
            ])
            ->recordUrl(fn (OrdenCompra $record) => VerOrdenCompraDropi::getUrl(['orden' => $record->id]))
            ->emptyStateHeading('No hay órdenes de compra de Dropi');
    }
}
