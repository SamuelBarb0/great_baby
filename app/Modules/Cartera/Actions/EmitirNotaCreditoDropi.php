<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Dropi\Models\DropiDevolucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * REU-3 + Re-audit fixes: Auto-generar Nota Crédito al recibir devolución Dropi.
 *
 *   Raíz A (FUNC C5 / DATOS C5) · idempotencia atómica: usa UPDATE...WHERE...
 *     para bloquear la devolución antes de dispatch. Si affectedRows=0, otro
 *     observer ya la tomó — abort seguro. Antes había ventana check-then-act.
 *
 *   Raíz D (DATOS C2) · crea entrada local en `notas_credito` ANTES de despachar
 *     a SIIGO. Consecutivo `NC-####` transaccional via SiguienteConsecutivoFactura.
 *     Cumple exigencia DIAN de libro local. Ref para SIIGO es determinista
 *     (`numeroCompleto`), no `now()->format('YmdHis')` — retry del job NO
 *     genera otra NC.
 *
 * Reglas configurables:
 *   dropi.auto_nota_credito        → activar/desactivar auto-fire
 *   dropi.auto_nc_envia_siigo      → tras crear, enviar a SIIGO
 */
class EmitirNotaCreditoDropi
{
    use AsAction;

    public function handle(DropiDevolucion $devolucion): ?array
    {
        if (! (bool) setting('dropi.auto_nota_credito', true)) {
            if ($devolucion->genero_nota_credito) {
                $devolucion->update([
                    'genero_nota_credito' => false,
                    'notas' => trim(($devolucion->notas ?? '') . "\n[NC skip] regla dropi.auto_nota_credito=false"),
                ]);
            }
            return ['status' => 'skip_regla_off'];
        }

        $pedido = $devolucion->pedido;
        if (! $pedido) return null;

        // 5.3 · la factura del pedido: la del corte (`factura_venta_id`, la
        // escribe GenerarFacturasB2BDeCorte) o la vieja referencia ARI.
        $factura = $pedido->factura_venta_id
            ? \App\Modules\Cartera\Models\FacturaVenta::find($pedido->factura_venta_id)
            : ($pedido->ari_factura_id
                ? \App\Modules\Cartera\Models\FacturaVenta::where('ari_factura_id', $pedido->ari_factura_id)->first()
                : null);

        if (! $factura && ! $pedido->ari_factura_id) {
            $devolucion->update([
                'genero_nota_credito' => false,
                'notas' => trim(($devolucion->notas ?? '') . "\n[NC skip] pedido sin factura previa"),
            ]);
            return ['status' => 'skip_sin_factura'];
        }

        $valor = (float) $pedido->monto_esperado_proveedor;
        if ($valor <= 0) {
            $devolucion->update([
                'genero_nota_credito' => false,
                'notas' => trim(($devolucion->notas ?? '') . "\n[NC skip] pedido con monto=0"),
            ]);
            Log::info('[NC-Dropi] Devolución sin valor; skip', ['pedido' => $pedido->guia]);
            return null;
        }

        $devId = $devolucion->id;

        // Re-audit RAÍZ D (FUNC N3 / DATOS N3) · TODO el flujo (reserva + consecutivo +
        // NotaCredito::create + update ref) envuelto en UNA transacción. Si algo
        // revienta después de consumir el consecutivo, el rollback deshace TODO
        // — no se quema el folio DIAN.
        try {
            return DB::transaction(function () use ($devId, $devolucion, $pedido, $valor, $factura) {
                // Raíz A · reservación atómica del "slot" de NC.
                $reservado = DropiDevolucion::whereKey($devId)
                    ->where('genero_nota_credito', false)
                    ->update(['genero_nota_credito' => true]);

                if ($reservado === 0) {
                    $devolucion->refresh();
                    return ['status' => 'ya_generada', 'ref' => $devolucion->nota_credito_ari_id];
                }

                // Consecutivo transaccional + validación rango DIAN.
                // Re-audit R3-06 · uppercase + trim del prefijo desde el inicio
                // para que el guardado en `notas_credito.prefijo` coincida con
                // el que devuelve SiguienteConsecutivoFactura (que también
                // uppercasea internamente). Evita case-mismatch.
                $prefijo = strtoupper(trim((setting('empresa.prefijo_nc_dian') ?: 'NC') . '-'));
                $rangoNc = (int) setting('empresa.rango_hasta_nc', 0) ?: null;
                $numeroCompleto = SiguienteConsecutivoFactura::run($prefijo, 4, $rangoNc);
                $numeroSecuencial = (int) preg_replace('/\D/', '', substr($numeroCompleto, strlen($prefijo)));

                // Re-audit DATOS #4 · la NC cuelga de la factura local: así
                // `recalcular()` le baja el saldo y cartera deja de cobrarla.
                $facturaLocal = $factura?->id;

                $nc = NotaCredito::create([
                    'prefijo' => rtrim($prefijo, '-'),
                    'numero' => $numeroSecuencial,
                    'factura_id' => $facturaLocal,
                    'devolucion_dropi_id' => $devId,
                    'motivo' => "Devolución Dropi guía {$pedido->guia}",
                    'valor' => $valor,
                    'estado' => 'emitida',
                    'emitida_at' => now(),
                ]);

                DropiDevolucion::whereKey($devId)->update([
                    'nota_credito_ari_id' => $numeroCompleto,
                ]);

                // Dispatch a SIIGO SOLO tras commit (post-tx).
                DB::afterCommit(function () use ($devId, $numeroCompleto, $pedido, $valor, $nc) {
                    try {
                        // 5.3 · el envío real (el mismo de las NC manuales, con su
                        // kill-switch de SIIGO). Antes iba a EnviarNotaCreditoSiigo,
                        // que sólo escribía «(mock) enviando NC» en el log.
                        if ((bool) setting('dropi.auto_nc_envia_siigo', true)) {
                            \App\Modules\Siigo\Jobs\PushNotaCreditoASiigo::dispatch($nc->id);
                        }
                        Log::info('[NC-Dropi] NC local + dispatch SIIGO', [
                            'pedido' => $pedido->guia, 'valor' => $valor, 'ref' => $numeroCompleto, 'nc_id' => $nc->id,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('[NC-Dropi] Fallo dispatch post-commit', [
                            'pedido' => $pedido->guia, 'error' => $e->getMessage(),
                        ]);
                        DropiDevolucion::whereKey($devId)->update([
                            'notas' => DB::raw("CONCAT(COALESCE(notas,''), '\n[NC ERROR dispatch] " . addslashes($e->getMessage()) . "')"),
                        ]);
                    }
                });

                return ['status' => 'creada', 'ref' => $numeroCompleto, 'valor' => $valor, 'nc_id' => $nc->id];
            });
        } catch (\Throwable $e) {
            Log::error('[NC-Dropi] Fallo tx creación NC', [
                'pedido' => $pedido->guia, 'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
