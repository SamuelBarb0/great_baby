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

        // Driver `api` (token de integración, ver DropiApiClient). Antes mandaba
        // la llave como `Bearer` y Dropi la espera en `dropi-integration-key`.
        $this->app->singleton(DropiApiClient::class, fn ($app) => new DropiApiClient(
            $app->make(DropiWebAuth::class),
            $app['config']->get('dropi.api'),
        ));

        // Con DROPI_DRIVER=api, todo lo que pide el cliente web (sync en tres
        // ritmos, información del pedido, estatus, etiquetas, wallet) recibe el
        // de la API oficial: lee pedidos por el token y hereda lo demás.
        $this->app->bind(DropiWebClient::class, fn ($app) => $app['config']->get('dropi.driver') === 'api'
            ? $app->make(DropiApiClient::class)
            : new DropiWebClient($app->make(DropiWebAuth::class)));

        $this->app->singleton(DropiClientInterface::class, function ($app) {
            $config = $app['config']->get('dropi');

            return match ($config['driver']) {
                'api' => $app->make(DropiApiClient::class),
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
