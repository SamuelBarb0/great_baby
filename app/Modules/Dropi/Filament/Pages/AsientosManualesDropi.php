<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Contabilidad\Actions\AsientosManuales;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use App\Modules\Siigo\Jobs\PushAsientoManualASiigo;
use App\Support\PasoNoPermitido;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * «Asientos manuales» de Dropi: ajustes contables a mano, cuadrados, que
 * escriben el libro en la línea Dropi. Quien crea no aprueba (salvo gerencia),
 * al aprobar viajan a SIIGO. Mismas reglas que /app (Actions\AsientosManuales).
 */
class AsientosManualesDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Asientos manuales';

    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Asientos manuales · Dropi';

    protected static ?string $slug = 'contabilidad-asientos-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/contabilidad/asientos-manuales';
    }

    protected static function puedeVer(User $u): bool
    {
        // AsientosManualesController.
        return $u->esContable();
    }

    /** Sólo auxiliares: las agrupadoras cuadran igual y vuelven rechazadas de SIIGO. */
    protected static function buscarCuentas(string $search): array
    {
        return PlanCuenta::query()->where('permite_movimiento', true)->where('activa', true)
            ->where(fn ($q) => $q->where('codigo', 'like', "{$search}%")->orWhere('nombre', 'like', "%{$search}%"))
            ->orderBy('codigo')->limit(30)->get()
            ->mapWithKeys(fn ($c) => [$c->codigo => "{$c->codigo} · {$c->nombre}"])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevo')->label('Nuevo asiento')->icon('heroicon-o-plus')
                ->modalWidth('5xl')
                ->schema([
                    DatePicker::make('fecha')->required()->default(now('America/Bogota')->toDateString()),
                    Textarea::make('glosa')->label('Explicación del asiento')->required()->minLength(10)->maxLength(500),
                    Repeater::make('lineas')->label('Líneas')->required()->minItems(2)->maxItems(50)
                        ->schema([
                            Select::make('cuenta_puc')->label('Cuenta')->required()->searchable()
                                ->getSearchResultsUsing(fn (string $search) => self::buscarCuentas($search))
                                ->getOptionLabelUsing(fn ($value) => ($c = PlanCuenta::where('codigo', $value)->first()) ? "{$c->codigo} · {$c->nombre}" : $value)
                                ->rules([Rule::exists('plan_cuentas', 'codigo')->where('permite_movimiento', true)->where('activa', true)])
                                ->validationMessages(['exists' => 'Esa cuenta es agrupadora o no existe: use la auxiliar que cuelga de ella.'])
                                ->columnSpan(2),
                            TextInput::make('descripcion')->label('Detalle')->required()->maxLength(300)->columnSpan(2),
                            TextInput::make('tercero_documento')->label('NIT tercero')->maxLength(30),
                            TextInput::make('debe')->label('Débito')->numeric()->minValue(0)->prefix('$'),
                            TextInput::make('haber')->label('Crédito')->numeric()->minValue(0)->prefix('$'),
                        ])
                        ->columns(7)
                        ->defaultItems(2)
                        ->addActionLabel('Agregar línea'),
                ])
                ->action(function (array $data) {
                    try {
                        $a = AsientosManuales::crear([
                            'fecha' => $data['fecha'],
                            'glosa' => $data['glosa'],
                            'lineas' => array_values($data['lineas']),
                        ], auth()->user(), LineaNegocio::Dropi);
                    } catch (PasoNoPermitido $e) {
                        Notification::make()->title('No se guardó')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title("Asiento #{$a->id} guardado")
                        ->body('Cuadrado por $' . number_format((float) $a->valor_total, 0, ',', '.') . '. Falta aprobarlo para enviarlo a SIIGO.')
                        ->success()->send();
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        $avisar = fn (PasoNoPermitido $e) => Notification::make()->title($e->getMessage())->danger()->send();

        return $table
            ->query(fn () => AsientoManual::query()->where('linea_negocio', LineaNegocio::Dropi->value)->with(['autor:id,name'])->withCount('lineas'))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('glosa')->searchable()->wrap(),
                TextColumn::make('lineas_count')->label('Líneas')->alignEnd(),
                TextColumn::make('valor_total')->label('Valor')->money('COP')->alignEnd(),
                TextColumn::make('autor.name')->label('Hizo'),
                TextColumn::make('estado')->badge()->formatStateUsing(fn ($state) => ucfirst((string) $state))
                    ->color(fn ($state) => match ($state) {
                        'cuadrado' => 'warning', 'aprobado' => 'info', 'sincronizado' => 'success', 'anulado' => 'danger', default => 'gray',
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(['cuadrado' => 'Cuadrado', 'aprobado' => 'Aprobado', 'sincronizado' => 'En SIIGO', 'anulado' => 'Anulado']),
            ])
            ->recordActions([
                Action::make('aprobar')->label('Aprobar')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (AsientoManual $record) => AsientosManuales::puedeAprobar(auth()->user(), $record))
                    ->requiresConfirmation()
                    ->modalDescription('Al aprobarlo viaja a SIIGO, que es la contabilidad oficial.')
                    ->action(function (AsientoManual $record) use ($avisar) {
                        try {
                            AsientosManuales::aprobar($record, auth()->user());
                        } catch (PasoNoPermitido $e) {
                            $avisar($e);

                            return;
                        }
                        Notification::make()->title("Asiento #{$record->id} aprobado · encolado a SIIGO")->success()->send();
                    }),
                Action::make('siigo')->label('Reenviar a SIIGO')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (AsientoManual $record) => $record->estado === 'aprobado' && ! $record->siigo_journal_id)
                    ->requiresConfirmation()
                    ->action(function (AsientoManual $record) {
                        PushAsientoManualASiigo::dispatchManual($record->id);
                        Notification::make()->title("Asiento #{$record->id} encolado a SIIGO")->success()->send();
                    }),
                Action::make('anular')->label('Anular')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (AsientoManual $record) => ! $record->anulado_at && $record->estado !== 'sincronizado' && ! $record->siigo_journal_id)
                    ->schema([Textarea::make('motivo')->label('¿Por qué se anula?')->required()->minLength(10)->maxLength(500)])
                    ->action(function (AsientoManual $record, array $data) use ($avisar) {
                        try {
                            AsientosManuales::anular($record, auth()->user(), $data['motivo']);
                        } catch (PasoNoPermitido $e) {
                            $avisar($e);

                            return;
                        }
                        Notification::make()->title("Asiento #{$record->id} anulado · sus líneas salieron del balance")->success()->send();
                    }),
            ])
            ->emptyStateHeading('No hay asientos manuales de Dropi');
    }
}
