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
            /*
             * Lo que todavía NO salió de bodega va PRIMERO.
             *
             * Estaba al revés —`despachado_at IS NULL` ascendente los manda al
             * final— y con el tope de 400 filas eran justo los que se caían de
             * la ventana. En una pantalla cuyo filtro por defecto es «pide
             * atención», lo que más atención pide no puede ser lo que no entra.
             */
            ->orderByRaw('despachado_at IS NOT NULL')
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

        /*
         * El conteo es de TODO, y ahora sí.
         *
         * Decía eso y contaba sobre las 400 filas cargadas: con más de 400
         * guías vivas, los contadores de arriba mostraban un pedazo y la
         * pantalla parecía tener menos trabajo del que tiene. Peor todavía
         * porque lo que se caía de la ventana era lo que pide atención.
         *
         * El estado se calcula en PHP —depende de las reglas de plazo—, así
         * que no se puede contar en SQL; pero traer sólo las dos fechas de
         * todas las guías es barato, y es lo único que el estado necesita.
         */
        $conteo = array_fill_keys(array_column(EstadoGuia::cases(), 'value'), 0);

        PedidoCliente::query()
            ->whereIn('estado', ['facturado', 'despachado'])
            ->select(['id', 'estado', 'despachado_at', 'entregado_at'])
            ->chunkById(1000, function ($tanda) use (&$conteo) {
                foreach ($tanda as $p) {
                    $conteo[$p->estadoGuia()->value]++;
                }
            });

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

        /*
         * No se puede entregar antes de haber despachado.
         *
         * La validación sólo miraba que la fecha no fuera futura, así que una
         * fecha tecleada con el mes equivocado pasaba y el pedido quedaba
         * entregado ANTES de salir de bodega. Eso no es sólo raro de leer: los
         * días en tránsito salen negativos y se le restan al promedio de la
         * transportadora, que es el número con el que se la reclama.
         */
        $entregadoEn = $data['fecha'] ? \Carbon\Carbon::parse($data['fecha']) : now();

        abort_if($entregadoEn->lt($pedido->despachado_at->copy()->startOfDay()), 422, sprintf(
            'La entrega no puede ser anterior al despacho. El pedido salió de bodega el %s.',
            $pedido->despachado_at->format('Y-m-d')
        ));

        $pedido->update([
            'entregado_at' => $entregadoEn,
            'entregado_por_id' => $r->user()->id,
            'entrega_nota' => $data['nota'],
        ]);

        return back()->with('success', "Pedido {$pedido->numero} marcado como entregado.");
    }
}
