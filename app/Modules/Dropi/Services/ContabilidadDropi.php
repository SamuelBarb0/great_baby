<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Cartera\Actions\CalcularAntiguedadCartera;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Support\EstadosFinancieros;
use App\Modules\Contabilidad\Support\FiltroEjes;
use Carbon\CarbonImmutable;

/**
 * La contabilidad del e-commerce (línea de negocio Dropi) de un mes, para la
 * página «Contabilidad Dropi» del panel /admin.
 *
 * Nada se calcula aquí por su cuenta: los saldos salen de EstadosFinancieros
 * (el mismo cálculo del balance y el estado de resultados de contabilidad) y la
 * antigüedad de CalcularAntiguedadCartera, filtrados con FiltroEjes a `dropi`.
 * Así esta página y las pantallas de contabilidad con «Línea: Dropi» dicen lo
 * mismo, siempre.
 */
class ContabilidadDropi
{
    /** @param  string|null  $mes  `YYYY-MM`; vacío o mal escrito = el mes en curso */
    public function delMes(?string $mes): array
    {
        $zona = 'America/Bogota';
        $inicio = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes)
            ? CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01', $zona)->startOfDay()
            : CarbonImmutable::now($zona)->startOfMonth();
        $fin = $inicio->endOfMonth();
        $hoy = CarbonImmutable::now($zona);
        // El balance es a una fecha: el cierre del mes, o hoy si el mes no ha terminado.
        $corte = $fin->greaterThan($hoy) ? $hoy : $fin;

        $ejes = FiltroEjes::de(LineaNegocio::Dropi->value, 'todas');

        // ---- Estado de resultados del mes (misma composición que contabilidad) ----
        $saldosMes = EstadosFinancieros::saldosPorCuenta($inicio->toDateString(), $fin->toDateString(), false, $ejes);
        $ingresos = EstadosFinancieros::agruparClase($saldosMes, '4', 'credito');
        $costoVentas = EstadosFinancieros::agruparClase($saldosMes, '6', 'debito');
        $gastos = EstadosFinancieros::agruparClase($saldosMes, '5', 'debito');
        $costosProd = EstadosFinancieros::agruparClase($saldosMes, '7', 'debito');
        $utilidadBruta = round($ingresos['total'] - $costoVentas['total'] - $costosProd['total'], 2);
        $utilidadNeta = round($utilidadBruta - $gastos['total'], 2);
        $margen = fn (float $v) => $ingresos['total'] > 0 ? round($v / $ingresos['total'] * 100, 1) : null;

        // ---- Balance general al corte (acumulado, como contabilidad) ----
        $saldos = EstadosFinancieros::saldosPorCuenta(null, $corte->toDateString(), false, $ejes);
        $activo = EstadosFinancieros::agruparClase($saldos, '1', 'debito');
        $pasivo = EstadosFinancieros::agruparClase($saldos, '2', 'credito');
        $patrimonio = EstadosFinancieros::agruparClase($saldos, '3', 'credito');
        $resultado = round(
            EstadosFinancieros::agruparClase($saldos, '4', 'credito')['total']
            - EstadosFinancieros::agruparClase($saldos, '5', 'debito')['total']
            - EstadosFinancieros::agruparClase($saldos, '6', 'debito')['total']
            - EstadosFinancieros::agruparClase($saldos, '7', 'debito')['total'], 2);
        $totalPatrimonio = round($patrimonio['total'] + $resultado, 2);
        $descuadre = round($activo['total'] - $pasivo['total'] - $totalPatrimonio, 2);

        // ---- Cartera del e-commerce ----
        $facturas = fn () => $ejes->aplicar(FacturaVenta::query());
        $pagos = fn () => $ejes->aplicarPor(PagoVenta::query(), 'factura');
        $antig = CalcularAntiguedadCartera::run(null, $ejes);

        $porCobrar = (float) $facturas()->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])->sum('saldo');
        $vencidas = $facturas()->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->where('fecha_vencimiento', '<', $hoy->toDateString())->count();
        $facturado = (float) $facturas()->where('estado', '!=', EstadoFactura::Anulada)
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()])->sum('total');
        $cobrado = (float) $pagos()->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])->sum('monto_aplicado');

        $notas = $ejes->aplicarPor(NotaCredito::query(), 'factura')
            ->whereBetween('created_at', [$inicio->startOfDay(), $fin->endOfDay()]);

        $ultimosPagos = $pagos()->with('factura:id,numero')
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->orderByDesc('fecha')->orderByDesc('id')->limit(10)->get()
            ->map(fn ($p) => [
                'fecha' => (string) ($p->fecha instanceof \DateTimeInterface ? $p->fecha->format('Y-m-d') : $p->fecha),
                'factura' => $p->factura?->numero ?? '—',
                'medio' => $p->medio_pago,
                'monto' => (float) $p->monto_aplicado,
            ])->all();

        return [
            'periodo' => [
                'mes' => $inicio->format('Y-m'),
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
                'corte' => $corte->toDateString(),
            ],
            'resultados' => [
                'ingresos' => $ingresos,
                'costo_ventas' => $costoVentas,
                'costos_produccion' => $costosProd,
                'gastos' => $gastos,
                'utilidad_bruta' => $utilidadBruta,
                'utilidad_neta' => $utilidadNeta,
                'margen_bruto' => $margen($utilidadBruta),
                'margen_neto' => $margen($utilidadNeta),
            ],
            'balance' => [
                'activo' => $activo,
                'pasivo' => $pasivo,
                'patrimonio' => $patrimonio,
                'resultado' => $resultado,
                'total_patrimonio' => $totalPatrimonio,
                'cuadra' => abs($descuadre) <= 0.01,
                'descuadre' => $descuadre,
            ],
            'cartera' => [
                'por_cobrar' => $porCobrar,
                'vencidas' => $vencidas,
                'facturado_mes' => $facturado,
                'cobrado_mes' => $cobrado,
                'antiguedad' => collect(TramoAntiguedad::cases())->map(fn ($t) => [
                    'tramo' => $t->label(),
                    'monto' => (float) ($antig['por_tramo'][$t->value]['monto'] ?? 0),
                    'facturas' => (int) ($antig['por_tramo'][$t->value]['count'] ?? 0),
                ])->all(),
            ],
            'notas_credito' => [
                'cantidad' => (clone $notas)->count(),
                'valor' => (float) (clone $notas)->sum('valor'),
            ],
            'ultimos_pagos' => $ultimosPagos,
            'sin_datos' => $saldos->isEmpty() && $saldosMes->isEmpty(),
        ];
    }
}
