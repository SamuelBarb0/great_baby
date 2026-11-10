<?php

namespace App\Modules\Contabilidad\Actions;

use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Models\PagoVenta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Cruza los movimientos del extracto contra los pagos del ERP.
 *
 * La conciliación que había decía CUÁNTO sobraba: un saldo escrito a mano menos
 * el saldo del sistema. Lo que no decía es QUÉ sobra, que es justo lo que toca
 * buscar a mano después, línea por línea, con el extracto impreso al lado.
 *
 * Acá el cruce queda en tres montones, y los tres importan:
 *
 *   · CONCILIADO  — el movimiento del banco tiene su pago en el ERP.
 *   · SÓLO BANCO  — plata que se movió y nadie registró. Acá viven los costos
 *     que cobra el banco solo (4x1000, cuota de manejo, IVA, intereses): no son
 *     errores, son gastos que faltan asentar, y por eso se les sugiere cuenta.
 *   · SÓLO ERP    — pagos registrados que el banco nunca vio. Un recibo mal
 *     digitado, un cheque que no se cobró, o plata que entró por otro lado.
 *
 * El cruce es por valor exacto y fecha cercana, y cada pago se usa UNA vez: dos
 * consignaciones de $1.000.000 el mismo día son dos movimientos, no uno contado
 * dos veces. Entre varios candidatos gana el de fecha más cercana, porque el
 * banco suele registrar el mismo día o el siguiente.
 */
class ConciliarExtracto
{
    use AsAction;

    /**
     * @param  string|null  $periodoDesde  El período que declara el extracto.
     * @param  string|null  $periodoHasta  Sin él se usa el de los movimientos.
     */
    public function handle(
        array $movimientos,
        ?int $diasTolerancia = null,
        ?string $periodoDesde = null,
        ?string $periodoHasta = null,
    ): array {
        $dias = $diasTolerancia ?? max(0, (int) setting('contable.dias_match_extracto'));

        $fechas = array_column($movimientos, 'fecha');
        if (! $fechas) {
            return [
                'conciliados' => [], 'solo_banco' => [], 'solo_erp' => [],
                'resumen' => $this->resumen([], [], []),
            ];
        }

        /*
         * La ventana es la del EXTRACTO, no la del primer y último movimiento.
         *
         * Si el extracto va del 1 al 30 pero el primer movimiento cae el 3,
         * tomar el 3 menos la holgura mete pagos de fin del mes pasado —que le
         * tocan a la conciliación anterior— y todos salen listados como «plata
         * que el banco nunca vio». El montón de SÓLO ERP es justamente el que
         * hay que perseguir a mano, así que ensuciarlo tiene costo real.
         *
         * Cuando el encabezado no trae período se cae a los movimientos, que
         * es lo que se hacía antes.
         */
        $desde = Carbon::parse($periodoDesde ?: min($fechas))->subDays($dias);
        $hasta = Carbon::parse($periodoHasta ?: max($fechas))->addDays($dias);

        $entradas = $this->candidatosEntrada($desde, $hasta);
        $salidas = $this->candidatosSalida($desde, $hasta);

        $conciliados = [];
        $soloBanco = [];

        foreach ($movimientos as $mov) {
            $esAbono = $mov['valor'] > 0;
            $bolsa = $esAbono ? $entradas : $salidas;

            $elegido = $this->mejorCandidato($bolsa, abs((float) $mov['valor']), $mov['fecha'], $dias);

            if ($elegido === null) {
                $soloBanco[] = $mov + [
                    'tipo' => $esAbono ? 'abono' : 'cargo',
                    'cuenta_sugerida' => $this->cuentaDelConcepto($mov['descripcion']),
                ];

                continue;
            }

            // Usado una vez y fuera de la bolsa.
            if ($esAbono) {
                unset($entradas[$elegido['clave']]);
            } else {
                unset($salidas[$elegido['clave']]);
            }

            $conciliados[] = $mov + [
                'tipo' => $esAbono ? 'abono' : 'cargo',
                'pago_tipo' => $elegido['pago_tipo'],
                'pago_id' => $elegido['id'],
                'pago_ref' => $elegido['referencia'],
                'pago_fecha' => $elegido['fecha'],
                'pago_tercero' => $elegido['tercero'],
                'dias_desfase' => $elegido['desfase'],
            ];
        }

        $soloErp = array_values(array_map(
            fn ($p) => [
                'pago_tipo' => $p['pago_tipo'],
                'pago_id' => $p['id'],
                'fecha' => $p['fecha'],
                'valor' => $p['pago_tipo'] === 'venta' ? $p['monto'] : -$p['monto'],
                'referencia' => $p['referencia'],
                'tercero' => $p['tercero'],
            ],
            array_merge($entradas, $salidas)
        ));

        usort($soloErp, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));

        return [
            'conciliados' => $conciliados,
            'solo_banco' => $soloBanco,
            'solo_erp' => $soloErp,
            'resumen' => $this->resumen($conciliados, $soloBanco, $soloErp),
        ];
    }

    /**
     * El candidato que más se parece: mismo valor, la fecha más cerca.
     *
     * El valor se compara al peso. Los centavos del extracto son reales
     * (intereses, IVA) pero un pago del ERP nunca los tiene, así que redondear
     * los dos lados evita que un `.00` contra `.004` deje de casar.
     */
    private function mejorCandidato(array $bolsa, float $valor, string $fecha, int $dias): ?array
    {
        $objetivo = (int) round($valor);
        $f = Carbon::parse($fecha);
        $mejor = null;

        foreach ($bolsa as $clave => $p) {
            if ((int) round($p['monto']) !== $objetivo) {
                continue;
            }

            $desfase = (int) abs($f->diffInDays(Carbon::parse($p['fecha'])));
            if ($desfase > $dias) {
                continue;
            }

            if ($mejor === null || $desfase < $mejor['desfase']) {
                $mejor = $p + ['clave' => $clave, 'desfase' => $desfase];
            }
        }

        return $mejor;
    }

    /** El cobro de una guía de Dropi no es un movimiento de banco. */
    private const WALLET_DROPI = 'wallet_dropi';

    /**
     * Los medios que NO pueden estar en el extracto. Se excluyen; el resto pasa.
     *
     * Se lista lo que se descarta, no lo que se acepta, y eso es a propósito:
     * si mañana alguien registra un pago con un medio que no está en el maestro
     * —un cheque, pongamos—, con la lista al revés se caería del cruce sin que
     * nadie se entere y su línea del banco aparecería como «plata que nadie
     * registró». Equivocarse de más es barato: sale en «sólo ERP», se marca a
     * mano y listo. Equivocarse de menos esconde un pago real.
     *
     * El maestro ya dice a qué cuenta del PUC va cada medio, así que no hay que
     * inventar la lista: 1105 es CAJA —el efectivo no entra nunca al extracto—
     * y sin cuenta están `cruce_cuentas` y `nec`, que no mueven un peso.
     *
     * Aparte va la wallet de Dropi, que ni siquiera está en el maestro: la
     * sincronización crea un PagoVenta por CADA guía cobrada, así que en un mes
     * son el grueso de los pagos y ninguno pasa por Bancolombia. Sin esto, cada
     * conciliación arrancaba con decenas de líneas falsas en «sólo ERP» y la
     * pantalla decía que no se podía asentar.
     */
    private function mediosQueNoVanAlBanco(): array
    {
        $delMaestro = DB::table('metodos_pago')
            ->where(fn ($q) => $q->whereNull('cuenta_puc')
                ->orWhere('cuenta_puc', 'like', '1105%'))
            ->pluck('codigo')
            ->all();

        return array_values(array_unique([...$delMaestro, self::WALLET_DROPI]));
    }

    /**
     * Saca de la bolsa lo que no pudo haber pasado por el banco.
     *
     * El `orWhereNull` no sobra: en SQL `NULL NOT IN (...)` no es verdadero,
     * así que sin eso un pago viejo sin medio registrado se caería del cruce,
     * que es justo lo contrario de lo que se busca.
     */
    private function soloLoQuePasaPorElBanco($query, string $columna)
    {
        $fuera = $this->mediosQueNoVanAlBanco();

        return $query->where(fn ($q) => $q->whereNotIn($columna, $fuera)->orWhereNull($columna));
    }

    /** Pagos de clientes: plata que entra. */
    private function candidatosEntrada(Carbon $desde, Carbon $hasta): array
    {
        return $this->soloLoQuePasaPorElBanco(
            PagoVenta::query()
                ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()]),
            'medio_pago'
        )
            ->with('contacto:id,nombre_completo')
            // Orden fijo: la previsualización y el guardado recorren la misma
            // bolsa, y entre dos pagos iguales tienen que elegir el mismo.
            ->orderBy('fecha')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (PagoVenta $p) => ['venta-'.$p->id => [
                'pago_tipo' => 'venta',
                'id' => $p->id,
                'monto' => (float) $p->monto_recibido,
                'fecha' => Carbon::parse($p->fecha)->toDateString(),
                'referencia' => $p->referencia ?: (string) ($p->medio_pago ?? ''),
                'tercero' => $p->contacto?->nombre_completo ?? '',
            ]])->all();
    }

    /** Pagos a proveedores: plata que sale. Un pago anulado no debería estar en el banco. */
    private function candidatosSalida(Carbon $desde, Carbon $hasta): array
    {
        return $this->soloLoQuePasaPorElBanco(
            PagoProveedor::query()
                ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
                ->where('estado', '!=', 'anulado'),
            'metodo'
        )
            ->with('proveedor:id,nombre_completo')
            ->orderBy('fecha')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (PagoProveedor $p) => ['proveedor-'.$p->id => [
                'pago_tipo' => 'proveedor',
                'id' => $p->id,
                'monto' => (float) $p->monto_neto,
                'fecha' => Carbon::parse($p->fecha)->toDateString(),
                'referencia' => $p->siigo_voucher_id ?: (string) ($p->metodo ?? ''),
                'tercero' => $p->proveedor?->nombre_completo ?? '',
            ]])->all();
    }

    /**
     * Qué cuenta le corresponde a un cobro que hace el banco solo.
     *
     * El 4x1000, la cuota de manejo y su IVA aparecen decenas de veces al mes y
     * no los registra nadie: no son errores, son gastos que el banco descuenta
     * y que igual hay que asentar. El mapa vive en Reglas para que contabilidad
     * lo ajuste a SU plan de cuentas sin tocar código.
     */
    private function cuentaDelConcepto(string $descripcion): ?array
    {
        $mapa = setting('contable.conceptos_banco');
        $mapa = is_array($mapa) ? $mapa : (json_decode((string) $mapa, true) ?: []);

        $texto = $this->sinTildes(mb_strtoupper($descripcion));

        foreach ($mapa as $clave => $cuenta) {
            if (str_contains($texto, $this->sinTildes(mb_strtoupper((string) $clave)))) {
                return ['concepto' => (string) $clave, 'cuenta' => (string) $cuenta];
            }
        }

        return null;
    }

    private function sinTildes(string $s): string
    {
        return strtr($s, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
    }

    private function resumen(array $conciliados, array $soloBanco, array $soloErp): array
    {
        $suma = fn (array $xs) => round(array_sum(array_map(fn ($x) => (float) $x['valor'], $xs)), 2);

        return [
            'conciliados' => count($conciliados),
            'solo_banco' => count($soloBanco),
            'solo_erp' => count($soloErp),
            'monto_conciliado' => $suma($conciliados),
            'monto_solo_banco' => $suma($soloBanco),
            'monto_solo_erp' => $suma($soloErp),
        ];
    }
}
