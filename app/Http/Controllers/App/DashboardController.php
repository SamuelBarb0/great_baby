<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $u = $request->user();
        // Routing por rol de la pantalla "/app": los KPIs globales son de
        //   Aracely/Gerencia/Gerente; cualquier otro rol se va a su propia
        //   pantalla de trabajo. El destino lo decide `User::rutaInicial()`,
        //   que es la misma que usa el login — antes aquí el fallback era la
        //   Estación de Empaque y mandaba a Gerente/Contador/Vendedor/SAC/
        //   Marketing/Facturador directo a un 403.
        if (! $u->esAracely() && ! $u->hasRole('Gerente')) {
            $destino = $u->rutaInicial();
            // `ir()` y no `redirect()`: si el destino es el panel (/admin), un
            //   redirect común lo dejaba dibujado en una ventana encima de /app.
            return AuthController::ir($destino === '/app' ? '/app/cosas-del-dia' : $destino);
        }

        $kpis = $this->kpis();
        return Inertia::render('Dashboard', [
            'kpis' => $kpis,
            'serie7d' => $this->serie7d(),
            'distribucion' => $this->distribucionEstados(),
            'mapa' => $this->mapaCiudades(),
            'ranking' => $this->rankingOperarios(),
            'comparativa' => $this->comparativaMes(),
            'alertas' => $this->alertasCriticas($kpis),
            // F5: refresh_seg configurable — antes 30s hardcoded en Vue.
            'refreshSeg' => (int) setting('dashboard.refresh_seg', 30),
        ]);
    }

    private function kpis(): array
    {
        [$hoyInicio, $hoyFin] = $this->rangoHoy();

        $empaqueAggs = EmpaqueRegistro::whereBetween('fin_at', [$hoyInicio, $hoyFin])
            ->where('estado', 'completado')
            ->selectRaw('COUNT(*) as total, AVG(duracion_segundos) as prom')
            ->first();

        $despachadosHoy = DropiPedido::whereBetween('despachado_at', [$hoyInicio, $hoyFin])->count();
        $devolucionesHoy = DropiPedido::whereBetween('devuelto_at', [$hoyInicio, $hoyFin])
            ->where('estado', EstadoPedidoDropi::Devuelto)->count();

        return [
            'pendientes' => DropiPedido::whereIn('estado', [
                EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando,
            ])->count(),
            'empacados_hoy' => (int) ($empaqueAggs?->total ?? 0),
            'prom_seg' => (int) ($empaqueAggs?->prom ?? 0),
            'despachados_hoy' => $despachadosHoy,
            'entregados_hoy' => DropiPedido::whereBetween('entregado_at', [$hoyInicio, $hoyFin])->count(),
            'ventas_hoy' => (float) DropiPedido::whereBetween('pagado_at', [$hoyInicio, $hoyFin])
                ->where('estado', EstadoPedidoDropi::Pagado)
                ->sum('monto_esperado_proveedor'),
            'devoluciones_hoy' => $devolucionesHoy,
            'tasa_devolucion' => $despachadosHoy > 0 ? round(($devolucionesHoy / $despachadosHoy) * 100, 1) : 0,
        ];
    }

    /** Rango [inicio, fin] del día en zona horaria de Bogotá, convertido a UTC para el WHERE. */
    private function rangoHoy(): array
    {
        return [
            \Carbon\Carbon::now('America/Bogota')->startOfDay(),
            \Carbon\Carbon::now('America/Bogota')->endOfDay(),
        ];
    }

    private function serie7d(): array
    {
        $desde = today()->subDays(6)->startOfDay();
        $hasta = today()->endOfDay();

        $empacadosPorDia = EmpaqueRegistro::where('estado', 'completado')
            ->whereBetween('fin_at', [$desde, $hasta])
            ->selectRaw('DATE(fin_at) as d, COUNT(*) as t')
            ->groupBy('d')->pluck('t', 'd');

        $despachadosPorDia = DropiPedido::whereBetween('despachado_at', [$desde, $hasta])
            ->selectRaw('DATE(despachado_at) as d, COUNT(*) as t')
            ->groupBy('d')->pluck('t', 'd');

        $labels = $empacados = $despachados = [];
        for ($i = 0; $i < 7; $i++) {
            $dia = $desde->copy()->addDays($i);
            $k = $dia->toDateString();
            $labels[] = $dia->format('D d');
            $empacados[] = (int) ($empacadosPorDia[$k] ?? 0);
            $despachados[] = (int) ($despachadosPorDia[$k] ?? 0);
        }
        return compact('labels', 'empacados', 'despachados');
    }

    private function distribucionEstados(): array
    {
        $data = DropiPedido::whereNotIn('estado', [
            EstadoPedidoDropi::CanceladoDropi,
            EstadoPedidoDropi::CanceladoGb,
            EstadoPedidoDropi::Pagado,
            EstadoPedidoDropi::GestionadoEnDropi,
        ])
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')->pluck('total', 'estado')->toArray();

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

        $labels = $values = $colors = [];
        foreach ($data as $estado => $total) {
            $enum = EstadoPedidoDropi::tryFrom($estado);
            $labels[] = $enum?->label() ?? $estado;
            $values[] = (int) $total;
            $colors[] = $colorMap[$estado] ?? '#9ca3af';
        }
        return compact('labels', 'values', 'colors');
    }

    private function mapaCiudades(): array
    {
        $coords = $this->coordCiudades();
        $conteos = DB::table('dropi_pedidos')
            ->whereNotNull('cliente_ciudad')->where('cliente_ciudad', '!=', '')
            ->selectRaw('cliente_ciudad, COUNT(*) as t')
            ->groupBy('cliente_ciudad')->orderByDesc('t')->limit(30)->get();

        $puntos = [];
        foreach ($conteos as $c) {
            if (! isset($coords[$c->cliente_ciudad])) continue;
            $puntos[] = [
                'ciudad' => $c->cliente_ciudad,
                'total' => (int) $c->t,
                'lat' => $coords[$c->cliente_ciudad][0],
                'lng' => $coords[$c->cliente_ciudad][1],
            ];
        }
        return $puntos;
    }

    private function rankingOperarios(): array
    {
        [$ini, $fin] = $this->rangoHoy();
        return DB::table('empaques_registro')
            ->join('users', 'users.id', '=', 'empaques_registro.operario_id')
            ->whereBetween('fin_at', [$ini, $fin])
            ->where('estado', 'completado')
            ->selectRaw('users.id as uid, users.name, COUNT(*) as total, AVG(duracion_segundos) as prom')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(5)
            ->get()
            ->map(fn ($r) => [
                'uid' => (int) $r->uid,
                'nombre' => $r->name,
                'total' => (int) $r->total,
                'prom' => (int) ($r->prom ?? 0),
            ])->toArray();
    }

    private function comparativaMes(): array
    {
        $hoy = today();
        $mesAnt = $hoy->copy()->subMonth();

        $empActual = EmpaqueRegistro::whereBetween('fin_at', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()])
            ->where('estado', 'completado')->count();
        $empAnt = EmpaqueRegistro::whereBetween('fin_at', [$mesAnt->copy()->startOfMonth(), $mesAnt->copy()->endOfDay()])
            ->where('estado', 'completado')->count();

        $venActual = (float) DropiPedido::whereBetween('pagado_at', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()])
            ->where('estado', EstadoPedidoDropi::Pagado)->sum('monto_esperado_proveedor');
        $venAnt = (float) DropiPedido::whereBetween('pagado_at', [$mesAnt->copy()->startOfMonth(), $mesAnt->copy()->endOfDay()])
            ->where('estado', EstadoPedidoDropi::Pagado)->sum('monto_esperado_proveedor');

        $delta = fn ($n, $v) => $v > 0 ? round((($n - $v) / $v) * 100, 1) : ($n > 0 ? 100 : 0);

        return [
            'empacados' => ['actual' => $empActual, 'anterior' => $empAnt, 'delta' => $delta($empActual, $empAnt)],
            'ventas' => ['actual' => $venActual, 'anterior' => $venAnt, 'delta' => $delta($venActual, $venAnt)],
        ];
    }

    private function alertasCriticas(?array $k = null): array
    {
        $k = $k ?? $this->kpis();
        $alertas = [];
        $umbralPend = (int) setting('dashboard.alerta_pendientes', 50);
        $umbralDev = (float) setting('dashboard.alerta_tasa_devolucion', 15);
        if ($k['pendientes'] > $umbralPend) {
            $alertas[] = ['id' => 'pendientes', 'nivel' => 'critico', 'mensaje' => "🔥 {$k['pendientes']} pedidos pendientes por empacar — pide refuerzos en bodega."];
        }
        if ($k['tasa_devolucion'] > $umbralDev) {
            $alertas[] = ['id' => 'tasa_dev', 'nivel' => 'critico', 'mensaje' => "⚠️ Tasa de devolución hoy: {$k['tasa_devolucion']}% — muy por encima del objetivo (<{$umbralDev}%)."];
        }

        // REU-2: mercancía fantasma
        $fant = \App\Modules\Dropi\Actions\AuditarMercanciaFantasma::resumen();
        if ($fant['total'] > 0) {
            $alertas[] = [
                'id' => 'fantasma', 'nivel' => 'critico',
                'mensaje' => "👻 {$fant['total']} devoluciones marcadas por Dropi hace más de {$fant['dias_tolerancia']} días que NO llegaron a bodega. Revisar posible mercancía perdida.",
            ];
        }

        // REU-5: mercancía en tránsito prolongado
        $transito = \App\Modules\Dropi\Actions\AuditarMercanciaEnTransito::resumen();
        if ($transito['total'] > 0) {
            $alertas[] = [
                'id' => 'transito', 'nivel' => 'alto',
                'mensaje' => "🚚 {$transito['total']} pedidos despachados hace más de {$transito['dias_max']} días sin novedad. ¿Contactar transportadora?",
            ];
        }

        return $alertas;
    }

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
            'Buenaventura' => [3.8801, -77.0313],
            'Palmira' => [3.5394, -76.3036],
            'Soacha' => [4.5793, -74.2170],
            'Envigado' => [6.1737, -75.5905],
            'Bello' => [6.3373, -75.5573],
        ];
    }
}
