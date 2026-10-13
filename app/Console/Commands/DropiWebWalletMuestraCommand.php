<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Clients\DropiWebClient;
use Illuminate\Console\Command;

/**
 * Guarda una página cruda del historial de la wallet de Dropi y su catálogo de
 * tipos, para escribir el mapeo JSON → PagoWalletDTO sobre datos reales. Solo
 * lectura.
 */
class DropiWebWalletMuestraCommand extends Command
{
    protected $signature = 'dropi:web-wallet-muestra {--n=50 : Movimientos a traer (1..200)} {--desde= : AAAA-MM-DD} {--hasta= : AAAA-MM-DD}';

    protected $description = 'Guarda en storage/app/dropi-web una muestra cruda de la wallet de Dropi (solo lectura).';

    public function handle(DropiWebClient $client): int
    {
        $n = max(1, min(200, (int) $this->option('n')));

        $muestra = [
            'tipos' => $client->tiposWallet(),
            'historial' => $client->walletCrudo(array_filter([
                'from' => $this->option('desde'),
                'until' => $this->option('hasta'),
            ]), 0, $n),
        ];

        $ruta = storage_path('app/dropi-web/muestra-wallet-' . now()->format('Ymd-His') . '.json');
        @mkdir(dirname($ruta), 0775, true);
        file_put_contents($ruta, json_encode($muestra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $objetos = $muestra['historial']['objects'] ?? [];
        $this->info(count($objetos) . ' movimientos (de ' . ($muestra['historial']['count'] ?? '?') . ') guardados en ' . $ruta);

        return self::SUCCESS;
    }
}
