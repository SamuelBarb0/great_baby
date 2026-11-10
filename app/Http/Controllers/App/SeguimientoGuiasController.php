<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Enums\EstadoGuia;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dónde está cada guía despachada.
 *
 * El pedido B2B moría en «despachado»: quedaba la fecha, la transportadora y
 * el número de guía, y nadie volvía a mirarlo. Si la mercancía no llegaba, el
 * primero en enterarse era el cliente llamando a reclamar.
 *
 * El canal Dropi sí tenía su alerta de mercancía en tránsito desde hacía
 * meses. La venta B2B, que es la que factura a crédito, no tenía ninguna.
 *
 * Los plazos los pone logística en Reglas: cuántos días es una entrega normal
 * y a partir de cuántos hay que reclamarle a la transportadora.
 */
class SeguimientoGuiasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Por la casilla de Logística, no por una lista de roles escrita a
            // mano: así un rol nuevo entra con sólo marcarle el permiso.
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(
                    \App\Auth\Permisos::puede($r->user(), 'logistica'),
                    403,
                    'El seguimiento de guías es de logística.'
                );

                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        $filtro = $r->query('estado', 'atencion');

        $pedidos = PedidoCliente::query()
            ->whereIn('estado', ['facturado', 'despachado'])
            ->with(['contacto:id,nombre_completo,ciudad', 'entregadoPor:id,name'])
            ->orderByRaw('despachado_at IS NULL')
            ->orderBy('despachado_at')
            ->limit(400)
            ->get();

        $filas = $pedidos->map(fn (PedidoCliente $p) => [
            'id' => $p->id,
            'numero' => $p->numero,
            'cliente' => $p->contacto?->nombre_completo ?? '—',
            'ciudad' => $p->contacto?->ciudad,
            'total' => (float) $p->total,
            'transportadora' => $p->transportadora,
            'guia' => $p->guia_transportadora,
            'despachado' => $p->despachado_at?->toDateString(),
            'entregado' => $p->entregado_at?->toDateString(),
            'entregado_por' => $p->entregadoPor?->name,
            'entrega_nota' => $p->entrega_nota,
            'dias' => $p->diasEnTransito(),
            'estado' => $p->estadoGuia()->value,
            'estado_label' => $p->estadoGuia()->label(),
            'color' => $p->estadoGuia()->color(),
            'pide_atencion' => $p->estadoGuia()->pideAtencion(),
            'rastreo' => $p->urlDeRastreo(),
        ]);

        // El conteo es de TODO, no de lo filtrado: si no, al filtrar por
        // «atención» los contadores de arriba cambiarían y no servirían para
        // comparar.
        $conteo = [];
        foreach (EstadoGuia::cases() as $e) {
            $conteo[$e->value] = $filas->where('estado', $e->value)->count();
        }

        $visibles = match ($filtro) {
            'atencion' => $filas->where('pide_atencion', true),
            'todos' => $filas,
            default => $filas->where('estado', $filtro),
        };

        return Inertia::render('Logistica/SeguimientoGuias', [
            'guias' => $visibles->values(),
            'conteo' => $conteo,
            'filtro' => $filtro,
            'plazos' => [
                'transito' => (int) setting('logistica.dias_transito'),
                'sin_entregar' => (int) setting('logistica.dias_sin_entregar'),
            ],
        ]);
    }

    /**
     * Marcar que llegó.
     *
     * La nota no es decorativa: es quién recibió, o por qué no se pudo
     * entregar. Sin eso, una guía «entregada» no sirve para reclamar nada.
     */
    public function entregar(Request $r, PedidoCliente $pedido): RedirectResponse
    {
        $data = $r->validate([
            'nota' => ['required', 'string', 'min:3', 'max:300'],
            'fecha' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        abort_if($pedido->despachado_at === null, 422,
            'Ese pedido todavía no ha salido de bodega.');
        abort_if($pedido->entregado_at !== null, 422,
            'Ese pedido ya estaba marcado como entregado.');

        $pedido->update([
            'entregado_at' => $data['fecha'] ? \Carbon\Carbon::parse($data['fecha']) : now(),
            'entregado_por_id' => $r->user()->id,
            'entrega_nota' => $data['nota'],
        ]);

        return back()->with('success', "Pedido {$pedido->numero} marcado como entregado.");
    }
}
