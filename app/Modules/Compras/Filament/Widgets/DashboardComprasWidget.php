<?php

namespace App\Modules\Compras\Filament\Widgets;

use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Filament\Resources\ImportacionResource;
use App\Modules\Compras\Filament\Resources\OrdenCompraResource;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardComprasWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Compras e Importaciones';

    protected static ?int $sort = 20;

    protected function getStats(): array
    {
        $fmt = fn ($v) => '$' . number_format((float) $v, 0, ',', '.');

        $ocPend = OrdenCompra::whereIn('estado', [
            EstadoOrdenCompra::Borrador,
            EstadoOrdenCompra::Enviada,
            EstadoOrdenCompra::Aprobada,
            EstadoOrdenCompra::Parcial,
        ])->count();
        $ocMonto = OrdenCompra::whereIn('estado', [
            EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Parcial,
        ])->sum('total');

        $vencidas = OrdenCompra::whereDate('fecha_esperada', '<', now())
            ->whereNotIn('estado', ['recibida', 'cerrada', 'anulada'])
            ->count();

        $impPendientes = Importacion::whereIn('estado', [
            EstadoImportacion::EnTransito,
            EstadoImportacion::EnPuerto,
            EstadoImportacion::Nacionalizada,
        ])->count();

        $impPorLiquidar = Importacion::whereIn('estado', [
            EstadoImportacion::Nacionalizada,
        ])->count();

        return [
            Stat::make('OC pendientes', $ocPend)
                ->description('En borrador, enviadas, aprobadas o parciales')
                ->color('info')
                ->url(OrdenCompraResource::getUrl('index')),

            Stat::make('Comprometido', $fmt($ocMonto))
                ->description('OC aprobadas/parciales en camino')
                ->color('warning'),

            Stat::make('OC con fecha vencida', $vencidas)
                ->description('Retraso del proveedor')
                ->color($vencidas > 0 ? 'danger' : 'success')
                ->url(OrdenCompraResource::getUrl('index', ['filters' => ['vencidas' => ['value' => true]]])),

            Stat::make('Contenedores activos', $impPendientes)
                ->description($impPorLiquidar . ' listos para liquidar')
                ->color($impPorLiquidar > 0 ? 'warning' : 'primary')
                ->url(ImportacionResource::getUrl('index')),
        ];
    }
}
