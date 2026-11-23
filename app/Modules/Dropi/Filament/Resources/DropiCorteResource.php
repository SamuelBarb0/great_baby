<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Actions\CerrarCorte;
use App\Modules\Dropi\Actions\GenerarRemisionesCorte;
use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages;
use App\Http\Controllers\Dropi\PedidosCortePdfController;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('fecha')->required()->native(false)->displayFormat('Y-m-d'),
            Select::make('numero')
                ->label('Número de corte')
                ->options([1 => 'Corte 1', 2 => 'Corte 2'])
                ->required(),
            Select::make('estado')
                ->options(collect(EstadoCorte::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required(),
            TextInput::make('pedidos_totales')->numeric()->default(0),
            TextInput::make('pedidos_pendientes_inv')->label('Pendientes por inventario')->numeric()->default(0),
            TextInput::make('pedidos_despachados')->numeric()->default(0),
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
                    ->color(fn ($state) => match ($state) {
                        EstadoCorte::Abierto => 'gray',
                        // Estados legacy removidos (P6): alistando/empacando/despachado.
                        EstadoCorte::Cerrado => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('pedidos_totales')->label('Total')->alignEnd(),
                TextColumn::make('pedidos_pendientes_inv')->label('Pend. inv.')->alignEnd()->color('danger'),
                TextColumn::make('pedidos_despachados')->label('Despachados')->alignEnd()->color('success'),
                TextColumn::make('cerrado_at')->label('Cerrado')->dateTime('Y-m-d H:i')->toggleable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->recordActions([
                Action::make('cerrar_corte')
                    ->label('Cerrar corte + Manifiesto')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Congela el corte, genera manifiesto PDF con QR y hash SHA-256, y produce remisiones 1:1 listas para ARI. Al cerrar no se puede editar.')
                    ->visible(fn (DropiCorte $record) => $record->estado !== EstadoCorte::Cerrado)
                    ->action(function (DropiCorte $record) {
                        try {
                            $r = CerrarCorte::run($record->id, auth()->id());
                            Notification::make()
                                ->title('✅ Corte cerrado y manifiesto generado')
                                ->body("Hash: " . substr($r['hash'], 0, 12) . "… · Remisiones: {$r['remisiones']} · Descarga el PDF desde el botón 'Manifiesto PDF' del corte.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo cerrar')->body($e->getMessage())->danger()->send();
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
                Action::make('generar_remisiones')
                    ->label('Solo remisiones ARI')
                    ->icon('heroicon-o-document-plus')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Crea remisiones 1:1 sin cerrar el corte (útil para probar).')
                    ->visible(fn (DropiCorte $record) => $record->estado !== EstadoCorte::Cerrado)
                    ->action(function (DropiCorte $record) {
                        $r = GenerarRemisionesCorte::run($record->id);
                        Notification::make()
                            ->title('Remisiones generadas')
                            ->body("Corte {$r['corte']} · {$r['remisiones_creadas']} remisiones · $" . number_format($r['valor_lote'], 0, ',', '.'))
                            ->success()->send();
                    }),
                EditAction::make(),
            ]);
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropiCortes::route('/'),
            'create' => Pages\CreateDropiCorte::route('/create'),
            'edit' => Pages\EditDropiCorte::route('/{record}/edit'),
        ];
    }
}
