<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use BackedEnum;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * «Pagos recibidos» sobre facturas de la línea Dropi. Un pago no tiene línea
 * propia: es la de su factura (igual que en /app).
 */
class PagosRecibidosDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Pagos recibidos';

    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Pagos recibidos · Dropi';

    protected static ?string $slug = 'contabilidad-pagos-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/pagos';
    }

    protected static function puedeVer(User $u): bool
    {
        // PagosController.
        return Permisos::puede($u, 'pagos');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::ejesDropi()->aplicarPor(
                PagoVenta::query()->with('factura:id,numero,contacto_id', 'factura.contacto:id,nombre_completo'),
                'factura',
            ))
            ->columns([
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('factura.numero')->label('Factura'),
                TextColumn::make('factura.contacto.nombre_completo')->label('Cliente'),
                TextColumn::make('medio_pago')->label('Medio')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('referencia')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('monto_recibido')->label('Recibido')->money('COP')->alignEnd()
                    ->summarize(Sum::make()->money('COP')->label('Total')),
                TextColumn::make('monto_aplicado')->label('Aplicado')->money('COP')->alignEnd()
                    ->summarize(Sum::make()->money('COP')->label('Total')),
            ])
            ->defaultSort('fecha', 'desc')
            ->recordUrl(fn (PagoVenta $p) => $p->factura_id ? '/app/facturas/' . $p->factura_id : null, shouldOpenInNewTab: true)
            ->emptyStateHeading('No hay pagos sobre facturas de Dropi');
    }
}
