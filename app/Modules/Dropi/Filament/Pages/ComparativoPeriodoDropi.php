<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

/**
 * «Comparativo periodo» (Reportes › Ejecutivos): este mes contra el anterior.
 *
 * Se compara lo comparable: este mes hasta hoy contra los MISMOS días del mes
 * anterior (1 al N); el mes anterior completo va aparte como referencia. Las
 * cifras usan las mismas definiciones que las tarjetas de Reportes (ventas =
 * pedidos pagados por `pagado_at`, etc.) para que las dos pantallas cuadren.
 */
class ComparativoPeriodoDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $title = 'Comparativo periodo';

    protected static ?string $slug = 'comparativo-periodo-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.comparativo-periodo-dropi';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        return \App\Auth\Permisos::puede($u, 'dropi_reportes');
    }

    /**
     * @return array{
     *   periodos: array{actual: array{0: CarbonImmutable, 1: CarbonImmutable}, anterior: array{0: CarbonImmutable, 1: CarbonImmutable}, anterior_completo: array{0: CarbonImmutable, 1: CarbonImmutable}},
     *   filas: list<array{nombre: string, dinero: bool, menos_es_mejor: bool, actual: float, anterior: float, anterior_completo: float, variacion: float|null}>
     * }
     */
    public function getComparativo(?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::now('America/Bogota');
        $ini = $hoy->startOfMonth();
        $iniAnt = $ini->subMonthNoOverflow();
        // Los mismos días del mes anterior; si el anterior es más corto, hasta su último día.
        $finAntParcial = $iniAnt->addDays($hoy->day - 1)->endOfDay()->min($iniAnt->endOfMonth());

        $periodos = [
            'actual' => [$ini, $hoy->endOfDay()],
            'anterior' => [$iniAnt, $finAntParcial],
            'anterior_completo' => [$iniAnt, $iniAnt->endOfMonth()],
        ];

        $metricas = [
            ['Pedidos recibidos', false, false, fn ($d, $h) => DropiPedido::whereBetween('created_at', [$d, $h])->count()],
            ['Pedidos despachados', false, false, fn ($d, $h) => DropiPedido::whereBetween('despachado_at', [$d, $h])->count()],
            ['Pedidos entregados', false, false, fn ($d, $h) => DropiPedido::whereBetween('entregado_at', [$d, $h])->count()],
            ['Ventas (pedidos pagados)', true, false, fn ($d, $h) => (float) DropiPedido::where('estado', EstadoPedidoDropi::Pagado)
                ->whereBetween('pagado_at', [$d, $h])->sum('monto_esperado_proveedor')],
            ['Pedidos pagados', false, false, fn ($d, $h) => DropiPedido::where('estado', EstadoPedidoDropi::Pagado)
                ->whereBetween('pagado_at', [$d, $h])->count()],
            ['Devoluciones', false, true, fn ($d, $h) => DropiPedido::where('estado', EstadoPedidoDropi::Devuelto)
                ->whereBetween('devuelto_at', [$d, $h])->count()],
            ['Devoluciones en pesos', true, true, fn ($d, $h) => (float) DropiPedido::where('estado', EstadoPedidoDropi::Devuelto)
                ->whereBetween('devuelto_at', [$d, $h])->sum('monto_esperado_proveedor')],
            ['Sanciones de Dropi', true, true, fn ($d, $h) => (float) DropiSancion::whereBetween('detectada_at', [$d, $h])->sum('diferencia')],
            ['Retiros a banco', true, false, fn ($d, $h) => abs((float) DropiWalletMovimiento::where('tipo', 'retiro_banco')
                ->whereBetween('fecha', [$d, $h])->sum('monto'))],
        ];

        $filas = array_map(function (array $m) use ($periodos) {
            [$nombre, $dinero, $menosEsMejor, $calc] = $m;
            $v = array_map(fn (array $p) => (float) $calc($p[0], $p[1]), $periodos);

            return [
                'nombre' => $nombre,
                'dinero' => $dinero,
                'menos_es_mejor' => $menosEsMejor,
                'actual' => $v['actual'],
                'anterior' => $v['anterior'],
                'anterior_completo' => $v['anterior_completo'],
                // Sin base no hay porcentaje que decir (de 0 a algo no es «+∞ %»).
                'variacion' => $v['anterior'] != 0.0 ? round(($v['actual'] - $v['anterior']) / abs($v['anterior']) * 100, 1) : null,
            ];
        }, $metricas);

        return ['periodos' => $periodos, 'filas' => $filas];
    }
}
