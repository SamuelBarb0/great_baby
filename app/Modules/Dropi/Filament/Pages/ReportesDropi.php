<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiDevolucionResource;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Support\Periodos;
use BackedEnum;
use Filament\Pages\Page;

class ReportesDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?string $title = 'Reportes Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Dinero';

    protected static ?int $navigationSort = 5;

    protected string $view = 'dropi.pages.reportes-dropi';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        // La casilla «Reportes Dropi» de la pantalla de Roles, no el nombre
        //   del rol: así un perfil como «Operador Dropi» sí puede entrar.
        return \App\Auth\Permisos::puede($u, 'dropi_reportes');
    }

    public function getBloques(): array
    {
        [$desde, $hasta, $labelPer] = Periodos::rango(Periodos::actual());

        $ventas = (float) DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->whereBetween('pagado_at', [$desde, $hasta])->sum('monto_esperado_proveedor');
        $devoluciones = (float) DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Devuelto)
            ->whereBetween('devuelto_at', [$desde, $hasta])->sum('monto_esperado_proveedor');
        $sanciones = (float) DropiSancion::whereBetween('detectada_at', [$desde, $hasta])->sum('diferencia');
        $retiros = abs((float) DropiWalletMovimiento::where('tipo', 'retiro_banco')
            ->whereBetween('fecha', [$desde, $hasta])->sum('monto'));

        return [
            'operativos' => [
                'titulo' => '⚙️ Operativos',
                'reportes' => [
                    ['nombre' => 'Corte del día', 'desc' => 'Pedidos del corte activo con estado y transportadora', 'url' => DropiPedidoResource::getUrl('index'), 'icon' => 'heroicon-o-clock'],
                    ['nombre' => 'Pendientes por inventario', 'desc' => 'Guías esperando devolución compatible', 'url' => DropiPedidoResource::getUrl('index', ['filters' => ['estado' => ['value' => 'pendiente_inventario']]]), 'icon' => 'heroicon-o-exclamation-triangle'],
                    ['nombre' => 'Despachados sin entregar', 'desc' => 'Seguimiento transportadora', 'url' => DropiPedidoResource::getUrl('index', ['filters' => ['estado' => ['value' => 'despachado']]]), 'icon' => 'heroicon-o-truck'],
                    ['nombre' => 'Devoluciones', 'desc' => 'Todas con destino de inventario', 'url' => DropiDevolucionResource::getUrl('index'), 'icon' => 'heroicon-o-arrow-uturn-left'],
                ],
            ],
            'financieros' => [
                'titulo' => '💰 Financieros · ' . $labelPer,
                'reportes' => [
                    ['nombre' => 'Ventas del periodo', 'desc' => '$' . number_format($ventas, 0, ',', '.') . ' · pedidos pagados', 'url' => DropiPedidoResource::getUrl('index', ['filters' => ['estado' => ['value' => 'pagado']]]), 'icon' => 'heroicon-o-arrow-trending-up'],
                    ['nombre' => 'Devoluciones en pesos', 'desc' => '$' . number_format($devoluciones, 0, ',', '.'), 'url' => DropiPedidoResource::getUrl('index', ['filters' => ['estado' => ['value' => 'devuelto']]]), 'icon' => 'heroicon-o-currency-dollar'],
                    ['nombre' => 'Conciliación wallet', 'desc' => 'Movimientos ↔ pedidos', 'url' => DropiWalletMovimientoResource::getUrl('index'), 'icon' => 'heroicon-o-wallet'],
                    ['nombre' => 'Retiros a banco', 'desc' => '$' . number_format($retiros, 0, ',', '.') . ' en el periodo', 'url' => DropiWalletMovimientoResource::getUrl('index', ['filters' => ['tipo' => ['value' => 'retiro_banco']]]), 'icon' => 'heroicon-o-banknotes'],
                    ['nombre' => 'Sanciones detectadas', 'desc' => '$' . number_format($sanciones, 0, ',', '.') . ' · descontadas por Dropi', 'url' => DiscrepanciasWallet::getUrl(), 'icon' => 'heroicon-o-shield-exclamation'],
                ],
            ],
            'auditoria' => [
                'titulo' => '🕵️ Auditoría',
                'reportes' => [
                    ['nombre' => 'Bitácora de estados', 'desc' => 'Quién cambió qué estado y cuándo', 'url' => BitacoraEstadosDropi::getUrl(), 'icon' => 'heroicon-o-clipboard-document-list'],
                    ['nombre' => 'Cambios financieros', 'desc' => 'Wallet, sanciones, remisiones auditadas', 'url' => CambiosFinancierosDropi::getUrl(), 'icon' => 'heroicon-o-shield-check'],
                ],
            ],
            'ejecutivos' => [
                'titulo' => '📊 Ejecutivos',
                'reportes' => [
                    ['nombre' => 'Dashboard consolidado', 'desc' => 'Corte activo + finanzas + alertas', 'url' => DashboardDropi::getUrl(), 'icon' => 'heroicon-o-chart-bar-square'],
                    ['nombre' => 'Comparativo periodo', 'desc' => 'Este mes vs. mes anterior', 'url' => ComparativoPeriodoDropi::getUrl(), 'icon' => 'heroicon-o-arrows-right-left'],
                ],
            ],
        ];
    }
}
