<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El lanzamiento de un contenedor: sus 10 tarjetas, de la recepción al
 * seguimiento. Termina cuando las diez están en Finalizado.
 */
class Lanzamiento extends Model
{
    protected $table = 'marketing_lanzamientos';

    protected $fillable = ['importacion_id', 'creado_por', 'finalizado_en'];

    protected $casts = [
        'finalizado_en' => 'datetime',
    ];

    public function importacion(): BelongsTo { return $this->belongsTo(Importacion::class); }
    public function creador(): BelongsTo { return $this->belongsTo(User::class, 'creado_por'); }
    public function tarjetas(): HasMany { return $this->hasMany(LanzamientoTarjeta::class)->orderBy('numero'); }
}
