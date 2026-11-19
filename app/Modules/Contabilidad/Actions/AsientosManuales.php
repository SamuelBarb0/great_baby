<?php

namespace App\Modules\Contabilidad\Actions;

use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Siigo\Jobs\PushAsientoManualASiigo;
use App\Support\PasoNoPermitido;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 4 · B.4 · El ciclo de un asiento manual: crearlo cuadrado (escribe el
 * libro), aprobarlo (va a SIIGO) y anularlo con firma.
 *
 * Vivía en AsientosManualesController; se sacó (7-nov) para que el panel de
 * Dropi lo haga con las mismas reglas. El asiento lleva su línea de negocio y
 * sus líneas en el libro la heredan.
 */
final class AsientosManuales
{
    /**
     * @param array{fecha:string, glosa:string, lineas:array<int, array{cuenta_puc:string,
     *     tercero_documento?:?string, debe?:?float, haber?:?float, descripcion:string}>} $data
     */
    public static function crear(array $data, User $quien, LineaNegocio $linea = LineaNegocio::GreatBaby): AsientoManual
    {
        $totalDebe = 0;
        $totalHaber = 0;
        foreach ($data['lineas'] as $l) {
            $d = (float) ($l['debe'] ?? 0);
            $h = (float) ($l['haber'] ?? 0);
            if (($d > 0 && $h > 0) || ($d == 0 && $h == 0)) {
                throw new PasoNoPermitido('Cada línea debe tener solo débito O crédito (no ambos, no ninguno).');
            }
            $totalDebe += $d;
            $totalHaber += $h;
        }
        if (abs($totalDebe - $totalHaber) >= 0.01) {
            throw new PasoNoPermitido('Débito ($' . number_format($totalDebe, 2) . ') ≠ Crédito ($' . number_format($totalHaber, 2) . '). No cuadra.');
        }

        return DB::transaction(function () use ($data, $quien, $totalDebe, $linea) {
            $a = AsientoManual::create([
                'fecha' => $data['fecha'],
                'glosa' => $data['glosa'],
                'linea_negocio' => $linea,
                'valor_total' => $totalDebe,
                'estado' => 'cuadrado',
                'user_id' => $quien->id,
            ]);
            foreach ($data['lineas'] as $i => $l) {
                $a->lineas()->create([
                    'cuenta_puc' => $l['cuenta_puc'],
                    'tercero_documento' => $l['tercero_documento'] ?? null,
                    'debe' => (float) ($l['debe'] ?? 0),
                    'haber' => (float) ($l['haber'] ?? 0),
                    'descripcion' => $l['descripcion'],
                    'orden' => $i,
                ]);
            }

            // QA-FIX #1 · los movimientos planos para que el mayor, el balance
            // y el estado de resultados vean este asiento.
            $movLineas = [];
            foreach ($data['lineas'] as $l) {
                $movLineas[] = [
                    'fecha' => $data['fecha'],
                    'cuenta_puc' => $l['cuenta_puc'],
                    'debe' => (float) ($l['debe'] ?? 0),
                    'haber' => (float) ($l['haber'] ?? 0),
                    'descripcion' => $a->glosa . ' · ' . $l['descripcion'],
                    'origen_type' => AsientoManual::class,
                    'origen_id' => $a->id,
                    'user_id' => $quien->id,
                ];
            }
            MovimientoContable::registrarAsientoAtomico($movLineas);

            return $a;
        });
    }

    /**
     * ¿Este usuario puede aprobar ESTE asiento? Nadie aprueba el suyo salvo
     * gerencia, o si la empresa habilitó la autoaprobación.
     */
    public static function puedeAprobar(?User $u, AsientoManual $a): bool
    {
        if (! $u || $a->estado !== 'cuadrado') {
            return false;
        }
        if ($a->user_id !== $u->id) {
            return true;
        }

        return $u->esRoot() || $u->hasRole('Gerente')
            || (bool) setting('contabilidad.permitir_auto_aprobar_asiento');
    }

    public static function aprobar(AsientoManual $a, User $quien): AsientoManual
    {
        if ($a->estado !== 'cuadrado') {
            throw new PasoNoPermitido('Solo se pueden aprobar asientos cuadrados.');
        }

        // QA-FIX #3 · Segregación de funciones: quien crea NO aprueba, salvo
        // gerencia o la excepción configurable para operación pequeña.
        $esGerencia = $quien->esRoot() || $quien->hasRole('Gerente');
        $permitirAuto = (bool) (function_exists('setting') ? setting('contabilidad.permitir_auto_aprobar_asiento', false) : false);
        if ($a->user_id === $quien->id && ! $esGerencia && ! $permitirAuto) {
            abort(403, 'Segregación de funciones: quien crea un asiento no puede aprobarlo. '
                .'Pedile la aprobación a Gerencia, o desactivá «Permitir que quien crea un asiento manual '
                .'lo apruebe» en Reglas de negocio → Contable.');
        }

        $a->update(['estado' => 'aprobado']);
        // A2 FIX · afterCommit evita job huérfano si una transacción externa rollbackea.
        DB::afterCommit(fn () => PushAsientoManualASiigo::dispatch($a->id));

        return $a;
    }

    /**
     * Anular con firma: el asiento queda marcado con su motivo y sus líneas
     * salen del balance (borrado suave). Lo que ya viajó a SIIGO no se toca acá.
     */
    public static function anular(AsientoManual $a, ?User $quien, string $motivo): AsientoManual
    {
        if ($a->anulado_at) {
            throw new PasoNoPermitido("El asiento #{$a->id} ya estaba anulado.");
        }
        if ($a->estado === 'sincronizado' || $a->siigo_journal_id) {
            throw new PasoNoPermitido("El asiento #{$a->id} ya está en SIIGO. "
                .'Anularlo sólo acá dejaría los dos libros distintos: hay que registrar un asiento contrario.');
        }

        DB::transaction(function () use ($a, $quien, $motivo) {
            MovimientoContable::query()
                ->where('origen_type', AsientoManual::class)
                ->where('origen_id', $a->id)
                ->delete();

            $a->update([
                'estado' => 'anulado',
                'anulado_at' => now(),
                'anulado_por' => $quien?->id,
                'motivo_anulacion' => $motivo,
            ]);
        });

        return $a;
    }
}
