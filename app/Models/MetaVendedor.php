<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contra qué se mide un vendedor en un mes.
 *
 * Va por mes y no una sola por vendedor porque la meta cambia con la
 * temporada —diciembre no se parece a febrero— y porque hay que poder mirar
 * atrás y ver contra qué se medía en su momento: una meta que se sobreescribe
 * borra la historia del cumplimiento.
 */
class MetaVendedor extends Model
{
    protected $table = 'metas_vendedor';

    protected $guarded = ['id'];

    protected $casts = [
        'anio' => 'integer',
        'mes' => 'integer',
        'monto' => 'decimal:2',
        'pedidos' => 'integer',
    ];

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeDelMes($q, int $anio, int $mes)
    {
        return $q->where('anio', $anio)->where('mes', $mes);
    }

    public static function para(int $userId, int $anio, int $mes): ?self
    {
        return static::where('user_id', $userId)->delMes($anio, $mes)->first();
    }
}
