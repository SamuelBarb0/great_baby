<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Clients\DropiWebClient;
use Illuminate\Console\Command;

/**
 * Guarda una página cruda de pedidos de Dropi (driver web) para escribir el mapeo
 * JSON → PedidoDropiDTO sobre datos reales. Solo lectura.
 */
class DropiWebMuestraCommand extends Command
{
    protected $signature = 'dropi:web-muestra {--n=20 : Pedidos a traer (1..100)}';

    protected $description = 'Guarda en storage/app/dropi-web una muestra cruda de pedidos de Dropi (solo lectura).';

    public function handle(DropiWebClient $client): int
    {
        $n = max(1, min(100, (int) $this->option('n')));
        $pagina = $client->pedidosCrudos([], 0, $n);

        $ruta = storage_path('app/dropi-web/muestra-pedidos-' . now()->format('Ymd-His') . '.json');
        @mkdir(dirname($ruta), 0775, true);
        file_put_contents($ruta, json_encode($pagina, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(count($pagina['objects']) . ' pedidos (de ' . ($pagina['count'] ?? '?') . ') guardados en ' . $ruta);

        return self::SUCCESS;
    }
}
