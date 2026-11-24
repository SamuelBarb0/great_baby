<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DropiAlistadorLock extends Model
{
    protected $table = 'dropi_alistador_locks';

    public $timestamps = false;

    protected $fillable = ['pedido_id', 'alistador_id', 'locked_at', 'heartbeat_at'];

    protected $casts = [
        'locked_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    /**
     * Las horas las pone PHP, no el DEFAULT CURRENT_TIMESTAMP: en producción
     * MySQL corre en UTC y la app en Bogotá (24-nov-2026). Con la hora de MySQL,
     * LiberarLocksAlistador veía el candado 5 h «en el futuro».
     */
    protected static function booted(): void
    {
        static::creating(function (self $l) {
            $l->locked_at ??= now();
            $l->heartbeat_at ??= now();
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_id');
    }

    public function alistador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alistador_id');
    }
}
