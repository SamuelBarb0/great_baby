<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\Importacion;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Abre un contenedor. Vivía en ComprasGestionController::importacionCrear; se
 * sacó (8-nov) para que el panel de Dropi lo abra igual, en la línea Dropi.
 * Los datos llegan ya validados por quien llama.
 */
class CrearImportacion
{
    use AsAction;

    /**
     * @param array{contenedor?:?string, bl_awb?:?string, proveedor_pais?:?string, puerto_origen?:?string,
     *     puerto_destino?:?string, incoterm?:?string, moneda_origen:string, tasa_cambio_liquidacion?:?float,
     *     fecha_zarpe?:?string, eta?:?string} $data
     */
    public function handle(array $data, LineaNegocio $linea = LineaNegocio::GreatBaby): Importacion
    {
        // Re-audit M2 PATRÓN C + I · usa siguienteNumero() atómico del modelo
        // (con lockForUpdate + año en TZ Colombia). Antes: `count()+1` sin lock =
        // colisión concurrente; `whereYear('created_at')` sin TZ = 31-dic 20:00
        // Bogotá cruzaba al año siguiente en UTC → salto de secuencia.
        return Importacion::create([
            ...$data,
            'numero' => Importacion::siguienteNumero(),
            'linea_negocio' => $linea,
            'creado_por' => auth()->id(),
        ]);
    }
}
