<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La nómina de un mes.
 *
 * Tres estados y tres decisiones distintas, cada una de alguien distinto:
 * Gestión Humana LIQUIDA, gerencia APRUEBA y tesorería PAGA. Un solo botón de
 * «listo» haría que quien lo apriete firme por los tres.
 */
class Nomina extends Model
{
    protected $table = 'rrhh_nominas';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_pago' => 'date',
        'total_devengado' => 'decimal:2',
        'total_deducido' => 'decimal:2',
        'total_neto' => 'decimal:2',
        'total_aportes' => 'decimal:2',
        'total_provisiones' => 'decimal:2',
        'total_costo_empresa' => 'decimal:2',
        'liquidada_at' => 'datetime',
        'aprobada_at' => 'datetime',
        'pagada_at' => 'datetime',
    ];

    public const BORRADOR = 'borrador';

    public const APROBADA = 'aprobada';

    public const PAGADA = 'pagada';

    public const ESTADOS = [
        self::BORRADOR => 'Borrador',
        self::APROBADA => 'Aprobada',
        self::PAGADA => 'Pagada',
    ];

    public function lineas(): HasMany
    {
        return $this->hasMany(NominaLinea::class, 'nomina_id')->orderBy('nombre');
    }

    public function liquidadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liquidada_por_id');
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por_id');
    }

    public function pagadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pagada_por_id');
    }

    /**
     * ¿Se puede volver a liquidar?
     *
     * Sólo en borrador. Llega una incapacidad tarde y hay que rehacer el mes
     * —pasa todos los meses—, pero una vez aprobada lo que dice es lo que se
     * paga: recalcular ahí cambiaría el soporte de un pago que ya salió.
     */
    public function sePuedeRehacer(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    public function estadoLabel(): string
    {
        // Tolera el modelo recién creado en memoria, que todavía no tiene
        // estado: el default de la columna lo pone la base, no PHP.
        return self::ESTADOS[$this->estado] ?? (string) ($this->estado ?: self::ESTADOS[self::BORRADOR]);
    }

    public function colorEstado(): string
    {
        return match ($this->estado) {
            self::PAGADA => 'success',
            self::APROBADA => 'info',
            default => 'warning',
        };
    }

    /**
     * Quién hizo más de una de las tres manos.
     *
     * El control son tres decisiones de tres personas distintas, y los
     * permisos no alcanzan a garantizarlo: el rol Gerente tiene `rrhh`,
     * `rrhh_nomina_aprobar` y `contabilidad`, así que puede liquidar,
     * aprobar y pagar él solo. Y Aracely y Gerencia son root.
     *
     * No se bloquea —en una empresa de este tamaño la dueña firma por todos,
     * y bloquearlo dejaría la nómina sin poder salir— pero sí se dice. Un
     * control que no se cumplió y además no se ve es peor que no tenerlo:
     * el papel afirma tres revisiones donde hubo una.
     *
     * @return array<string> Los nombres, o vacío si cada quien hizo lo suyo.
     */
    public function hizoVariasManos(): array
    {
        $cuantas = [];

        foreach (['liquidada_por_id', 'aprobada_por_id', 'pagada_por_id'] as $campo) {
            if ($quien = $this->{$campo}) {
                $cuantas[$quien] = ($cuantas[$quien] ?? 0) + 1;
            }
        }

        $repetidos = array_keys(array_filter($cuantas, fn (int $n) => $n > 1));

        return $repetidos === []
            ? []
            : User::whereIn('id', $repetidos)->orderBy('name')->pluck('name')->all();
    }

    /** El mes en palabras: «octubre de 2026». */
    public function titulo(): string
    {
        return ucfirst(\Carbon\Carbon::createFromFormat('Y-m-d', $this->periodo.'-01')
            ->locale('es')->isoFormat('MMMM [de] YYYY'));
    }

    /**
     * Las personas a las que la liquidación no les pudo calcular nada.
     *
     * Casi siempre es una ficha sin salario. Se avisa en vez de pagarles cero
     * en silencio, que es la forma en que alguien se queda sin sueldo.
     *
     * @return \Illuminate\Support\Collection<int, NominaLinea>
     */
    public function enCero()
    {
        return $this->lineas->filter(fn (NominaLinea $l) => (float) $l->neto <= 0);
    }
}
