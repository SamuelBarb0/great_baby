<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La liquidación definitiva de alguien que se fue.
 *
 * Guarda su propio retrato y las fórmulas con las que salió cada número: es
 * el soporte de un pago y la persona se lo lleva. Tiene que decir lo mismo
 * dentro de dos años, aunque después le corrijan el salario a la ficha.
 */
class Liquidacion extends Model
{
    protected $table = 'rrhh_liquidaciones';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_retiro' => 'date',
        'fecha_pago' => 'date',
        'salario_base' => 'decimal:2',
        'auxilio_base' => 'decimal:2',
        'cesantias_ya_pagadas' => 'decimal:2',
        'indemnizacion' => 'decimal:2',
        'otros_descuentos' => 'decimal:2',
        'cesantias' => 'decimal:2',
        'intereses_cesantias' => 'decimal:2',
        'prima' => 'decimal:2',
        'vacaciones' => 'decimal:2',
        'total_a_favor' => 'decimal:2',
        'total_descuentos' => 'decimal:2',
        'neto' => 'decimal:2',
        'detalle' => 'array',
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

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
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

    /** Sólo en borrador: una vez aprobada, lo que dice es lo que se paga. */
    public function sePuedeRehacer(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    public function estadoLabel(): string
    {
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

    /** Cuánto duró el contrato, en palabras. */
    public function tiempoServido(): string
    {
        if (! $this->fecha_ingreso || ! $this->fecha_retiro) {
            return '—';
        }

        $d = $this->fecha_ingreso->diff($this->fecha_retiro);
        $partes = [];

        if ($d->y) {
            $partes[] = $d->y.($d->y === 1 ? ' año' : ' años');
        }
        if ($d->m) {
            $partes[] = $d->m.($d->m === 1 ? ' mes' : ' meses');
        }
        if ($d->d || $partes === []) {
            $partes[] = $d->d.($d->d === 1 ? ' día' : ' días');
        }

        return implode(', ', $partes);
    }

    /**
     * Los renglones del impreso, sin los que van en cero.
     *
     * @return array<array{concepto:string, valor:float, formula:?string, resta:bool}>
     */
    public function conceptos(): array
    {
        $d = $this->detalle ?? [];

        $filas = [
            ['concepto' => 'Cesantías', 'valor' => (float) $this->cesantias, 'formula' => $d['cesantias'] ?? null, 'resta' => false],
            ['concepto' => 'Intereses sobre cesantías', 'valor' => (float) $this->intereses_cesantias, 'formula' => $d['intereses'] ?? null, 'resta' => false],
            ['concepto' => 'Prima de servicios', 'valor' => (float) $this->prima, 'formula' => $d['prima'] ?? null, 'resta' => false],
            ['concepto' => 'Vacaciones compensadas', 'valor' => (float) $this->vacaciones, 'formula' => $d['vacaciones'] ?? null, 'resta' => false],
            ['concepto' => $this->indemnizacion_concepto ?: 'Indemnización', 'valor' => (float) $this->indemnizacion, 'formula' => null, 'resta' => false],
            ['concepto' => 'Cesantías ya consignadas o anticipadas', 'valor' => (float) $this->cesantias_ya_pagadas, 'formula' => null, 'resta' => true],
            ['concepto' => $this->otros_descuentos_concepto ?: 'Otros descuentos', 'valor' => (float) $this->otros_descuentos, 'formula' => null, 'resta' => true],
        ];

        return array_values(array_filter($filas, fn (array $f) => $f['valor'] != 0.0));
    }
}
