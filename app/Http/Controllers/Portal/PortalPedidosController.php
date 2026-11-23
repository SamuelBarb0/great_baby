<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class PortalPedidosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('auth:cliente')];
    }

    public function index(Request $request): Response
    {
        $cliente = $request->user('cliente');
        $estado = (string) $request->input('estado', '');

        // QA-D Bloque3: withCount evita N+1 (antes items()->count() por pedido).
        $pedidos = PedidoCliente::where('contacto_id', $cliente->id)
            ->withCount('items')
            // En unidades, no en líneas: «3 de 6» es lo que el cliente cuenta
            // cuando abre la caja. En SQL para no recorrer los ítems por fila.
            ->withSum('items as unidades_pedidas', 'cantidad')
            ->withSum('items as unidades_despachadas', 'cantidad_despachada')
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('id')->paginate(20)
            ->withQueryString();

        $conteos = PedidoCliente::where('contacto_id', $cliente->id)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')->pluck('total', 'estado')->all();

        return Inertia::render('Portal/Pedidos/Index', [
            'pedidos' => $pedidos->through(fn ($p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'estado' => $p->estado,
                'total' => (float) $p->total,
                'fecha' => $p->created_at?->format('Y-m-d H:i'),
                'items_count' => (int) $p->items_count,
                /*
                 * El estado no distingue un pedido que salió a medias de uno
                 * que no ha salido: los dos dicen «facturado». Para el cliente
                 * esa diferencia es toda la diferencia, así que va aparte.
                 */
                'salio_at' => $p->salidaDeBodega()?->format('Y-m-d'),
                'resto_pendiente' => (bool) $p->resto_pendiente,
                'unidades_pedidas' => (int) $p->unidades_pedidas,
                'unidades_despachadas' => (int) $p->unidades_despachadas,
            ]),
            'conteos' => $conteos,
            'estado_filtro' => $estado ?: null,
        ]);
    }

    public function show(Request $request, int $pedido): Response
    {
        $cliente = $request->user('cliente');

        $p = PedidoCliente::with([
            'items',
            'factura:id,numero,total,estado',
            // Una guía por caja: con Interrapidísimo el cliente recibe varios
            // bultos y cada uno se rastrea aparte.
            'cajas:id,pedido_id,numero,viaje,guia',
        ])
            ->where('contacto_id', $cliente->id)
            ->findOrFail($pedido);

        /*
         * Lo que de verdad salió, contado en unidades.
         *
         * `cantidad_despachada` en NULL se lee acá como CERO, nunca como «la
         * cantidad pedida». El `??` al revés —el que usa el descuento de
         * stock, donde NULL significa «sale completo»— haría que un pedido
         * que todavía no ha salido de bodega le dijera al cliente «ya
         * despachamos las 6 unidades». Son dos significados opuestos del
         * mismo NULL según quién lo lea.
         */
        $pedidas = (int) $p->items->sum('cantidad');
        $despachadas = (int) $p->items->sum(fn ($i) => (int) ($i->cantidad_despachada ?? 0));

        return Inertia::render('Portal/Pedidos/Show', [
            'pedido' => [
                'id' => $p->id,
                'numero' => $p->numero,
                'estado' => $p->estado,
                'subtotal' => (float) $p->subtotal,
                'iva' => (float) $p->iva,
                'total' => (float) $p->total,
                'notas_cliente' => $p->notas_cliente,
                'motivo_rechazo' => $p->motivo_rechazo,
                'creado' => $p->created_at?->format('Y-m-d H:i'),
                'enviado_at' => $p->enviado_at?->format('Y-m-d H:i'),
                'aprobado_at' => $p->aprobado_at?->format('Y-m-d H:i'),
                'rechazado_at' => $p->rechazado_at?->format('Y-m-d H:i'),
                'facturado_at' => $p->facturado_at?->format('Y-m-d H:i'),
                /*
                 * Acá iba `facturado_por` con el NOMBRE PROPIO del empleado
                 * que facturó. La pantalla no lo pintaba en ninguna parte,
                 * pero Inertia manda el payload entero dentro del HTML: a
                 * cualquier cliente le bastaba «ver código fuente» para leer
                 * quién le factura. El portal es de otra empresa; el personal
                 * de Great Baby no va en él.
                 */
                /*
                 * CUÁNDO SALIÓ, Y LA GUÍA PARA RASTREARLO.
                 *
                 * El portal terminaba en «Facturado»: el cliente veía su
                 * factura y después nada. La guía existía en el sistema desde
                 * el primer día, pero sólo la veía logística, así que para
                 * saber dónde iba su mercancía el cliente tenía que llamar.
                 *
                 * `salidaDeBodega()` y no `despachado_at`: en un despacho
                 * parcial esa columna queda en NULL a propósito, y el cliente
                 * cuya primera caja ya va en camino vería «no ha salido».
                 */
                'salio_at' => $p->salidaDeBodega()?->format('Y-m-d'),
                'entregado_at' => $p->entregado_at?->format('Y-m-d'),
                'transportadora' => $p->transportadora,
                'guia' => $p->guia_transportadora,
                'rastreo' => $p->urlDeRastreo(),
                'cajas' => $p->cajas->map(fn ($c) => [
                    'numero' => (int) $c->numero,
                    'viaje' => (int) $c->viaje,
                    'guia' => $c->guia,
                ])->values(),
                'viajes' => (int) ($p->cajas->max('viaje') ?? 0),
                /*
                 * `resto_pendiente`, NO `despacho_parcial`.
                 *
                 * La segunda es pegajosa: se queda en true para siempre, así
                 * que un pedido que salió en dos viajes y ya está completo le
                 * seguiría diciendo al cliente que le debemos mercancía.
                 */
                'resto_pendiente' => (bool) $p->resto_pendiente,
                'unidades_pedidas' => $pedidas,
                'unidades_despachadas' => $despachadas,
                'factura' => $p->factura ? [
                    'id' => $p->factura->id,
                    'numero' => $p->factura->numero,
                    'total' => (float) $p->factura->total,
                    'estado' => is_object($p->factura->estado) ? $p->factura->estado->value : $p->factura->estado,
                ] : null,
            ],
            'items' => $p->items->map(fn ($i) => [
                'sku' => $i->sku_snapshot,
                'desc' => $i->descripcion_snapshot,
                'cantidad' => (int) $i->cantidad,
                // Línea por línea: qué de esto ya salió y qué se debe. Es la
                // pregunta que el cliente hace por teléfono.
                'despachada' => (int) ($i->cantidad_despachada ?? 0),
                'pendiente' => max(0, (int) $i->cantidad - (int) ($i->cantidad_despachada ?? 0)),
                'precio' => (float) $i->precio_unitario,
                'subtotal' => (float) $i->subtotal,
                'iva' => (float) $i->iva_valor,
                'total' => (float) $i->total,
            ])->values(),
        ]);
    }
}
