<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\ProgramacionPago;
use App\Support\PasoNoPermitido;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Anula una programación que todavía no se ejecutó. Vivía en
 * `ProgramacionPagosController::anular`; se sacó (7-nov) para el panel de Dropi.
 */
class AnularProgramacionPago
{
    use AsAction;

    public function handle(ProgramacionPago $p, string $motivo): ProgramacionPago
    {
        if ($p->estado === 'ejecutada') {
            throw new PasoNoPermitido('Esa programación ya se ejecutó: los pagos están hechos.');
        }

        $p->update([
            'estado' => 'anulada',
            'notas' => trim(($p->notas ? $p->notas."\n" : '').'ANULADA: '.$motivo),
        ]);

        return $p;
    }
}
