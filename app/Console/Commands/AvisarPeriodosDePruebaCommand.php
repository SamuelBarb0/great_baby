<?php

namespace App\Console\Commands;

use App\Http\Controllers\App\EmpleadoController;
use Illuminate\Console\Command;

/**
 * Recuerda los períodos de prueba que están por vencerse.
 *
 * Hoy no existe nada parecido: el plazo se vence, nadie decide, y a partir de
 * ahí terminar el contrato ya no se puede sin justa causa ni indemnización.
 * La decisión hay que tomarla antes, y para eso hay que acordarse.
 */
class AvisarPeriodosDePruebaCommand extends Command
{
    protected $signature = 'rrhh:avisar-periodos-prueba {--dias=15 : Con cuánta anticipación avisar}';

    protected $description = 'Avisa a Gestión Humana de los períodos de prueba que vencen pronto';

    public function handle(): int
    {
        $n = EmpleadoController::avisarPruebasPorVencer((int) $this->option('dias'));

        $this->info($n === 0
            ? 'Ningún período de prueba por vencer.'
            : "Se avisó de {$n} período(s) de prueba por vencer.");

        return self::SUCCESS;
    }
}
