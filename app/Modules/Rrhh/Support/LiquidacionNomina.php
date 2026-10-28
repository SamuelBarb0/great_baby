<?php

namespace App\Modules\Rrhh\Support;

use App\Modules\Rrhh\Enums\TipoNovedad;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Novedad;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que se le liquida a una persona en un mes.
 *
 * Es cálculo puro: entra un empleado y un período, sale el desglose. No toca
 * base de datos ni decide nada — eso lo hace quien la llama, que es el
 * controlador cuando el período se liquida.
 *
 * ── La regla de oro ─────────────────────────────────────────────────────────
 *
 * Los descuentos por ausentismo **no se recalculan acá**: se leen de
 * `rrhh_novedades.descuento`, que es lo que Gestión Humana revisó y, si hizo
 * falta, corrigió a mano. Repetir la fórmula en dos lugares es exactamente la
 * forma en que este sistema se ha roto antes: el cuadro mostraría un número y
 * la nómina pagaría otro, y nadie sabría cuál creer.
 */
class LiquidacionNomina
{
    public readonly Carbon $desde;

    public readonly Carbon $hasta;

    public function __construct(public readonly string $periodo)
    {
        $this->desde = Carbon::createFromFormat('Y-m-d', $periodo.'-01')->startOfMonth();
        $this->hasta = $this->desde->copy()->endOfMonth();
    }

    public static function de(string $periodo): self
    {
        return new self($periodo);
    }

    /**
     * Quién entra en esta nómina.
     *
     * Quien estuvo vinculado aunque sea un día del mes. Los que entraron a
     * mitad de mes y los que se retiraron a mitad de mes cobran sus días: si
     * sólo se liquidara a los activos de hoy, la gente que se fue el 20 no
     * cobraría el mes que trabajó.
     */
    public function empleados(): Collection
    {
        return Empleado::query()
            ->whereNotNull('fecha_ingreso')
            ->where('fecha_ingreso', '<=', $this->hasta)
            ->where(fn ($q) => $q->whereNull('fecha_retiro')->orWhere('fecha_retiro', '>=', $this->desde))
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Los días que le corresponde cobrar.
     *
     * Sobre el mes comercial de `rrhh.dias_mes`, que es el mismo con el que
     * se calculan los descuentos: si uno usara 30 y el otro los días reales
     * del calendario, un descuento de dos días no coincidiría con dos días de
     * salario.
     */
    public function diasDe(Empleado $e): float
    {
        $diasMes = $this->diasMes();

        /*
         * Todo a medianoche antes de restar.
         *
         * `endOfMonth()` trae las 23:59:59, y restarle una fecha de ingreso a
         * las 00:00 daba 15,99 días en vez de 16 — un día de más en el
         * sueldo de cada persona que entra o sale a mitad de mes. Acá lo que
         * se cuenta son días de calendario, no horas.
         */
        $primero = $this->desde->copy()->startOfDay();
        $ultimo = $this->hasta->copy()->startOfDay();

        $entra = $e->fecha_ingreso && $e->fecha_ingreso->greaterThan($primero)
            ? $e->fecha_ingreso->copy()->startOfDay() : $primero->copy();

        $sale = $e->fecha_retiro && $e->fecha_retiro->lessThan($ultimo)
            ? $e->fecha_retiro->copy()->startOfDay() : $ultimo->copy();

        if ($sale->lessThan($entra)) {
            return 0.0;
        }

        // Mes completo: se paga el mes comercial, no los 28 o 31 del
        // calendario. Es lo que hace la liquidación colombiana y lo que
        // espera el contador.
        if ($entra->equalTo($primero) && $sale->equalTo($ultimo)) {
            return (float) $diasMes;
        }

        // Parcial: los días corridos, topados al mes comercial. El día de
        // entrada y el de salida se pagan los dos, de ahí el +1.
        $dias = (int) $entra->diffInDays($sale) + 1;

        return (float) min($dias, $diasMes);
    }

    /**
     * El desglose completo de una persona.
     *
     * @return array<string, mixed>
     */
    public function liquidar(Empleado $e): array
    {
        $diasMes = $this->diasMes();
        $dias = $this->diasDe($e);
        $formulas = [];

        // ── Devengados ──────────────────────────────────────────────────
        $salario = (float) $e->salario;
        $devSalario = round($salario / $diasMes * $dias, 2);
        $formulas['salario'] = $this->enPalabras($salario, $diasMes, $dias);

        /*
         * El auxilio de transporte es proporcional a los días, igual que el
         * salario, y sólo para quien lo devenga (hasta 2 SMMLV).
         *
         * No es salario: la ley lo excluye expresamente de la base de
         * cotización, y por eso más abajo no entra en el IBC. Meterlo ahí
         * haría cotizar de más a toda la gente de salario mínimo.
         */
        $devAuxilio = 0.0;
        if ($e->devengaAuxilioTransporte()) {
            $auxilio = (float) setting('rrhh.auxilio_transporte');
            $devAuxilio = round($auxilio / $diasMes * $dias, 2);
            $formulas['auxilio'] = $this->enPalabras($auxilio, $diasMes, $dias);
        } else {
            /*
             * El motivo REAL y no uno por defecto: desde que existe la
             * exclusión por ficha, «no devenga» puede ser por sueldo o
             * porque vive en el sitio de trabajo, y el desprendible decía
             * siempre lo primero. Un papel que explica mal es peor que uno
             * que no explica.
             */
            $formulas['auxilio'] = 'No devenga auxilio de transporte: '
                .rtrim(lcfirst($e->porQueNoDevengaAuxilio() ?? 'sin motivo registrado'), '.').'.';
        }

        // Lo pactado aparte en la ficha laboral (rodamiento, bonificación…).
        $devAdicional = round((float) $e->valor_adicional / $diasMes * $dias, 2);
        if ($devAdicional > 0) {
            $formulas['adicional'] = trim(($e->concepto_adicional ?: 'Valor adicional pactado').' · '
                .$this->enPalabras((float) $e->valor_adicional, $diasMes, $dias));
        }

        [$devExtras, $detalleExtras] = $this->horasExtra($e, $salario, $diasMes);
        if ($detalleExtras !== []) {
            $formulas['extras'] = implode(' · ', $detalleExtras);
        }

        $totalDevengado = round($devSalario + $devAuxilio + $devAdicional + $devExtras, 2);

        // ── Deducciones ─────────────────────────────────────────────────
        /*
         * El ingreso base de cotización: lo salarial y nada más. El auxilio
         * de transporte queda por fuera (art. 17 Ley 344 de 1996) y las horas
         * extra sí entran, porque son remuneración del trabajo.
         */
        $ibc = round($devSalario + $devAdicional + $devExtras, 2);

        $pctSalud = (float) setting('rrhh.aporte_salud_pct');
        $pctPension = (float) setting('rrhh.aporte_pension_pct');

        $dedSalud = round($ibc * $pctSalud / 100, 2);
        $dedPension = round($ibc * $pctPension / 100, 2);

        $formulas['salud'] = $this->porcentajeEnPalabras($ibc, $pctSalud);
        $formulas['pension'] = $this->porcentajeEnPalabras($ibc, $pctPension);

        [$dedAusentismo, $detalleAusentismo] = $this->ausentismo($e);
        if ($detalleAusentismo !== []) {
            $formulas['ausentismo'] = implode(' · ', $detalleAusentismo);
        }

        $totalDeducido = round($dedSalud + $dedPension + $dedAusentismo, 2);

        // ── Lo que pone la empresa ──────────────────────────────────────
        $patronal = $this->aportesPatronales($e, $ibc, $devSalario, $devAuxilio, $formulas);

        $totalDevengadoNeto = round($totalDevengado - $totalDeducido, 2);

        return [
            'empleado_id' => $e->id,
            // El retrato: lo que diga el desprendible dentro de dos años.
            'nombre' => $e->nombre,
            'documento' => $e->numero_documento,
            'cargo' => $e->cargo,
            'salario_base' => $salario,

            'dias_trabajados' => $dias,

            'devengado_salario' => $devSalario,
            'devengado_auxilio' => $devAuxilio,
            'devengado_adicional' => $devAdicional,
            'devengado_extras' => $devExtras,

            'deduccion_salud' => $dedSalud,
            'deduccion_pension' => $dedPension,
            'deduccion_ausentismo' => $dedAusentismo,

            'total_devengado' => $totalDevengado,
            'total_deducido' => $totalDeducido,
            'neto' => $totalDevengadoNeto,

            'detalle' => $formulas,
        ] + $patronal;
    }

    /**
     * Los aportes de la empresa y las provisiones.
     *
     * Van juntos porque se calculan sobre la misma base, pero se devuelven en
     * dos totales distintos a propósito: los aportes SALEN este mes a la
     * PILA, las provisiones se GUARDAN para cuando llegue la prima o las
     * cesantías. Sumarlos en un solo número haría creer que todo se paga ya.
     *
     * @param  array<string,string>  $formulas  se le agregan las cuentas en palabras
     * @return array<string, mixed>
     */
    private function aportesPatronales(
        Empleado $e,
        float $ibc,
        float $devSalario,
        float $devAuxilio,
        array &$formulas,
    ): array {
        /*
         * La exoneración, que es la regla que más plata mueve.
         *
         * Una empresa que declara renta no paga salud patronal, SENA ni ICBF
         * por quien gane menos de 10 salarios mínimos (Ley 1607/2012 art. 25,
         * modificada por la Ley 1819/2016 art. 65). Son 13,5 puntos: darla por
         * sentada en cualquiera de los dos sentidos descuadra el costo de toda
         * la nómina.
         */
        $minimo = (float) setting('rrhh.salario_minimo');
        $exonerada = (bool) setting('rrhh.empresa_exonerada_parafiscales');
        $aplicaExoneracion = $exonerada && $minimo > 0 && (float) $e->salario < 10 * $minimo;

        $pct = fn (string $clave) => (float) setting($clave);

        $salud = $aplicaExoneracion ? 0.0 : round($ibc * $pct('rrhh.patronal_salud_pct') / 100, 2);
        $sena = $aplicaExoneracion ? 0.0 : round($ibc * $pct('rrhh.patronal_sena_pct') / 100, 2);
        $icbf = $aplicaExoneracion ? 0.0 : round($ibc * $pct('rrhh.patronal_icbf_pct') / 100, 2);

        $formulas['exoneracion'] = $aplicaExoneracion
            ? 'Exonerada de salud, SENA e ICBF: gana menos de 10 salarios mínimos ($'
                .number_format(10 * $minimo, 0, ',', '.').').'
            : 'Sin exoneración: se pagan salud patronal, SENA e ICBF completos.';

        // Pensión y caja NO se exoneran nunca.
        $pension = round($ibc * $pct('rrhh.patronal_pension_pct') / 100, 2);
        $caja = round($ibc * $pct('rrhh.patronal_caja_pct') / 100, 2);

        [$arl, $notaArl] = $this->arl($e, $ibc);
        $formulas['arl'] = $notaArl;

        $aportes = round($salud + $pension + $arl + $caja + $sena + $icbf, 2);

        /*
         * Provisiones.
         *
         * La base es TODO lo salarial del mes —salario, lo pactado aparte y
         * las horas extra—, que es el mismo IBC sobre el que se cotiza, más
         * el auxilio de transporte para cesantías, intereses y prima. Las
         * vacaciones van sin auxilio, igual que se liquidan al final.
         *
         * Que el IBC y la base de provisiones coincidan no es un detalle: si
         * el adicional cotizara pero no se provisionara, el sistema estaría
         * diciendo que es salario para pagarle a la EPS y no lo es para la
         * prima de la persona. Una de las dos tendría que estar mal.
         */
        $baseProv = round($ibc + $devAuxilio, 2);

        $cesantias = round($baseProv * $pct('rrhh.provision_cesantias_pct') / 100, 2);
        $interesesProv = round($baseProv * $pct('rrhh.provision_intereses_pct') / 100, 2);
        $primaProv = round($baseProv * $pct('rrhh.provision_prima_pct') / 100, 2);
        $vacacionesProv = round($ibc * $pct('rrhh.provision_vacaciones_pct') / 100, 2);

        $provisiones = round($cesantias + $interesesProv + $primaProv + $vacacionesProv, 2);

        $formulas['provisiones'] = sprintf(
            'Cesantías, intereses y prima sobre $%s; vacaciones sobre $%s (sin auxilio).',
            number_format($baseProv, 2, ',', '.'),
            number_format($ibc, 2, ',', '.'),
        );

        return [
            'patronal_salud' => $salud,
            'patronal_pension' => $pension,
            'patronal_arl' => $arl,
            'patronal_caja' => $caja,
            'patronal_sena' => $sena,
            'patronal_icbf' => $icbf,

            'provision_cesantias' => $cesantias,
            'provision_intereses' => $interesesProv,
            'provision_prima' => $primaProv,
            'provision_vacaciones' => $vacacionesProv,

            'total_aportes' => $aportes,
            'total_provisiones' => $provisiones,
        ];
    }

    /**
     * La ARL, que depende del CARGO y no del sueldo.
     *
     * El nivel de riesgo vive en la ficha. Si no está puesto se asume el I
     * —el más bajo— y se dice: suponer el más alto inflaría el costo de toda
     * la nómina por un dato que falta, y suponerlo en silencio escondería que
     * falta.
     *
     * @return array{0: float, 1: string}
     */
    private function arl(Empleado $e, float $ibc): array
    {
        $nivel = strtoupper(trim((string) $e->arl_nivel_riesgo));

        $tarifas = [
            'I' => 'rrhh.arl_i_pct',
            'II' => 'rrhh.arl_ii_pct',
            'III' => 'rrhh.arl_iii_pct',
            'IV' => 'rrhh.arl_iv_pct',
            'V' => 'rrhh.arl_v_pct',
        ];

        $falta = ! isset($tarifas[$nivel]);
        $usado = $falta ? 'I' : $nivel;
        $tarifa = (float) setting($tarifas[$usado]);
        $valor = round($ibc * $tarifa / 100, 2);

        $nota = sprintf('Riesgo %s · %s%% de $%s = $%s',
            $usado,
            rtrim(rtrim(number_format($tarifa, 3, ',', '.'), '0'), ','),
            number_format($ibc, 2, ',', '.'),
            number_format($valor, 2, ',', '.'),
        );

        return [$valor, $falta
            ? $nota.' · ⚠ la ficha no tiene nivel de riesgo: se asumió el I, el más bajo.'
            : $nota];
    }

    /**
     * Las horas extra del mes, con su recargo.
     *
     * Entran digitadas —Great Baby no tiene marcación— con su clase y si
     * fueron nocturnas. Los cuatro factores son reglas editables: cambian por
     * ley y no deberían esperar a un programador.
     *
     * @return array{0: float, 1: array<string>}
     */
    private function horasExtra(Empleado $e, float $salario, int $diasMes): array
    {
        $novedades = Novedad::delPeriodo($this->periodo)
            ->deTipo(TipoNovedad::HorasExtra)
            ->where('empleado_id', $e->id)
            ->get();

        if ($novedades->isEmpty() || $salario <= 0) {
            return [0.0, []];
        }

        // La hora ordinaria: el mes comercial por la jornada de 8 horas.
        $valorHora = $salario / ($diasMes * 8);

        $total = 0.0;
        $detalle = [];

        foreach ($novedades as $n) {
            $horas = (float) ($n->horas ?? 0);

            if ($horas <= 0) {
                continue;
            }

            $factor = $this->factorDe($n);
            $valor = round($valorHora * $factor * $horas, 2);
            $total += $valor;

            $detalle[] = sprintf(
                '%s horas %s × %s × %s = %s',
                rtrim(rtrim(number_format($horas, 2, ',', '.'), '0'), ','),
                $n->etiquetaDeClase().($n->nocturna ? ' nocturna' : ''),
                '$'.number_format($valorHora, 2, ',', '.'),
                number_format($factor, 2, ',', '.'),
                '$'.number_format($valor, 2, ',', '.'),
            );
        }

        return [round($total, 2), $detalle];
    }

    /** El factor que le toca a una novedad de horas extra. */
    private function factorDe(Novedad $n): float
    {
        $dominical = strtoupper((string) $n->clase) === 'DOMINICAL';

        return (float) match (true) {
            $dominical && $n->nocturna => setting('rrhh.recargo_extra_dominical_nocturna'),
            $dominical => setting('rrhh.recargo_extra_dominical'),
            (bool) $n->nocturna => setting('rrhh.recargo_extra_nocturna'),
            default => setting('rrhh.recargo_extra_diurna'),
        };
    }

    /**
     * Lo que se le descuenta por ausentismo.
     *
     * Se LEE de la novedad, no se recalcula: `descuento` es lo que Gestión
     * Humana revisó y pudo haber corregido a mano. Si acá se volviera a
     * calcular, el cuadro del mes y el desprendible dirían cosas distintas.
     *
     * @return array{0: float, 1: array<string>}
     */
    private function ausentismo(Empleado $e): array
    {
        $novedades = Novedad::delPeriodo($this->periodo)
            ->ausentismo()
            ->where('empleado_id', $e->id)
            ->get();

        $total = 0.0;
        $detalle = [];

        foreach ($novedades as $n) {
            $valor = (float) ($n->descuento ?? 0);

            if ($valor <= 0) {
                continue;
            }

            $total += $valor;
            $detalle[] = sprintf(
                '%s · %s días · $%s%s',
                $n->tipo->label(),
                rtrim(rtrim(number_format($n->diasEquivalentes(), 2, ',', '.'), '0'), ','),
                number_format($valor, 2, ',', '.'),
                $n->descuento_manual ? ' (ajustado a mano)' : '',
            );
        }

        return [round($total, 2), $detalle];
    }

    private function diasMes(): int
    {
        return max(1, (int) setting('rrhh.dias_mes'));
    }

    /** «$2.000.000 ÷ 30 × 28 = $1.866.666,67» */
    private function enPalabras(float $base, int $diasMes, float $dias): string
    {
        return sprintf(
            '$%s ÷ %d × %s = $%s',
            number_format($base, 2, ',', '.'),
            $diasMes,
            rtrim(rtrim(number_format($dias, 2, ',', '.'), '0'), ','),
            number_format($base / $diasMes * $dias, 2, ',', '.'),
        );
    }

    /** «4% de $1.866.666,67 = $74.666,67» */
    private function porcentajeEnPalabras(float $base, float $pct): string
    {
        return sprintf(
            '%s%% de $%s = $%s',
            rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ','),
            number_format($base, 2, ',', '.'),
            number_format($base * $pct / 100, 2, ',', '.'),
        );
    }
}
