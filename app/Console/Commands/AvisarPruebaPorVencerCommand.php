<?php

namespace App\Console\Commands;

use App\Models\NotificacionErp;
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
 * Avisa al jefe que ya opinó —si opinó— y a Gestión Humana siempre, porque la
 * evaluación necesita las dos firmas.
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
            $cuando = $quedan === null ? 'sin fecha'
                : ($quedan < 0 ? 'YA SE VENCIÓ hace '.abs($quedan).' días'
                    : ($quedan === 0 ? 'vence HOY' : "vence en {$quedan} días"));

            $this->warn("· {$e->nombre_completo}: {$cuando}");

            if ($seco) {
                continue;
            }

            NotificacionErp::crear([
                // Al jefe que ya opinó; si todavía no opinó nadie, a todos,
                // que en la práctica es Gestión Humana.
                'user_id' => $e->prueba_jefe_por_id,
                'tipo' => 'prueba_por_vencer',
                'titulo' => "Período de prueba · {$e->nombre_completo}",
                'mensaje' => "{$cuando}. Pasado el período, terminar el contrato ya no es libre: "
                    .'necesita justa causa. La evaluación va firmada por el jefe del área y por Gestión Humana.',
                'color' => $quedan !== null && $quedan < 0 ? 'danger' : 'warning',
                'icono' => 'heroicon-o-clock',
                'url' => "/app/rrhh/empleados/{$e->id}",
            ]);
        }

        $this->newLine();
        $this->line($empleados->count().' período(s) por vencer'.($seco ? ' · dry-run, no se avisó' : ' · avisados'));

        return self::SUCCESS;
    }
}
