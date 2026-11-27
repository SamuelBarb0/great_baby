<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Actions\CerrarCorte;
use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages;
use App\Http\Controllers\Dropi\PedidosCortePdfController;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DropiCorteResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = DropiCorte::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Cortes Dropi';

    protected static ?string $modelLabel = 'Corte';

    protected static ?string $pluralModelLabel = 'Cortes Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 6;

    // Fix D1 · matriz Permisos (Contador ve cortes para reportes contables).
    public static function canViewAny(): bool
    {
        return \App\Auth\Permisos::puede(auth()->user(), 'dropi_cortes');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Auth\Permisos::puede(auth()->user(), 'dropi_cortes');
    }

    /**
     * La cabecera de la pantalla del corte (26-nov-2026): qué corte es y cómo
     * van sus pedidos. Los pedidos, uno por uno, van debajo (PedidosRelationManager).
     * El corte ya no se crea ni se edita a mano: nace con «Generar corte».
     */
    public static function infolist(Schema $schema): Schema
    {
        $cuenta = fn (DropiCorte $c, array $estados) => $c->pedidos()->whereIn('estado', $estados)->count();

        return $schema->components([
            Section::make('Cómo van sus pedidos')
                ->description(fn (DropiCorte $record) => 'Corte ' . mb_strtolower((string) EstadoCorte::de($record->estado)?->label()))
                ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
                ->schema([
                    TextEntry::make('total')->label('Pedidos')
                        ->state(fn (DropiCorte $record) => $record->pedidos()->count())->size('lg')->weight('bold'),
                    TextEntry::make('por_empacar')->label('Por empacar')->color('warning')->size('lg')->weight('bold')
                        ->state(fn (DropiCorte $record) => $cuenta($record, [EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando])),
                    TextEntry::make('sin_inventario')->label('Pendientes por inventario')->color('danger')->size('lg')->weight('bold')
                        ->state(fn (DropiCorte $record) => $cuenta($record, [EstadoPedidoDropi::PendienteInventario])),
                    TextEntry::make('empacados')->label('Empacados')->color('info')->size('lg')->weight('bold')
                        ->state(fn (DropiCorte $record) => $cuenta($record, [EstadoPedidoDropi::Empacado])),
                    TextEntry::make('despachados')->label('Despachados')->color('success')->size('lg')->weight('bold')
                        ->state(fn (DropiCorte $record) => $cuenta($record, [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado, EstadoPedidoDropi::DevolucionEnCamino, EstadoPedidoDropi::Devuelto])),
                    TextEntry::make('cancelados')->label('Cancelados')->color('gray')->size('lg')->weight('bold')
                        ->state(fn (DropiCorte $record) => $cuenta($record, [EstadoPedidoDropi::CanceladoDropi, EstadoPedidoDropi::CanceladoGb])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('numero')->label('#')->badge(),
                BadgeColumn::make('estado')
                    ->formatStateUsing(fn ($state) => $state instanceof EstadoCorte ? $state->label() : $state)
                    ->color(fn ($state) => $state instanceof EstadoCorte ? $state->color() : 'gray'),
                TextColumn::make('pedidos_totales')->label('Total')->alignEnd(),
                TextColumn::make('pedidos_pendientes_inv')->label('Pend. inv.')->alignEnd()->color('danger'),
                TextColumn::make('pedidos_despachados')->label('Despachados')->alignEnd()->color('success'),
                TextColumn::make('cortado_at')->label('Cerrado')->dateTime('Y-m-d H:i')->toggleable(),
                TextColumn::make('cerrado_at')->label('Finalizado')->dateTime('Y-m-d H:i')->toggleable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->recordActions(self::accionesDelCorte());
    }

    /**
     * Lo que se hace con un corte: en su fila y arriba en su pantalla. Sin
     * remisiones sueltas, editar ni crear (26-nov-2026: se hace desde «Generar
     * corte» y desde Dropi).
     *
     * @return list<Action>
     */
    public static function accionesDelCorte(): array
    {
        return [
            // Primero se cierra la entrada y se empaca; al final se congela
            //   (24-nov-2026: «desde cerrar corte poder hacer todo el proceso
            //   de empacado»). Ver EstadoCorte.
            Action::make('cerrar_entrada')
                ->label('Cerrar corte')
                ->icon('heroicon-o-scissors')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(fn (DropiCorte $record) => 'Cerrar el corte '.$record->etiqueta())
                ->modalDescription('Desde ahora este corte no recibe pedidos: los nuevos de Dropi van al siguiente corte. Sus pedidos se siguen recolectando, empacando y despachando. Cuando todo esté empacado, «Finalizar corte» lo congela con el manifiesto.')
                ->modalSubmitActionLabel('Cerrar corte')
                ->visible(fn (DropiCorte $record) => EstadoCorte::de($record->estado) === EstadoCorte::Abierto)
                ->action(function (DropiCorte $record) {
                    try {
                        \App\Modules\Dropi\Actions\CortarEntradaCorte::run($record->id, auth()->id());
                        Notification::make()->title('Corte cerrado: ya no recibe pedidos')
                            ->body('Sigan recolectando y empacando. Los pedidos nuevos entran al siguiente corte.')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('No se pudo cerrar')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('cerrar_corte')
                ->label('Finalizar corte + Manifiesto')
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Congela el corte y genera el manifiesto PDF con QR y hash SHA-256. Pide que no queden pedidos sin empacar. Al finalizar no se puede cambiar.')
                ->visible(fn (DropiCorte $record) => $record->estado !== EstadoCorte::Cerrado)
                ->action(function (DropiCorte $record) {
                    try {
                        $r = CerrarCorte::run($record->id, auth()->id());
                        Notification::make()
                            ->title('✅ Corte finalizado y manifiesto generado')
                            ->body("Hash: " . substr($r['hash'], 0, 12) . "… · Descarga el PDF desde el botón 'Manifiesto PDF' del corte.")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('No se pudo finalizar')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('descargar_manifiesto')
                ->label('Manifiesto PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (DropiCorte $record) => (bool) $record->manifiesto_pdf_path)
                ->url(fn (DropiCorte $record) => route('dropi.manifiesto.descargar', $record))
                ->openUrlInNewTab(),
            self::accionPedidosPdf(),
            // Empacados que quedaron sin guía (Dropi la rechazó o no
            //   respondió al empacar): se piden todas de una vez.
            Action::make('guias_pendientes')
                ->label(fn (DropiCorte $record) => 'Guías pendientes (' . \App\Modules\Dropi\Actions\GenerarGuiaDropi::sinGuia($record)->count() . ')')
                ->icon('heroicon-o-truck')
                ->color('warning')
                ->visible(fn (DropiCorte $record) => \App\Modules\Dropi\Actions\GenerarGuiaDropi::activa()
                    && \App\Modules\Dropi\Actions\GenerarGuiaDropi::sinGuia($record)->exists())
                ->requiresConfirmation()
                ->modalHeading('Generar las guías pendientes en Dropi')
                ->modalDescription('Pide a Dropi la guía de cada pedido empacado de este corte que todavía no la tiene. Las guías son reales: la transportadora las registra. Los stickers se imprimen desde la Estación de Empaque.')
                ->action(function (DropiCorte $record) {
                    $r = \App\Modules\Dropi\Actions\GenerarGuiaDropi::make()->pendientesDelCorte($record, auth()->id());
                    $n = Notification::make()
                        ->title("Guías generadas: {$r['generadas']}")
                        ->body($r['errores'] ? 'Sin guía: ' . implode(' · ', array_slice($r['errores'], 0, 5)) : null);
                    ($r['errores'] ? $n->warning()->persistent() : $n->success())->send();
                }),
        ];
    }

    /**
     * «Pedidos PDF» del corte, abierto o cerrado: todos los pedidos o uno solo
     * (bodega, 23-nov-2026). Va en la fila y arriba en la pantalla del corte.
     */
    public static function accionPedidosPdf(): Action
    {
        return Action::make('pedidos_pdf')
            ->label('Pedidos PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn () => PedidosCortePdfController::puedeDescargar())
            ->modalHeading(fn (DropiCorte $record) => 'Pedidos en PDF · ' . $record->etiqueta())
            ->modalDescription('El PDF se abre en una pestaña nueva, desde donde se descarga o se imprime.')
            ->fillForm(['alcance' => 'todos'])
            ->schema([
                Radio::make('alcance')
                    ->label('¿Qué quieres descargar?')
                    ->options(fn (DropiCorte $record) => [
                        'todos' => 'Todos los pedidos del corte (' . $record->pedidos()->count() . ')',
                        'uno' => 'Solo un pedido',
                    ])
                    ->required()
                    ->live(),
                Select::make('pedido_id')
                    ->label('Pedido')
                    ->placeholder('Busca por guía o cliente')
                    ->searchable()
                    ->native(false)
                    ->getSearchResultsUsing(fn (string $search, DropiCorte $record) => $record->pedidos()
                        ->where(fn ($q) => $q->where('guia', 'like', "%{$search}%")
                            ->orWhere('cliente_nombre', 'like', "%{$search}%")
                            ->orWhere('dropi_orden_id', 'like', "%{$search}%"))
                        ->orderBy('guia')->limit(50)->get()
                        ->mapWithKeys(fn (DropiPedido $p) => [$p->id => $p->guia . ' · ' . $p->cliente_nombre])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($p = DropiPedido::find($value)) ? $p->guia . ' · ' . $p->cliente_nombre : null)
                    ->visible(fn ($get) => $get('alcance') === 'uno')
                    ->required(fn ($get) => $get('alcance') === 'uno'),
            ])
            ->modalSubmitActionLabel('Abrir PDF')
            ->action(function (DropiCorte $record, array $data, $livewire) {
                $url = route('dropi.corte.pedidos.pdf', array_filter([
                    'corte' => $record->id,
                    'pedido' => ($data['alcance'] ?? 'todos') === 'uno' ? ($data['pedido_id'] ?? null) : null,
                ]));
                $livewire->js('window.open(' . json_encode($url) . ', "_blank")');
            });
    }

    public static function getRelations(): array
    {
        return [DropiCorteResource\RelationManagers\PedidosRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropiCortes::route('/'),
            'view' => Pages\ViewDropiCorte::route('/{record}'),
        ];
    }
}
