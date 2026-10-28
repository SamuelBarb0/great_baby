<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class AprobarOrdenCompra
{
    use AsAction;

    public function handle(OrdenCompra $orden): OrdenCompra
    {
        if (! in_array($orden->estado, [EstadoOrdenCompra::Borrador, EstadoOrdenCompra::Enviada], true)) {
            throw new InvalidArgumentException('Sólo se aprueban OC en borrador o enviadas.');
        }
        if ($orden->items()->count() === 0) {
            throw new InvalidArgumentException('La OC no tiene ítems.');
        }
        // Re-audit M2 R3 PATRÓN R (SEG-A1) · Segregación de Funciones — quien
        //   crea NO aprueba, salvo rol Aracely/Gerencia (dueño real del negocio).
        //   Excepción configurable vía `setting('compras.permitir_auto_aprobar', false)`
        //   por si en operación pequeña Aracely opera sola.
        $u = auth()->user();
        $mismoUsuario = $orden->creado_por !== null && $u && $orden->creado_por === $u->id;
        $esGerencia = $u && ($u->hasRole('Aracely') || $u->hasRole('Gerencia') || $u->hasRole('Gerente'));
        /*
         * El default decia `true` mientras el comentario de arriba decia
         * `false`, asi que `! $permitirAuto` era SIEMPRE falso: el control
         * existia con su mensaje de 403 y NUNCA se disparaba. Y la clave no
         * estaba registrada en `Reglas::DEFAULTS`, asi que tampoco se podia
         * arreglar desde la interfaz. Ahora es `false` y esta registrada.
         */
        $permitirAuto = (bool) (function_exists('setting') ? setting('compras.permitir_auto_aprobar', false) : false);
        if ($mismoUsuario && ! $esGerencia && ! $permitirAuto) {
            abort(403, 'Segregación de funciones: quien crea una OC no puede aprobarla. '
                .'Pedile la aprobación a Gerencia, o desactivá «Permitir que quien crea una OC la apruebe» '
                .'en Reglas de negocio → Contable.');
        }
        // Re-audit SEG-B5 · exigir total > 0 (no aprobar OC con precios 0).
        if ((float) $orden->total <= 0) {
            throw new InvalidArgumentException('La OC tiene total = 0. Revisa precios de los ítems antes de aprobar.');
        }

        $orden->estado = EstadoOrdenCompra::Aprobada;
        $orden->aprobado_por = auth()->id();
        $orden->aprobado_at = now();
        $orden->save();

        // Re-audit M2 R3 PATRÓN R (SEG-M2) · audit log de aprobación.
        \Illuminate\Support\Facades\Log::channel(
            array_key_exists('audit', config('logging.channels') ?? []) ? 'audit' : 'stack'
        )->info('compras.oc.aprobar', [
            'user_id' => auth()->id(),
            'orden_id' => $orden->id,
            'numero' => $orden->numero,
            'proveedor_id' => $orden->proveedor_id,
            'total' => (float) $orden->total,
            'creado_por' => $orden->creado_por,
        ]);

        // Sprint 4 · A.6 · WhatsApp cadena valor · via cola async (QA-FIX #5).
        \App\Modules\Notificaciones\Jobs\NotificarCadenaValorJob::dispatch('oc-aprobada', $orden->id);

        return $orden;
    }
}
