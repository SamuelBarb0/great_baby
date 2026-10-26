<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Actions\ConciliarWalletDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Trae la wallet de Dropi (driver web) y la concilia con los pedidos: cada pago
 * de guía se enlaza a su pedido por la orden de Dropi, el pedido pasa a Pagado
 * y la suma pagada se compara con lo esperado.
 *
 * Sin --desde retoma desde el último movimiento guardado (con un día de margen:
 * los repetidos se saltan por su id). La primera vez arranca en
 * DROPI_WALLET_DESDE (1-sep-2026): Samuel decidió no traer la historia vieja,
 * porque cada movimiento genera su asiento contable.
 */
class DropiWebWalletSyncCommand extends Command
{
    protected $signature = 'dropi:web-wallet-sync {--desde= : AAAA-MM-DD}';

    protected $description = 'Importa la wallet de Dropi (driver web) y la concilia con los pedidos.';

    public function handle(DropiWebClient $client): int
    {
        $desde = $this->option('desde')
            ? CarbonImmutable::parse($this->option('desde'), config('app.timezone'))->startOfDay()
            : $this->desdeUltimo();

        $this->info('Wallet de Dropi desde ' . $desde->toDateString() . '…');
        try {
            $r = (new ConciliarWalletDropi($client))->handle($desde);
        } catch (DropiSesionNoDisponible $e) {
            // Con el driver api los pedidos y sus pagos entran por la llave de
            //   integración; la wallet completa (retiros, movimientos sin pedido)
            //   solo existe por la sesión web, que con 2FA pide el código de la
            //   app cada 4 h. Sin sesión se salta: se avisa una vez por hora.
            if (Cache::add('dropi:web-wallet-sync:sin-sesion', true, 3600)) {
                Log::warning('dropi.web.wallet_sin_sesion', ['msg' => $e->getMessage()]);
            }
            $this->warn($e->getMessage() . ' Los pagos de los pedidos siguen entrando por la API.');

            return self::SUCCESS;
        }

        $this->table(['procesados', 'pagos', 'sanciones', 'otros', 'errores'], [[
            $r['procesados'], $r['pagos_matcheados'], $r['sanciones_detectadas'], $r['gastos_registrados'], $r['errores'],
        ]]);

        return $r['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function desdeUltimo(): CarbonImmutable
    {
        $inicio = CarbonImmutable::parse(config('dropi.web.wallet_desde'), config('app.timezone'))->startOfDay();
        $ultimo = DropiWalletMovimiento::where('dropi_movimiento_id', 'like', 'web:%')->max('fecha');

        return $ultimo ? max($inicio, CarbonImmutable::parse($ultimo, config('app.timezone'))->subDay()->startOfDay()) : $inicio;
    }
}
