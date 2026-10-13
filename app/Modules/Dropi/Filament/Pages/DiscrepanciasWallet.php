<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Reporte de discrepancias:
 *  - Pedidos despachados/entregados SIN pago matcheado en wallet (esperando cobro)
 *  - Sanciones detectadas (diferencia entre esperado y recibido)
 *  - Pagos de wallet SIN pedido asociado (huérfanos)
 */
class DiscrepanciasWallet extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Discrepancias wallet';

    protected static ?string $title = 'Discrepancias · Wallet ↔ Pedidos';

    protected static string|\UnitEnum|null $navigationGroup = 'Dropi';

    protected static ?int $navigationSort = 7;

    protected string $view = 'dropi.pages.discrepancias-wallet';

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function getPedidosSinCobro()
    {
        return DropiPedido::query()
            ->whereIn('estado', [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado])
            ->whereDoesntHave('pagoWallet')
            ->where('despachado_at', '<=', now()->subDays(3))
            // La wallet se importa desde DROPI_WALLET_DESDE: lo despachado antes
            // se cobró antes y su pago no está aquí. Sin este límite salían como
            // deudas los pedidos viejos (ej. los cargados por Excel el 21-sep).
            ->where('despachado_at', '>=', config('dropi.web.wallet_desde'))
            ->orderBy('despachado_at')
            ->limit(50)
            ->get();
    }

    public function getSanciones()
    {
        return DropiSancion::query()
            ->with('pedido')
            ->orderByDesc('detectada_at')
            ->limit(50)
            ->get();
    }

    public function getPagosHuerfanos()
    {
        return DropiWalletMovimiento::query()
            ->where('tipo', 'pago_guia')
            ->whereNull('pedido_id')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get();
    }

    public function getTotales(): array
    {
        return [
            'esperado' => (float) DropiPedido::query()
                ->whereIn('estado', [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado])
                ->whereDoesntHave('pagoWallet')
                ->where('despachado_at', '>=', config('dropi.web.wallet_desde'))
                ->sum('monto_esperado_proveedor'),
            'sanciones' => (float) DropiSancion::sum('diferencia'),
            'huerfanos' => (float) DropiWalletMovimiento::where('tipo', 'pago_guia')->whereNull('pedido_id')->sum('monto'),
        ];
    }
}
