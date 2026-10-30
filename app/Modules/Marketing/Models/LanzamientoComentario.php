<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentario de una tarjeta. Los movimientos, bloqueos y aprobaciones también
 * quedan aquí (con su `tipo`), así la tarjeta cuenta su propia historia.
 */
class LanzamientoComentario extends Model
{
    protected $table = 'marketing_lanzamiento_comentarios';

    protected $fillable = ['tarjeta_id', 'user_id', 'tipo', 'cuerpo'];

    public function tarjeta(): BelongsTo { return $this->belongsTo(LanzamientoTarjeta::class, 'tarjeta_id'); }
    public function autor(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
