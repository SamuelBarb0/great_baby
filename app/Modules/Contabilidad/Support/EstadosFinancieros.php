<?php

namespace App\Modules\Contabilidad\Support;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Models\PlanCuenta;
use Illuminate\Support\Collection;

/**
 * Saldos por cuenta y su agrupación por clase del PUC: la base del balance
 * general y del estado de resultados.
 *
 * Vivía como métodos privados de ContabilidadExtrasController. Se sacó aquí
 * (sin cambiar una línea de la cuenta) para que «Contabilidad Dropi» del panel
 * /admin use EXACTAMENTE el mismo cálculo que las pantallas de contabilidad:
 * dos implementaciones del mismo balance son dos números distintos el día que
 * alguien arregle sólo una.
 */
final class EstadosFinancieros
{
    /**
     * @return Collection<int, array{codigo: string, nombre: string, debe: float, haber: float}>
     */
    public static function saldosPorCuenta(?string $desde, string $hasta, bool $incluirAnulados, ?FiltroEjes $ejes = null): Collection
    {
        $filas = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->when($desde, fn ($q) => $q->where('fecha', '>=', $desde))
            ->where('fecha', '<=', $hasta)
            ->selectRaw('cuenta_puc, SUM(debe) AS debe, SUM(haber) AS haber')
            ->groupBy('cuenta_puc')
            ->get();

        $nombres = PlanCuenta::query()
            ->whereIn('codigo', $filas->pluck('cuenta_puc'))
            ->pluck('nombre', 'codigo');

        return $filas->map(fn ($f) => [
            'codigo' => (string) $f->cuenta_puc,
            'nombre' => $nombres[$f->cuenta_puc] ?? 'Cuenta fuera del plan',
            'debe' => (float) $f->debe,
            'haber' => (float) $f->haber,
        ]);
    }

    /**
     * @return array{cuentas: array<int, array>, total: float}
     */
    public static function agruparClase(Collection $saldos, string $clase, string $naturaleza): array
    {
        $cuentas = $saldos
            ->filter(fn ($c) => str_starts_with($c['codigo'], $clase))
            ->map(function ($c) use ($naturaleza) {
                $c['saldo'] = $naturaleza === 'debito'
                    ? round($c['debe'] - $c['haber'], 2)
                    : round($c['haber'] - $c['debe'], 2);
                return $c;
            })
            ->filter(fn ($c) => abs($c['saldo']) > 0.009)
            ->sortBy('codigo')
            ->values();

        return [
            'cuentas' => $cuentas->all(),
            'total' => round($cuentas->sum('saldo'), 2),
        ];
    }
}
