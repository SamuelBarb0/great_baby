<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource\Pages;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use BackedEnum;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DropiWalletMovimientoResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = DropiWalletMovimiento::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Wallet Dropi';

    protected static ?string $modelLabel = 'Movimiento wallet';

    protected static ?string $pluralModelLabel = 'Movimientos wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'Dinero';

    protected static ?int $navigationSort = 2;

    // Fix D1 · matriz Permisos (Contador debe ver wallet para conciliación).
    public static function canViewAny(): bool
    {
        return \App\Auth\Permisos::puede(auth()->user(), 'dropi_wallet');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Auth\Permisos::puede(auth()->user(), 'dropi_wallet');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                BadgeColumn::make('tipo')
                    ->formatStateUsing(fn ($state) => $state instanceof TipoMovimientoWallet ? $state->label() : $state)
                    ->color(fn ($state) => match ($state instanceof TipoMovimientoWallet ? $state->value : $state) {
                        'pago_guia' => 'success',
                        'retiro_banco' => 'info',
                        'indemnizacion' => 'danger',
                        'flete_garantia', 'tarjeta' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('pedido.guia')->label('Guía')->searchable()->toggleable(),
                TextColumn::make('categoria')
                    ->formatStateUsing(fn ($state) => $state ? \Illuminate\Support\Str::of($state)->replace('_', ' ')->title() : '—')
                    ->toggleable(),
                TextColumn::make('monto')->money('COP')->alignEnd()
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('tipo')
                    ->options(collect(TipoMovimientoWallet::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make()
                        ->exports([
                            \pxlrbt\FilamentExcel\Exports\ExcelExport::make('wallet')
                                ->fromTable()
                                ->withFilename('wallet-dropi-' . now()->format('Y-m-d')),
                        ]),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropiWalletMovimientos::route('/'),
        ];
    }
}
