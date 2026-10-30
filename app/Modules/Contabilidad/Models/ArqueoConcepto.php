<?php

namespace App\Modules\Contabilidad\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que explica el descuadre de un arqueo.
 *
 * En el formato en papel son renglones sueltos debajo del conteo —«ICA
 * Janderson $189.000», «vueltos dados en pesos $40.800»—. Viven en su propia
 * tabla, y no en el campo de observaciones, para poder exigir que sumen
 * exactamente la diferencia.
 */
class ArqueoConcepto extends Model
{
    protected $table = 'arqueo_conceptos';

    protected $guarded = ['id'];

    protected $casts = ['valor' => 'decimal:2'];

    public function arqueo(): BelongsTo
    {
        return $this->belongsTo(Arqueo::class);
    }
}
