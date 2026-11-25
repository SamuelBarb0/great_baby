<?php

namespace App\Modules\Dropi\Filament\Widgets;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vista de estado integral (Aracely). Cada tarjeta enlaza a su detalle.
 */
class ResumenCorteActivoWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Corte activo · Estado integral';

    // Fix C2 · render inmediato, sin skeleton negro.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        // El que se está empacando (cerrado, en despacho) antes que el nuevo.
        $corte = DropiCorte::enTrabajo();
        $pedidosAcumulados = DropiPedido::query()->count();
        $porConciliar = DropiPedido::query()
            ->whereIn('estado', [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado])
            ->count();

        if (! $corte) {
            return [
                Stat::make('Sin corte activo', 'Sincronice pedidos para crear el primero')
                    ->color('gray')->icon('heroicon-o-inbox'),
            ];
        }

        return [
            Stat::make('Corte activo', $corte->etiqueta())
                ->description(ucfirst($corte->estado->label()))
                ->color($corte->estado->color())
                ->icon('heroicon-o-clipboard-document-list')
                ->url(DropiCorteResource::getUrl('edit', ['record' => $corte->id])),

            Stat::make('Pedidos en el corte', (string) $corte->pedidos_totales)
                ->description($corte->pedidos_despachados . ' despachados · clic para ver')
                ->color('info')
                ->chart([
                    max(0, $corte->pedidos_totales - $corte->pedidos_despachados),
                    $corte->pedidos_despachados,
                ])
                ->icon('heroicon-o-truck')
                ->url(DropiPedidoResource::getUrl('index', ['filters' => ['corte_id' => ['value' => $corte->id]]])),

            Stat::make('Pendientes por inventario', (string) $corte->pedidos_pendientes_inv)
                ->description('Esperan devolución compatible · clic para ver')
                ->color($corte->pedidos_pendientes_inv > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle')
                ->url(\App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos::urlEtapa(\App\Modules\Dropi\Enums\EstadoPedidoDropi::PendienteInventario)),

            Stat::make('Por conciliar (histórico)', (string) $porConciliar)
                ->description('Acumulado · ignora el filtro de periodo')
                ->color('warning')
                ->icon('heroicon-o-banknotes')
                ->url(\App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos::urlEtapa(\App\Modules\Dropi\Enums\EstadoPedidoDropi::Despachado)),

            Stat::make('Pedidos totales', (string) $pedidosAcumulados)
                ->description('En toda la historia · clic para ver')
                ->color('gray')
                ->icon('heroicon-o-circle-stack')
                ->url(DropiPedidoResource::getUrl('index')),
        ];
    }
}
