<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Portal\Actions\ConvertirCotizacionEnPedido;
use App\Modules\Portal\Actions\CrearCotizacion;
use App\Modules\Portal\Models\Cotizacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cotizaciones: lo que se le prometió a un cliente antes de que haya pedido.
 *
 * El ERP sólo sabía levantar pedidos. Un cliente B2B casi nunca compra en la
 * primera conversación —pide precios, compara y vuelve a la semana—, así que
 * ese paso se hacía por WhatsApp y después se transcribía a mano: el precio
 * prometido no quedaba registrado en ninguna parte y al transcribir se
 * equivocaban.
 *
 * La cotización se arma desde la MISMA pantalla donde el vendedor arma un
 * pedido, con el mismo carrito y los mismos precios. Lo único que cambia es a
 * dónde va: pedido o cotización. Una segunda pantalla de catálogo se habría
 * ido separando de la primera.
 */
class CotizacionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'vendedor'), 403,
                    'Las cotizaciones las maneja el equipo comercial.');

                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        $estado = (string) $r->input('estado', '');
        $q = trim((string) $r->input('q', ''));
        $u = $r->user();

        /*
         * Un vendedor ve lo suyo; quien manda, todo.
         *
         * Es la misma regla que ya aplica el panel del vendedor: sin esto,
         * cualquiera de los ocho abre la cotización de un compañero y se entera
         * de qué precio le está ofreciendo a quién.
         */
        $esSuper = \App\Auth\Permisos::esRoot($u) || $u->esAracely();

        $cotizaciones = Cotizacion::query()
            ->with(['cliente:id,razon_social,nombre_completo', 'vendedor:id,name', 'pedido:id,numero'])
            ->when(! $esSuper, fn ($qb) => $qb->where('vendedor_id', $u->id))
            ->when($estado === 'vencidas', fn ($qb) => $qb
                ->whereDate('vigente_hasta', '<', now()->toDateString())
                ->whereNotIn('estado', ['convertida', 'rechazada']))
            ->when($estado && $estado !== 'vencidas', fn ($qb) => $qb->where('estado', $estado))
            ->when($q, fn ($qb) => $qb->where(function ($w) use ($q) {
                $w->where('numero', 'like', "%{$q}%")
                    ->orWhereHas('cliente', fn ($c) => $c
                        ->where('razon_social', 'like', "%{$q}%")
                        ->orWhere('nombre_completo', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cotizaciones/Index', [
            'cotizaciones' => $cotizaciones->through(fn (Cotizacion $c) => [
                'id' => $c->id,
                'numero' => $c->numero,
                'cliente' => $c->cliente?->razon_social ?: $c->cliente?->nombre_completo,
                'vendedor' => $c->vendedor?->name,
                'fecha' => $c->fecha?->toDateString(),
                'vigente_hasta' => $c->vigente_hasta?->toDateString(),
                'dias_para_vencer' => $c->diasParaVencer(),
                'estado' => $c->estadoVisible(),
                'total' => (float) $c->total,
                'pedido' => $c->pedido?->numero,
            ]),
            'filtros' => ['estado' => $estado ?: null, 'q' => $q ?: null],
            'resumen' => $this->resumen($esSuper ? null : (int) $u->id),
        ]);
    }

    /**
     * Cuánta plata hay cotizada y cuánta se está venciendo.
     *
     * Una cotización aceptada que vence sin convertirse es plata que el cliente
     * YA dijo que sí y que se pierde por no haberla pasado a pedido. Es el
     * número que justifica la pantalla.
     */
    private function resumen(?int $vendedorId): array
    {
        $base = fn () => Cotizacion::query()
            ->when($vendedorId, fn ($q) => $q->where('vendedor_id', $vendedorId));

        $aviso = max(1, (int) setting('ventas.dias_aviso_cotizacion'));

        return [
            'vigentes' => (clone $base())->vigentes()->count(),
            'monto_vigente' => round((float) (clone $base())->vigentes()->sum('total'), 2),
            'aceptadas_sin_convertir' => (clone $base())
                ->where('estado', 'aceptada')->whereNull('pedido_id')->count(),
            'por_vencer' => (clone $base())->porVencer($aviso)->count(),
            'monto_por_vencer' => round((float) (clone $base())->porVencer($aviso)->sum('total'), 2),
            'dias_aviso' => $aviso,
        ];
    }

    /** Se arma desde la pantalla del vendedor; acá sólo se guarda. */
    public function crear(Request $r, int $contactoId): RedirectResponse
    {
        $data = $r->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id', 'required_without:items.*.producto_id'],
            'items.*.producto_id' => ['nullable', 'integer', 'exists:productos,id', 'required_without:items.*.variante_id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:9999'],
            // El vendedor puede cotizar por debajo de la lista: negociar es
            // media razón de existir de una cotización.
            'items.*.precio' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:today'],
            'empresa_id' => ['nullable', 'integer', Rule::exists('empresa_config', 'id')->where('activa', true)],
        ]);

        $cliente = Contacto::whereKey($contactoId)->whereNotNull('lista_precios_id')->firstOrFail();

        /*
         * La cuenta tiene dueño y no soy yo → 403.
         *
         * El pedido del vendedor ya traía este candado —`autorizarCuenta()` en
         * VendedorPedidoController—, pero la cotización nació sin él, y por acá
         * se colaba justo lo que ese candado evita: con la URL del cliente de
         * un compañero, cualquiera de los 8 vendedores le cotizaba, veía razón
         * social, documento, ciudad y sus precios, y después convertía esa
         * cotización en un pedido real a su nombre.
         *
         * El buscador de clientes ya filtra por cartera, pero eso es comodidad:
         * un POST armado a mano se la salta. Gerencia pasa siempre, que tiene
         * que poder cotizar por teléfono.
         */
        abort_unless($cliente->esTrabajablePor($r->user()), 403,
            'Esta cuenta es de otro vendedor. Pedile a gerencia que te la reasigne.');

        $cot = app(CrearCotizacion::class)->ejecutar(
            cliente: $cliente,
            items: $data['items'],
            vendedorId: $r->user()->id,
            notas: $data['notas'] ?? null,
            vigenteHasta: $data['vigente_hasta'] ?? null,
            empresaId: $data['empresa_id'] ?? null,
        );

        return redirect("/app/cotizaciones/{$cot->id}")
            ->with('success', "Cotización {$cot->numero} creada · vence el {$cot->vigente_hasta->format('Y-m-d')}.");
    }

    public function show(Request $r, Cotizacion $cotizacion): Response
    {
        $cotizacion->load(['items', 'cliente', 'vendedor:id,name', 'pedido:id,numero',
            'creadoPor:id,name', 'convertidaPor:id,name']);

        $this->autorizar($r, $cotizacion);

        return Inertia::render('Cotizaciones/Show', [
            'cotizacion' => [
                'id' => $cotizacion->id,
                'numero' => $cotizacion->numero,
                'estado' => $cotizacion->estadoVisible(),
                'estado_guardado' => $cotizacion->estado,
                'fecha' => $cotizacion->fecha?->toDateString(),
                'vigente_hasta' => $cotizacion->vigente_hasta?->toDateString(),
                'dias_para_vencer' => $cotizacion->diasParaVencer(),
                'vigente' => $cotizacion->estaVigente(),
                'cliente' => [
                    'id' => $cotizacion->cliente?->id,
                    'nombre' => $cotizacion->cliente?->razon_social ?: $cotizacion->cliente?->nombre_completo,
                    'documento' => $cotizacion->cliente?->numero_documento,
                    'ciudad' => $cotizacion->cliente?->ciudad,
                ],
                'vendedor' => $cotizacion->vendedor?->name,
                'notas' => $cotizacion->notas,
                'motivo_rechazo' => $cotizacion->motivo_rechazo,
                'subtotal' => (float) $cotizacion->subtotal,
                'iva' => (float) $cotizacion->iva,
                'total' => (float) $cotizacion->total,
                'pedido' => $cotizacion->pedido ? [
                    'id' => $cotizacion->pedido->id, 'numero' => $cotizacion->pedido->numero,
                ] : null,
                /*
                 * Sólo cuando NO es el mismo vendedor.
                 *
                 * «Convertida por Carlos» debajo de «Vendedor: Carlos» es ruido
                 * en el 95% de los casos. El dato importa justo cuando difiere:
                 * ahí dice que alguien más cerró esa venta, y de quién sigue
                 * siendo.
                 */
                'convertida_por' => ($cotizacion->convertida_por
                    && (int) $cotizacion->convertida_por !== (int) $cotizacion->vendedor_id)
                        ? $cotizacion->convertidaPor?->name
                        : null,
                // El mismo texto que explica el botón deshabilitado, para que
                // no haya dos versiones de la razón.
                'por_que_no' => $cotizacion->porQueNoSeConvierte(),
                'items' => $cotizacion->items->map(fn ($i) => [
                    'id' => $i->id,
                    'sku' => $i->sku_snapshot,
                    'descripcion' => $i->descripcion_snapshot,
                    'cantidad' => (int) $i->cantidad,
                    'precio' => (float) $i->precio_unitario,
                    'iva_pct' => (float) $i->iva_porcentaje,
                    'total' => (float) $i->total,
                ]),
            ],
        ]);
    }

    public function enviar(Request $r, Cotizacion $cotizacion): RedirectResponse
    {
        $this->autorizar($r, $cotizacion);

        abort_unless($cotizacion->estado === 'borrador', 422,
            'Sólo se puede enviar una cotización en borrador.');

        $cotizacion->update(['estado' => 'enviada', 'enviada_at' => now()]);

        return back()->with('success', 'Cotización marcada como enviada al cliente.');
    }

    /** Lo que contestó el cliente. */
    public function responder(Request $r, Cotizacion $cotizacion): RedirectResponse
    {
        $this->autorizar($r, $cotizacion);

        $data = $r->validate([
            'respuesta' => ['required', 'in:aceptada,rechazada'],
            // Por qué se perdió. Sin esto, al mes no hay forma de saber si se
            // pierde por precio, por plazo o por tiempo de entrega.
            'motivo_rechazo' => ['required_if:respuesta,rechazada', 'nullable', 'string', 'max:255'],
        ]);

        abort_if(in_array($cotizacion->estado, ['convertida'], true), 422,
            'Esta cotización ya se convirtió en pedido.');

        $cotizacion->update([
            'estado' => $data['respuesta'],
            'motivo_rechazo' => $data['respuesta'] === 'rechazada' ? $data['motivo_rechazo'] : null,
            'respondida_at' => now(),
        ]);

        return back()->with('success', $data['respuesta'] === 'aceptada'
            ? 'Cliente aceptó. Ya se puede convertir en pedido.'
            : 'Cotización marcada como rechazada.');
    }

    public function convertir(Request $r, Cotizacion $cotizacion): RedirectResponse
    {
        $this->autorizar($r, $cotizacion);

        // Quién aprieta el botón, no de quién es la venta: el pedido queda del
        // vendedor que cotizó aunque lo convierta gerencia.
        $pedido = app(ConvertirCotizacionEnPedido::class)
            ->ejecutar($cotizacion, convertidaPor: $r->user()->id);

        $ajena = (int) $pedido->vendedor_id !== (int) $r->user()->id;

        $aviso = "Pedido {$pedido->numero} creado desde la cotización {$cotizacion->numero}, "
            .'con los precios que se cotizaron.'
            .($ajena ? " La venta quedó a nombre de {$pedido->vendedor?->name}." : '');

        /*
         * El IVA se recalcula con el impuesto de HOY, y eso es a propósito.
         *
         * El precio se congela porque es una promesa comercial: lo acordaron
         * dos personas y se respeta. El IVA no se acuerda con nadie —lo pone
         * la ley o lo corrige quien arregla la ficha del producto—, así que
         * facturar el de hace veinte días sería liquidar mal un impuesto.
         *
         * Lo que sí no puede pasar es que el total cambie en silencio: el
         * cliente aceptó una cifra. Si el impuesto se movió, el total del
         * pedido no es el de la cotización y hay que decirlo acá, que es donde
         * alguien lo puede atajar antes de facturar.
         */
        $diferencia = round((float) $pedido->total - (float) $cotizacion->total, 2);

        if (abs($diferencia) >= 0.01) {
            $aviso .= sprintf(
                ' OJO: el total quedó en $%s y la cotización decía $%s (%s$%s). '
                .'Cambió el IVA del producto entre que se cotizó y hoy.',
                number_format((float) $pedido->total, 0, ',', '.'),
                number_format((float) $cotizacion->total, 0, ',', '.'),
                $diferencia > 0 ? '+' : '−',
                number_format(abs($diferencia), 0, ',', '.')
            );
        }

        return redirect("/app/pedidos-b2b/{$pedido->id}")
            ->with($diferencia != 0.0 ? 'warning' : 'success', $aviso);
    }

    /** Un vendedor no abre la cotización de un compañero. */
    private function autorizar(Request $r, Cotizacion $cotizacion): void
    {
        $u = $r->user();

        if (\App\Auth\Permisos::esRoot($u) || $u->esAracely()) {
            return;
        }

        abort_unless((int) $cotizacion->vendedor_id === (int) $u->id, 403,
            'Esa cotización es de otro vendedor.');
    }
}
