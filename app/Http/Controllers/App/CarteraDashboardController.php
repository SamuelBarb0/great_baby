<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Actions\CalcularAntiguedadCartera;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Support\FiltroEjes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CarteraDashboardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // Casilla: Panel de cartera.
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'cartera_dashboard'), 403);
                return $next($r);
            }),
        ];
    }

    /** Línea de negocio y destino fiscal (`?linea=dropi` = la cartera del e-commerce). */
    private FiltroEjes $ejes;

    public function __invoke(Request $request): Response
    {
        $this->ejes = FiltroEjes::deRequest($request);

        return Inertia::render('Cartera/Dashboard', [
            'filtros' => $this->ejes->aParametros(),
            'opcionesEjes' => FiltroEjes::opciones(),
            'semaforo' => $this->semaforo(),
            /*
             * La misma cartera, abierta por vendedor.
             *
             * El contrato pide el semáforo «por tramos Y POR VENDEDOR», y lo
             * segundo no estaba: no había forma de responder quién vende bien
             * pero cobra mal, que es la pregunta que importa cuando la cartera
             * se empieza a envejecer.
             */
            'porVendedor' => CalcularAntiguedadCartera::make()->porVendedor($this->ejes),
            'tramos' => array_map(fn ($t) => [
                'valor' => $t->value, 'label' => $t->label(), 'color' => $t->colorHex(),
            ], TramoAntiguedad::cases()),
            'kpis' => $this->kpis(),
            'topMorosos' => $this->topMorosos(),
            'cobradoMes' => $this->cobradoMes(),
            'facturadoMes' => $this->facturadoMes(),
            'proximosVencer' => $this->proximosVencer(),
            'refreshSeg' => (int) setting('dashboard.refresh_seg', 30),
            'bucketDias' => [
                'verde' => (int) setting('cartera.bucket_1_dias', 30),
                'amarillo' => (int) setting('cartera.bucket_2_dias', 60),
                'rojo' => (int) setting('cartera.bucket_3_dias', 90),
            ],
        ]);
    }

    private function semaforo(): array
    {
        $ant = CalcularAntiguedadCartera::run(null, $this->ejes);
        $labels = [];
        $data = [];
        $colors = [];
        foreach (TramoAntiguedad::cases() as $t) {
            $labels[] = $t->label();
            $data[] = (float) ($ant['por_tramo'][$t->value]['monto'] ?? 0);
            $colors[] = match ($t) {
                TramoAntiguedad::AlDia => '#10b981',
                TramoAntiguedad::D0_30 => '#84cc16',
                TramoAntiguedad::D31_59 => '#f59e0b',
                TramoAntiguedad::D60_89 => '#f97316',
                TramoAntiguedad::D90_119 => '#ef4444',
                TramoAntiguedad::D120Mas => '#991b1b',
            };
        }
        return [
            'labels' => $labels,
            'data' => $data,
            'colors' => $colors,
            'total' => (float) $ant['total'],
            'total_facturas' => (int) $ant['count'],
        ];
    }

    private function kpis(): array
    {
        $hoy = today('America/Bogota');
        $inicioMes = $hoy->copy()->startOfMonth();

        $fac = fn () => $this->ejes->aplicar(FacturaVenta::query());
        // Un pago no tiene ejes propios: son los de su factura.
        $pag = fn () => $this->ejes->aplicarPor(PagoVenta::query(), 'factura');

        $totalPendiente = $fac()->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])->sum('saldo');
        $vencidasCount = $fac()->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->where('fecha_vencimiento', '<', $hoy)->count();
        $cobradoMes = $pag()->whereBetween('fecha', [$inicioMes, $hoy->copy()->endOfDay()])->sum('monto_aplicado');
        $facturadoMes = $fac()->whereBetween('fecha_emision', [$inicioMes, $hoy->copy()->endOfDay()])
            ->where('estado', '!=', EstadoFactura::Anulada)->sum('total');

        return [
            'total_pendiente' => (float) $totalPendiente,
            'vencidas_count' => $vencidasCount,
            'cobrado_mes' => (float) $cobradoMes,
            'facturado_mes' => (float) $facturadoMes,
        ];
    }

    private function topMorosos(int $limite = 10): array
    {
        return DB::table('facturas_venta as f')
            ->join('contactos as c', 'c.id', '=', 'f.contacto_id')
            ->tap(fn ($q) => $this->ejes->aplicar($q, 'f'))
            ->whereNull('f.deleted_at') // QA-D Bloque3: respetar soft-deletes (bypaseaba scope Eloquent)
            ->whereNull('c.deleted_at')
            ->whereNotIn('f.estado', [EstadoFactura::Pagada->value, EstadoFactura::Anulada->value])
            ->where('f.fecha_vencimiento', '<', today('America/Bogota'))
            ->selectRaw('c.id, c.nombre_completo, c.telefono, SUM(f.saldo) as saldo, COUNT(*) as facturas, MAX(DATEDIFF(?, f.fecha_vencimiento)) as dias_max', [today('America/Bogota')->toDateString()])
            ->groupBy('c.id', 'c.nombre_completo', 'c.telefono')
            ->orderByDesc('saldo')
            ->limit($limite)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'nombre' => (string) $r->nombre_completo,
                'telefono' => (string) ($r->telefono ?? ''),
                'saldo' => (float) $r->saldo,
                'facturas' => (int) $r->facturas,
                'dias_max' => (int) $r->dias_max,
            ])
            ->toArray();
    }

    private function cobradoMes(): array
    {
        $desde = today('America/Bogota')->copy()->subDays(29)->startOfDay();
        $hasta = today('America/Bogota')->copy()->endOfDay();
        $porDia = $this->ejes->aplicarPor(PagoVenta::query(), 'factura')->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('DATE(fecha) as d, SUM(monto_aplicado) as t')
            ->groupBy('d')->pluck('t', 'd');
        $labels = $valores = [];
        for ($i = 0; $i < 30; $i++) {
            $dia = $desde->copy()->addDays($i);
            $labels[] = $dia->format('d/m');
            $valores[] = (float) ($porDia[$dia->toDateString()] ?? 0);
        }
        return ['labels' => $labels, 'valores' => $valores];
    }

    private function facturadoMes(): array
    {
        $desde = today('America/Bogota')->copy()->subDays(29)->startOfDay();
        $hasta = today('America/Bogota')->copy()->endOfDay();
        $porDia = $this->ejes->aplicar(FacturaVenta::query())->whereBetween('fecha_emision', [$desde, $hasta])
            ->where('estado', '!=', EstadoFactura::Anulada)
            ->selectRaw('DATE(fecha_emision) as d, SUM(total) as t')
            ->groupBy('d')->pluck('t', 'd');
        $labels = $valores = [];
        for ($i = 0; $i < 30; $i++) {
            $dia = $desde->copy()->addDays($i);
            $labels[] = $dia->format('d/m');
            $valores[] = (float) ($porDia[$dia->toDateString()] ?? 0);
        }
        return ['labels' => $labels, 'valores' => $valores];
    }

    private function proximosVencer(): array
    {
        $hoy = today('America/Bogota');
        return DB::table('facturas_venta as f')
            ->join('contactos as c', 'c.id', '=', 'f.contacto_id')
            ->tap(fn ($q) => $this->ejes->aplicar($q, 'f'))
            ->whereNull('f.deleted_at') // QA-D Bloque3
            ->whereNull('c.deleted_at')
            ->whereNotIn('f.estado', [EstadoFactura::Pagada->value, EstadoFactura::Anulada->value])
            ->whereBetween('f.fecha_vencimiento', [$hoy, $hoy->copy()->addDays(7)])
            ->selectRaw('f.id, f.numero, f.fecha_vencimiento, f.saldo, c.nombre_completo, c.telefono')
            ->orderBy('f.fecha_vencimiento')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'numero' => (string) $r->numero,
                'nombre' => (string) $r->nombre_completo,
                'telefono' => (string) ($r->telefono ?? ''),
                'vence' => \Carbon\Carbon::parse($r->fecha_vencimiento)->toDateString(),
                'saldo' => (float) $r->saldo,
                'dias' => (int) \Carbon\Carbon::parse($r->fecha_vencimiento)->diffInDays($hoy, absolute: false),
            ])
            ->toArray();
    }
}
