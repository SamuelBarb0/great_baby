<?php

namespace App\Modules\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la entrega de cargo: una función, un pendiente, una carpeta.
 *
 * Todos los bloques tienen la misma forma —un nombre y un detalle— así que
 * comparten tabla. Lo que cambia es qué significa el detalle, y eso lo dice
 * `EntregaCargo::BLOQUES`.
 */
class EntregaCargoItem extends Model
{
    protected $table = 'rrhh_entrega_cargo_items';

    protected $guarded = ['id'];

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(EntregaCargo::class, 'entrega_id');
    }
}
