<?php

namespace App\Modules\Cartera\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 4 · B.3 · Regla parametrizable de retención.
 */
class RetencionConfig extends Model
{
    protected $table = 'retenciones_config';
    protected $fillable = [
        'tipo', 'concepto', 'ciudad', 'base_minima', 'tarifa_pct',
        'cuenta_puc', 'siigo_tax_id', 'activa', 'notas',
        /*
         * Sin estas dos, una regla NUEVA nacia inservible y en silencio:
         * `create()` las descartaba por no estar aca, quedaban NULL, y
         * `RegistrarAsientoContable` filtra por `ambito = ventas` Y
         * `whereNotNull('cuenta_puc_contrapartida')` — asi que la
         * autorretencion no se practicaba en ninguna factura. La pantalla
         * decia «Regla guardada» y la listaba activa.
         *
         * Peor: al EDITARLA el controlador usa query builder, que ignora
         * $fillable y si las escribe. O sea que empezaba a funcionar sola
         * despues de la primera edicion, lo que hacia el sintoma imposible
         * de reproducir a la primera.
         */
        'ambito', 'cuenta_puc_contrapartida',
    ];
    protected $casts = [
        'base_minima' => 'decimal:2',
        'tarifa_pct' => 'decimal:3',
        'activa' => 'boolean',
    ];
}
