<?php

namespace App\Console\Commands;

use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Services\SiigoService;
use Illuminate\Console\Command;

class SiigoSyncCommand extends Command
{
    protected $signature = 'siigo:sync {recurso=all : catalogos|productos|clientes|all}';

    protected $description = 'Sincroniza recursos desde SIIGO (catálogos, productos, clientes).';

    public function handle(): int
    {
        $cfg = SiigoConfig::current();
        if (! $cfg->activo || ! $cfg->username) {
            $this->warn('SIIGO no está configurado o inactivo. Configura credenciales en /app/siigo');
            return self::SUCCESS;
        }

        $service = new SiigoService(new SiigoClient($cfg));
        $r = $this->argument('recurso');

        if ($r === 'catalogos' || $r === 'all') {
            $this->info('Sincronizando catálogos + warehouses...');
            $x = $service->sincronizarCatalogos();
            // El resumen reventaba el comando cuando algún valor no era
            // escalar («Array to string conversion»), y entonces parecía que
            // la sincronización había fallado aunque ya hubiera guardado los
            // 582 registros. El resumen es decoración: no puede tumbar nada.
            $this->line('  → '.collect($x)->map(function ($n, $k) {
                return $k.': '.(is_scalar($n) ? $n : (is_countable($n) ? count($n) : json_encode($n)));
            })->join(' · '));
        }

        if ($r === 'productos' || $r === 'all') {
            $this->info('Sincronizando productos...');
            $x = $service->sincronizarProductos();
            $this->line("  → Nuevos: {$x['nuevos']} · Actualizados: {$x['actualizados']} · Errores: {$x['errores']}");
        }

        if ($r === 'clientes' || $r === 'all') {
            $this->info('Sincronizando clientes...');
            $x = $service->sincronizarClientes();
            $this->line("  → Nuevos: {$x['nuevos']} · Actualizados: {$x['actualizados']} · Errores: {$x['errores']}");
        }

        return self::SUCCESS;
    }
}
