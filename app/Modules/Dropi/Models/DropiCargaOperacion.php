<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una operación de una carga a Dropi: crear un producto o mover el stock de
 * las variaciones de uno que ya existe. Ver EjecutarOperacionDropi.
 */
class DropiCargaOperacion extends Model
{
    public const ACTUALIZAR = 'actualizar';
    public const CREAR = 'crear';

    public const REVISAR = 'revisar';
    public const LISTO = 'listo';
    public const OMITIDO = 'omitido';
    public const OK = 'ok';
    public const ERROR = 'error';

    protected $table = 'dropi_carga_operaciones';

    protected $guarded = ['id'];

    protected $casts = [
        'detalle' => 'array',
        'candidato' => 'array',
        'cantidad_excel' => 'float',
        'procesado_at' => 'datetime',
    ];

    public function carga(): BelongsTo
    {
        return $this->belongsTo(DropiCargaProducto::class, 'carga_id');
    }

    public function pendiente(): bool
    {
        return $this->estado === self::LISTO;
    }
}
