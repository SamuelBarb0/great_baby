<?php

namespace App\Modules\Contabilidad\Actions;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Gerencia\Models\GastoOperativo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El asiento de un gasto operativo.
 *
 * Esto NO EXISTÍA. La pantalla de gastos tenía el flujo completo —crear,
 * aprobar, marcar pagado— y no generaba ni un movimiento contable. O sea: se
 * aprobaba un gasto, se pagaba, la pantalla confirmaba que todo salió bien, y
 * el balance general y el estado de resultados no se enteraban nunca. Quien
 * mirara la utilidad del mes la veía más alta de lo real por todo lo gastado.
 *
 * Son DOS asientos, no uno, porque son dos hechos económicos distintos:
 *
 *   1) **Causación**, al aprobar — la obligación ya existe aunque no se haya
 *      pagado:
 *          DEBE  5xxx  gasto
 *          HABER 2335  costos y gastos por pagar
 *
 *   2) **Pago**, al marcar pagado — se cancela la obligación:
 *          DEBE  2335  costos y gastos por pagar
 *          HABER 1105/1110  caja o banco
 *
 * Juntar los dos en uno al pagar sería más corto y estaría mal: entre la
 * aprobación y el pago el pasivo existe, y es justo lo que alguien necesita ver
 * para saber cuánto debe la empresa.
 *
 * Los dos ejes salen del gasto, igual que en compras y en ventas.
 */
class RegistrarAsientoGasto
{
    /** Marca que distingue el asiento de pago del de causación. */
    private const SUFIJO_PAGO = ' · pago';

    /**
     * Causación: el gasto aprobado entra al libro como gasto y como pasivo.
     *
     * Idempotente: si ya se causó, no vuelve a asentar. Re-aprobar un gasto o
     * reintentar el job no puede duplicar el gasto en el estado de resultados.
     */
    public function causar(GastoOperativo $gasto): int
    {
        if ($this->yaTieneAsiento($gasto, pago: false)) {
            return 0;
        }

        $monto = round((float) $gasto->monto, 2);
        if ($monto <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($gasto, $monto) {
            $destino = $this->destinoDe($gasto);
            $texto = "Gasto {$gasto->numero} — {$gasto->descripcion}";

            return MovimientoContable::registrarAsientoAtomico([
                $this->linea($gasto, $this->cuentaDeGasto($gasto), $monto, 0, $texto, $destino),
                $this->linea($gasto, $this->cuentaPorPagar(), 0, $monto, $texto, $destino),
            ]);
        });
    }

    /**
     * Pago: se cancela el pasivo contra caja o banco.
     *
     * Si el gasto nunca se causó —porque viene de antes de que esto existiera—
     * se causa primero. Si no, el pago debitaría un pasivo que nunca se
     * acreditó y 2335 quedaría en negativo para siempre.
     */
    public function pagar(GastoOperativo $gasto): int
    {
        if ($this->yaTieneAsiento($gasto, pago: true)) {
            return 0;
        }

        $monto = round((float) $gasto->monto, 2);
        if ($monto <= 0) {
            return 0;
        }

        $this->causar($gasto);

        return DB::transaction(function () use ($gasto, $monto) {
            $destino = $this->destinoDe($gasto);
            $texto = "Gasto {$gasto->numero} — {$gasto->descripcion}".self::SUFIJO_PAGO;

            return MovimientoContable::registrarAsientoAtomico([
                $this->linea($gasto, $this->cuentaPorPagar(), $monto, 0, $texto, $destino),
                $this->linea($gasto, $this->cuentaDeCaja($gasto), 0, $monto, $texto, $destino),
            ]);
        });
    }

    /**
     * Rehace los dos asientos desde cero.
     *
     * Hace falta cuando cambia algo que ya se asentó: la cuenta de gasto que el
     * contador reclasifica, o el destino fiscal. Sin esto el libro sigue
     * mostrando lo de antes y sólo se descubre en el cierre.
     */
    public function rehacer(GastoOperativo $gasto): void
    {
        DB::transaction(function () use ($gasto) {
            MovimientoContable::query()
                ->where('origen_type', GastoOperativo::class)
                ->where('origen_id', $gasto->id)
                ->forceDelete();

            if (in_array($gasto->estado, ['aprobado', 'pagado'], true)) {
                $this->causar($gasto);
            }
            if ($gasto->estado === 'pagado') {
                $this->pagar($gasto);
            }
        });
    }

    /**
     * Contra qué cuenta se debita.
     *
     * La `categoria` del gasto es texto libre («papelería», «arriendo»), así
     * que no sirve para elegir una cuenta del PUC: la pone el contador. Si la
     * dejó vacía se usa la cuenta por defecto, que es un cajón de sastre a
     * propósito — lo importante es que el gasto ENTRE al libro; reclasificarlo
     * después es trivial, descubrir en el cierre que nunca entró no lo es.
     */
    private function cuentaDeGasto(GastoOperativo $gasto): string
    {
        $cuenta = trim((string) $gasto->cuenta_puc);

        return $cuenta !== '' ? $cuenta : (string) setting('contable.cta_gasto_default');
    }

    private function cuentaPorPagar(): string
    {
        return (string) setting('contable.cta_gastos_por_pagar');
    }

    private function cuentaDeCaja(GastoOperativo $gasto): string
    {
        return $gasto->metodo_pago === 'efectivo'
            ? (string) setting('contable.cta_caja_default')
            : (string) setting('contable.cta_bancos_default');
    }

    /**
     * El destino del gasto, sin dejar pasar «reparto».
     *
     * Un gasto no se reparte: o tiene factura del proveedor con qué soportarlo
     * y se declara, o no la tiene y no se declara. Si alguien marcó reparto por
     * algún camino, cae en interno, que es lo conservador.
     */
    private function destinoDe(GastoOperativo $gasto): DestinoFiscal
    {
        $destino = $gasto->destino_fiscal ?? DestinoFiscal::porDefecto();

        return $destino === DestinoFiscal::Reparto ? DestinoFiscal::Interno : $destino;
    }

    /** @return array<string,mixed> */
    private function linea(
        GastoOperativo $gasto, string $cuenta, float $debe, float $haber, string $texto, DestinoFiscal $destino
    ): array {
        if (trim($cuenta) === '') {
            throw new RuntimeException(
                "El gasto {$gasto->numero} no tiene cuenta contable y la cuenta por defecto está vacía. "
                .'Configurala en Reglas de negocio → Contable.'
            );
        }

        return [
            'fecha' => $gasto->fecha,
            'cuenta_puc' => $cuenta,
            'debe' => $debe, 'haber' => $haber,
            'origen_type' => GastoOperativo::class, 'origen_id' => $gasto->id,
            'descripcion' => $texto,
            'linea_negocio' => $gasto->linea_negocio,
            'destino_fiscal' => $destino,
            'user_id' => auth()->id(),
        ];
    }

    private function yaTieneAsiento(GastoOperativo $gasto, bool $pago): bool
    {
        $q = MovimientoContable::query()
            ->where('origen_type', GastoOperativo::class)
            ->where('origen_id', $gasto->id);

        // Los dos asientos cuelgan del mismo documento, así que se distinguen
        // por la marca en la descripción.
        $pago
            ? $q->where('descripcion', 'like', '%'.self::SUFIJO_PAGO)
            : $q->where('descripcion', 'not like', '%'.self::SUFIJO_PAGO);

        return $q->exists();
    }
}
