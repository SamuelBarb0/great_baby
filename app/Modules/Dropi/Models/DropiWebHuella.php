<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo último que el sync vio de un pedido en la web de Dropi: su estatus tal
 * cual (PENDIENTE, GUIA_GENERADA…) y si la guía ya se descargó allá. Lo escribe
 * SincronizarDropiWeb; aquí sólo se lee (filtros de «Pedidos Dropi»).
 */
class DropiWebHuella extends Model
{
    protected $table = 'dropi_web_huellas';

    protected $primaryKey = 'dropi_orden_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'guia_descargada' => 'boolean',
        'etiquetas' => 'array',
        'visto_at' => 'datetime',
        'sincronizado_at' => 'datetime',
    ];
}
