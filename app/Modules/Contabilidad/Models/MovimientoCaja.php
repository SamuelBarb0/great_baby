<?php

namespace App\Modules\Contabilidad\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Una entrada o una salida de la caja, del día.
 *
 * Es el renglón que hoy escriben a mano en el Excel: «Cancelación de
 * domicilios · Olinto · $395.000». `entregado_a` no es un adorno — sin eso un
 * gasto de caja no se le puede cobrar a nadie ni pedirle el soporte después.
 *
 * Se borra suave porque un movimiento ya contado en un arqueo cerrado es
 * historia: quitarlo de la vista no puede borrar el rastro de que estuvo.
 */
class MovimientoCaja extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'movimientos_caja';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
    ];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function arqueo(): BelongsTo
    {
        return $this->belongsTo(Arqueo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** La otra punta, cuando este movimiento es un traslado entre cajas. */
    public function contraparte(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contraparte_id');
    }

    public function esTraslado(): bool
    {
        return $this->contraparte_id !== null;
    }

    /** Los del día que todavía no entraron a un arqueo cerrado. */
    public function scopeSueltos(Builder $q): Builder
    {
        return $q->whereNull('arqueo_id');
    }

    public function scopeDelDia(Builder $q, int $cajaId, string $fecha): Builder
    {
        return $q->where('caja_id', $cajaId)->whereDate('fecha', $fecha);
    }

    /** Lo que suma al saldo: un gasto resta. */
    public function efecto(): float
    {
        return $this->tipo === 'ingreso' ? (float) $this->valor : -(float) $this->valor;
    }
}
