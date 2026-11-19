<?php

namespace App\Modules\Compras\Actions;

use App\Models\User;
use App\Modules\Compras\Models\ProgramacionPago;
use App\Support\PasoNoPermitido;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Autorizar el desembolso de una programación: queda quién, cuándo y por
 * cuánto. Vivía en `ProgramacionPagosController::autorizar`; se sacó (7-nov)
 * para el panel de Dropi.
 */
class AutorizarProgramacionPago
{
    use AsAction;

    /**
     * Quién suelta la plata. Armar la lista es trabajo de contabilidad;
     * autorizar el desembolso es decisión de gerencia.
     */
    public static function puedeAutorizar(?User $u): bool
    {
        return (bool) ($u && ($u->esRoot() || $u->hasRole('Gerente')));
    }

    public function handle(ProgramacionPago $p, User $quien): ProgramacionPago
    {
        abort_unless(self::puedeAutorizar($quien), 403, 'Autorizar el desembolso es de gerencia.');

        if ($p->estaAutorizada()) {
            throw new PasoNoPermitido("La programación {$p->numero} ya estaba autorizada.");
        }

        // Quien la armó no la autoriza: si fuera el mismo, la firma no diría nada.
        if ($p->creada_por === $quien->id && ! $quien->esRoot()) {
            throw new PasoNoPermitido('Quien arma la programación no la autoriza. Pedile el visto bueno a gerencia.');
        }

        $p->update([
            'estado' => 'autorizada',
            'autorizada_por' => $quien->id,
            'autorizada_at' => now(),
        ]);

        return $p;
    }
}
