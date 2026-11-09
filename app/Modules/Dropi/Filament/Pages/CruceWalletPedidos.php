<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Services\CruceWalletPedidos as Cruce;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Cruce wallet ↔ pedidos: qué pagó Dropi, qué está en camino, qué se entregó y
 * no se ha pagado (atrasos) y qué pagos no tienen pedido en el ERP.
 */
class CruceWalletPedidos extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Cruce wallet ↔ pedidos';

    protected static ?string $title = 'Cruce wallet ↔ pedidos';

    // 9-nov · en «Contabilidad», junto a la wallet: el cruce dice además qué
    // pagos ya están en el libro (por su recibo de caja) y cuáles no.
    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 7;

    protected string $view = 'dropi.pages.cruce-wallet-pedidos';

    /** Quien ve la wallet ve su cruce (antes sólo Aracely: la Contadora no entraba). */
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && ($u->esAracely() || Permisos::puede($u, 'dropi_wallet'));
    }

    public function cruce(): Cruce
    {
        return app(Cruce::class);
    }
}
