<?php

namespace App\Modules\Dropi\Filament\Widgets;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Support\Periodos;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * §23 diseño Dropi — Bloque financiero (vista Aracely).
 * Ventas, devoluciones en $, retirado a banco, gastos por categoría, sanciones detectadas.
 * Todo respeta el periodo seleccionado.
 */
class MetricasFinancierasWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Métricas financieras · Dropi';

    protected static ?int $sort = 2;

    // Fix C2 · sin lazy → render inmediato en la primera carga en vez del
    //   skeleton negro por 5s. En demo con datasets pequeños es mejor así.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        [$desde, $hasta, $labelPeriodo] = Periodos::rango(Periodos::actual());
        $fmt = fn (float $v) => '$' . number_format($v, 0, ',', '.');

        // Ventas = suma monto_esperado_proveedor de pedidos pagados en el periodo
        $ventas = (float) DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->whereBetween('pagado_at', [$desde, $hasta])
            ->sum('monto_esperado_proveedor');

        // Devoluciones en $ = suma monto_esperado_proveedor de pedidos devueltos en el periodo
        $devoluciones = (float) DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Devuelto)
            ->whereBetween('devuelto_at', [$desde, $hasta])
            ->sum('monto_esperado_proveedor');

        $pctDevoluciones = $ventas > 0 ? round(($devoluciones / $ventas) * 100, 1) : 0;

        // Retirado a banco (montos negativos de tipo retiro_banco → abs)
        $retirado = abs((float) DropiWalletMovimiento::query()
            ->where('tipo', TipoMovimientoWallet::RetiroBanco)
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('monto'));

        // Gastos por categoría (indemnización + flete garantía + tarjeta)
        $gastos = abs((float) DropiWalletMovimiento::query()
            ->whereIn('tipo', ['indemnizacion', 'flete_garantia', 'tarjeta'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('monto'));

        // Sanciones detectadas (suma diferencias)
        $sanciones = (float) DropiSancion::query()
            ->whereBetween('detectada_at', [$desde, $hasta])
            ->sum('diferencia');

        return [
            Stat::make('Ventas ' . $labelPeriodo, $fmt($ventas))
                ->description('Precio proveedor · pedidos pagados')
                ->color('success')
                ->icon('heroicon-o-arrow-trending-up')
                ->url(DropiPedidoResource::getUrl('index', ['filters' => ['estado' => ['value' => 'pagado']]])),

            Stat::make('Devoluciones ' . $labelPeriodo, $fmt($devoluciones))
                ->description($pctDevoluciones . '% de las ventas del periodo')
                ->color($pctDevoluciones > 10 ? 'danger' : ($pctDevoluciones > 5 ? 'warning' : 'success'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->url(DropiPedidoResource::getUrl('index', ['filters' => ['estado' => ['value' => 'devuelto']]])),

            Stat::make('Retirado a banco', $fmt($retirado))
                ->description($labelPeriodo . ' · reportado por la wallet')
                ->color('info')
                ->icon('heroicon-o-banknotes')
                ->url(DropiWalletMovimientoResource::getUrl('index', ['filters' => ['tipo' => ['value' => 'retiro_banco']]])),

            Stat::make('Gastos wallet', $fmt($gastos))
                ->description('Indemnizaciones + fletes + tarjeta · ' . $labelPeriodo)
                ->color($gastos > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-minus-circle')
                ->url(DropiWalletMovimientoResource::getUrl('index')),

            Stat::make('Sanciones detectadas', $fmt($sanciones))
                ->description($labelPeriodo . ' · descontadas por Dropi')
                ->color($sanciones > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-shield-exclamation'),
        ];
    }
}
