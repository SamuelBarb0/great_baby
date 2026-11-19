<?php

namespace App\Modules\Contabilidad\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuántos billetes de cada valor había en el cajón.
 *
 * Se guarda el desglose y no sólo el total para que un conteo mal sumado se
 * pueda revisar: «62 de cincuenta mil» es verificable, «$4.825.000» no.
 */
class ArqueoDenominacion extends Model
{
    protected $table = 'arqueo_denominaciones';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'denominacion' => 'integer',
        'cantidad' => 'integer',
    ];

    public function arqueo(): BelongsTo
    {
        return $this->belongsTo(Arqueo::class);
    }

    public function subtotal(): int
    {
        return $this->denominacion * $this->cantidad;
    }
}
