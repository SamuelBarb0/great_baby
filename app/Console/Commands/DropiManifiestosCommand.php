<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Actions\SincronizarManifiestosDropi;
use Illuminate\Console\Command;

/**
 * Trae de Dropi los manifiestos de entrega a las transportadoras y guarda su PDF
 * y su Excel (ver SincronizarManifiestosDropi). Corre cada 15 minutos con los
 * últimos 2 días; la primera vez, `--dias=30` llena el último mes.
 */
class DropiManifiestosCommand extends Command
{
    protected $signature = 'dropi:manifiestos {--dias=2 : Días hacia atrás} {--sin-archivos : Sólo la lista, sin descargar PDF ni Excel}';

    protected $description = 'Trae de Dropi los manifiestos (Logistic Management → Salidas) con su PDF y Excel.';

    public function handle(SincronizarManifiestosDropi $sync): int
    {
        $r = $sync->handle((int) $this->option('dias'), ! $this->option('sin-archivos'));

        $this->info("Vistos: {$r['vistos']} · nuevos: {$r['nuevos']} · archivos descargados: {$r['archivos']}");
        foreach (array_slice($r['errores'], 0, 10) as $e) {
            $this->warn($e);
        }

        return self::SUCCESS;
    }
}
