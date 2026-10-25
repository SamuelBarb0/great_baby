<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Crm\Actions\SegmentarClientes;
use App\Modules\Crm\Models\ComisionCalculada;
use App\Modules\Crm\Models\Interaccion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CrmController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esAracely(), 403);
                return $next($r);
            }),
        ];
    }

    /** Vista principal CRM con 3 pestañas embebidas */
    public function index(Request $request): Response
    {
        return Inertia::render('Cartera/Crm/Index', [
            'segmentos' => $this->segmentosResumen(),
            'interaccionesRecientes' => $this->interaccionesRecientes(),
            'comisionesUltimoMes' => $this->comisionesUltimoMes(),
            'tiposInteraccion' => Interaccion::tipos(),
            'fuentes' => $this->leadsPorFuente(),
        ]);
    }

    /** Registrar interacción rápida */
    public function crearInteraccion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contacto_id' => ['required', 'exists:contactos,id'],
            'tipo' => ['required', 'string'],
            'asunto' => ['required', 'string', 'max:200'],
            'detalle' => ['nullable', 'string'],
            'resultado' => ['nullable', 'string'],
            'proxima_accion_at' => ['nullable', 'date'],
            'proxima_accion_nota' => ['nullable', 'string'],
        ]);

        Interaccion::create([
            ...$data,
            'user_id' => $request->user()->id,
            'ocurrida_at' => now(),
        ]);

        return back()->with('success', '✅ Interacción registrada');
    }

    /** Correr segmentación manualmente */
    public function segmentarAhora(Request $request): RedirectResponse
    {
        $n = SegmentarClientes::run();
        return back()->with('success', "✅ {$n} contactos re-segmentados");
    }

    /**
     * MKT-6 · Clientes y ventas por canal de llegada.
     *
     * Los contactos se cuentan por SU fuente; los pedidos, por la fuente que
     * quedó copiada en el pedido al crearlo. «Venta» = facturado o despachado,
     * igual que en el seguimiento del vendedor.
     */
    private function leadsPorFuente(): array
    {
        $contactos = \App\Models\Contacto::query()
            ->where('es_cliente', true)
            ->selectRaw("COALESCE(fuente, '') as f, COUNT(*) as total")
            ->groupBy('f')
            ->pluck('total', 'f');

        $pedidos = \App\Modules\Portal\Models\PedidoCliente::query()
            ->selectRaw("COALESCE(fuente, '') as f, COUNT(*) as pedidos, COUNT(DISTINCT contacto_id) as clientes_con_pedido")
            ->selectRaw("COALESCE(SUM(CASE WHEN estado IN ('facturado','despachado') THEN total ELSE 0 END), 0) as ventas")
            ->groupBy('f')
            ->get()
            ->keyBy('f');

        $etiquetas = \App\Models\Contacto::FUENTES + ['' => 'Sin registrar'];

        return collect($etiquetas)
            ->map(function (string $label, string $clave) use ($contactos, $pedidos) {
                $p = $pedidos->get($clave);
                $total = (int) ($contactos[$clave] ?? 0);
                $conPedido = (int) ($p->clientes_con_pedido ?? 0);

                return [
                    'fuente' => $clave === '' ? null : $clave,
                    'label' => $label,
                    'contactos' => $total,
                    'clientes_con_pedido' => $conPedido,
                    'pedidos' => (int) ($p->pedidos ?? 0),
                    'ventas' => (float) ($p->ventas ?? 0),
                    'conversion' => $total > 0 ? round(min($conPedido, $total) / $total * 100, 1) : 0,
                ];
            })
            ->filter(fn ($r) => $r['contactos'] > 0 || $r['pedidos'] > 0 || $r['fuente'] !== null)
            ->values()
            ->all();
    }

    private function segmentosResumen(): array
    {
        $resumen = DB::table('contactos')
            ->whereNotNull('segmento')
            ->where('es_cliente', true)
            ->selectRaw('segmento, COUNT(*) as total, COALESCE(SUM(total_comprado_ytd), 0) as compra_ytd')
            ->groupBy('segmento')
            ->get();

        $config = [
            'vip' => ['color' => 'emerald', 'label' => 'VIP', 'emoji' => '👑'],
            'frecuente' => ['color' => 'blue', 'label' => 'Frecuente', 'emoji' => '💙'],
            'nuevo' => ['color' => 'amber', 'label' => 'Nuevo', 'emoji' => '✨'],
            'dormido' => ['color' => 'purple', 'label' => 'Dormido', 'emoji' => '😴'],
            'inactivo' => ['color' => 'red', 'label' => 'Inactivo', 'emoji' => '💤'],
            'riesgo' => ['color' => 'red', 'label' => 'Riesgo', 'emoji' => '⚠️'],
        ];

        return $resumen->map(fn ($r) => [
            'segmento' => $r->segmento,
            'label' => $config[$r->segmento]['label'] ?? ucfirst($r->segmento),
            'emoji' => $config[$r->segmento]['emoji'] ?? '📦',
            'color' => $config[$r->segmento]['color'] ?? 'gray',
            'total' => (int) $r->total,
            'compra_ytd' => (float) $r->compra_ytd,
        ])->values()->all();
    }

    private function interaccionesRecientes(int $limit = 20): array
    {
        return Interaccion::query()
            ->with(['contacto:id,nombre_completo', 'usuario:id,name'])
            ->latest('ocurrida_at')
            ->limit($limit)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'tipo' => $i->tipo,
                'asunto' => $i->asunto,
                'detalle' => $i->detalle,
                'resultado' => $i->resultado,
                'contacto_id' => $i->contacto_id,
                'contacto' => $i->contacto?->nombre_completo,
                'usuario' => $i->usuario?->name,
                'ocurrida_at' => $i->ocurrida_at?->toIso8601String(),
                'ocurrida_hace' => $i->ocurrida_at?->diffForHumans(),
                'proxima_accion_at' => $i->proxima_accion_at?->toDateString(),
                'proxima_accion_nota' => $i->proxima_accion_nota,
            ])->toArray();
    }

    private function comisionesUltimoMes(): array
    {
        $ultimo = ComisionCalculada::orderByDesc('anio')->orderByDesc('mes')->first();
        if (! $ultimo) return ['periodo' => null, 'items' => [], 'total_a_pagar' => 0];

        $items = ComisionCalculada::with('vendedor:id,name')
            ->where('anio', $ultimo->anio)->where('mes', $ultimo->mes)
            ->orderByDesc('total_a_pagar')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'vendedor' => $c->vendedor?->name ?? '—',
                'total_facturado' => (float) $c->total_facturado,
                'total_cobrado' => (float) $c->total_cobrado,
                'base' => (float) $c->base_comisionable,
                'porcentaje' => (float) $c->porcentaje_aplicado,
                'comision' => (float) $c->comision,
                'bono' => (float) $c->bono_meta,
                'total_a_pagar' => (float) $c->total_a_pagar,
                'estado' => $c->estado,
            ]);

        return [
            'periodo' => sprintf('%04d-%02d', $ultimo->anio, $ultimo->mes),
            'items' => $items->all(),
            'total_a_pagar' => (float) $items->sum('total_a_pagar'),
        ];
    }
}
