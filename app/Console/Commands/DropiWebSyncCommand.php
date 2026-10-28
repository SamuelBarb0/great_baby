<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DropiWebSyncCommand extends Command
{
    protected $signature = 'dropi:web-sync
        {modo=recientes : recientes | ventana | transito | pagos}
        {--dias=3 : Ventana en días para el modo ventana}
        {--max-detalles=120 : Máximo de pedidos a detallar por vuelta}';

    protected $description = 'Sincroniza pedidos de Dropi casi en tiempo real por el driver web (ver SincronizarDropiWeb).';

    public function handle(SincronizarDropiWeb $sync): int
    {
        $modo = (string) $this->argument('modo');

        try {
            $r = $sync->handle($modo, (int) $this->option('dias'), 40, (int) $this->option('max-detalles'));
        } catch (DropiSesionNoDisponible $e) {
            // Sin sesión no hay nada que hacer; se avisa una vez por hora, no cada 15 s.
            if (Cache::add('dropi:web-sync:sin-sesion', true, 3600)) {
                Log::warning('dropi.web.sync_sin_sesion', ['msg' => $e->getMessage()]);
            }
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        if ($r['cambiados'] > 0 || $this->output->isVerbose()) {
            Log::info('dropi.web.sync', $r);
        }
        $this->line(sprintf('[%s] vistos %d · cambiados %d · nuevos %d · actualizados %d · pagos %d · errores %d',
            $r['modo'], $r['vistos'], $r['cambiados'], $r['nuevos'], $r['actualizados'], $r['pagos'] ?? 0, $r['errores']));

        return self::SUCCESS;
    }
}
