<?php

namespace App\Console\Commands;

use App\Http\Controllers\App\EmpleadoController;
use App\Modules\Rrhh\Models\Empleado;
use Illuminate\Console\Command;

/**
 * Avisa de los períodos de prueba que están por vencerse.
 *
 * La pantalla de Empleados ya mostraba cuántos días quedaban, y el modelo ya
 * traía el scope para encontrarlos. Pero mostrar no es avisar: había que
 * entrar y mirar. Si nadie entraba, el período se vencía solo.
 *
 * Y vencerse solo tiene consecuencias: pasado el período, terminar el contrato
 * deja de ser libre y pasa a necesitar justa causa. Es de los pocos plazos del
 * ERP donde no enterarse cuesta plata de verdad.
 *
 * El aviso va a Gestión Humana SIEMPRE —es quien cierra la evaluación— y
 * además al jefe del área cuando todavía no dio su concepto, que es quien
 * tiene que dar el primer paso.
 *
 * Quién recibe qué vive en `EmpleadoController::avisarPruebasPorVencer()` y no
 * acá. Había dos caminos haciendo lo mismo —este comando y
 * `rrhh:avisar-periodos-prueba`, agendados los dos— con tipos de aviso
 * distintos y destinatarios invertidos: éste mandaba el aviso al jefe cuando
 * ya había opinado y a TODO EL ERP cuando no, con lo cual Gestión Humana no lo
 * recibía nunca. Dos implementaciones de la misma regla se separan siempre;
 * la pregunta no es si, es cuándo.
 */
class AvisarPruebaPorVencerCommand extends Command
{
    protected $signature = 'rrhh:avisar-prueba {--dry-run : sólo muestra, no avisa}';

    protected $description = 'Avisa de los períodos de prueba que vencen pronto';

    public function handle(): int
    {
        $dias = max(1, (int) setting('rrhh.dias_aviso_prueba'));
        $seco = (bool) $this->option('dry-run');

        $empleados = Empleado::pruebaPorVencer($dias)->get();

        if ($empleados->isEmpty()) {
            $this->info("Ningún período de prueba vence en los próximos {$dias} días.");

            return self::SUCCESS;
        }

        foreach ($empleados as $e) {
            $quedan = $e->diasDePrueba();

            $this->warn('· '.$e->nombre_completo.': '.match (true) {
                $quedan === null => 'sin fecha',
                $quedan < 0 => 'YA SE VENCIÓ hace '.abs($quedan).' días',
                $quedan === 0 => 'vence HOY',
                default => "vence en {$quedan} días",
            });
        }

        if (! $seco) {
            EmpleadoController::avisarPruebasPorVencer($dias);
        }

        $this->newLine();
        $this->line($empleados->count().' período(s) por vencer'.($seco ? ' · dry-run, no se avisó' : ' · avisados'));

        return self::SUCCESS;
    }
}
