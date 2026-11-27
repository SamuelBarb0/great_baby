<?php

namespace App\Modules\Dropi\Filament\Resources\DropiCorteResource\RelationManagers;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los pedidos del corte, uno por uno (26-nov-2026: «que cuando le das click al
 * corte puedas ver cada pedido individualmente como vista previa»). Clic en la
 * fila: el pedido en un panel lateral, sin salir del corte.
 */
class PedidosRelationManager extends RelationManager
{
    protected static string $relationship = 'pedidos';

    protected static ?string $title = 'Pedidos del corte';

    /** @var array<int, array>|null qué pasó con la guía de cada pedido, de los reportes del corte */
    private ?array $resultados = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** El último resultado de guía de cada pedido en los reportes de este corte. */
    public function resultadoDe(DropiPedido $p): ?array
    {
        $this->resultados ??= DropiLoteImpresion::query()->where('corte_id', $this->getOwnerRecord()->getKey())->orderBy('id')->get()
            ->flatMap(fn (DropiLoteImpresion $l) => $l->resultados ?? [])
            ->keyBy('pedido_id')->all();

        return $this->resultados[$p->id] ?? null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items.variante.producto', 'items.producto']))
            ->defaultSort('dropi_creado_at')
            ->columns([
                TextColumn::make('dropi_orden_id')->label('Orden')->searchable()->fontFamily('mono')->copyable(),
                TextColumn::make('guia')->label('Guía')->searchable()->fontFamily('mono')
                    ->formatStateUsing(fn (DropiPedido $record, $state) => $record->tieneGuiaReal() ? $state : '—')
                    ->description(fn (DropiPedido $record) => $record->tieneGuiaReal() ? $record->transportadora : null),
                TextColumn::make('cliente_nombre')->label('Cliente')->searchable()->weight('medium')
                    ->description(fn (DropiPedido $record) => $record->cliente_ciudad),
                TextColumn::make('productos')->label('Productos')
                    ->state(fn (DropiPedido $record) => $record->items
                        ->map(fn (DropiPedidoItem $i) => ($i->referenciaErp() ?? $i->sku_dropi) . ' ×' . $i->cantidad)
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->fontFamily('mono'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof EstadoPedidoDropi ? $state->label() : $state)
                    ->color(fn ($state) => $state instanceof EstadoPedidoDropi ? $state->color() : 'gray'),
                TextColumn::make('resultado_guia')->label('Su guía en el reporte')
                    ->state(function (DropiPedido $record) {
                        $r = $this->resultadoDe($record);
                        $t = $r ? (DropiLoteImpresion::RESULTADOS[$r['tipo']] ?? null) : null;

                        return $t ? "{$t['icono']} {$t['titulo']}" : null;
                    })
                    ->color(function (DropiPedido $record) {
                        $r = $this->resultadoDe($record);

                        return match (DropiLoteImpresion::RESULTADOS[$r['tipo'] ?? '']['grupo'] ?? null) {
                            'accion' => 'danger', 'bien' => 'success', default => 'gray',
                        };
                    })
                    ->tooltip(fn (DropiPedido $record) => $this->resultadoDe($record)['detalle'] ?? null)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('estado')->label('Estado')->multiple()
                    ->options(collect(EstadoPedidoDropi::cases())->mapWithKeys(fn (EstadoPedidoDropi $e) => [$e->value => $e->label()])),
            ])
            ->recordAction('vista_previa')
            ->recordActions([
                Action::make('vista_previa')
                    ->label('Vista previa')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->modalHeading(fn (DropiPedido $record) => "Orden {$record->dropi_orden_id} · {$record->cliente_nombre}")
                    ->modalContent(fn (DropiPedido $record) => view('dropi.pages.partials.vista-previa-pedido', [
                        'pedido' => $record,
                        'resultado' => $this->resultadoDe($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->extraModalFooterActions(fn (DropiPedido $record) => [
                        Action::make('abrir')->label('Abrir pedido completo')->icon('heroicon-o-arrow-top-right-on-square')
                            ->url(DropiPedidoResource::getUrl('view', ['record' => $record]))->openUrlInNewTab(),
                    ]),
            ])
            ->paginated([25, 50, 100]);
    }
}
