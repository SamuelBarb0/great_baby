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
                // Casilla: CRM · Segmentacion.
                   abort_unless(\App\Auth\Permisos::puede($r->user(), 'crm'), 403);
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
            // Quién puede reescribir la segmentación de todos los clientes.
            'puede_segmentar' => \App\Auth\Permisos::esRoot($request->user()),
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
        /*
         * Ver los segmentos es una cosa; reescribirlos para TODOS los clientes
         * es otra. La pantalla lo avisa —«recalcula los segmentos de TODOS los
         * clientes, los previos se sobrescribirán»— y la acción no tenía puerta
         * propia: colgaba de la de clase. Mientras el CRM fue de Aracely sola
         * no se notó; al pasar la puerta a la casilla `crm`, que la matriz le
         * da también al Vendedor y a Servicio al Cliente, el botón habría
         * quedado al alcance de cualquiera de ellos.
         */
        abort_unless(\App\Auth\Permisos::esRoot($request->user()), 403,
            'Re-segmentar a todos los clientes es de Gerencia.');

        $n = SegmentarClientes::run();
        return back()->with('success', "✅ {$n} contactos re-segmentados");
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
