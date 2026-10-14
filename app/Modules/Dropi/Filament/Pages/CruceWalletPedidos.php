<?php

namespace App\Modules\Dropi\Filament\Pages;

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

    protected static string|\UnitEnum|null $navigationGroup = 'Dropi';

    protected static ?int $navigationSort = 7;

    protected string $view = 'dropi.pages.cruce-wallet-pedidos';

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function cruce(): Cruce
    {
        return app(Cruce::class);
    }
}
