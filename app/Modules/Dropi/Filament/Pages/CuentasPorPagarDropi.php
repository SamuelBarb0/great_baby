<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Cartera\Actions\RegistrarPagoProveedor;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Services\CuentasPorPagar;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Cuentas por pagar» de Dropi: lo que se le debe a proveedores por compras de
 * la línea Dropi. El cálculo es el de /app (CuentasPorPagar), ya filtrado.
 *
 * Desde cada factura se registra el pago (fase 2, 7-nov). Nace pendiente:
 * lo confirma otra persona en «Pagos a proveedores».
 */
class CuentasPorPagarDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Cuentas por pagar';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Cuentas por pagar · Dropi';

    protected static ?string $slug = 'compras-cuentas-por-pagar-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/compras/cuentas-por-pagar';
    }

    protected static function puedeVer(User $u): bool
    {
        // CuentasPorPagarController.
        return Permisos::puede($u, 'cuentas_por_pagar');
    }

    protected function filas(): Collection
    {
        return once(fn () => app(CuentasPorPagar::class)->pendientes(static::ejesDropi()));
    }

    public function resumen(): array
    {
        $r = app(CuentasPorPagar::class)->resumen($this->filas());
        $cop = fn (float $v) => '$ ' . number_format($v, 0, ',', '.');

        return [
            ['label' => 'Deuda Dropi', 'valor' => $cop($r['total'])],
            ['label' => 'Vencido', 'valor' => $cop($r['vencido']), 'alerta' => $r['vencido'] > 0],
            ['label' => 'Por vencer', 'valor' => $cop($r['por_vencer'])],
            ['label' => 'Facturas · proveedores', 'valor' => $r['facturas'] . ' · ' . $r['proveedores']],
        ];
    }

    /** Los conceptos de retención que tienen regla activa (los mismos que ofrece /app). */
    public static function conceptosRetencion(): array
    {
        return DB::table('retenciones_config')
            ->where('tipo', 'retefuente')->where('activa', true)
            ->orderBy('concepto')
            ->get(['concepto', 'notas'])
            ->mapWithKeys(fn ($c) => [$c->concepto => $c->notas ?: $c->concepto])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            // Viene de un servicio, no de una consulta: la tabla recibe las filas ya hechas.
            ->records(fn () => $this->filas()->keyBy('recepcion_id')->all())
            ->columns([
                TextColumn::make('proveedor')->label('Proveedor'),
                TextColumn::make('factura')->label('Factura'),
                TextColumn::make('numero')->label('Recepción'),
                TextColumn::make('vencimiento')->label('Vence'),
                TextColumn::make('dias_mora')->label('Días de mora')->alignEnd()
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string) $state : 'Al día')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('valor')->money('COP')->alignEnd(),
                TextColumn::make('abonado')->money('COP')->alignEnd(),
                TextColumn::make('saldo')->money('COP')->alignEnd()->weight('bold'),
            ])
            ->recordActions([
                Action::make('pagar')->label('Registrar pago')->icon('heroicon-o-banknotes')
                    // PagosProveedorController: registrar pagos es del equipo contable.
                    ->visible(fn () => (bool) auth()->user()?->esContable())
                    ->modalHeading(fn (array $record) => "Pago a {$record['proveedor']} · factura {$record['factura']}")
                    ->modalDescription('Queda pendiente: lo confirma otra persona en «Pagos a proveedores», y ahí sale el asiento y va a SIIGO.')
                    ->fillForm(fn (array $record) => [
                        'fecha' => now('America/Bogota')->toDateString(),
                        'monto_bruto' => $record['saldo'],
                        'iva' => 0,
                        'concepto_retencion' => array_key_exists('compras_generales', self::conceptosRetencion()) ? 'compras_generales' : null,
                        'metodo' => 'transferencia',
                        'ciudad' => RecepcionCompra::with('orden.proveedor:id,ciudad')->find($record['recepcion_id'])?->orden?->proveedor?->ciudad,
                    ])
                    ->schema([
                        DatePicker::make('fecha')->required(),
                        TextInput::make('monto_bruto')->label('Valor a pagar (bruto)')->numeric()->required()->minValue(0.01)->prefix('$')
                            ->helperText('Antes de retenciones. Se calculan con las reglas de /app.'),
                        TextInput::make('iva')->label('IVA incluido en el valor')->numeric()->minValue(0)->default(0)->prefix('$'),
                        Select::make('concepto_retencion')->label('Concepto de retención')->options(fn () => self::conceptosRetencion())->required(),
                        TextInput::make('ciudad')->label('Ciudad (para ReteICA)')->maxLength(100),
                        Toggle::make('gran_contribuyente')->label('Somos gran contribuyente frente a este proveedor'),
                        Select::make('metodo')->label('Cómo se paga')->required()
                            ->options(['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque']),
                        Textarea::make('observaciones')->maxLength(500),
                    ])
                    ->action(function (array $record, array $data) {
                        // No se paga más de lo que se debe de esa factura.
                        if ((float) $data['monto_bruto'] > (float) $record['saldo'] + 0.01) {
                            Notification::make()->title('Es más de lo que se debe')
                                ->body('De esa factura se deben $' . number_format((float) $record['saldo'], 0, ',', '.') . '.')
                                ->danger()->send();

                            return;
                        }
                        $rc = RecepcionCompra::with('orden:id,proveedor_id')->findOrFail($record['recepcion_id']);
                        $pago = RegistrarPagoProveedor::run([
                            'fecha' => $data['fecha'],
                            'contacto_id' => $rc->orden->proveedor_id,
                            'orden_compra_id' => $rc->orden_id,
                            'recepcion_id' => $rc->id,
                            'monto_bruto' => (float) $data['monto_bruto'],
                            'iva' => (float) ($data['iva'] ?? 0),
                            'concepto_retencion' => $data['concepto_retencion'],
                            'ciudad' => $data['ciudad'] ?? null,
                            'gran_contribuyente' => (bool) ($data['gran_contribuyente'] ?? false),
                            'metodo' => $data['metodo'],
                            'observaciones' => $data['observaciones'] ?? null,
                            'user_id' => auth()->id(),
                        ]);
                        Notification::make()->title("Pago #{$pago->id} registrado")
                            ->body('Neto $' . number_format((float) $pago->monto_neto, 0, ',', '.')
                                . ' · retenido $' . number_format((float) $pago->monto_retenciones, 0, ',', '.')
                                . '. Falta que otra persona lo confirme.')
                            ->success()->send();
                    }),
            ])
            ->recordUrl(fn (array $record) => VerOrdenCompraDropi::getUrl(['orden' => RecepcionCompra::whereKey($record['recepcion_id'])->value('orden_id')]))
            ->emptyStateHeading('No se le debe nada a proveedores por compras de Dropi');
    }
}
