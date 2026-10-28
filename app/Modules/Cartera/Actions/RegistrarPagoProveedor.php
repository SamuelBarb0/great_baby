<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Services\CalculadorRetenciones;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Sprint 4 · B.3+ · Registra un pago a proveedor calculando y aplicando
 * las retenciones tributarias (retefuente/reteica/reteiva) automáticamente.
 *
 * Flujo:
 *   1. Calcula retenciones con CalculadorRetenciones
 *   2. Crea PagoProveedor con monto_neto = bruto - retenciones
 *   3. Persiste retenciones_aplicadas polimórficas
 */
class RegistrarPagoProveedor
{
    use AsAction;

    public function __construct(protected CalculadorRetenciones $calc)
    {
    }

    public function handle(array $data): PagoProveedor
    {
        return DB::transaction(function () use ($data) {
            $bruto = (float) $data['monto_bruto'];
            $iva = (float) ($data['iva'] ?? 0);
            $base = $bruto - $iva; // base gravable = bruto sin IVA

            // QA-FIX #9 · leer el flag del propio contacto (fuente de verdad) y no del form.
            $contacto = \App\Models\Contacto::find($data['contacto_id']);
            $esAutoRet = (bool) ($contacto?->es_autorretenedor ?? false);

            $retenciones = $this->calc->calcular(
                base: $base,
                concepto: $data['concepto_retencion'] ?? 'compras_generales',
                ciudad: $data['ciudad'] ?? null,
                iva: $iva,
                granContribuyente: (bool) ($data['gran_contribuyente'] ?? false),
                esAutorretenedor: $esAutoRet,
            );

            $totalRet = array_sum(array_column($retenciones, 'valor'));
            $neto = round($bruto - $totalRet, 2);

            $pago = PagoProveedor::create([
                'fecha' => $data['fecha'],
                'contacto_id' => $data['contacto_id'],
                'orden_compra_id' => $data['orden_compra_id'] ?? null,
                'recepcion_id' => $data['recepcion_id'] ?? null,
                'monto_bruto' => $bruto,
                'iva' => $iva,
                'monto_retenciones' => $totalRet,
                'monto_neto' => $neto,
                'concepto_retencion' => $data['concepto_retencion'] ?? 'compras_generales',
                'ciudad' => $data['ciudad'] ?? null,
                'gran_contribuyente' => (bool) ($data['gran_contribuyente'] ?? false),
                'metodo' => $data['metodo'] ?? 'transferencia',
                /*
                 * De qué cuenta sale la plata. Silvia la configura en Reglas.
                 *
                 * Decía `contabilidad.cta_bancos_default`, y ese grupo no
                 * existe: todas las reglas contables viven bajo `contable.`.
                 * Como la clave no estaba registrada, `setting()` devolvía
                 * siempre el literal '1110' y Silvia no tenía dónde cambiarla
                 * —el arreglo que decía este comentario nunca llegó a estar
                 * conectado—.
                 */
                'cuenta_puc_egreso' => $data['cuenta_puc_egreso']
                    ?? setting('contable.cta_bancos_default'),
                'observaciones' => $data['observaciones'] ?? null,
                'user_id' => $data['user_id'] ?? auth()->id(),
            ]);

            $this->calc->aplicar($pago, $retenciones);

            return $pago->load('retenciones');
        });
    }
}
