<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Agrega un gasto (flete, agencia, arancel…) a un contenedor, con su soporte.
 *
 * Vivía en ComprasGestionController::importacionGastoAgregar; se sacó (8-nov)
 * para que el panel de Dropi lo haga con las mismas reglas. Los datos llegan
 * ya validados por quien llama.
 */
class AgregarGastoImportacion
{
    use AsAction;

    /**
     * @param array{concepto:string, descripcion?:?string, moneda:string, monto:float, capitalizable?:bool,
     *     metodo_prorrateo:string, factura_proveedor?:?string, fecha:string, proveedor_id?:?int} $data
     */
    public function handle(Importacion $imp, array $data, ?UploadedFile $comprobante = null): GastoImportacion
    {
        // El formulario la deja opcional y la columna no: en blanco daba un 500.
        $data['descripcion'] = ($data['descripcion'] ?? null) ?: ConceptoGastoImportacion::from($data['concepto'])->label();
        abort_if(
            $imp->estado === EstadoImportacion::Liquidada,
            422,
            'La importación ya está liquidada. Requiere reversar liquidación (a definir) para agregar gastos.'
        );
        // Re-audit M2 R3 PATRÓN R (SEG-B2) · si moneda ≠ COP y no hay TRM
        // definida, ABORTA — antes se usaba 1 silenciosamente y USD 1200
        // entraba al asiento como COP 1200 (subvaluación grave del costo).
        if ($data['moneda'] !== 'COP' && ((float) $imp->tasa_cambio_liquidacion) <= 0) {
            abort(422, "Falta definir la tasa de cambio de la importación (moneda={$data['moneda']}). Edítala y agrega TRM antes de agregar gastos en moneda extranjera.");
        }
        // Un gasto en pesos ya está en pesos: multiplicarlo por la tasa convertía
        // un flete de 5 millones en 2.600 millones. Y el contenedor sólo conoce la
        // tasa de su moneda: un gasto en otra no tiene con qué convertirse.
        if ($data['moneda'] !== 'COP' && $data['moneda'] !== $imp->moneda_origen) {
            throw ValidationException::withMessages(['moneda' => "Este contenedor sólo tiene tasa de {$imp->moneda_origen}: registre el gasto en COP o en {$imp->moneda_origen}."]);
        }
        $data['monto_base'] = $data['moneda'] === 'COP'
            ? (float) $data['monto']
            : $data['monto'] * (float) $imp->tasa_cambio_liquidacion;

        $gasto = $imp->gastos()->create($data);
        if ($comprobante) {
            GuardarDocumentoImportacion::run($imp, $comprobante, 'comprobante_gasto', $gasto);
        }

        return $gasto;
    }
}
