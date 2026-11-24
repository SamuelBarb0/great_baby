<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DropiEstadoBitacora extends Model
{
    protected $table = 'dropi_estados_bitacora';

    public $timestamps = false;

    protected $fillable = [
        'pedido_id', 'estado_desde', 'estado_hasta',
        'fuente', 'payload', 'user_id',
        'lat', 'lng', 'gps_precision_m', 'gps_estado', 'ip', 'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * La hora la pone PHP, no el DEFAULT CURRENT_TIMESTAMP de la columna: en
     * producción MySQL corre en UTC y la app en Bogotá, y la bitácora quedaba
     * 5 h adelantada frente al resto del ERP (24-nov-2026).
     */
    protected static function booted(): void
    {
        static::creating(function (self $b) {
            $b->created_at ??= now();
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
