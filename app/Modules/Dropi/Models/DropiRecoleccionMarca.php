<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia del corte que el alistador ya recogió (el «chulito» del modo
 * Recolección). `cantidad` es el total que pedía el corte al marcarla.
 */
class DropiRecoleccionMarca extends Model
{
    protected $table = 'dropi_recoleccion_marcas';

    protected $fillable = ['corte_id', 'lote_impresion_id', 'sku_dropi', 'cantidad', 'marcado_por', 'marcado_at'];

    protected $casts = [
        'cantidad' => 'integer',
        'marcado_at' => 'datetime',
    ];

    public function corte(): BelongsTo
    {
        return $this->belongsTo(DropiCorte::class, 'corte_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marcado_por');
    }
}
