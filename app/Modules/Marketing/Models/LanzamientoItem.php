<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un paso del checklist de una tarjeta: quién lo marcó y cuándo. */
class LanzamientoItem extends Model
{
    protected $table = 'marketing_lanzamiento_items';

    protected $fillable = ['tarjeta_id', 'texto', 'grupo', 'posicion', 'hecho_por', 'hecho_en'];

    protected $casts = [
        'hecho_en' => 'datetime',
    ];

    public function tarjeta(): BelongsTo { return $this->belongsTo(LanzamientoTarjeta::class, 'tarjeta_id'); }
    public function autor(): BelongsTo { return $this->belongsTo(User::class, 'hecho_por'); }
}
