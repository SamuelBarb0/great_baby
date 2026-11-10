<?php

namespace App\Modules\Contabilidad\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conciliación bancaria de una cuenta en un período.
 *
 * Nació guardando sólo saldos: el del extracto, el del sistema y la resta de
 * los dos. Eso dice CUÁNTO sobra; no dice qué. Desde noviembre guarda también
 * las líneas del extracto (ver `lineas`), que es donde está el trabajo real.
 *
 * Se exporta a SIIGO como asiento en modo borrador, para que contabilidad
 * ajuste allá antes de que quede en firme.
 */
class ConciliacionBancaria extends Model
{
    protected $table = 'conciliaciones_bancarias';

    protected $fillable = [
        'fecha', 'periodo_desde', 'periodo_hasta', 'banco', 'cuenta_numero', 'cuenta_puc',
        'saldo_anterior', 'total_abonos', 'total_cargos',
        'saldo_extracto', 'saldo_sistema', 'diferencia',
        'estado', 'notas', 'adjuntos',
        'siigo_id', 'siigo_borrador', 'siigo_exportado_at',
        'user_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'periodo_desde' => 'date',
        'periodo_hasta' => 'date',
        'saldo_anterior' => 'decimal:2',
        'total_abonos' => 'decimal:2',
        'total_cargos' => 'decimal:2',
        'saldo_extracto' => 'decimal:2',
        'saldo_sistema' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'adjuntos' => 'array',
        'siigo_borrador' => 'bool',
        'siigo_exportado_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ConciliacionBancaria $c) {
            $c->diferencia = round((float) $c->saldo_extracto - (float) $c->saldo_sistema, 2);
        });
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ConciliacionLinea::class, 'conciliacion_id');
    }

    /**
     * El extracto comprobado contra sí mismo.
     *
     * Si saldo anterior + abonos − cargos no da el saldo actual, el extracto se
     * leyó mal y la conciliación no vale: antes de buscar diferencias contra el
     * ERP hay que estar seguro de haber leído bien el banco.
     */
    public function cuadraConsigoMisma(): bool
    {
        $esperado = (float) $this->saldo_anterior + (float) $this->total_abonos - (float) $this->total_cargos;

        return abs($esperado - (float) $this->saldo_extracto) < 0.01;
    }

    /** Lo que sigue sin explicación: ni casó con un pago, ni tiene cuenta. */
    public function sinExplicar(): int
    {
        return $this->lineas()
            ->where('estado', '!=', 'conciliada')
            ->where(fn ($q) => $q->whereNull('cuenta_puc')->orWhere('cuenta_puc', ''))
            ->count();
    }
}
