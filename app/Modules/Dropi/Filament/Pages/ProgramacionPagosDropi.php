<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Compras\Actions\AnularProgramacionPago;
use App\Modules\Compras\Actions\AutorizarProgramacionPago;
use App\Modules\Compras\Actions\CrearProgramacionPago;
use App\Modules\Compras\Models\ProgramacionPago;
use App\Modules\Compras\Services\CuentasPorPagar;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use App\Support\PasoNoPermitido;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * «Programación de pagos» de Dropi: qué facturas de proveedores se pagan y
 * cuándo, con la firma de quien autoriza el desembolso (gerencia). Quien la
 * arma no la autoriza. Mismas acciones que /app.
 */
class ProgramacionPagosDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Programación de pagos';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Programación de pagos · Dropi';

    protected static ?string $slug = 'compras-programacion-pagos-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/compras/programacion-pagos';
    }

    protected static function puedeVer(User $u): bool
    {
        // ProgramacionPagosController.
        return Permisos::puede($u, 'programacion_pagos');
    }

    /** Una programación es de Dropi si paga facturas de compras Dropi. */
    public static function consulta(): Builder
    {
        return ProgramacionPago::query()
            ->whereHas('items.recepcion', fn ($q) => $q->where('linea_negocio', LineaNegocio::Dropi->value));
    }

    /** Las facturas de Dropi que se deben, por recepción. */
    protected static function porPagar(): Collection
    {
        return once(fn () => app(CuentasPorPagar::class)->pendientes(static::ejesDropi())->keyBy('recepcion_id'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nueva')->label('Nueva programación')->icon('heroicon-o-plus')
                ->visible(fn () => Permisos::puedeAccion(auth()->user(), 'programacion_pagos', 'crear'))
                ->modalWidth('3xl')
                ->schema([
                    DatePicker::make('fecha_programada')->label('Se paga el')->required()->default(now('America/Bogota')->toDateString()),
                    TextInput::make('monto_autorizado')->label('Tope autorizado (opcional)')->numeric()->minValue(0)->prefix('$'),
                    Repeater::make('items')->label('Facturas')->required()->minItems(1)
                        ->schema([
                            Select::make('recepcion_id')->label('Factura')->required()->distinct()
                                ->options(fn () => self::porPagar()->mapWithKeys(fn ($f) => [
                                    $f['recepcion_id'] => "{$f['proveedor']} · {$f['factura']} · debe $" . number_format($f['saldo'], 0, ',', '.'),
                                ])->all())
                                ->live()
                                ->afterStateUpdated(fn ($state, Set $set) => $set('monto', self::porPagar()[$state]['saldo'] ?? null))
                                ->columnSpan(2),
                            TextInput::make('monto')->label('Cuánto')->numeric()->required()->minValue(0.01)->prefix('$')
                                ->helperText(fn (Get $get) => ($f = self::porPagar()[$get('recepcion_id')] ?? null)
                                    ? 'Se deben $' . number_format($f['saldo'], 0, ',', '.') : null),
                        ])
                        ->columns(3)
                        ->addActionLabel('Agregar factura'),
                    Textarea::make('notas')->maxLength(1000),
                ])
                ->action(function (array $data) {
                    $items = collect($data['items'] ?? [])->values()->map(fn ($i) => [
                        'recepcion_id' => (int) $i['recepcion_id'], 'monto' => (float) $i['monto'],
                    ]);
                    // Desde acá sólo se programan facturas de Dropi.
                    if ($items->contains(fn ($i) => ! self::porPagar()->has($i['recepcion_id']))) {
                        Notification::make()->title('Hay una factura que no es de Dropi o ya está pagada')->danger()->send();

                        return;
                    }
                    try {
                        $p = CrearProgramacionPago::run([
                            'fecha_programada' => $data['fecha_programada'],
                            'monto_autorizado' => $data['monto_autorizado'] ?? null,
                            'notas' => $data['notas'] ?? null,
                            'items' => $items->all(),
                        ], auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title('No se pudo programar')->body(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    Notification::make()->title("Programación {$p->numero} creada")
                        ->body('Por $' . number_format($p->fresh()->total(), 0, ',', '.') . '. Falta autorizarla.')->success()->send();
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        $avisar = fn (PasoNoPermitido $e) => Notification::make()->title($e->getMessage())->danger()->send();

        return $table
            ->query(fn () => self::consulta()->with(['items.recepcion:id,numero,factura_proveedor', 'creadaPor:id,name', 'autorizadaPor:id,name']))
            ->columns([
                TextColumn::make('numero')->label('Programación')->searchable(),
                TextColumn::make('fecha_programada')->label('Se paga el')->date('Y-m-d')->sortable(),
                TextColumn::make('facturas')->label('Facturas')
                    ->state(fn (ProgramacionPago $record) => $record->items->map(fn ($i) => $i->recepcion?->factura_proveedor ?: $i->recepcion?->numero)->implode(', ')),
                TextColumn::make('total')->label('Total')->alignEnd()
                    ->state(fn (ProgramacionPago $record) => '$ ' . number_format($record->total(), 0, ',', '.')),
                TextColumn::make('monto_autorizado')->label('Tope')->money('COP')->alignEnd()->placeholder('—'),
                TextColumn::make('creadaPor.name')->label('Armó'),
                TextColumn::make('autorizadaPor.name')->label('Autorizó')->placeholder('—'),
                TextColumn::make('estado')->badge()->formatStateUsing(fn ($state) => ucfirst((string) $state))
                    ->color(fn ($state) => match ($state) { 'borrador' => 'warning', 'autorizada' => 'success', 'anulada' => 'danger', default => 'gray' }),
            ])
            ->defaultSort('fecha_programada', 'desc')
            ->recordActions([
                Action::make('autorizar')->label('Autorizar')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (ProgramacionPago $record) => AutorizarProgramacionPago::puedeAutorizar(auth()->user())
                        && ! $record->estaAutorizada() && $record->estado === 'borrador')
                    ->requiresConfirmation()
                    ->modalDescription('Queda tu firma: quién, cuándo y por cuánto. Quien la armó no la puede autorizar.')
                    ->action(function (ProgramacionPago $record) use ($avisar) {
                        try {
                            AutorizarProgramacionPago::run($record, auth()->user());
                        } catch (PasoNoPermitido $e) {
                            $avisar($e);

                            return;
                        }
                        Notification::make()->title("Programación {$record->numero} autorizada")->success()->send();
                    }),
                Action::make('anular')->label('Anular')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (ProgramacionPago $record) => ! in_array($record->estado, ['ejecutada', 'anulada'], true))
                    ->schema([Textarea::make('motivo')->label('¿Por qué se anula?')->required()->minLength(10)->maxLength(500)])
                    ->action(function (ProgramacionPago $record, array $data) use ($avisar) {
                        try {
                            AnularProgramacionPago::run($record, $data['motivo']);
                        } catch (PasoNoPermitido $e) {
                            $avisar($e);

                            return;
                        }
                        Notification::make()->title("Programación {$record->numero} anulada")->success()->send();
                    }),
            ])
            ->emptyStateHeading('No hay programaciones de pago de Dropi');
    }
}
