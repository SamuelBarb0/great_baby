<?php

namespace App\Modules\Marketing\Observers;

use App\Modules\Compras\Models\Importacion;
use App\Modules\Marketing\Services\TableroLanzamiento;

/**
 * «Cuando Compras confirma un nuevo contenedor, se crean las siguientes
 * tarjetas» (TABLERO GREAT BABY.pdf): cada importación nueva nace con su
 * tablero de lanzamiento.
 */
class ImportacionObserver
{
    public function __construct(private TableroLanzamiento $tablero) {}

    public function created(Importacion $importacion): void
    {
        $this->tablero->crearPara($importacion, auth()->user());
    }
}
