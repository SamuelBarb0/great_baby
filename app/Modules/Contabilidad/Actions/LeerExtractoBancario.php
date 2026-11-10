<?php

namespace App\Modules\Contabilidad\Actions;

use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Lee un extracto bancario pegado como texto y lo vuelve movimientos.
 *
 * La conciliación existía como tabla y como modelo —`conciliaciones_bancarias`,
 * con su saldo de extracto y su diferencia— pero no estaba enganchada a ninguna
 * pantalla ni a ninguna ruta: lo único que se podía guardar era un saldo escrito
 * a mano y la resta contra el sistema. Eso dice CUÁNTO sobra o falta; no dice
 * QUÉ sobra ni qué falta, que es lo que hay que buscar a mano después.
 *
 * El formato es el del extracto de Bancolombia, que es el que usa Great Baby:
 *
 *     FECHA DESCRIPCIÓN SUCURSAL DCTO. VALOR SALDO
 *     1/09 TRANSFERENCIA DESDE NEQUI 355,000.00 227,920,668.05
 *     1/09 PAGO A PROV administradorlogis -2,480,436.00 227,140,895.21
 *
 * Tres detalles del formato que importan:
 *
 *   · La fecha viene SIN año (`1/09`). El año sale del encabezado
 *     «DESDE: 2026/08/31 HASTA: 2026/09/30», y se elige el que deje la fecha
 *     dentro del período —si no, un extracto de diciembre a enero pondría los
 *     movimientos de enero un año antes.
 *   · El valor ya viene con signo: negativo es cargo, positivo es abono. No hay
 *     que adivinar por la descripción.
 *   · Cada página repite el encabezado. Las líneas que no son movimiento no
 *     casan con el patrón y se descartan solas.
 *
 * Al final se comprueba la identidad del propio extracto:
 * saldo anterior + abonos − cargos = saldo actual. Si no cuadra, el extracto se
 * leyó mal y no se concilia nada: más vale no conciliar que conciliar con un
 * movimiento perdido en el camino.
 */
class LeerExtractoBancario
{
    use AsAction;

    /** `1/09  DESCRIPCIÓN  -2,480,436.00  227,140,895.21` */
    private const MOVIMIENTO = '/^\s*(\d{1,2})\/(\d{1,2})\s+(.+?)\s+(-?[\d.,]+)\s+(-?[\d.,]+)\s*$/u';

    public function handle(string $texto): array
    {
        $lineas = preg_split('/\R/u', $texto) ?: [];

        [$desde, $hasta] = $this->periodo($lineas);
        $resumen = $this->resumen($lineas);

        $movimientos = [];
        foreach ($lineas as $cruda) {
            if (! preg_match(self::MOVIMIENTO, $cruda, $m)) {
                continue;
            }

            $fecha = $this->fechaConAnio((int) $m[1], (int) $m[2], $desde, $hasta);
            if (! $fecha) {
                continue;
            }

            $movimientos[] = [
                'fecha' => $fecha->toDateString(),
                'descripcion' => trim(preg_replace('/\s+/u', ' ', $m[3])),
                'valor' => $this->aNumero($m[4]),
                'saldo' => $this->aNumero($m[5]),
            ];
        }

        $abonos = array_sum(array_map(fn ($m) => max(0, $m['valor']), $movimientos));
        $cargos = array_sum(array_map(fn ($m) => max(0, -$m['valor']), $movimientos));

        // El extracto se revisa contra sí mismo antes de creerle nada.
        $esperado = $resumen['saldo_anterior'] + $abonos - $cargos;
        $descuadre = $resumen['saldo_actual'] === null
            ? null
            : round($resumen['saldo_actual'] - $esperado, 2);

        return [
            'desde' => $desde?->toDateString(),
            'hasta' => $hasta?->toDateString(),
            'cuenta' => $this->cuenta($lineas),
            'saldo_anterior' => $resumen['saldo_anterior'],
            'saldo_actual' => $resumen['saldo_actual'],
            'total_abonos' => round($abonos, 2),
            'total_cargos' => round($cargos, 2),
            'movimientos' => $movimientos,
            'descuadre' => $descuadre,
            // Sin saldos en el encabezado no se puede comprobar nada; se avisa
            // en vez de dar por buena una lectura que nadie verificó.
            'cuadra' => $descuadre !== null && abs($descuadre) < 0.01,
            'verificable' => $descuadre !== null,
        ];
    }

    /** «DESDE: 2026/08/31 HASTA: 2026/09/30» */
    private function periodo(array $lineas): array
    {
        foreach ($lineas as $l) {
            if (preg_match('/DESDE:\s*(\d{4})\/(\d{1,2})\/(\d{1,2}).*HASTA:\s*(\d{4})\/(\d{1,2})\/(\d{1,2})/u', $l, $m)) {
                return [
                    CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3]),
                    CarbonImmutable::create((int) $m[4], (int) $m[5], (int) $m[6]),
                ];
            }
        }

        return [null, null];
    }

    private function cuenta(array $lineas): ?string
    {
        foreach ($lineas as $l) {
            if (preg_match('/N[ÚU]?MERO\s+(\d{6,})/ui', $l, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function resumen(array $lineas): array
    {
        $saldoAnterior = 0.0;
        $saldoActual = null;

        foreach ($lineas as $l) {
            if ($saldoActual === null && preg_match('/SALDO\s+ACTUAL\s*\$?\s*(-?[\d.,]+)/ui', $l, $m)) {
                $saldoActual = $this->aNumero($m[1]);
            }
            if (preg_match('/SALDO\s+ANTERIOR\s*\$?\s*(-?[\d.,]+)/ui', $l, $m)) {
                $saldoAnterior = $this->aNumero($m[1]);
            }
        }

        return ['saldo_anterior' => $saldoAnterior, 'saldo_actual' => $saldoActual];
    }

    /**
     * El día y el mes vienen del movimiento; el año, del período.
     *
     * Un extracto que cruza diciembre trae `28/12` y `03/01`: el año del
     * segundo es el del `hasta`, no el del `desde`.
     */
    private function fechaConAnio(int $dia, int $mes, ?CarbonImmutable $desde, ?CarbonImmutable $hasta): ?CarbonImmutable
    {
        if ($mes < 1 || $mes > 12 || $dia < 1 || $dia > 31) {
            return null;
        }

        $anios = array_unique(array_filter([$desde?->year, $hasta?->year])) ?: [now()->year];

        foreach ($anios as $anio) {
            if (! checkdate($mes, $dia, $anio)) {
                continue;
            }
            $f = CarbonImmutable::create($anio, $mes, $dia);
            if (! $desde || ! $hasta || $f->betweenIncluded($desde, $hasta)) {
                return $f;
            }
        }

        return checkdate($mes, $dia, (int) reset($anios))
            ? CarbonImmutable::create((int) reset($anios), $mes, $dia)
            : null;
    }

    /**
     * «-2,480,436.00» → -2480436.0
     *
     * El extracto usa coma de miles y punto decimal. Si algún banco los
     * invierte («2.480.436,00»), se detecta por cuál separador va de último.
     */
    private function aNumero(string $crudo): float
    {
        $s = trim(str_replace(['$', ' ', "\u{00A0}"], '', $crudo));
        $ultimaComa = strrpos($s, ',');
        $ultimoPunto = strrpos($s, '.');

        if ($ultimaComa !== false && ($ultimoPunto === false || $ultimaComa > $ultimoPunto)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return round((float) $s, 2);
    }
}
