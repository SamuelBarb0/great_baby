<?php

namespace App\Modules\Dropi\Filament\Widgets;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Panel "Requiere atención":
 * - Pedidos cerca del plazo de sanción Dropi (72h — aviso a 48h)
 * - Guías despachadas hace >7/10/15 días sin entrega
 * - Retiros de wallet fuera del ciclo normal
 */
class AlertasOperativasWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Requiere atención';

    protected static ?int $sort = 3;

    // Fix C2 · render inmediato.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $cfg = config('dropi.alertas');

        $porVencer = DropiPedido::query()
            ->whereIn('estado', [
                EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario,
                EstadoPedidoDropi::Alistando, EstadoPedidoDropi::Empacado,
            ])
            ->where('created_at', '<=', now()->subHours($cfg['aviso_previo_horas']))
            ->count();

        $entregasTardias = DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Despachado)
            ->where('despachado_at', '<=', now()->subDays($cfg['entrega_critica_dias']))
            ->count();

        $entregasContactar = DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Despachado)
            ->where('despachado_at', '<=', now()->subDays($cfg['entrega_contactar_dias']))
            ->count();

        $ultimoRetiro = DropiWalletMovimiento::query()
            ->where('tipo', 'retiro_banco')->orderByDesc('fecha')->first();

        $diasSinRetiro = $ultimoRetiro ? (int) $ultimoRetiro->fecha->diffInDays(now()) : 0;

        $etiquetaRetiro = match (true) {
            ! $ultimoRetiro => 'Sin retiros aún',
            $diasSinRetiro <= $cfg['retiro_wallet_normal_dias'] => 'En ciclo normal',
            $diasSinRetiro <= $cfg['retiro_wallet_aviso_dias'] => 'Ya toca retirar',
            default => 'Fuera de ciclo',
        };

        $colorRetiro = match (true) {
            ! $ultimoRetiro => 'gray',
            $diasSinRetiro <= $cfg['retiro_wallet_normal_dias'] => 'success',
            $diasSinRetiro <= $cfg['retiro_wallet_aviso_dias'] => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('Cerca del plazo Dropi', (string) $porVencer)
                ->description('Alerta a las ' . $cfg['aviso_previo_horas'] . 'h · límite 72h')
                ->color($porVencer > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-clock')
                ->url(\App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos::urlEtapa(\App\Modules\Dropi\Enums\EstadoPedidoDropi::Pending)),

            Stat::make('Entregas críticas ≥ ' . $cfg['entrega_critica_dias'] . 'd', (string) $entregasTardias)
                ->description('Revisar seguimiento con transportadora')
                ->color($entregasTardias > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-map-pin')
                ->url(\App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos::urlEtapa(\App\Modules\Dropi\Enums\EstadoPedidoDropi::Despachado)),

            Stat::make('Contactar transportadora ≥ ' . $cfg['entrega_contactar_dias'] . 'd', (string) $entregasContactar)
                ->description('Requiere gestión directa')
                ->color($entregasContactar > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-phone')
                ->url(\App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos::urlEtapa(\App\Modules\Dropi\Enums\EstadoPedidoDropi::Despachado)),

            Stat::make('Wallet · último retiro', $ultimoRetiro ? "hace {$diasSinRetiro} d" : '—')
                ->description($etiquetaRetiro)
                ->color($colorRetiro)
                ->icon('heroicon-o-banknotes')
                ->url(DropiWalletMovimientoResource::getUrl('index', ['filters' => ['tipo' => ['value' => 'retiro_banco']]])),
        ];
    }
}
