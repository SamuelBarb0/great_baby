<?php

namespace App\Console\Commands;

use App\Modules\Compras\Services\PlanDePagos;
use Illuminate\Console\Command;

/**
 * Naranja a los 10 días, rojo a los 3 y, ya vencida, insiste hasta que alguien le
 * haga seguimiento: lo que pidió Compras para no enterarse tarde de un pago a China.
 */
class AvisarPagosExteriorCommand extends Command
{
    protected $signature = 'compras:avisar-pagos-exterior';

    protected $description = 'Avisa en la campana de los pagos al exterior por vencer y vencidos';

    public function handle(PlanDePagos $plan): int
    {
        $plan->asegurarPlanes();
        $n = $plan->avisar();

        $this->info($n === 0 ? 'Ningún pago por avisar.' : "Se avisó de {$n} pago(s).");

        return self::SUCCESS;
    }
}
