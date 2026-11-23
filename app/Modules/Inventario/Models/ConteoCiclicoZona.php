<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Por dónde va el conteo cíclico de cada zona.
 *
 * Una fila por zona del reparto (`logistica.zonas_bodega`), con el último
 * estante que se mandó a contar. El comando `inventario:conteo-del-dia`
 * arranca la tanda del día después de ése y, al llegar al final de la zona,
 * vuelve al principio sumando una vuelta.
 *
 * La zona es texto a propósito: es la clave del reparto que el área mueve desde
 * Reglas, no un catálogo. Hoy son «1», «2» y «3»; mañana pueden ser letras.
 */
class ConteoCiclicoZona extends Model
{
    protected $table = 'conteos_ciclicos_zonas';

    protected $fillable = [
        'zona', 'cursor_posicion_id', 'ultima_tanda_at',
        'ultima_vuelta_at', 'vueltas', 'posiciones_mandadas',
    ];

    protected $casts = [
        'ultima_tanda_at' => 'datetime',
        'ultima_vuelta_at' => 'datetime',
        'vueltas' => 'integer',
        'posiciones_mandadas' => 'integer',
    ];

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'cursor_posicion_id');
    }
}
