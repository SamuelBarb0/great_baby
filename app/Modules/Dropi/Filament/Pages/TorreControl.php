<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Torre de Control — pantalla estilo "control room" para bodega/gerencia.
 * Auto-refresh cada 30s, KPIs grandes, gráficas Chart.js, reloj en vivo.
 */
class TorreControl extends Page
{
    protected string $view = 'dropi.pages.torre-control';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = '🗼 Torre de Control';

    protected static ?string $title = 'Torre de Control · GREAT BABY';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = -10;

    protected static ?string $slug = 'torre-control';

    public static function canAccess(): bool
    {
        // Antes era una lista de nombres de rol: un rol creado en Roles con la
        //   casilla «Torre de control» la veía en /app pero no en el panel.
        //   La casilla manda.
        return Permisos::puede(auth()->user(), 'torre_control');
    }

    // Caché en memoria del componente para el ciclo de vida del render actual.
    // El wire:poll.30s invalida el componente entero, así que no necesitamos TTL.
    private ?array $kpisCache = null;

    public function kpis(): array
    {
        if ($this->kpisCache !== null) {
            return $this->kpisCache;
        }

        $hoy = today();

        $pendientesEmpaque = DropiPedido::whereIn('estado', [
            EstadoPedidoDropi::Pending,
            EstadoPedidoDropi::Alistando,
        ])->count();

        // 1 query agrupada por estado del registro para empacados + promedio
        $empaqueAggs = EmpaqueRegistro::whereDate('fin_at', $hoy)
            ->where('estado', 'completado')
            ->selectRaw('COUNT(*) as total, AVG(duracion_segundos) as prom')
            ->first();
        $empacadosHoy = (int) ($empaqueAggs?->total ?? 0);
        $promSeg = (int) ($empaqueAggs?->prom ?? 0);

        $despachadosHoy = DropiPedido::whereDate('despachado_at', $hoy)->count();
        $entregadosHoy = DropiPedido::whereDate('entregado_at', $hoy)->count();

        $ventasHoy = (float) DropiPedido::whereDate('pagado_at', $hoy)
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->sum('monto_esperado_proveedor');

        $devolucionesHoy = DropiPedido::whereDate('devuelto_at', $hoy)
            ->where('estado', EstadoPedidoDropi::Devuelto)
            ->count();

        $tasaDevolucion = $despachadosHoy > 0
            ? round(($devolucionesHoy / $despachadosHoy) * 100, 1)
            : 0;

        return $this->kpisCache = [
            'pendientes' => $pendientesEmpaque,
            'empacados_hoy' => $empacadosHoy,
            'despachados_hoy' => $despachadosHoy,
            'entregados_hoy' => $entregadosHoy,
            'ventas_hoy' => $ventasHoy,
            'devoluciones_hoy' => $devolucionesHoy,
            'tasa_devolucion' => $tasaDevolucion,
            'prom_empaque_seg' => $promSeg,
        ];
    }

    /**
     * Últimos 7 días — línea de pedidos empacados y despachados.
     * 2 queries agrupadas por día (antes eran 14).
     */
    public function serie7d(): array
    {
        $desde = today()->subDays(6)->startOfDay();
        $hasta = today()->endOfDay();

        $empacadosPorDia = EmpaqueRegistro::query()
            ->where('estado', 'completado')
            ->whereBetween('fin_at', [$desde, $hasta])
            ->selectRaw('DATE(fin_at) as d, COUNT(*) as t')
            ->groupBy('d')
            ->pluck('t', 'd');

        $despachadosPorDia = DropiPedido::query()
            ->whereBetween('despachado_at', [$desde, $hasta])
            ->selectRaw('DATE(despachado_at) as d, COUNT(*) as t')
            ->groupBy('d')
            ->pluck('t', 'd');

        $labels = [];
        $empacados = [];
        $despachados = [];

        for ($i = 0; $i < 7; $i++) {
            $dia = $desde->copy()->addDays($i);
            $k = $dia->toDateString();
            $labels[] = $dia->format('D d');
            $empacados[] = (int) ($empacadosPorDia[$k] ?? 0);
            $despachados[] = (int) ($despachadosPorDia[$k] ?? 0);
        }

        return compact('labels', 'empacados', 'despachados');
    }

    /**
     * Distribución de pedidos activos por estado (donut).
     */
    public function distribucionEstados(): array
    {
        $data = DropiPedido::query()
            ->whereNotIn('estado', [
                EstadoPedidoDropi::CanceladoDropi,
                EstadoPedidoDropi::CanceladoGb,
                EstadoPedidoDropi::Pagado,
                EstadoPedidoDropi::GestionadoEnDropi,
            ])
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        $labels = [];
        $values = [];
        $colors = [];

        $colorMap = [
            'pending' => '#f59e0b',
            'pendiente_inventario' => '#ef4444',
            'alistando' => '#f97316',
            'empacado' => '#10b981',
            'despachado' => '#3b82f6',
            'entregado' => '#8b5cf6',
            'devolucion_en_camino' => '#6b7280',
            'devuelto' => '#78716c',
        ];

        foreach ($data as $estado => $total) {
            $enum = EstadoPedidoDropi::tryFrom($estado);
            $labels[] = $enum?->label() ?? $estado;
            $values[] = $total;
            $colors[] = $colorMap[$estado] ?? '#9ca3af';
        }

        return compact('labels', 'values', 'colors');
    }

    /**
     * Top 6 ciudades por pedidos históricos.
     */
    public function topCiudades(): array
    {
        $data = DB::table('dropi_pedidos')
            ->whereNotNull('cliente_ciudad')
            ->where('cliente_ciudad', '!=', '')
            ->selectRaw('cliente_ciudad, COUNT(*) as total')
            ->groupBy('cliente_ciudad')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return [
            'labels' => $data->pluck('cliente_ciudad')->toArray(),
            'values' => $data->pluck('total')->map(fn ($v) => (int) $v)->toArray(),
        ];
    }

    /**
     * Comparativa vs mismo día del mes anterior.
     */
    public function comparativaMes(): array
    {
        $hoy = today();
        $mesAnt = $hoy->copy()->subMonth();

        $empacadosMes = EmpaqueRegistro::whereBetween('fin_at', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()])
            ->where('estado', 'completado')->count();
        $empacadosMesAnt = EmpaqueRegistro::whereBetween('fin_at', [$mesAnt->copy()->startOfMonth(), $mesAnt->copy()->endOfDay()])
            ->where('estado', 'completado')->count();

        $ventasMes = (float) DropiPedido::whereBetween('pagado_at', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()])
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->sum('monto_esperado_proveedor');
        $ventasMesAnt = (float) DropiPedido::whereBetween('pagado_at', [$mesAnt->copy()->startOfMonth(), $mesAnt->copy()->endOfDay()])
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->sum('monto_esperado_proveedor');

        $devMes = DropiPedido::whereBetween('devuelto_at', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()])
            ->where('estado', EstadoPedidoDropi::Devuelto)->count();
        $devMesAnt = DropiPedido::whereBetween('devuelto_at', [$mesAnt->copy()->startOfMonth(), $mesAnt->copy()->endOfDay()])
            ->where('estado', EstadoPedidoDropi::Devuelto)->count();

        $delta = fn ($nuevo, $viejo) => $viejo > 0 ? round((($nuevo - $viejo) / $viejo) * 100, 1) : ($nuevo > 0 ? 100 : 0);

        return [
            'empacados' => ['actual' => $empacadosMes, 'anterior' => $empacadosMesAnt, 'delta' => $delta($empacadosMes, $empacadosMesAnt)],
            'ventas' => ['actual' => $ventasMes, 'anterior' => $ventasMesAnt, 'delta' => $delta($ventasMes, $ventasMesAnt)],
            'devoluciones' => ['actual' => $devMes, 'anterior' => $devMesAnt, 'delta' => $delta($devMes, $devMesAnt)],
        ];
    }

    /**
     * Coordenadas de ciudades colombianas (top 40 por volumen).
     */
    private function coordCiudades(): array
    {
        return [
            'Bogotá' => [4.7110, -74.0721], 'Bogota' => [4.7110, -74.0721],
            'Medellín' => [6.2442, -75.5812], 'Medellin' => [6.2442, -75.5812],
            'Cali' => [3.4516, -76.5320],
            'Barranquilla' => [10.9685, -74.7813],
            'Cartagena' => [10.3910, -75.4794],
            'Cúcuta' => [7.8891, -72.4967], 'Cucuta' => [7.8891, -72.4967],
            'Bucaramanga' => [7.1193, -73.1227],
            'Pereira' => [4.8143, -75.6946],
            'Manizales' => [5.0703, -75.5138],
            'Ibagué' => [4.4389, -75.2322], 'Ibague' => [4.4389, -75.2322],
            'Villavicencio' => [4.1420, -73.6266],
            'Santa Marta' => [11.2408, -74.1990],
            'Neiva' => [2.9273, -75.2819],
            'Armenia' => [4.5389, -75.6725],
            'Pasto' => [1.2136, -77.2811],
            'Popayán' => [2.4448, -76.6147], 'Popayan' => [2.4448, -76.6147],
            'Montería' => [8.7479, -75.8814], 'Monteria' => [8.7479, -75.8814],
            'Valledupar' => [10.4631, -73.2532],
            'Sincelejo' => [9.3047, -75.3978],
            'Riohacha' => [11.5443, -72.9072],
            'Tunja' => [5.5353, -73.3678],
            'Yopal' => [5.3378, -72.3959],
            'Florencia' => [1.6144, -75.6062],
            'Buenaventura' => [3.8801, -77.0313],
            'Palmira' => [3.5394, -76.3036],
            'Soacha' => [4.5793, -74.2170],
            'Envigado' => [6.1737, -75.5905],
            'Bello' => [6.3373, -75.5573],
        ];
    }

    /**
     * Puntos del mapa: cada ciudad con volumen y lat/lng.
     */
    public function mapaCiudades(): array
    {
        $conteos = DB::table('dropi_pedidos')
            ->whereNotNull('cliente_ciudad')->where('cliente_ciudad', '!=', '')
            ->selectRaw('cliente_ciudad, COUNT(*) as t')
            ->groupBy('cliente_ciudad')->orderByDesc('t')->limit(30)->get();

        $coords = $this->coordCiudades();
        $puntos = [];
        foreach ($conteos as $c) {
            if (! isset($coords[$c->cliente_ciudad])) {
                continue;
            }
            $puntos[] = [
                'ciudad' => $c->cliente_ciudad,
                'total' => (int) $c->t,
                'lat' => $coords[$c->cliente_ciudad][0],
                'lng' => $coords[$c->cliente_ciudad][1],
            ];
        }
        return $puntos;
    }

    /**
     * Alertas críticas para banner rojo.
     */
    public function alertasCriticas(): array
    {
        $alertas = [];
        $k = $this->kpis();

        if ($k['pendientes'] > 50) {
            $alertas[] = ['nivel' => 'critico', 'mensaje' => "🔥 {$k['pendientes']} pedidos pendientes por empacar — pide refuerzos en bodega."];
        }
        if ($k['tasa_devolucion'] > 15) {
            $alertas[] = ['nivel' => 'critico', 'mensaje' => "⚠️ Tasa de devolución hoy: {$k['tasa_devolucion']}% — muy por encima del objetivo (<10%)."];
        }

        return $alertas;
    }

    /**
     * Ranking del día — operarios de empaque.
     */
    public function rankingOperarios(): array
    {
        return DB::table('empaques_registro')
            ->join('users', 'users.id', '=', 'empaques_registro.operario_id')
            ->whereDate('fin_at', today())
            ->where('estado', 'completado')
            ->selectRaw('users.name, COUNT(*) as total, AVG(duracion_segundos) as prom')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->name,
                'total' => (int) $r->total,
                'prom_seg' => (int) ($r->prom ?? 0),
            ])
            ->toArray();
    }
}
