<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Anula una OC que todavía no se recibió. Vivía en
 * `ComprasGestionController::ocAnular`; se sacó (7-nov) para el panel de Dropi.
 */
class AnularOrdenCompra
{
    use AsAction;

    public function handle(OrdenCompra $o, string $motivo): OrdenCompra
    {
        // Re-audit M2 FUNC-C4 · agregado Anulada (idempotencia — evita re-anular).
        abort_if(in_array($o->estado, [EstadoOrdenCompra::Recibida, EstadoOrdenCompra::Cerrada, EstadoOrdenCompra::Anulada]), 422, 'OC ya recibida/cerrada/anulada.');
        // Re-audit M2 PATRÓN B (DATOS-C2) · asignación DIRECTA: con mass-assign
        // `estado` se descartaba y la OC seguía viva.
        $o->estado = EstadoOrdenCompra::Anulada;
        $o->anulado_at = now();
        $o->anulado_por = auth()->id();
        $o->motivo_anulacion = $motivo;
        $o->observaciones = trim(($o->observaciones ?? '') . "\n[ANULADA " . now()->toDateString() . ' por ' . auth()->user()?->name . '] ' . $motivo);
        $o->save();
        // Re-audit M2 R3 PATRÓN R (SEG-M2) · array_key_exists, no el truthy de config().
        Log::channel(
            array_key_exists('audit', config('logging.channels') ?? []) ? 'audit' : 'stack'
        )->info('compras.oc.anular', [
            'user_id' => auth()->id(), 'orden_id' => $o->id, 'numero' => $o->numero,
            'total' => (float) $o->total, 'motivo' => $motivo,
        ]);

        return $o;
    }
}
