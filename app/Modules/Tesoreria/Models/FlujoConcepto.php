<?php

namespace App\Modules\Tesoreria\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un renglón del flujo de caja.
 *
 * Los grupos son los del Excel que ya usan, en el mismo orden, porque quien
 * lo llena hoy tiene que reconocer la pantalla.
 *
 * `origen` es lo único nuevo de fondo: dice de dónde sale la columna «Real».
 * Sin origen se teclea, como hoy. Con origen lo calcula el ERP — y ahí está
 * la diferencia entre transcribir cifras cada semana y verlas solas.
 */
class FlujoConcepto extends Model
{
    protected $table = 'flujo_conceptos';

    protected $guarded = ['id'];

    protected $casts = [
        'valor_fijo_mensual' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /** En el orden en que aparecen en la hoja. */
    public const GRUPOS = [
        'saldo_inicial' => 'Saldo inicial de la caja',
        'ingreso_operacional' => 'Ingresos operacionales',
        'otro_ingreso' => 'Otros ingresos',
        'egreso_operacional' => 'Egresos operacionales',
        'otro_egreso' => 'Otros egresos',
    ];

    /** De dónde puede salir el real, calculado. */
    public const ORIGENES = [
        'recaudo_cartera' => 'Pagos recibidos de clientes',
        'pagos_proveedor' => 'Pagos a proveedores',
        'nomina' => 'Nóminas pagadas',
        'gastos_caja' => 'Gastos operativos pagados',
        'movimientos_caja' => 'Movimientos de caja',
    ];

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function proyecciones(): HasMany
    {
        return $this->hasMany(FlujoProyeccion::class, 'concepto_id');
    }

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true);
    }

    /** Los egresos restan: el signo lo decide el grupo, no quien teclea. */
    public function esEgreso(): bool
    {
        return str_starts_with($this->grupo, 'egreso') || str_starts_with($this->grupo, 'otro_egreso');
    }

    public function etiquetaGrupo(): string
    {
        return self::GRUPOS[$this->grupo] ?? $this->grupo;
    }
}
