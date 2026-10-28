<?php

namespace App\Modules\Contabilidad\Actions;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Rrhh\Models\Liquidacion;
use Illuminate\Support\Facades\DB;

/**
 * El asiento de la liquidación definitiva.
 *
 * El gemelo de `RegistrarAsientoNomina`, y tenía el mismo hueco: se liquidaba a
 * quien se iba, gerencia aprobaba, tesorería pagaba, y el libro no registraba
 * nada. Hay $1.797.892 aprobados y sin pagar de una persona que se retiró en
 * junio; ninguno de esos pesos existe en la contabilidad.
 *
 * ── Qué lo hace distinto de la nómina del mes ────────────────────────────
 *
 * La nómina mensual **crea** el pasivo prestacional: provisiona cesantías,
 * prima y vacaciones mes a mes. La liquidación lo **cancela**: lo que se le
 * paga a quien se va sale de esas provisiones, no es un gasto nuevo. Por eso
 * acá las cuentas de cesantías, intereses, prima y vacaciones van al DEBE —se
 * está pagando un pasivo— y no al gasto.
 *
 * La única excepción es la indemnización: nadie provisiona despidos, así que
 * ésa sí es gasto del período en que se decide.
 *
 *   DEBE   cesantías, intereses, prima, vacaciones   el pasivo que se cancela
 *   DEBE   indemnización                             gasto del período
 *   HABER  otros descuentos                          lo que la persona debía
 *   HABER  salarios por pagar                        el neto que se le gira
 *
 * ── Un aviso que conviene leer ───────────────────────────────────────────
 *
 * Si la empresa nunca provisionó —y hasta hoy no provisionaba, porque la
 * nómina no asentaba— estas cuentas van a quedar en negativo al pagar la
 * primera liquidación. No es un error del asiento: es la verdad apareciendo.
 * Dice que se está pagando una prestación que nunca se guardó. Se corrige
 * solo a medida que las nóminas nuevas vayan provisionando.
 */
class RegistrarAsientoLiquidacion
{
    /** Marca que distingue el asiento de pago del de causación. */
    private const SUFIJO_PAGO = ' · pago';

    /**
     * Causación: lo que se le debe a quien se va entra al libro.
     *
     * Idempotente: reaprobar o reintentar no duplica la liquidación.
     */
    public function causar(Liquidacion $liq): int
    {
        if ($this->yaTieneAsiento($liq, pago: false)) {
            return 0;
        }

        $neto = round((float) $liq->neto, 2);
        if ($neto <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($liq, $neto) {
            $texto = "Liquidación definitiva · {$liq->nombre}";
            $lineas = [];

            $poner = function (string $regla, float $debe, float $haber) use (&$lineas, $liq, $texto) {
                if ($debe > 0 || $haber > 0) {
                    $lineas[] = $this->linea($liq, $regla, $debe, $haber, $texto);
                }
            };

            // Lo que sale de las provisiones.
            $poner('cta_nomina_provision_cesantias', (float) $liq->cesantias, 0);
            $poner('cta_nomina_provision_intereses', (float) $liq->intereses_cesantias, 0);
            $poner('cta_nomina_provision_prima', (float) $liq->prima, 0);
            $poner('cta_nomina_provision_vacaciones', (float) $liq->vacaciones, 0);

            // Lo que es gasto nuevo.
            $poner('cta_liquidacion_indemnizacion', (float) $liq->indemnizacion, 0);

            /*
             * Lo que se le descuenta del neto, que son dos cosas distintas:
             *
             *  · las cesantías que la empresa YA giró al fondo — el trabajador
             *    las cobra allá, así que no salen de nuestro banco; y
             *  · lo que la persona le debía a la empresa.
             *
             * Sin esta parte el asiento no cuadra: `neto` es lo que queda
             * después de restarlas, y los débitos de arriba son el bruto.
             */
            $poner('cta_liquidacion_cesantias_consignadas', 0, (float) $liq->cesantias_ya_pagadas);
            $poner('cta_liquidacion_descuentos', 0, (float) $liq->otros_descuentos);

            // Y lo que se le gira.
            $poner('cta_nomina_salarios_por_pagar', 0, $neto);

            return MovimientoContable::registrarAsientoAtomico($lineas);
        });
    }

    /** Pago: se cancela contra el banco lo que se le debía. */
    public function pagar(Liquidacion $liq): int
    {
        if ($this->yaTieneAsiento($liq, pago: true)) {
            return 0;
        }

        $neto = round((float) $liq->neto, 2);
        if ($neto <= 0) {
            return 0;
        }

        // Si nunca se causó —liquidaciones anteriores a que esto existiera— se
        // causa primero, o el pago debitaría un pasivo que nadie acreditó.
        $this->causar($liq);

        return DB::transaction(function () use ($liq, $neto) {
            $texto = "Liquidación definitiva · {$liq->nombre}".self::SUFIJO_PAGO;

            return MovimientoContable::registrarAsientoAtomico([
                $this->linea($liq, 'cta_nomina_salarios_por_pagar', $neto, 0, $texto),
                $this->linea($liq, 'cta_bancos_default', 0, $neto, $texto),
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function linea(Liquidacion $liq, string $regla, float $debe, float $haber, string $texto): array
    {
        return [
            'fecha' => $liq->fecha_pago ?: $liq->aprobada_at ?: $liq->fecha_retiro ?: now(),
            'cuenta_puc' => (string) setting('contable.'.$regla),
            'debe' => round($debe, 2),
            'haber' => round($haber, 2),
            'origen_type' => Liquidacion::class,
            'origen_id' => $liq->id,
            'descripcion' => $texto,
            'linea_negocio' => LineaNegocio::GreatBaby,
            'destino_fiscal' => DestinoFiscal::Siigo,
            'user_id' => auth()->id(),
        ];
    }

    private function yaTieneAsiento(Liquidacion $liq, bool $pago): bool
    {
        $q = MovimientoContable::query()
            ->where('origen_type', Liquidacion::class)
            ->where('origen_id', $liq->id);

        return $pago
            ? $q->where('descripcion', 'like', '%'.self::SUFIJO_PAGO)->exists()
            : $q->where('descripcion', 'not like', '%'.self::SUFIJO_PAGO)->exists();
    }
}
