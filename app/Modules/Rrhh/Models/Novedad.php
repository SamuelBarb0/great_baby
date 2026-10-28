<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Enums\TipoNovedad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una novedad del mes: una incapacidad, un permiso, una ausencia, horas extra.
 *
 * Es la fila del Excel que Gestión Humana le manda al contador cada corte. La
 * diferencia con el Excel es que acá el descuento SE CALCULA y se deja ver la
 * fórmula, en vez de llegar digitado desde una calculadora que nadie más vio.
 */
class Novedad extends Model
{
    use SoftDeletes;

    protected $table = 'rrhh_novedades';

    protected $fillable = [
        'empleado_id', 'tipo', 'clase',
        'fecha_inicio', 'fecha_fin', 'fecha_reintegro',
        'dias', 'horas', 'nocturna', 'remunerada',
        'descuento', 'descuento_manual',
        'observaciones', 'soporte_path', 'soporte_nombre',
        'periodo', 'registrada_por_id',
    ];

    protected $casts = [
        'tipo' => TipoNovedad::class,
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_reintegro' => 'date',
        'dias' => 'decimal:2',
        'horas' => 'decimal:2',
        'nocturna' => 'bool',
        'remunerada' => 'bool',
        'descuento' => 'decimal:2',
        'descuento_manual' => 'bool',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por_id');
    }

    public function scopeDelPeriodo(Builder $q, string $periodo): Builder
    {
        return $q->where('periodo', $periodo);
    }

    public function scopeDeTipo(Builder $q, TipoNovedad $t): Builder
    {
        return $q->where('tipo', $t->value);
    }

    /**
     * Las que cuentan para el ausentismo: todo menos las horas extra.
     *
     * Se filtra por los tipos del enum y no por `!= horas_extra`, para que el
     * día que entre un quinto tipo haya que decidir explícitamente si suma al
     * indicador en vez de que entre solo.
     */
    public function scopeAusentismo(Builder $q): Builder
    {
        $tipos = array_map(
            fn (TipoNovedad $t) => $t->value,
            array_filter(TipoNovedad::cases(), fn (TipoNovedad $t) => $t->cuentaAusentismo()),
        );

        return $q->whereIn('tipo', $tipos);
    }

    public function etiquetaDeClase(): string
    {
        return $this->tipo->etiquetaDeClase($this->clase);
    }

    /**
     * Los días que se pierden de trabajo.
     *
     * Un permiso de dos horas no es un día perdido ni es cero: son 2/8 de día.
     * Sin esto, el ausentismo del mes se calcularía contando como día completo
     * cada cita médica, y el número que llega a la Junta sería el doble del
     * real.
     */
    public function diasEquivalentes(): float
    {
        if ($this->dias !== null && (float) $this->dias > 0) {
            return (float) $this->dias;
        }

        if ($this->horas !== null && (float) $this->horas > 0) {
            return round((float) $this->horas / 8, 4);
        }

        return 0.0;
    }

    /**
     * Lo que se le descuenta, y por qué.
     *
     * Devuelve el valor junto con la fórmula en palabras. Las dos cosas van
     * juntas a propósito: la pantalla muestra la cuenta al lado del número,
     * para que quien revisa el cuadro no tenga que creerle al sistema.
     *
     * Las dos reglas salen de los datos reales de octubre:
     *
     *   · INCAPACIDAD — se descuenta el auxilio de transporte proporcional.
     *     Quince días descontaron 124.547,50, que es 249.095 ÷ 30 × 15. Quien
     *     gana más de dos salarios mínimos no devenga auxilio, así que no hay
     *     nada que descontarle.
     *
     *   · AUSENCIA O PERMISO NO REMUNERADO — se descuenta el día entero.
     *     Dos días contra un salario de 2.000.000 descontaron 133.333,33, que
     *     es 2.000.000 ÷ 30 × 2.
     *
     * @return array{valor: float, formula: string}
     */
    public function descuentoSugerido(): array
    {
        $dias = $this->diasEquivalentes();
        $diasMes = max(1, (int) setting('rrhh.dias_mes'));

        if ($dias <= 0 || ! $this->tipo->esDescuento()) {
            return ['valor' => 0.0, 'formula' => 'No genera descuento.'];
        }

        // Un permiso remunerado es tiempo pagado: la cita médica del artículo
        // 57 del Código Sustantivo del Trabajo no se descuenta.
        if ($this->tipo === TipoNovedad::Permiso && $this->remunerada) {
            return ['valor' => 0.0, 'formula' => 'Permiso remunerado: no se descuenta.'];
        }

        if ($this->tipo === TipoNovedad::Incapacidad) {
            return $this->descuentoDeAuxilio($dias, $diasMes);
        }

        return $this->descuentoDeSalario($dias, $diasMes);
    }

    /** Incapacidad: se pierde el auxilio de transporte, no el salario. */
    private function descuentoDeAuxilio(float $dias, int $diasMes): array
    {
        $salario = (float) ($this->empleado?->salario ?? 0);
        $minimo = (float) setting('rrhh.salario_minimo');
        $auxilio = (float) setting('rrhh.auxilio_transporte');

        if ($salario <= 0) {
            return [
                'valor' => 0.0,
                'formula' => 'No se puede calcular: la ficha de la persona no tiene salario registrado.',
            ];
        }

        /*
         * Quien no devenga el auxilio no tiene qué perder por incapacidad.
         *
         * Se pregunta por el empleado y no por el salario: desde que existe
         * la exclusión por ficha —quien vive en el sitio de trabajo, quien
         * tiene ruta de la empresa— el sueldo ya no es la única razón, y el
         * cuadro decía siempre «gana más de dos mínimos» aunque fuera falso.
         */
        if (! $this->empleado?->devengaAuxilioTransporte()) {
            return [
                'valor' => 0.0,
                'formula' => 'No devenga auxilio de transporte ('
                    .rtrim(lcfirst($this->empleado?->porQueNoDevengaAuxilio() ?? 'sin motivo registrado'), '.')
                    .'): no hay descuento.',
            ];
        }

        $valor = round($auxilio / $diasMes * $dias, 2);

        return [
            'valor' => $valor,
            'formula' => sprintf(
                'Auxilio de transporte %s ÷ %d días × %s días = %s',
                $this->pesos($auxilio), $diasMes, $this->numero($dias), $this->pesos($valor),
            ),
        ];
    }

    /** Ausencia o permiso sin remunerar: se pierde el día de salario. */
    private function descuentoDeSalario(float $dias, int $diasMes): array
    {
        $salario = (float) ($this->empleado?->salario ?? 0);

        if ($salario <= 0) {
            return [
                'valor' => 0.0,
                'formula' => 'No se puede calcular: la ficha de la persona no tiene salario registrado.',
            ];
        }

        $valor = round($salario / $diasMes * $dias, 2);

        return [
            'valor' => $valor,
            'formula' => sprintf(
                'Salario %s ÷ %d días × %s días = %s',
                $this->pesos($salario), $diasMes, $this->numero($dias), $this->pesos($valor),
            ),
        ];
    }

    /**
     * Pone el descuento calculado, salvo que alguien lo haya corregido a mano.
     *
     * Esa salvedad es el punto: sin ella, recalcular un período pisaría en
     * silencio la corrección que Gestión Humana hizo por una razón que el
     * sistema no conoce.
     */
    public function recalcularDescuento(): void
    {
        if ($this->descuento_manual) {
            return;
        }

        $this->descuento = $this->descuentoSugerido()['valor'];
    }

    private function pesos(float $v): string
    {
        return '$'.number_format($v, 0, ',', '.');
    }

    private function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }
}
