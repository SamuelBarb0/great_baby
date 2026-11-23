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
            /*
             * Se ordena por la salida REAL, no por `despachado_at`.
             *
             * Con el despacho parcial esas dos dejaron de ser lo mismo: un
             * pedido cuya primera caja ya va en camino tiene `despachado_at` en
             * NULL hasta que salga el resto. Ordenando por esa columna, una
             * guía que lleva ocho días en la calle se mezclaba con las que no
             * han salido, y la pantalla ordenaba por una fecha mientras la
             * columna «Días» mostraba otra.
             */
            ->orderByRaw('COALESCE(primer_despacho_at, despachado_at) IS NOT NULL')
            ->orderByRaw('COALESCE(primer_despacho_at, despachado_at)')
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
            // La fecha que se muestra es la de la salida real: en un parcial,
            // la de la primera caja. `despachado_at` sigue vacío hasta que salga
            // todo, y esa columna decía «—» sobre una guía que ya iba en camino.
            'despachado' => $p->salidaDeBodega()?->toDateString(),
            // Y si todavía falta mercancía, se dice APARTE del estado de la guía.
            // Son dos cosas distintas: la guía puede ir a tiempo, tardía o no
            // entregada, y además faltar el resto. Meterlo como un estado más
            // apagaría la alarma: un parcial despachado hace treinta días
            // dejaría de ser «no entregada» y se caería de «Piden atención»,
            // justo la caja que hay que reclamar.
            'resto_pendiente' => (bool) $p->resto_pendiente,
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
            /*
             * `primer_despacho_at` VA ACÁ, y olvidarla es el error más
             * traicionero de esta pantalla.
             *
             * Las FILAS de arriba no tienen `select()`: traen la fila entera,
             * así que leen cualquier columna nueva sin que nadie la agregue.
             * Este conteo sí recorta. Y como el proyecto no activa
             * `Model::shouldBeStrict()`, un atributo que no se trajo no explota:
             * vale null. Así que `estadoGuia()` daría una cosa en las filas y
             * otra en los contadores, y la pantalla mostraría nueve guías bajo
             * una pestaña que dice siete, en silencio y para siempre.
             *
             * `id` es obligatorio aparte: `chunkById` ordena por él.
             */
            ->select(['id', 'estado', 'despachado_at', 'primer_despacho_at', 'entregado_at'])
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

        /*
         * Basta con que haya salido ALGO.
         *
         * Miraba `despachado_at`, que en un despacho parcial se deja en NULL
         * hasta que salga todo. Resultado: la caja que SÍ salió y SÍ llegó no
         * se podía marcar entregada, y quedaba acumulando días como si la
         * transportadora la hubiera perdido.
         */
        $salida = $pedido->salidaDeBodega();

        abort_if($salida === null, 422,
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
        /*
         * El `??` importa: `validate()` NO devuelve las claves `nullable` que
         * no vinieron en la petición, sólo las que llegaron. La pantalla manda
         * siempre `fecha: null` explícito, así que acá nunca se notó — pero
         * cualquier otro llamador que omita el campo se lleva un 500 con
         * «Undefined array key "fecha"» en vez de la fecha de hoy.
         */
        $entregadoEn = ! empty($data['fecha']) ? \Carbon\Carbon::parse($data['fecha']) : now();

        // Contra la salida real, igual que el candado de arriba. Si se aflojara
        // uno y no el otro, esta línea reventaría con «copy() on null».
        abort_if($entregadoEn->lt($salida->copy()->startOfDay()), 422, sprintf(
            'La entrega no puede ser anterior al despacho. El pedido salió de bodega el %s.',
            $salida->format('Y-m-d')
        ));

        $pedido->update([
            'entregado_at' => $entregadoEn,
            'entregado_por_id' => $r->user()->id,
            'entrega_nota' => $data['nota'],
        ]);

        return back()->with('success', "Pedido {$pedido->numero} marcado como entregado.");
    }
}
