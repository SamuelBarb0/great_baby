<?php

namespace App\Modules\Rrhh\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que se le liquidó a una persona en un mes.
 *
 * Guarda su propio retrato —nombre, documento, cargo y salario— porque un
 * desprendible tiene que poder reimprimirse en dos años diciendo lo mismo,
 * aunque la persona ya no trabaje acá.
 */
class NominaLinea extends Model
{
    protected $table = 'rrhh_nomina_lineas';

    protected $guarded = ['id'];

    protected $casts = [
        'salario_base' => 'decimal:2',
        'dias_trabajados' => 'decimal:2',
        'devengado_salario' => 'decimal:2',
        'devengado_auxilio' => 'decimal:2',
        'devengado_adicional' => 'decimal:2',
        'devengado_extras' => 'decimal:2',
        'deduccion_salud' => 'decimal:2',
        'deduccion_pension' => 'decimal:2',
        'deduccion_ausentismo' => 'decimal:2',
        'patronal_salud' => 'decimal:2',
        'patronal_pension' => 'decimal:2',
        'patronal_arl' => 'decimal:2',
        'patronal_caja' => 'decimal:2',
        'patronal_sena' => 'decimal:2',
        'patronal_icbf' => 'decimal:2',
        'provision_cesantias' => 'decimal:2',
        'provision_intereses' => 'decimal:2',
        'provision_prima' => 'decimal:2',
        'provision_vacaciones' => 'decimal:2',
        'total_aportes' => 'decimal:2',
        'total_provisiones' => 'decimal:2',
        'costo_empresa' => 'decimal:2',
        'total_devengado' => 'decimal:2',
        'total_deducido' => 'decimal:2',
        'neto' => 'decimal:2',
        'detalle' => 'array',
    ];

    public function nomina(): BelongsTo
    {
        return $this->belongsTo(Nomina::class, 'nomina_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * Los conceptos del desprendible, ya ordenados y sin los que van en cero.
     *
     * Un desprendible con seis renglones en cero es más difícil de leer que
     * uno con dos.
     *
     * @return array<array{concepto:string, valor:float, formula:?string, resta:bool}>
     */
    public function conceptos(): array
    {
        $d = $this->detalle ?? [];

        $filas = [
            ['concepto' => 'Salario', 'valor' => (float) $this->devengado_salario, 'formula' => $d['salario'] ?? null, 'resta' => false],
            ['concepto' => 'Auxilio de transporte', 'valor' => (float) $this->devengado_auxilio, 'formula' => $d['auxilio'] ?? null, 'resta' => false],
            ['concepto' => 'Otros devengados', 'valor' => (float) $this->devengado_adicional, 'formula' => $d['adicional'] ?? null, 'resta' => false],
            ['concepto' => 'Horas extra', 'valor' => (float) $this->devengado_extras, 'formula' => $d['extras'] ?? null, 'resta' => false],
            ['concepto' => 'Salud', 'valor' => (float) $this->deduccion_salud, 'formula' => $d['salud'] ?? null, 'resta' => true],
            ['concepto' => 'Pensión', 'valor' => (float) $this->deduccion_pension, 'formula' => $d['pension'] ?? null, 'resta' => true],
            ['concepto' => 'Ausentismo', 'valor' => (float) $this->deduccion_ausentismo, 'formula' => $d['ausentismo'] ?? null, 'resta' => true],
        ];

        return array_values(array_filter($filas, fn (array $f) => $f['valor'] != 0.0));
    }

    /**
     * Lo que la empresa pone encima, en dos bloques separados.
     *
     * Los APORTES salen este mes a la PILA; las PROVISIONES se guardan para
     * cuando llegue la prima o las cesantías. Juntarlos en una sola lista
     * haría creer que todo se paga ya.
     *
     * @return array{aportes: array<array{concepto:string, valor:float}>, provisiones: array<array{concepto:string, valor:float}>}
     */
    public function loQuePoneLaEmpresa(): array
    {
        $d = $this->detalle ?? [];

        $limpiar = fn (array $filas) => array_values(array_filter(
            $filas, fn (array $f) => $f['valor'] != 0.0,
        ));

        return [
            'aportes' => $limpiar([
                ['concepto' => 'Salud (empresa)', 'valor' => (float) $this->patronal_salud, 'formula' => null],
                ['concepto' => 'Pensión (empresa)', 'valor' => (float) $this->patronal_pension, 'formula' => null],
                ['concepto' => 'ARL', 'valor' => (float) $this->patronal_arl, 'formula' => $d['arl'] ?? null],
                ['concepto' => 'Caja de compensación', 'valor' => (float) $this->patronal_caja, 'formula' => null],
                ['concepto' => 'SENA', 'valor' => (float) $this->patronal_sena, 'formula' => null],
                ['concepto' => 'ICBF', 'valor' => (float) $this->patronal_icbf, 'formula' => null],
            ]),
            'provisiones' => $limpiar([
                ['concepto' => 'Cesantías', 'valor' => (float) $this->provision_cesantias, 'formula' => null],
                ['concepto' => 'Intereses sobre cesantías', 'valor' => (float) $this->provision_intereses, 'formula' => null],
                ['concepto' => 'Prima de servicios', 'valor' => (float) $this->provision_prima, 'formula' => null],
                ['concepto' => 'Vacaciones', 'valor' => (float) $this->provision_vacaciones, 'formula' => null],
            ]),
            'exoneracion' => $d['exoneracion'] ?? null,
            'nota_provisiones' => $d['provisiones'] ?? null,
        ];
    }
}
