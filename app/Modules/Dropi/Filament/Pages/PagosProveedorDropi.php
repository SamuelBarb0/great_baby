<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Cartera\Actions\AnularPagoProveedor;
use App\Modules\Cartera\Actions\ConfirmarPagoProveedor;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use App\Modules\Siigo\Jobs\PushPagoProveedorASiigo;
use App\Support\PasoNoPermitido;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Pagos a proveedores» de Dropi: los pagos de facturas de compras Dropi.
 *
 * Se registran desde «Cuentas por pagar» y nacen pendientes. Confirmar es de
 * OTRA persona del equipo contable (cuatro ojos, igual que /app): al confirmar
 * sale el asiento y el pago va a SIIGO.
 */
class PagosProveedorDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Pagos a proveedores';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Pagos a proveedores · Dropi';

    protected static ?string $slug = 'compras-pagos-proveedor-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/cartera/pagos-proveedor';
    }

    protected static function puedeVer(User $u): bool
    {
        // PagosProveedorController.
        return $u->esContable();
    }

    /** Un pago es de Dropi si paga una recepción de Dropi. */
    public static function consulta(): Builder
    {
        return PagoProveedor::query()
            ->whereHas('recepcion', fn ($q) => $q->where('linea_negocio', LineaNegocio::Dropi->value));
    }

    public function resumen(): array
    {
        $mes = fn () => self::consulta()->where('estado', '!=', 'anulado')
            ->whereBetween('fecha', [now('America/Bogota')->startOfMonth()->toDateString(), now('America/Bogota')->toDateString()]);
        $cop = fn (float $v) => '$ ' . number_format($v, 0, ',', '.');
        $pendientes = self::consulta()->where('estado', 'pendiente')->count();

        return [
            ['label' => 'Por confirmar', 'valor' => (string) $pendientes, 'alerta' => $pendientes > 0],
            ['label' => 'Pagado este mes (neto)', 'valor' => $cop((float) $mes()->sum('monto_neto'))],
            ['label' => 'Retenido este mes', 'valor' => $cop((float) $mes()->sum('monto_retenciones'))],
        ];
    }

    public function table(Table $table): Table
    {
        $avisar = fn (PasoNoPermitido $e) => Notification::make()->title($e->getMessage())
            ->{$e->tipo === 'info' ? 'info' : 'danger'}()->send();

        return $table
            ->query(fn () => self::consulta()->with(['proveedor:id,nombre_completo', 'recepcion:id,numero,factura_proveedor', 'autor:id,name']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('proveedor.nombre_completo')->label('Proveedor')->searchable(),
                TextColumn::make('recepcion.factura_proveedor')->label('Factura'),
                TextColumn::make('monto_bruto')->label('Bruto')->money('COP')->alignEnd(),
                TextColumn::make('monto_retenciones')->label('Retenido')->money('COP')->alignEnd(),
                TextColumn::make('monto_neto')->label('Neto')->money('COP')->alignEnd()
                    ->summarize(Sum::make()->money('COP')->label('Total')),
                TextColumn::make('metodo')->label('Medio')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('autor.name')->label('Registró')->toggleable(),
                TextColumn::make('estado')->badge()->formatStateUsing(fn ($state) => ucfirst((string) $state))
                    ->color(fn ($state) => match ($state) { 'pendiente' => 'warning', 'confirmado' => 'success', 'anulado' => 'danger', default => 'gray' }),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'anulado' => 'Anulado']),
            ])
            ->recordActions([
                Action::make('confirmar')->label('Confirmar')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (PagoProveedor $record) => $record->estado === 'pendiente')
                    ->requiresConfirmation()
                    ->modalDescription('Al confirmar sale el asiento contable y el pago va a SIIGO. Quien lo registró no lo puede confirmar.')
                    ->action(function (PagoProveedor $record) use ($avisar) {
                        try {
                            ConfirmarPagoProveedor::run($record, auth()->user());
                        } catch (PasoNoPermitido $e) {
                            $avisar($e);

                            return;
                        }
                        Notification::make()->title("Pago #{$record->id} confirmado y encolado a SIIGO")->success()->send();
                    }),
                Action::make('siigo')->label('Reenviar a SIIGO')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (PagoProveedor $record) => $record->estado === 'confirmado' && ! $record->siigo_voucher_id)
                    ->requiresConfirmation()
                    ->action(function (PagoProveedor $record) {
                        PushPagoProveedorASiigo::dispatchManual($record->id);
                        Notification::make()->title("Pago #{$record->id} encolado a SIIGO")->success()->send();
                    }),
                Action::make('anular')->label('Anular')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (PagoProveedor $record) => $record->estado !== 'anulado' && ! $record->siigo_voucher_id)
                    ->schema([Textarea::make('motivo')->label('¿Por qué se anula?')->required()->minLength(5)->maxLength(500)])
                    ->action(function (PagoProveedor $record, array $data) use ($avisar) {
                        try {
                            $borradas = AnularPagoProveedor::run($record, auth()->user(), $data['motivo']);
                        } catch (PasoNoPermitido $e) {
                            $avisar($e);

                            return;
                        }
                        Notification::make()->title("Pago #{$record->id} anulado")
                            ->body($borradas > 0 ? "Se revirtieron {$borradas} línea(s) del libro." : null)->success()->send();
                    }),
            ])
            ->emptyStateHeading('No hay pagos a proveedores de Dropi');
    }
}
