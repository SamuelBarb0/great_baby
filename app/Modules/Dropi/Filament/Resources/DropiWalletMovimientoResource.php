<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Filament\Pages\LibroContableDropi;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource\Pages;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropiWalletMovimientoResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = DropiWalletMovimiento::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Wallet Dropi';

    protected static ?string $modelLabel = 'Movimiento wallet';

    protected static ?string $pluralModelLabel = 'Movimientos wallet';

    // 9-nov · vive en «Contabilidad»: cada movimiento es un asiento (o un
    // recibo de caja) del libro de Dropi, y la columna «Asiento» lo enlaza.
    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 6;

    /** La tabla filtrada a un movimiento: para llegar desde el libro contable. */
    public static function urlDelMovimiento(int $id): string
    {
        return static::getUrl('index').'?'.http_build_query(['filters' => ['movimiento' => ['id' => $id]]]);
    }

    /** La tabla filtrada a uno o varios estados contables. */
    public static function urlDeEstados(array $estados): string
    {
        return static::getUrl('index').'?'.http_build_query(['filters' => ['estado_contable' => ['values' => $estados]]]);
    }

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
        $estados = DropiWalletMovimiento::estadosContables();

        return $table
            // Filament inyecta por NOMBRE: el parámetro tiene que llamarse $query.
            ->modifyQueryUsing(fn (Builder $query) => $query->conEstadoContable()->with('pedido'))
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
                // El enlace con contabilidad: a su asiento en el libro, o a la
                // factura cuyo recibo de caja lo asienta.
                TextColumn::make('estado_contable')->label('Asiento')->badge()
                    ->formatStateUsing(fn ($state) => $estados[$state][0] ?? '—')
                    ->color(fn ($state) => $estados[$state][1] ?? 'gray')
                    ->tooltip(fn (DropiWalletMovimiento $r) => $estados[$r->estado_contable][2] ?? null)
                    ->url(fn (DropiWalletMovimiento $r) => match ($r->estado_contable) {
                        DropiWalletMovimiento::CONTABLE_ASENTADO => LibroContableDropi::urlDeWallet($r->id),
                        DropiWalletMovimiento::CONTABLE_POR_RECIBO, DropiWalletMovimiento::CONTABLE_RECIBO_PENDIENTE => \App\Modules\Dropi\Filament\Pages\VerFacturaDropi::urlDe($r->pedido?->factura_venta_id),
                        default => null,
                    }, shouldOpenInNewTab: true),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('tipo')
                    ->options(collect(TipoMovimientoWallet::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
                SelectFilter::make('estado_contable')->label('Asiento')->multiple()
                    ->options(collect($estados)->map(fn ($e) => $e[0])->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['values'] ?? [],
                        fn ($q, $valores) => $q->estadoContable($valores),
                    )),
                Filter::make('movimiento')
                    ->schema([TextInput::make('id')->label('Movimiento #')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when($data['id'] ?? null, fn ($q, $id) => $q->whereKey((int) $id))),
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
