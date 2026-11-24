<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Models\DropiCorte;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * «Cerrar corte» (24-nov-2026: «desde cerrar corte poder hacer todo el proceso
 * de empacado»): la hora de corte de la bodega. El corte deja de recibir pedidos
 * —los nuevos de Dropi van al siguiente corte (AsignarCorteACarga)— y los suyos
 * se siguen recolectando, empacando y despachando.
 *
 * No congela nada: el hash, el manifiesto y las remisiones ARI siguen siendo de
 * CerrarCorte («Finalizar corte»), cuando todo esté empacado.
 */
class CortarEntradaCorte
{
    use AsAction;

    public function handle(int $corteId, int $userId): DropiCorte
    {
        return DB::transaction(function () use ($corteId, $userId) {
            $corte = DropiCorte::lockForUpdate()->findOrFail($corteId);

            if (EstadoCorte::de($corte->estado) !== EstadoCorte::Abierto) {
                throw new RuntimeException("El corte {$corte->etiqueta()} ya está cerrado ({$corte->estado->label()}).");
            }

            // $guarded: estado y cortado_* sólo se escriben aquí, por propiedad.
            $corte->estado = EstadoCorte::EnDespacho;
            $corte->cortado_at = now();
            $corte->cortado_por = $userId;
            $corte->save();

            return $corte;
        });
    }
}
