<?php

namespace App\Modules\Dropi\Providers;

use App\Modules\Dropi\Clients\DropiApiClient;
use App\Modules\Dropi\Clients\DropiClientInterface;
use App\Modules\Dropi\Clients\DropiMockClient;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Events\PedidoDropiTransicionado;
use App\Modules\Dropi\Listeners\DescontarInventarioAlEmpacar;
use App\Modules\Dropi\Listeners\RecalcularContadoresCorte;
use App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi;
use App\Modules\Dropi\Listeners\RevertirEgresoAlDesempacar;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Modules\Facturacion\Emisores\AriEmisor;
use App\Support\Contracts\EmisorDocumentoFiscal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class DropiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Emisor fiscal (ARI por defecto; swap fácil a SIIGO/Facture cuando toque).
        $this->app->singleton(EmisorDocumentoFiscal::class, function () {
            return new AriEmisor(
                env('ARI_API_URL', ''),
                env('ARI_API_KEY', ''),
            );
        });

        $this->app->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));

        $this->app->singleton(DropiClientInterface::class, function ($app) {
            $config = $app['config']->get('dropi');

            return match ($config['driver']) {
                'api' => new DropiApiClient(
                    Http::baseUrl($config['api']['url'])
                        ->withToken($config['api']['key'])
                        ->timeout($config['api']['timeout'])
                        ->acceptJson(),
                    $config['api']['url'],
                    $config['api']['key'],
                ),
                'web' => $app->make(DropiWebClient::class),
                default => new DropiMockClient($config['mock']['fixtures_path']),
            };
        });
    }

    public function boot(): void
    {
        // Side-effects del cambio de estado van SIEMPRE por listener.
        //  - DescontarInventarioAlEmpacar (P3): Empacado → egreso.
        //  - RevertirEgresoAlDesempacar (Re-audit H4): Empacado → Alistando → ingreso reverso.
        //  - RecalcularContadoresCorte (A4): actualiza contadores del corte activo.
        Event::listen(PedidoDropiTransicionado::class, DescontarInventarioAlEmpacar::class);
        Event::listen(PedidoDropiTransicionado::class, RevertirEgresoAlDesempacar::class);
        Event::listen(PedidoDropiTransicionado::class, RecalcularContadoresCorte::class);
    }
}
