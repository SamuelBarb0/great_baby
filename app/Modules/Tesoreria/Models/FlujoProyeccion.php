<?php

namespace App\Modules\Tesoreria\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que un área proyecta para un concepto en un período.
 *
 * `periodo` es el LUNES de la semana o el día 1 del mes, nunca un rango:
 * guardar «del 5 al 11» obliga a comparar fechas en cada consulta.
 *
 * `real_manual` existe para los conceptos que no tienen origen calculable.
 * No es un atajo: hay plata que entra o sale por fuera del ERP —un préstamo
 * de un socio, un sobregiro— y negarse a registrarla la dejaría invisible.
 */
class FlujoProyeccion extends Model
{
    protected $table = 'flujo_proyecciones';

    protected $guarded = ['id'];

    protected $casts = [
        'periodo' => 'date',
        'monto' => 'decimal:2',
        'real_manual' => 'decimal:2',
    ];

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(FlujoConcepto::class, 'concepto_id');
    }

    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturado_por');
    }
}
