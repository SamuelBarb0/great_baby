<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Cartera\Actions\ConsultarCredito;
use App\Modules\Portal\Actions\CrearPedidoCliente;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Services\SeguimientoVendedorService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LOG-J1 · El vendedor arma pedido "a nombre de" un cliente.
 *
 * Los 8 vendedores de Jorge visitan comercios con muestras. Antes volvían
 * a la oficina y pedían a Aracely que levantara los pedidos. Esta pantalla
 * los deja levantar el pedido ellos mismos desde su celular/tablet con su
 * propia sesión del ERP, dejando el `vendedor_id` registrado para que
 * comisiones y gerencia sepan quién vendió qué.
 *
 * El pedido pasa por el mismo semáforo de cartera que LOG-J2 (si el
 * cliente tiene mora, el pedido queda retenido).
 */
class VendedorPedidoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            // Antes preguntaba `hasRole('Vendedor')`: el rol estaba escrito en
            // código y crear uno nuevo con ese acceso no servía de nada.
            abort_unless(
                \App\Auth\Permisos::puede($u, 'vendedor'),
                403,
                'Esta vista es para vendedores en terreno.'
            );
            return $next($r);
        })];
    }

    /**
     * LOG-J1 + Miracle port · Dashboard del vendedor con los KPIs del mes,
     *   comparativa con el periodo anterior, pedidos por estado (funnel),
     *   ranking de clientes y últimos pedidos levantados. Debajo del tablero
     *   queda el buscador para armar un pedido nuevo.
     */
    public function index(Request $r, SeguimientoVendedorService $sv): Response
    {
        $vendedorId = (int) $r->user()->id;
        // Aracely/Gerencia ven agregado (vendedor_id=null); cada Vendedor
        //   solo ve lo suyo. Mismo patrón que Miracle PanelVendedorController.
        $esSuper = $r->user()->esAracely();
        $vendedorScope = $esSuper ? null : $vendedorId;

        [$periodo, $ini, $fin, $iniAnt, $finAnt] = $sv->resolverPeriodo(
            $r->input('periodo'),
            $r->input('desde'),
            $r->input('hasta')
        );

        $comparativa = $sv->comparativaVendedor($vendedorScope, $ini, $fin, $iniAnt, $finAnt);
        $funnel      = $sv->pedidosPorEstado($vendedorScope, $ini, $fin);
        $ranking     = $sv->rankingClientes($vendedorScope, $ini, $fin, 10);
        $tendencia   = $sv->tendenciaDiaria($vendedorScope, 30);

        // Últimos 10 pedidos levantados por este vendedor (independiente del
        //   periodo seleccionado · es la bandeja de trabajo).
        $misPedidos = PedidoCliente::query()
            ->when(! $esSuper, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->with(['contacto:id,razon_social,nombre_completo'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'numero', 'contacto_id', 'estado', 'total', 'created_at', 'motivo_retencion'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
                'estado' => $p->estado,
                'total' => (float) $p->total,
                'fecha' => $p->created_at?->format('Y-m-d H:i'),
                'motivo_retencion' => $p->motivo_retencion,
            ]);

        // Buscador de clientes para armar pedido nuevo · un vendedor sólo ve su
        //   cartera y las cuentas libres. Sin esto cualquiera de los 8 abría el
        //   cliente de un compañero y se quedaba la comisión.
        $clientes = Contacto::query()
            ->where('activo', true)
            ->when(! $esSuper, fn ($q) => $q->deVendedor($vendedorId))
            ->with('vendedor:id,name')
            ->when(! empty($r->input('q')), function ($q) use ($r) {
                $term = '%' . trim($r->input('q')) . '%';
                $q->where(function ($w) use ($term) {
                    $w->where('razon_social', 'like', $term)
                      ->orWhere('nombre_completo', 'like', $term)
                      ->orWhere('numero_documento', 'like', $term)
                      ->orWhere('email', 'like', $term);
                });
            })
            ->whereNotNull('lista_precios_id')
            ->orderBy('razon_social')
            ->limit(20)
            ->get(['id', 'razon_social', 'nombre_completo', 'numero_documento', 'email',
                   'ciudad', 'lista_precios_id', 'telefono', 'vendedor_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'razon_social' => $c->razon_social,
                'nombre_completo' => $c->nombre_completo,
                'numero_documento' => $c->numero_documento,
                'email' => $c->email,
                'ciudad' => $c->ciudad,
                'telefono' => $c->telefono,
                'lista_precios_id' => $c->lista_precios_id,
                // Para que el vendedor distinga su cartera de las cuentas libres.
                'es_mio' => (int) $c->vendedor_id === $vendedorId,
                'libre' => $c->vendedor_id === null,
                'vendedor' => $c->vendedor?->name,
            ]);

        return Inertia::render('Vendedor/Panel', [
            'periodo' => $periodo,
            'rango' => [
                'inicio' => $ini->format('Y-m-d'),
                'fin' => $fin->format('Y-m-d'),
            ],
            'resumen' => $comparativa['actual'],
            'anterior' => $comparativa['anterior'],
            'variacion' => $comparativa['variacion'],
            'funnel' => $funnel,
            'ranking' => $ranking,
            'tendencia' => $tendencia,
            'mis_pedidos' => $misPedidos,
            'clientes' => $clientes,
            'q' => $r->input('q'),
            'es_super' => $esSuper,
        ]);
    }

    /** Miracle port · Ventas por cliente (ranking + última compra). */
    public function ventasPorCliente(Request $r, SeguimientoVendedorService $sv): Response
    {
        $u = $r->user();
        $scope = $u->esAracely() ? null : (int) $u->id;
        [$periodo, $ini, $fin] = $sv->resolverPeriodo($r->input('periodo'), $r->input('desde'), $r->input('hasta'));

        return Inertia::render('Vendedor/VentasPorCliente', [
            'periodo' => $periodo,
            'rango' => ['inicio' => $ini->format('Y-m-d'), 'fin' => $fin->format('Y-m-d')],
            'clientes' => $sv->rankingClientes($scope, $ini, $fin, 50),
            'es_super' => $u->esAracely(),
        ]);
    }

    /** Miracle port · Contado vs crédito (desglose por tipo de pago en el periodo). */
    public function contadoCredito(Request $r, SeguimientoVendedorService $sv): Response
    {
        $u = $r->user();
        $scope = $u->esAracely() ? null : (int) $u->id;
        [$periodo, $ini, $fin] = $sv->resolverPeriodo($r->input('periodo'), $r->input('desde'), $r->input('hasta'));

        // Resumen contado/crédito desde FacturaVenta por tipo.
        $contado = \App\Modules\Cartera\Models\FacturaVenta::query()
            ->whereBetween('fecha_emision', [$ini->toDateString(), $fin->toDateString()])
            ->whereNotIn('estado', ['borrador', 'anulada'])
            ->where('tipo', 'contado')
            ->whereHas('origen', function ($q) use ($u, $scope) {
                if (! $u->esAracely()) {
                    $q->where('vendedor_id', $scope);
                }
            })
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto')
            ->first();

        $credito = \App\Modules\Cartera\Models\FacturaVenta::query()
            ->whereBetween('fecha_emision', [$ini->toDateString(), $fin->toDateString()])
            ->whereNotIn('estado', ['borrador', 'anulada'])
            ->where('tipo', 'credito')
            ->whereHas('origen', function ($q) use ($u, $scope) {
                if (! $u->esAracely()) {
                    $q->where('vendedor_id', $scope);
                }
            })
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto, COALESCE(SUM(saldo), 0) as saldo_pendiente')
            ->first();

        return Inertia::render('Vendedor/ContadoCredito', [
            'periodo' => $periodo,
            'rango' => ['inicio' => $ini->format('Y-m-d'), 'fin' => $fin->format('Y-m-d')],
            'contado' => [
                'cantidad' => (int) ($contado->cantidad ?? 0),
                'monto' => (float) ($contado->monto ?? 0),
            ],
            'credito' => [
                'cantidad' => (int) ($credito->cantidad ?? 0),
                'monto' => (float) ($credito->monto ?? 0),
                'saldo_pendiente' => (float) ($credito->saldo_pendiente ?? 0),
            ],
            'es_super' => $u->esAracely(),
        ]);
    }

    /** Miracle port · Seguimiento (pendientes · por cobrar · últimos). */
    public function seguimiento(Request $r, SeguimientoVendedorService $sv): Response
    {
        $u = $r->user();
        $scope = $u->esAracely() ? null : (int) $u->id;
        $data = $sv->seguimientoPedidos($scope);

        return Inertia::render('Vendedor/Seguimiento', [
            'pendientes' => $data['pendientes'],
            'por_cobrar' => $data['por_cobrar'],
            'ultimos' => $data['ultimos'],
            'totales' => $data['totales'],
            'es_super' => $u->esAracely(),
        ]);
    }

    public function nuevo(Request $r, int $contactoId): Response
    {
        $cliente = Contacto::query()
            ->with('lista:id,nombre')
            ->where('id', $contactoId)
            ->whereNotNull('lista_precios_id')
            ->firstOrFail();

        $this->autorizarCuenta($cliente, $r);

        // Catálogo con el precio que corresponde al cliente — reutilizamos
        //   PrecioVariante · lista_id del cliente.
        $precios = PrecioVariante::query()
            ->where('lista_id', $cliente->lista_precios_id)
            ->where(function ($w) {
                $w->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', now()->toDateString());
            })
            ->pluck('precio', 'variante_id');

        $variantes = ProductoVariante::query()
            ->with(['producto' => fn ($q) => $q->select('id', 'referencia', 'nombre', 'impuesto_id')
                ->with('impuesto:id,porcentaje')])
            ->whereIn('id', $precios->keys())
            ->whereHas('producto', fn ($q) => $q->where('activo', true))
            ->orderBy('id')
            ->get(['id', 'producto_id', 'codigo_barras', 'color_nombre', 'talla'])
            ->map(fn ($v) => [
                'variante_id' => $v->id,
                'sku' => $v->codigo_barras ?: ($v->producto?->referencia . '-' . $v->id),
                'ref' => $v->producto?->referencia,
                'nombre' => $v->producto?->nombre,
                'color' => $v->color_nombre,
                'talla' => $v->talla,
                'precio' => (float) ($precios[$v->id] ?? 0),
                'iva_pct' => (float) ($v->producto?->impuesto?->porcentaje ?? 0),
            ])
            ->values();

        // Productos AGREGADOS con precio en la lista del cliente.
        //
        // Son los que no se desglosan por variante —colores surtidos— y hasta
        // el 2026-10-08 no tenían dónde guardar su precio, así que ni siquiera
        // llegaban a este catálogo: el vendedor no podía ofrecerlos. Van en la
        // misma lista que las variantes, con `variante_id` nulo, que es como
        // los distingue el pedido.
        $agregados = \App\Modules\Catalogo\Models\PrecioProducto::query()
            ->where('lista_id', $cliente->lista_precios_id)
            ->vigentes()
            ->with(['producto' => fn ($q) => $q->select('id', 'referencia', 'nombre', 'impuesto_id', 'activo', 'desglose_stock')
                ->with('impuesto:id,porcentaje')])
            ->whereHas('producto', fn ($q) => $q->where('activo', true)->where('desglose_stock', false))
            ->get()
            ->map(fn ($pp) => [
                'variante_id' => null,
                'producto_id' => $pp->producto_id,
                'sku' => $pp->producto?->referencia,
                'ref' => $pp->producto?->referencia,
                'nombre' => $pp->producto?->nombre,
                'color' => 'colores surtidos',
                'talla' => null,
                'precio' => (float) $pp->precio,
                'iva_pct' => (float) ($pp->producto?->impuesto?->porcentaje ?? 0),
            ])
            ->values();

        $variantes = $variantes->concat($agregados)->values();

        /*
         * Cuántas unidades hay de verdad.
         *
         * El catálogo del vendedor NO traía existencias: se podían prometer 999
         * unidades de algo que tiene 35 y la pantalla no decía nada, ni al
         * escribir la cantidad ni al enviar el pedido. Comprobado el 2026-10-13
         * levantando un pedido real por $24.875.100 sobre 35 unidades de stock.
         * El problema aparecía recién en bodega, con el cliente ya avisado y la
         * factura en camino.
         *
         * No se bloquea —un pedido contra contenedor que viene en camino es
         * legítimo— pero tiene que verse antes de prometerlo.
         */
        $saldos = $this->disponiblePorSujeto($variantes);

        $variantes = $variantes->map(function ($v) use ($saldos) {
            $v['disponible'] = (int) ($saldos[$v['variante_id'] ? 'v:'.$v['variante_id'] : 'p:'.$v['producto_id']] ?? 0);

            return $v;
        })->values();

        // Semáforo rápido · para que el vendedor sepa en terreno si el pedido
        //   del cliente va a quedar retenido antes de armarlo.
        $credito = ConsultarCredito::run($cliente->id);

        return Inertia::render('Vendedor/NuevoPedido', [
            'cliente' => [
                'id' => $cliente->id,
                'nombre' => $cliente->razon_social ?: $cliente->nombre_completo,
                'documento' => $cliente->numero_documento,
                'ciudad' => $cliente->ciudad,
                'lista' => $cliente->lista?->nombre,
                'telefono' => $cliente->telefono,
            ],
            'variantes' => $variantes,
            'credito' => [
                'tiene_mora_critica' => (bool) ($credito['tiene_mora_critica'] ?? false),
                'facturas_vencidas' => (int) ($credito['facturas_vencidas'] ?? 0),
                'saldo_cartera' => (float) ($credito['saldo_cartera'] ?? 0),
                'cupo' => (float) ($credito['cupo'] ?? 0),
                'dias_mora_max' => (int) ($credito['dias_mora_max'] ?? 0),
            ],
        ]);
    }

    /**
     * Saldo disponible de cada ítem del catálogo, en dos consultas.
     *
     * Sólo cuentan las bodegas de venta activas: lo que está en avería, en
     * garantía o reservado no se le puede prometer a nadie.
     *
     * @return array<string,int>  clave `v:{id}` o `p:{id}` => unidades
     */
    private function disponiblePorSujeto(\Illuminate\Support\Collection $items): array
    {
        $ubicaciones = \App\Modules\Dropi\Models\InventarioUbicacion::query()
            ->where('activa', true)
            ->where('disponible_para_venta', true)
            ->pluck('id');

        if ($ubicaciones->isEmpty()) {
            return [];
        }

        $out = [];

        $variantes = $items->pluck('variante_id')->filter()->values();
        if ($variantes->isNotEmpty()) {
            \App\Modules\Dropi\Models\InventarioMovimiento::query()
                ->whereIn('variante_id', $variantes)
                ->whereIn('ubicacion_id', $ubicaciones)
                ->selectRaw('variante_id, SUM(cantidad) as saldo')
                ->groupBy('variante_id')->get()
                // `fn` captura por valor: con una arrow function `$out` se
                // escribía sobre una copia y volvía siempre vacío.
                ->each(function ($r) use (&$out) {
                    $out['v:'.$r->variante_id] = (int) $r->saldo;
                });
        }

        $productos = $items->where('variante_id', null)->pluck('producto_id')->filter()->values();
        if ($productos->isNotEmpty()) {
            \App\Modules\Dropi\Models\InventarioMovimiento::query()
                ->whereIn('producto_id', $productos)
                ->whereNull('variante_id')
                ->whereIn('ubicacion_id', $ubicaciones)
                ->selectRaw('producto_id, SUM(cantidad) as saldo')
                ->groupBy('producto_id')->get()
                ->each(function ($r) use (&$out) {
                    $out['p:'.$r->producto_id] = (int) $r->saldo;
                });
        }

        return $out;
    }

    public function confirmar(Request $r, int $contactoId): RedirectResponse
    {
        $data = $r->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            // Una línea es de variante (producto granular) O de producto
            // (agregado, colores surtidos), nunca las dos. Antes sólo aceptaba
            // variantes y por eso el vendedor no podía pedir un agregado.
            'items.*.variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id', 'required_without:items.*.producto_id'],
            'items.*.producto_id' => ['nullable', 'integer', 'exists:productos,id', 'required_without:items.*.variante_id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:9999'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $cliente = Contacto::whereKey($contactoId)->whereNotNull('lista_precios_id')->firstOrFail();
        $this->autorizarCuenta($cliente, $r);

        // Las reglas del pedido viven en una sola parte.
        //
        // Acá había una segunda copia completa de lo que ya hacía el portal:
        // precios por lista, semáforo de cartera (LOG-J2), ruteo por ciudad
        // (LOG-J4), consecutivo, items y aviso a gerencia. Dos copias de la
        // misma norma terminan separándose sin que nadie lo note —la del
        // vendedor ya no aceptaba productos agregados, por ejemplo— y el
        // pedido que levanta el vendedor en la calle tiene que valer
        // exactamente lo mismo que el que arma el cliente en el portal.
        $pedido = app(CrearPedidoCliente::class)->ejecutar(
            cliente: $cliente,
            items: $data['items'],
            notas: $data['notas'] ?? null,
            vendedorId: $r->user()->id,
        );

        return redirect()->route('app.vendedor.index')->with(
            $pedido->estado === 'retenido' ? 'warning' : 'success',
            "Pedido {$pedido->numero} registrado para {$cliente->nombre_completo}."
            .($pedido->estado === 'retenido' ? " RETENIDO por cartera: {$pedido->motivo_retencion}." : '')
        );
    }

    /**
     * La cuenta tiene dueño y no soy yo → 403.
     *
     * `pedidos_cliente.vendedor_id` registraba quién levantó el pedido, pero el
     * cliente no tenía dueño: con la URL del cliente de un compañero cualquiera
     * de los 8 vendedores le levantaba el pedido y la comisión salía a su
     * nombre. Gerencia pasa siempre (tiene que poder levantar por teléfono).
     */
    private function autorizarCuenta(Contacto $cliente, Request $r): void
    {
        abort_unless(
            $cliente->esTrabajablePor($r->user()),
            403,
            'Esta cuenta es de otro vendedor. Pedile a gerencia que te la reasigne.'
        );
    }

    private function siguienteNumero(): string
    {
        $prefijo = 'PB-' . now()->format('ymd') . '-';
        $ultimo = PedidoCliente::where('numero', 'like', $prefijo . '%')
            ->lockForUpdate()->orderByDesc('id')->value('numero');
        $sig = $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1;
        return $prefijo . str_pad((string) $sig, 4, '0', STR_PAD_LEFT);
    }
}
