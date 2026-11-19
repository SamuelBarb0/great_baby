<?php

namespace App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource\Pages;

use App\Modules\Dropi\Actions\ConciliarWalletDropi;
use App\Modules\Dropi\Filament\Pages\CruceWalletPedidos;
use App\Modules\Dropi\Filament\Pages\LibroContableDropi;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\RateLimiter;

class ListDropiWalletMovimientos extends ListRecords
{
    protected static string $resource = DropiWalletMovimientoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('libro')
                ->label('Ver en el libro contable')
                ->icon('heroicon-o-book-open')
                ->color('gray')
                ->url(LibroContableDropi::urlDeWallet())
                ->visible(fn () => LibroContableDropi::canAccess()),
            Action::make('cruce')
                ->label('Cruce con pedidos')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->url(fn () => CruceWalletPedidos::getUrl()),
            Action::make('conciliar')
                ->label('Conciliar wallet Dropi')
                ->icon('heroicon-o-arrows-right-left')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('§15 · Trae movimientos de la wallet Dropi, matchea pagos por guía y detecta sanciones.')
                ->action(function () {
                    $key = 'dropi-conciliar:' . auth()->id();
                    if (RateLimiter::tooManyAttempts($key, 3)) {
                        Notification::make()
                            ->title('Espera un momento')
                            ->body('Ya conciliaste hace poco. Reintenta en ' . RateLimiter::availableIn($key) . 's.')
                            ->warning()->send();
                        return;
                    }
                    RateLimiter::hit($key, 60);

                    $r = app(ConciliarWalletDropi::class)->handle(CarbonImmutable::now()->subMonths(3));

                    Notification::make()
                        ->title('Conciliación completada')
                        ->body("Procesados: {$r['procesados']} · Pagos: {$r['pagos_matcheados']} · Sanciones: {$r['sanciones_detectadas']} · Gastos: {$r['gastos_registrados']}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
