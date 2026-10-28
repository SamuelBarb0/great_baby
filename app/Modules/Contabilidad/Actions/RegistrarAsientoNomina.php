<?php

namespace App\Modules\Contabilidad\Actions;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Rrhh\Models\Nomina;
use Illuminate\Support\Facades\DB;

/**
 * El asiento de la nómina.
 *
 * Esto NO EXISTÍA. El módulo tenía el flujo completo y bien hecho —Gestión
 * Humana liquida, Gerencia aprueba, Tesorería paga, cada mano con su permiso—
 * y no escribía ni una línea en el libro. Se pagaron $5.518.390 de la nómina
 * de octubre y la contabilidad no se enteró: ni el gasto de personal, ni los
 * aportes, ni las provisiones, ni la cuenta por pagar laboral. Quien mirara la
 * utilidad del mes la veía más alta de lo real por toda la nómina.
 *
 * Son DOS asientos, igual que en gastos, porque son dos hechos distintos:
 *
 *   1) **Causación**, al aprobar — la obligación con la gente ya existe
 *      aunque todavía no se haya consignado.
 *   2) **Pago**, al marcar pagada — se cancela esa obligación contra el banco.
 *
 * Entre los dos momentos el pasivo existe, y es justo lo que alguien necesita
 * ver para saber cuánto debe la empresa antes del día 30.
 *
 * ── La causación, en limpio ──────────────────────────────────────────────
 *
 *   DEBE   sueldos, extras, auxilio          lo que la gente se ganó
 *   DEBE   salud, pensión, ARL, parafiscales lo que PONE la empresa
 *   DEBE   cesantías, intereses, prima, vac. lo que se provisiona
 *
 *   HABER  sueldos                           el ausentismo, como MENOR gasto
 *   HABER  EPS, pensión, ARL, parafiscales   lo que se le gira a cada entidad
 *   HABER  cesantías, intereses, prima, vac. el pasivo que se acumula
 *   HABER  salarios por pagar                el neto que se le consigna
 *
 * Las cuentas de salud y pensión juntan lo descontado al empleado con lo que
 * pone la empresa, porque a la EPS y al fondo se les gira todo en la misma
 * planilla: separarlo en el libro obligaría a sumarlo otra vez para pagar.
 *
 * El ausentismo va como crédito al gasto de sueldos y no como un pasivo: es
 * plata que la persona no recibe y que la empresa no le debe a nadie, así que
 * lo que corresponde es que el gasto quede por lo realmente causado. Se deja
 * como línea aparte —y no restándolo del débito— para que en el libro se vea
 * cuánto se dejó de pagar por ausencias, que es un dato que a Gestión Humana
 * le preguntan todos los meses.
 */
class RegistrarAsientoNomina
{
    /** Marca que distingue el asiento de pago del de causación. */
    private const SUFIJO_PAGO = ' · pago';

    /**
     * Causación: la nómina aprobada entra al libro.
     *
     * Idempotente: si ya se causó, no vuelve a asentar. Reaprobar o reintentar
     * no puede duplicar el gasto de personal del mes.
     */
    public function causar(Nomina $nomina): int
    {
        if ($this->yaTieneAsiento($nomina, pago: false)) {
            return 0;
        }

        $t = $this->totales($nomina);
        if ($t['devengado'] <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($nomina, $t) {
            $texto = "Nómina {$nomina->periodo}";
            $lineas = [];

            $debe = function (string $regla, float $monto) use (&$lineas, $nomina, $texto) {
                if ($monto > 0) {
                    $lineas[] = $this->linea($nomina, $regla, $monto, 0, $texto);
                }
            };
            $haber = function (string $regla, float $monto) use (&$lineas, $nomina, $texto) {
                if ($monto > 0) {
                    $lineas[] = $this->linea($nomina, $regla, 0, $monto, $texto);
                }
            };

            // Lo que la gente se ganó.
            $debe('cta_nomina_sueldos', $t['salario'] + $t['adicional']);
            $debe('cta_nomina_extras', $t['extras']);
            $debe('cta_nomina_auxilio', $t['auxilio']);

            // Lo que pone la empresa encima del sueldo.
            $debe('cta_nomina_gasto_salud', $t['pat_salud']);
            $debe('cta_nomina_gasto_pension', $t['pat_pension']);
            $debe('cta_nomina_gasto_arl', $t['pat_arl']);
            $debe('cta_nomina_gasto_caja', $t['pat_caja']);
            $debe('cta_nomina_gasto_icbf', $t['pat_icbf']);
            $debe('cta_nomina_gasto_sena', $t['pat_sena']);

            // Lo que se guarda para después.
            $debe('cta_nomina_gasto_cesantias', $t['prov_cesantias']);
            $debe('cta_nomina_gasto_intereses', $t['prov_intereses']);
            $debe('cta_nomina_gasto_prima', $t['prov_prima']);
            $debe('cta_nomina_gasto_vacaciones', $t['prov_vacaciones']);

            // El ausentismo: menor gasto, no pasivo.
            $haber('cta_nomina_sueldos', $t['ausentismo']);

            // Lo que se le gira a cada entidad (empleado + empresa).
            $haber('cta_nomina_por_pagar_salud', $t['ded_salud'] + $t['pat_salud']);
            $haber('cta_nomina_por_pagar_pension', $t['ded_pension'] + $t['pat_pension']);
            $haber('cta_nomina_por_pagar_arl', $t['pat_arl']);
            $haber('cta_nomina_por_pagar_parafiscales', $t['pat_caja'] + $t['pat_icbf'] + $t['pat_sena']);

            // El pasivo prestacional que se acumula.
            $haber('cta_nomina_provision_cesantias', $t['prov_cesantias']);
            $haber('cta_nomina_provision_intereses', $t['prov_intereses']);
            $haber('cta_nomina_provision_prima', $t['prov_prima']);
            $haber('cta_nomina_provision_vacaciones', $t['prov_vacaciones']);

            // Y lo que se le consigna a la gente.
            $haber('cta_nomina_salarios_por_pagar', $t['neto']);

            return MovimientoContable::registrarAsientoAtomico($lineas);
        });
    }

    /**
     * Pago: se cancela lo que se le debe a la gente contra el banco.
     *
     * Si la nómina nunca se causó —porque es anterior a que esto existiera— se
     * causa primero. Si no, el pago debitaría un pasivo que nunca se acreditó
     * y salarios por pagar quedaría en negativo para siempre.
     */
    public function pagar(Nomina $nomina): int
    {
        if ($this->yaTieneAsiento($nomina, pago: true)) {
            return 0;
        }

        $t = $this->totales($nomina);
        if ($t['neto'] <= 0) {
            return 0;
        }

        $this->causar($nomina);

        return DB::transaction(function () use ($nomina, $t) {
            $texto = "Nómina {$nomina->periodo}".self::SUFIJO_PAGO;

            return MovimientoContable::registrarAsientoAtomico([
                $this->linea($nomina, 'cta_nomina_salarios_por_pagar', $t['neto'], 0, $texto),
                $this->linea($nomina, 'cta_bancos_default', 0, $t['neto'], $texto),
            ]);
        });
    }

    /**
     * Suma las líneas de la nómina.
     *
     * Se suma desde las líneas y no desde los totales del encabezado a
     * propósito: el encabezado de la nómina de octubre quedó con aportes y
     * provisiones en cero porque se liquidó dos horas antes de que esas
     * columnas existieran, y las líneas sí traen el detalle. Lo que se asienta
     * es lo que se le pagó a cada quien.
     *
     * @return array<string,float>
     */
    private function totales(Nomina $nomina): array
    {
        $campos = [
            'salario' => 'devengado_salario',
            'auxilio' => 'devengado_auxilio',
            'adicional' => 'devengado_adicional',
            'extras' => 'devengado_extras',
            'ded_salud' => 'deduccion_salud',
            'ded_pension' => 'deduccion_pension',
            'ausentismo' => 'deduccion_ausentismo',
            'pat_salud' => 'patronal_salud',
            'pat_pension' => 'patronal_pension',
            'pat_arl' => 'patronal_arl',
            'pat_caja' => 'patronal_caja',
            'pat_sena' => 'patronal_sena',
            'pat_icbf' => 'patronal_icbf',
            'prov_cesantias' => 'provision_cesantias',
            'prov_intereses' => 'provision_intereses',
            'prov_prima' => 'provision_prima',
            'prov_vacaciones' => 'provision_vacaciones',
            'devengado' => 'total_devengado',
            'neto' => 'neto',
        ];

        $lineas = $nomina->lineas()->get();
        $t = [];
        foreach ($campos as $alias => $columna) {
            $t[$alias] = round((float) $lineas->sum($columna), 2);
        }

        return $t;
    }

    /** @return array<string,mixed> */
    private function linea(Nomina $nomina, string $regla, float $debe, float $haber, string $texto): array
    {
        return [
            'fecha' => $nomina->fecha_pago ?: $nomina->aprobada_at ?: now(),
            'cuenta_puc' => (string) setting('contable.'.$regla),
            'debe' => round($debe, 2),
            'haber' => round($haber, 2),
            'origen_type' => Nomina::class,
            'origen_id' => $nomina->id,
            'descripcion' => $texto,
            /*
             * La nómina es de la operación propia y se declara: no hay aquí
             * nada que repartir entre lo fiscal y lo interno. Un sueldo o se
             * pagó o no se pagó, y si se pagó la DIAN lo quiere ver.
             */
            'linea_negocio' => LineaNegocio::GreatBaby,
            'destino_fiscal' => DestinoFiscal::Siigo,
            'user_id' => auth()->id(),
        ];
    }

    private function yaTieneAsiento(Nomina $nomina, bool $pago): bool
    {
        $q = MovimientoContable::query()
            ->where('origen_type', Nomina::class)
            ->where('origen_id', $nomina->id);

        return $pago
            ? $q->where('descripcion', 'like', '%'.self::SUFIJO_PAGO)->exists()
            : $q->where('descripcion', 'not like', '%'.self::SUFIJO_PAGO)->exists();
    }
}
