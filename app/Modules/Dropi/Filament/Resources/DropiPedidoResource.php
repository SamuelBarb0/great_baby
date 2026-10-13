<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages;
use App\Modules\Dropi\Models\DropiPedido;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropiPedidoResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = DropiPedido::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Pedidos Dropi';

    protected static ?string $modelLabel = 'Pedido';

    protected static ?string $pluralModelLabel = 'Pedidos Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Dropi';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'guia';

    // Re-audit DR-γ (SEG-C2) · global-search sólo por guía + orden Dropi;
    //   sacamos cliente_nombre/ciudad para que la búsqueda global no exponga
    //   PII a roles no-Aracely (Alistador ve la vista dedicada).
    public static function getGloballySearchableAttributes(): array
    {
        return ['guia', 'dropi_orden_id'];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        $u = auth()->user();
        // Sólo Aracely/Gerencia ve monto + cliente en global-search.
        if (! $u || ! $u->esAracely()) {
            return ['Estado' => $record->estado?->label()];
        }
        return [
            'Cliente' => $record->cliente_nombre . ' · ' . $record->cliente_ciudad,
            'Estado' => $record->estado?->label(),
            'Monto' => '$' . number_format((float) $record->monto_esperado_proveedor, 0, ',', '.'),
        ];
    }

    // Re-audit DR-γ (SEG-C2) · antes: `(bool) auth()->user()` → cualquier user
    //   del panel /admin leía PII (cliente_doc, teléfono, dirección) y montos
    //   financieros de TODOS los pedidos, además en global-search. Ahora
    //   restringido a Aracely/Gerencia y Alistador (que necesita ver su cola).
    //   SAC no debería mirar toda la cartera de pedidos: si necesita algo,
    //   entra por Contactos.
    public static function canViewAny(): bool
    {
        $u = auth()->user();
        return $u && ($u->esAracely() || $u->esAlistador());
    }

    public static function canCreate(): bool
    {
        // Deshabilitado a propósito: los pedidos entran SOLO por "Importar
        // pedidos (Excel)" (y por la sincronización con Dropi cuando se habilite
        // el API/MCP). Crearlos a mano duplicaría lo que ya se hace en Dropi.
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    /**
     * Pedido que trae el sync web: lo que manda Dropi (guía, cliente,
     * transportadora…) se cambia en Dropi y llega solo.
     */
    public static function vieneDeDropi(?DropiPedido $record): bool
    {
        return $record !== null && filled($record->dropi_orden_id)
            && \Illuminate\Support\Facades\DB::table('dropi_web_huellas')->where('dropi_orden_id', $record->dropi_orden_id)->exists();
    }

    /**
     * En esos pedidos, los campos de Dropi quedan de solo lectura y NO se guardan:
     * si alguien deja el formulario abierto y el sync pone la guía real mientras
     * tanto, guardar no la pisa con la vieja. Los pedidos manuales o de Excel
     * siguen editables.
     */
    protected static function campoDeDropi(TextInput $campo): TextInput
    {
        return $campo
            ->disabled(fn ($record) => self::vieneDeDropi($record))
            ->dehydrated(fn ($record) => ! self::vieneDeDropi($record))
            ->hintIcon(fn ($record) => self::vieneDeDropi($record) ? 'heroicon-o-lock-closed' : null)
            ->hintIconTooltip('Viene de Dropi: se cambia en Dropi y llega solo.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificación del pedido')->schema([
                Select::make('corte_id')
                    ->relationship('corte', 'fecha')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record?->etiqueta() ?? '—')
                    ->required()
                    ->searchable(),
                self::campoDeDropi(TextInput::make('guia')->label('Guía')->required()->unique(ignoreRecord: true)
                    ->helperText('§11 diseño Dropi — identificador principal, único en el sistema.')),
                self::campoDeDropi(TextInput::make('dropi_orden_id')->label('ID orden Dropi')),
                self::campoDeDropi(TextInput::make('transportadora')),
                self::campoDeDropi(TextInput::make('tienda')),
            ])->columns(2),

            Section::make('Vendedor dropshipping')->schema([
                self::campoDeDropi(TextInput::make('vendedor_nombre')->label('Nombre del vendedor')),
                TextInput::make('vendedor_identificacion')->label('Identificación'),
                Toggle::make('requiere_factura_b2b')
                    ->label('Facturar B2B a este vendedor')
                    ->helperText('§21 — vendedores del sondeo se facturan B2B directo; el resto va a consumidor final.'),
            ])->columns(3),

            Section::make('Cliente final (envío)')->schema([
                self::campoDeDropi(TextInput::make('cliente_nombre')->required()),
                TextInput::make('cliente_doc')->label('Documento'),
                self::campoDeDropi(TextInput::make('cliente_telefono')->label('Teléfono')),
                self::campoDeDropi(TextInput::make('cliente_direccion')->label('Dirección')->columnSpanFull()),
                self::campoDeDropi(TextInput::make('cliente_ciudad')->label('Ciudad')),
                self::campoDeDropi(TextInput::make('cliente_depto')->label('Departamento')),
            ])->columns(3),

            Section::make('Ciclo de vida (lectura)')
                ->description('El estado y las fechas se actualizan solo por Acciones (sync, alistamiento, conciliación). Ver bitácora abajo.')
                ->schema([
                    Select::make('estado')
                        ->options(collect(EstadoPedidoDropi::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()]))
                        ->disabled()->dehydrated(false),
                    DateTimePicker::make('despachado_at')->label('Despachado')->native(false)->disabled()->dehydrated(false),
                    DateTimePicker::make('entregado_at')->label('Entregado')->native(false)->disabled()->dehydrated(false),
                    DateTimePicker::make('devuelto_at')->label('Devuelto')->native(false)->disabled()->dehydrated(false),
                    DateTimePicker::make('pagado_at')->label('Pagado')->native(false)->disabled()->dehydrated(false),
                ])->columns(2),

            Section::make('Valores (lectura)')
                ->description('Los montos vienen de la sincronización con Dropi y del catálogo de proveedor GB. No editable a mano.')
                ->schema([
                    TextInput::make('monto_esperado_proveedor')
                        ->label('Monto esperado (precio proveedor GB)')
                        ->numeric()->prefix('$')->disabled()->dehydrated(false),
                    TextInput::make('monto_cliente_final')->label('Total al cliente')->numeric()->prefix('$')->disabled()->dehydrated(false),
                    TextInput::make('ganancia_vendedor')->label('Ganancia vendedor')->numeric()->prefix('$')->disabled()->dehydrated(false),
                    TextInput::make('flete_transportadora')->label('Flete')->numeric()->prefix('$')->disabled()->dehydrated(false),
                ])->columns(2),

            Section::make('Facturación (lectura)')
                ->schema([
                    TextInput::make('ari_factura_id')->label('ID factura ARI')->disabled()->dehydrated(false),
                    DateTimePicker::make('ari_enviado_at')->label('Enviado a ARI')->native(false)->disabled()->dehydrated(false),
                    TextInput::make('nota_credito_id')->label('Nota crédito')->disabled()->dehydrated(false),
                ])->columns(2)->collapsible()->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('guia')->label('Guía')->searchable()->weight('bold')->copyable(),
                TextColumn::make('corte.fecha')->label('Corte')
                    ->formatStateUsing(fn ($state, $record) => $record->corte
                        ? 'C' . $record->corte->numero . ' · ' . $record->corte->fecha->format('m-d')
                        : '—')
                    ->badge()->color('gray')->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('cliente_nombre')->label('Cliente')->searchable()->limit(25),
                TextColumn::make('cliente_ciudad')->label('Ciudad')->searchable()->toggleable(),
                TextColumn::make('transportadora')->badge()->color('gray')->toggleable(),
                BadgeColumn::make('estado')
                    ->formatStateUsing(fn ($state) => $state instanceof EstadoPedidoDropi ? $state->label() : $state)
                    ->color(fn ($state) => $state instanceof EstadoPedidoDropi ? $state->color() : 'gray'),
                TextColumn::make('monto_esperado_proveedor')
                    ->label('Esperado')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('despachado_at')->label('Despachado')->dateTime('Y-m-d H:i')->toggleable(isToggledHiddenByDefault: true)->sortable(),
                TextColumn::make('pagado_at')->label('Pagado')->dateTime('Y-m-d H:i')->toggleable(isToggledHiddenByDefault: true)->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            // Tabla en vivo: el sync con Dropi trae pedidos cada 15 s; la tabla se
            //   refresca al mismo ritmo sin recargar la página (conserva filtros,
            //   búsqueda y paginación). Livewire pausa el sondeo si la pestaña no se ve.
            ->poll('15s')
            ->filters([
                SelectFilter::make('estado')
                    ->options(collect(EstadoPedidoDropi::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])),
                SelectFilter::make('corte_id')
                    ->label('Corte')
                    ->relationship('corte', 'fecha')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record?->etiqueta() ?? '—'),
                SelectFilter::make('transportadora')
                    ->options(fn () => \App\Modules\Dropi\Models\DropiPedido::query()
                        ->whereNotNull('transportadora')
                        ->distinct()->pluck('transportadora', 'transportadora')->toArray()),
                SelectFilter::make('cliente_ciudad')->label('Ciudad')
                    ->options(fn () => \App\Modules\Dropi\Models\DropiPedido::query()
                        ->whereNotNull('cliente_ciudad')
                        ->distinct()->pluck('cliente_ciudad', 'cliente_ciudad')->toArray()),
                Filter::make('rango_fechas')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('desde')->native(false),
                        \Filament\Forms\Components\DatePicker::make('hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    })
                    ->indicateUsing(function (array $data): array {
                        $chips = [];
                        if ($data['desde'] ?? null) $chips[] = 'Desde ' . $data['desde'];
                        if ($data['hasta'] ?? null) $chips[] = 'Hasta ' . $data['hasta'];
                        return $chips;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Las acciones de la fila de pedidos de la web de Dropi, hechas desde el ERP.
                \Filament\Actions\ActionGroup::make([
                    self::accionInfoDropi(),
                    self::accionActualizarEstatusEnDropi(),
                    self::accionEtiquetasDropi(),
                ])
                    ->label('Dropi')
                    ->icon('heroicon-o-truck')
                    ->button()
                    ->color('warning')
                    ->visible(fn (DropiPedido $record) => filled($record->dropi_orden_id) && (auth()->user()?->esAracely() ?? false)),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make()
                        ->exports([
                            \pxlrbt\FilamentExcel\Exports\ExcelExport::make('table')
                                ->fromTable()
                                ->withFilename('pedidos-dropi-' . now()->format('Y-m-d')),
                        ]),
                ]),
            ]);
    }

    /** La lupa de Dropi: el pedido tal como está ahora en Dropi. Solo lectura. */
    protected static function accionInfoDropi(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('infoDropi')
            ->label('Información en Dropi')
            ->icon('heroicon-o-magnifying-glass')
            ->modalHeading(fn (DropiPedido $record) => 'Orden ' . $record->dropi_orden_id . ' en Dropi')
            ->modalWidth('4xl')
            ->modalContent(function (DropiPedido $record) {
                try {
                    $detalle = app(\App\Modules\Dropi\Clients\DropiWebClient::class)->pedidoDetalle((int) $record->dropi_orden_id);
                    $error = null;
                } catch (\Throwable $e) {
                    $detalle = [];
                    $error = 'No se pudo consultar Dropi: ' . $e->getMessage();
                }

                return view('dropi.pedidos.info-dropi', compact('detalle', 'error'));
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }

    /**
     * Etiquetas de Dropi: marcar las que el pedido debe tener (se aplica la
     * diferencia en Dropi) y, si hace falta, crear una nueva en la cuenta.
     */
    protected static function accionEtiquetasDropi(): \Filament\Actions\Action
    {
        $client = fn () => app(\App\Modules\Dropi\Clients\DropiWebClient::class);

        return \Filament\Actions\Action::make('etiquetasDropi')
            ->label('Etiquetas en Dropi')
            ->icon('heroicon-o-tag')
            ->modalHeading(fn (DropiPedido $record) => 'Etiquetas de la orden ' . $record->dropi_orden_id . ' en Dropi')
            ->modalDescription('Los cambios se guardan en Dropi de verdad.')
            // Lo que vio el sync (≤15 s de antigüedad): abre al instante. Al guardar,
            //   GestionarEtiquetasDropi compara contra Dropi en vivo.
            ->fillForm(fn (DropiPedido $record) => [
                'etiquetas' => json_decode((string) \Illuminate\Support\Facades\DB::table('dropi_web_huellas')
                    ->where('dropi_orden_id', $record->dropi_orden_id)->value('etiquetas'), true) ?: [],
                'color' => '#f59e0b',
            ])
            ->schema([
                \Filament\Forms\Components\CheckboxList::make('etiquetas')
                    ->label('Etiquetas del pedido')
                    ->options(function () use ($client) {
                        try {
                            return collect($client()->etiquetasCuenta())->pluck('nombre', 'id')->all();
                        } catch (\Throwable) {
                            return [];
                        }
                    })
                    ->helperText('Marca las que debe tener. Si la lista está vacía, la cuenta aún no tiene etiquetas: crea una abajo.')
                    ->columns(2),
                TextInput::make('nueva')->label('Crear y poner una etiqueta nueva')->maxLength(40)->placeholder('Ej.: Revisar dirección'),
                \Filament\Forms\Components\ColorPicker::make('color')->label('Color de la etiqueta nueva')->default('#f59e0b'),
            ])
            ->modalSubmitActionLabel('Guardar en Dropi')
            ->action(function (DropiPedido $record, array $data) {
                try {
                    $r = \App\Modules\Dropi\Actions\GestionarEtiquetasDropi::run(
                        $record, $data['etiquetas'] ?? [], $data['nueva'] ?? null, $data['color'] ?? null, auth()->id(),
                    );
                } catch (\Throwable $e) {
                    \Filament\Notifications\Notification::make()->title('Dropi no guardó las etiquetas')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                \Filament\Notifications\Notification::make()
                    ->title('Etiquetas guardadas en Dropi')
                    ->body(($r['creada'] ? 'Etiqueta nueva creada. ' : '') . "Puestas: {$r['puestas']} · quitadas: {$r['quitadas']}")
                    ->success()->send();
            });
    }

    /**
     * «Actualizar Estatus» de Dropi desde el ERP: el cambio se sube a Dropi y el
     * pedido se vuelve a traer al momento (ver ActualizarEstatusEnDropi).
     */
    protected static function accionActualizarEstatusEnDropi(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('actualizarEstatusDropi')
            ->label('Actualizar estatus')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (DropiPedido $record) => filled($record->dropi_orden_id) && (auth()->user()?->esAracely() ?? false))
            ->modalHeading(fn (DropiPedido $record) => 'Actualizar estatus en Dropi · orden ' . $record->dropi_orden_id)
            ->modalDescription(function (DropiPedido $record) {
                $actual = \Illuminate\Support\Facades\DB::table('dropi_web_huellas')
                    ->where('dropi_orden_id', $record->dropi_orden_id)->value('estatus_dropi');

                return 'Estatus actual en Dropi: ' . ($actual ?: 'sin dato todavía')
                    . '. El cambio se hace en Dropi de verdad, igual que desde su web; si no aplica para este pedido, Dropi lo rechaza y aquí verás su motivo.';
            })
            ->schema([
                Select::make('estatus')
                    ->label('Nuevo estatus')
                    ->options(\App\Modules\Dropi\Actions\ActualizarEstatusEnDropi::OPCIONES)
                    ->required()
                    ->live()
                    ->native(false),
                Textarea::make('motivo')
                    ->label('Motivo del rechazo')
                    ->rows(2)
                    ->maxLength(500)
                    ->visible(fn ($get) => $get('estatus') === 'RECHAZADO')
                    ->required(fn ($get) => $get('estatus') === 'RECHAZADO'),
            ])
            ->modalSubmitActionLabel('Actualizar en Dropi')
            ->action(function (DropiPedido $record, array $data) {
                try {
                    $r = \App\Modules\Dropi\Actions\ActualizarEstatusEnDropi::run($record, $data['estatus'], $data['motivo'] ?? null, auth()->id());
                } catch (\Throwable $e) {
                    \Filament\Notifications\Notification::make()->title('Dropi no hizo el cambio')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                \Filament\Notifications\Notification::make()
                    ->title('Estatus actualizado en Dropi')
                    ->body($r['shipping_guide'] ? "Guía: {$r['shipping_guide']} · {$r['shipping_company']}" : null)
                    ->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropiPedidos::route('/'),
            'create' => Pages\CreateDropiPedido::route('/create'),
            'view' => Pages\ViewDropiPedido::route('/{record}'),
            'edit' => Pages\EditDropiPedido::route('/{record}/edit'),
        ];
    }
}
