<?php

namespace App\Modules\Rrhh\Support;

use App\Modules\Rrhh\Models\Empleado;
use Carbon\Carbon;

/**
 * Lo que se le debe a alguien cuando se va.
 *
 * Cálculo puro: entra el empleado y los tres datos que el sistema no puede
 * saber solo —vacaciones ya tomadas, cesantías ya consignadas e
 * indemnización— y sale el desglose con su fórmula.
 *
 * ── Las cuatro cuentas y sus dos bases ──────────────────────────────────────
 *
 * Es el punto donde más se equivoca un Excel hecho a mano:
 *
 *  · **Cesantías, intereses y prima** se calculan sobre salario MÁS auxilio
 *    de transporte. El auxilio no es salario para cotizar, pero la ley lo
 *    manda incluir acá (art. 7 Ley 1ª de 1963 para la prima, y el auxilio
 *    hace parte de la base de cesantías).
 *  · **Vacaciones** se calculan sobre el salario SOLO. El auxilio compensa
 *    el transporte al trabajo, y en vacaciones no hay transporte al trabajo.
 *
 * Usar la misma base para las cuatro —que es lo fácil— le paga de más o de
 * menos a cada persona que sale.
 *
 * Todo corre sobre el año comercial de 360 días, que es el que usa la
 * liquidación colombiana.
 */
class LiquidacionDefinitiva
{
    /** El año comercial. No es `dias_mes × 12` por casualidad: son 360. */
    private const DIAS_ANO = 360;

    /** El interés legal sobre cesantías: 12% anual. */
    private const INTERES_CESANTIAS = 0.12;

    public function __construct(public readonly Empleado $empleado) {}

    public static function de(Empleado $e): self
    {
        return new self($e);
    }

    /**
     * El cálculo completo.
     *
     * @param  array{vacaciones_tomadas?:int, cesantias_ya_pagadas?:float, indemnizacion?:float, otros_descuentos?:float}  $ajustes
     * @return array<string, mixed>
     */
    public function calcular(array $ajustes = []): array
    {
        $e = $this->empleado;

        $ingreso = $e->fecha_ingreso?->copy()->startOfDay();
        $retiro = $e->fecha_retiro?->copy()->startOfDay();

        if (! $ingreso || ! $retiro || $retiro->lessThan($ingreso)) {
            return $this->vacia('La ficha no tiene fechas de ingreso y retiro coherentes.');
        }

        /*
         * `pagoMensual()` y no `salario` a secas: incluye lo pactado aparte
         * en la ficha, que es lo mismo que cotiza en la nómina. Si cotizara
         * y no se liquidara, el sistema diría que es salario para la EPS y
         * no lo es para la prestación de la persona.
         */
        $salario = $e->pagoMensual();
        $auxilio = $e->devengaAuxilioTransporte() ? (float) setting('rrhh.auxilio_transporte') : 0.0;

        // Las dos bases. Acá es donde se equivoca el Excel.
        $basePrestacional = $salario + $auxilio;

        $formulas = [];

        // ── Días de cada cuenta ─────────────────────────────────────────
        /*
         * Cesantías e intereses: desde el 1 de enero, o desde el ingreso si
         * entró este año. Lo de años anteriores ya se consignó al fondo en
         * febrero y por eso no se vuelve a deber —si quedó algo pendiente,
         * entra como «cesantías ya pagadas» en negativo, que lo escribe
         * Gestión Humana—.
         */
        $desdeCesantias = $this->mayorDe($ingreso, $retiro->copy()->startOfYear());
        $diasCesantias = $this->dias360($desdeCesantias, $retiro);

        /*
         * Prima: el semestre en curso. Enero–junio o julio–diciembre, que es
         * como la manda la ley.
         */
        $iniciaSemestre = $retiro->month <= 6
            ? $retiro->copy()->startOfYear()
            : $retiro->copy()->startOfYear()->addMonths(6);
        $desdePrima = $this->mayorDe($ingreso, $iniciaSemestre);
        $diasPrima = $this->dias360($desdePrima, $retiro);

        /*
         * Vacaciones: TODO el tiempo trabajado, desde el ingreso, menos los
         * días que ya disfrutó. No se reinician cada año: lo no disfrutado
         * se acumula y al salir se compensa en dinero.
         */
        $diasVacaciones = $this->dias360($ingreso, $retiro);

        // ── Las cuatro cuentas ──────────────────────────────────────────
        $cesantias = round($basePrestacional * $diasCesantias / self::DIAS_ANO, 2);
        $formulas['cesantias'] = $this->comoSeCalcula(
            $basePrestacional, $diasCesantias, self::DIAS_ANO,
            "salario + auxilio, del {$desdeCesantias->format('d/m/Y')} al {$retiro->format('d/m/Y')}",
        );

        /*
         * Intereses: 12% anual sobre las cesantías, proporcional a los mismos
         * días. No sobre el salario — sobre las cesantías.
         */
        $intereses = round($cesantias * self::INTERES_CESANTIAS * $diasCesantias / self::DIAS_ANO, 2);
        $formulas['intereses'] = sprintf(
            '$%s × 12%% × %d ÷ %d = $%s',
            number_format($cesantias, 2, ',', '.'), $diasCesantias, self::DIAS_ANO,
            number_format($intereses, 2, ',', '.'),
        );

        $prima = round($basePrestacional * $diasPrima / self::DIAS_ANO, 2);
        $formulas['prima'] = $this->comoSeCalcula(
            $basePrestacional, $diasPrima, self::DIAS_ANO,
            "salario + auxilio, semestre desde el {$desdePrima->format('d/m/Y')}",
        );

        /*
         * Vacaciones: sobre 720 y no sobre 360, porque son 15 días hábiles
         * por año trabajado — medio mes —, y SIN auxilio de transporte.
         */
        $tomadas = max(0, (int) ($ajustes['vacaciones_tomadas'] ?? 0));
        $diasNetos = max(0, $diasVacaciones - $tomadas * 2);
        $vacaciones = round($salario * $diasNetos / 720, 2);
        $formulas['vacaciones'] = sprintf(
            '$%s × %d ÷ 720 = $%s (salario sin auxilio%s)',
            number_format($salario, 2, ',', '.'), $diasNetos,
            number_format($vacaciones, 2, ',', '.'),
            $tomadas > 0 ? ", descontando {$tomadas} días ya disfrutados" : '',
        );

        // ── Lo que entra a mano ─────────────────────────────────────────
        $indemnizacion = round((float) ($ajustes['indemnizacion'] ?? 0), 2);
        $yaPagadas = round((float) ($ajustes['cesantias_ya_pagadas'] ?? 0), 2);
        $otros = round((float) ($ajustes['otros_descuentos'] ?? 0), 2);

        $aFavor = round($cesantias + $intereses + $prima + $vacaciones + $indemnizacion, 2);
        $descuentos = round($yaPagadas + $otros, 2);

        return [
            'nombre' => $e->nombre,
            'documento' => $e->numero_documento,
            'cargo' => $e->cargo,
            'fecha_ingreso' => $ingreso->toDateString(),
            'fecha_retiro' => $retiro->toDateString(),
            'tipo_retiro' => $e->tipo_retiro,
            'salario_base' => $salario,
            'auxilio_base' => $auxilio,

            'dias_cesantias' => $diasCesantias,
            'dias_prima' => $diasPrima,
            'dias_vacaciones' => $diasVacaciones,

            'vacaciones_tomadas' => $tomadas,
            'cesantias_ya_pagadas' => $yaPagadas,
            'indemnizacion' => $indemnizacion,
            'otros_descuentos' => $otros,

            'cesantias' => $cesantias,
            'intereses_cesantias' => $intereses,
            'prima' => $prima,
            'vacaciones' => $vacaciones,

            'total_a_favor' => $aFavor,
            'total_descuentos' => $descuentos,
            'neto' => round($aFavor - $descuentos, 2),

            'detalle' => $formulas,
        ];
    }

    /**
     * Días entre dos fechas con el año comercial de 360.
     *
     * Cada mes cuenta 30, se complete o no: es el convenio con el que se
     * liquidan las prestaciones en Colombia, y el mismo que usa el mes
     * comercial de la nómina. Contar días de calendario le pagaría distinto a
     * quien se va en febrero y a quien se va en marzo.
     */
    private function dias360(Carbon $desde, Carbon $hasta): int
    {
        $d1 = min($desde->day, 30);
        $d2 = min($hasta->day, 30);

        $dias = ($hasta->year - $desde->year) * 360
            + ($hasta->month - $desde->month) * 30
            + ($d2 - $d1);

        // El día de salida también se trabaja.
        return max(0, $dias + 1);
    }

    private function mayorDe(Carbon $a, Carbon $b): Carbon
    {
        return $a->greaterThan($b) ? $a->copy() : $b->copy();
    }

    private function comoSeCalcula(float $base, int $dias, int $sobre, string $nota): string
    {
        return sprintf(
            '$%s × %d ÷ %d = $%s (%s)',
            number_format($base, 2, ',', '.'), $dias, $sobre,
            number_format($base * $dias / $sobre, 2, ',', '.'), $nota,
        );
    }

    /** @return array<string, mixed> */
    private function vacia(string $porque): array
    {
        return [
            'nombre' => $this->empleado->nombre,
            'documento' => $this->empleado->numero_documento,
            'error' => $porque,
            'cesantias' => 0.0, 'intereses_cesantias' => 0.0,
            'prima' => 0.0, 'vacaciones' => 0.0,
            'total_a_favor' => 0.0, 'total_descuentos' => 0.0, 'neto' => 0.0,
            'detalle' => ['error' => $porque],
        ];
    }
}
