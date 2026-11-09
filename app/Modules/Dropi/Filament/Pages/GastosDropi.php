<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Contabilidad\Actions\Gastos;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Contabilidad\Support\CatalogoGastos;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use App\Modules\Gerencia\Models\GastoOperativo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * «Gastos» de la línea Dropi: pedirlos, aprobarlos (se causan en el libro),
 * pagarlos (de caja o banco) y reclasificarlos. Mismas acciones que /app
 * (Contabilidad\Actions\Gastos); todo lo que nace acá es de Dropi.
 */
class GastosDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Gastos';

    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Gastos · Dropi';

    protected static ?string $slug = 'contabilidad-gastos-dropi';

    public const METODOS = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'nequi' => 'Nequi', 'daviplata' => 'Daviplata'];

    protected static function rutaEnErp(): string
    {
        return '/app/gastos';
    }

    protected static function puedeVer(User $u): bool
    {
        // En /app quien no es contable sólo ve los suyos; acá es la vista contable.
        return $u->esContable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevo')->label('Nuevo gasto')->icon('heroicon-o-plus')
                ->modalWidth('2xl')
                ->schema([
                    DatePicker::make('fecha')->required()->default(now('America/Bogota')->toDateString()),
                    Select::make('categoria')->label('Tipo de gasto')->required()
                        ->options(fn () => collect(CatalogoGastos::todos())->map(fn ($t) => $t['etiqueta'])->all()),
                    TextInput::make('descripcion')->label('Descripción')->required()->maxLength(200),
                    TextInput::make('monto')->numeric()->required()->minValue(0.01)->prefix('$'),
                    TextInput::make('proveedor')->label('A quién se le paga')->maxLength(200),
                    TextInput::make('factura_ref')->label('Factura del proveedor')->maxLength(100)
                        ->helperText('Sin factura queda interno: no se declara ante la DIAN.'),
                    Select::make('metodo_pago')->label('Cómo se paga')->required()->options(self::METODOS)->default('transferencia'),
                    Select::make('tipo')->required()->options(['gasto' => 'Gasto', 'reembolso' => 'Reembolso'])->default('gasto'),
                    Textarea::make('notas')->maxLength(500),
                ])
                ->action(function (array $data) {
                    $g = Gastos::crear($data, auth()->user(), LineaNegocio::Dropi);
                    Notification::make()->title("Solicitud {$g->numero} creada")
                        ->body('Queda pendiente de aprobación.')->success()->send();
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::ejesDropi()->aplicar(GastoOperativo::query()->with('solicita:id,name')))
            ->columns([
                TextColumn::make('numero')->label('Gasto')->searchable()->sortable(),
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('categoria')->label('Tipo')->searchable()
                    ->formatStateUsing(fn ($state) => CatalogoGastos::todos()[$state]['etiqueta'] ?? $state),
                TextColumn::make('descripcion')->label('Descripción')->searchable()->wrap(),
                TextColumn::make('proveedor')->placeholder('—')->toggleable(),
                TextColumn::make('cuenta_puc')->label('Cuenta')->toggleable(),
                TextColumn::make('solicita.name')->label('Pidió')->toggleable(),
                TextColumn::make('monto')->money('COP')->alignEnd()->sortable()
                    ->summarize(Sum::make()->money('COP')->label('Total')),
                TextColumn::make('estado')->badge()->formatStateUsing(fn ($state) => ucfirst((string) $state))
                    ->color(fn ($state) => match ($state) {
                        'pendiente' => 'warning', 'rechazado' => 'danger', 'pagado' => 'success', default => 'gray',
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(['pendiente' => 'Pendiente', 'aprobado' => 'Aprobado', 'pagado' => 'Pagado', 'rechazado' => 'Rechazado']),
            ])
            ->recordActions([
                Action::make('aprobar')->label('Aprobar')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (GastoOperativo $record) => $record->estado === 'pendiente' && Gastos::puedeAprobar(auth()->user()))
                    ->requiresConfirmation()
                    ->modalDescription('Al aprobar se causa en el libro: la obligación ya existe aunque no se haya pagado.')
                    ->action(function (GastoOperativo $record) {
                        Gastos::aprobar($record, auth()->user());
                        Notification::make()->title("Gasto {$record->numero} aprobado y causado en el libro")->success()->send();
                    }),
                Action::make('rechazar')->label('Rechazar')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (GastoOperativo $record) => $record->estado === 'pendiente' && Gastos::puedeAprobar(auth()->user()))
                    ->schema([Textarea::make('motivo')->label('¿Por qué se rechaza?')->required()->minLength(10)->maxLength(500)])
                    ->action(function (GastoOperativo $record, array $data) {
                        Gastos::rechazar($record, auth()->user(), $data['motivo']);
                        Notification::make()->title('Gasto rechazado')->success()->send();
                    }),
                Action::make('pagar')->label('Pagar')->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (GastoOperativo $record) => $record->estado === 'aprobado' && (bool) auth()->user()?->esContable())
                    ->modalDescription(fn (GastoOperativo $record) => $record->metodo_pago === 'efectivo'
                        ? 'Es en efectivo: decí de qué caja sale, así cuadra el arqueo del día.'
                        : 'Sale del banco y cancela la obligación en el libro.')
                    ->schema(fn (GastoOperativo $record) => $record->metodo_pago === 'efectivo' ? [
                        Select::make('caja_id')->label('Caja')->required()
                            ->options(fn () => Caja::activas()->whereIn('tipo', ['menor', 'general'])->orderBy('codigo')->get()
                                ->mapWithKeys(fn ($c) => [$c->id => "{$c->codigo} · {$c->nombre}"])->all()),
                    ] : [])
                    ->action(function (GastoOperativo $record, array $data) {
                        Gastos::pagar($record, auth()->user(), isset($data['caja_id']) ? (int) $data['caja_id'] : null);
                        Notification::make()->title("Gasto {$record->numero} pagado y registrado en el libro")->success()->send();
                    }),
                Action::make('reclasificar')->label('Reclasificar')->icon('heroicon-o-adjustments-horizontal')->color('gray')
                    ->visible(fn (GastoOperativo $record) => (bool) auth()->user()?->esContable() && $record->estado !== 'rechazado')
                    ->fillForm(fn (GastoOperativo $record) => [
                        'cuenta_puc' => $record->cuenta_puc,
                        'destino' => ($record->destino_fiscal ?? DestinoFiscal::Siigo)->value,
                        'motivo' => $record->motivo_destino,
                    ])
                    ->schema([
                        Select::make('cuenta_puc')->label('Cuenta')->searchable()
                            ->getSearchResultsUsing(fn (string $search) => PlanCuenta::query()
                                ->where('permite_movimiento', true)->where('activa', true)
                                ->where(fn ($q) => $q->where('codigo', 'like', "{$search}%")->orWhere('nombre', 'like', "%{$search}%"))
                                ->orderBy('codigo')->limit(30)->get()
                                ->mapWithKeys(fn ($c) => [$c->codigo => "{$c->codigo} · {$c->nombre}"])->all())
                            ->getOptionLabelUsing(fn ($value) => ($c = PlanCuenta::where('codigo', $value)->first()) ? "{$c->codigo} · {$c->nombre}" : $value),
                        Select::make('destino')->label('¿Se declara?')->required()->live()
                            ->options([DestinoFiscal::Siigo->value => 'Sí, va a SIIGO', DestinoFiscal::Interno->value => 'No, es interno']),
                        Textarea::make('motivo')->label('Por qué no se declara')->maxLength(300)
                            ->required(fn (Get $get) => $get('destino') === DestinoFiscal::Interno->value)
                            ->visible(fn (Get $get) => $get('destino') === DestinoFiscal::Interno->value),
                    ])
                    ->action(function (GastoOperativo $record, array $data) {
                        try {
                            // La línea no cambia: desde este panel el gasto sigue siendo de Dropi.
                            Gastos::reclasificar($record, $data['cuenta_puc'] ?? null, DestinoFiscal::from($data['destino']), $data['motivo'] ?? null);
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        Notification::make()->title("Gasto {$record->numero} reclasificado y asiento rehecho")->success()->send();
                    }),
            ])
            ->emptyStateHeading('No hay gastos de Dropi');
    }
}
