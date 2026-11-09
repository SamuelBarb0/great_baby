<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** «Recepciones»: la mercancía que llegó para la línea Dropi. */
class RecepcionesDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Recepciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Recepciones · Dropi';

    protected static ?string $slug = 'compras-recepciones-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/compras';
    }

    protected static function puedeVer(User $u): bool
    {
        // Las recepciones salen en la misma pantalla de compras de /app.
        return $u->puedeArmarOrdenes();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::ejesDropi()->aplicar(RecepcionCompra::query()->with('orden:id,numero,proveedor_id', 'orden.proveedor:id,nombre_completo')))
            ->columns([
                TextColumn::make('numero')->label('Recepción')->searchable()->sortable(),
                TextColumn::make('orden.numero')->label('Orden'),
                TextColumn::make('orden.proveedor.nombre_completo')->label('Proveedor'),
                TextColumn::make('fecha_recepcion')->label('Fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('factura_proveedor')->label('Factura proveedor')->searchable()->placeholder('—'),
                TextColumn::make('total_recibido')->label('Total')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('estado')->badge()->formatStateUsing(fn ($state) => ucfirst((string) $state)),
            ])
            ->defaultSort('fecha_recepcion', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(['borrador' => 'Borrador', 'confirmada' => 'Confirmada']),
            ])
            ->recordUrl(fn (RecepcionCompra $record) => VerOrdenCompraDropi::getUrl(['orden' => $record->orden_id]))
            ->emptyStateHeading('No hay recepciones de Dropi');
    }
}
