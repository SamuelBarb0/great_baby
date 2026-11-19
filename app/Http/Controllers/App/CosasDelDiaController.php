<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\GarantiaTicket;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Models\AlertaStockConfig;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MEJORAS-A · Cosas del día: alertas proactivas para Aracely.
 * Todo lo que necesita atención HOY, en una sola pantalla.
 */
class CosasDelDiaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            // LOG · "Cosas del día" también le sirve al AdminBodega para
            //   arrancar la mañana con lo urgente (retenidos, novedades).
            abort_unless($r->user()?->esEquipoBodega(), 403);
            return $next($r);
        })];
    }

    public function __invoke(Request $r): Response
    {
        // Cache 5 min para no golpear BD en cada refresh
        $data = Cache::remember('cosas-del-dia', 300, function () {
            $hoy = today('America/Bogota');
            $ayer24h = now()->subHours(24);

            // 1. Pedidos B2B sin aprobar hace 24h+
            $pedidosB2BLentos = PedidoCliente::where('estado', 'enviado')
                ->where('created_at', '<', $ayer24h)
                ->with('contacto:id,nombre_completo,razon_social')
                ->orderBy('created_at')->limit(10)->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'numero' => $p->numero, 'url' => '/app/pedidos-b2b/' . $p->id,
                    'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
                    'total' => (float) $p->total,
                    'hace_horas' => (int) $p->created_at->diffInHours(now()),
                ]);

            // 2. Garantías sin decidir + con plazo vencido
            $garantiasSinDecidir = GarantiaTicket::whereIn('estado', ['abierto', 'en_revision'])
                ->limit(10)->get()
                ->map(fn ($g) => [
                    'id' => $g->id, 'numero' => $g->numero, 'url' => '/app/garantias/' . $g->id,
                    'cliente' => $g->cliente_nombre,
                    'plazo_vencido' => $g->plazo_concepto_at && $g->plazo_concepto_at->isPast(),
                    'hace_dias' => (int) $g->created_at?->diffInDays(now()),
                ]);

            // 3. Facturas vencidas grandes (top 10 por saldo)
            $facturasVencidas = FacturaVenta::where('estado', 'vencida')
                ->whereNull('deleted_at')->orderByDesc('saldo')->with('contacto:id,nombre_completo,razon_social,telefono')
                ->limit(10)->get()
                ->map(fn ($f) => [
                    'id' => $f->id, 'numero' => $f->numero, 'url' => '/app/facturas/' . $f->id,
                    'cliente' => $f->contacto?->razon_social ?: $f->contacto?->nombre_completo,
                    'telefono' => $f->contacto?->telefono,
                    'saldo' => (float) $f->saldo,
                    'dias_vencida' => (int) $f->fecha_vencimiento?->diffInDays($hoy, absolute: false),
                ]);

            // 4. Clientes dormidos (VIP sin comprar hace 30d+)
            $clientesDormidos = Contacto::where('activo', true)
                ->where('es_cliente_b2b', true)
                ->where(function ($q) {
                    $q->whereNull('ultima_compra_at')->orWhere('ultima_compra_at', '<', now()->subDays(30));
                })
                ->whereIn('segmento', ['VIP', 'Frecuente'])
                ->orderBy('ultima_compra_at')->limit(10)->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'url' => '/app/contactos/' . $c->id,
                    'nombre' => $c->razon_social ?: $c->nombre_completo,
                    'telefono' => $c->telefono, 'segmento' => $c->segmento,
                    'ultima_compra' => $c->ultima_compra_at?->format('Y-m-d') ?? 'nunca',
                    'dias_sin_comprar' => $c->ultima_compra_at ? (int) $c->ultima_compra_at->diffInDays(now()) : 999,
                ]);

            // 5. Dropi mercancía fantasma (marcada devuelta pero no llegó física)
            $fantasma = [];
            if (class_exists(\App\Modules\Dropi\Actions\AuditarMercanciaFantasma::class)) {
                try {
                    $fantasma = \App\Modules\Dropi\Actions\AuditarMercanciaFantasma::resumen()['ejemplos'] ?? [];
                    $fantasma = array_slice($fantasma, 0, 5);
                } catch (\Throwable) { $fantasma = []; }
            }

            // 6. Stock crítico (variantes con alerta activa + saldo bajo)
            $criticos = AlertaStockConfig::with('variante.producto:id,nombre')
                ->where('activa', true)
                ->limit(50)->get()
                ->filter(function ($a) {
                    $saldo = (int) InventarioMovimiento::where('variante_id', $a->variante_id)
                        ->where('ubicacion_id', $a->ubicacion_id)->sum('cantidad');
                    return $saldo <= (int) $a->stock_minimo;
                })
                ->take(10)
                ->map(fn ($a) => [
                    'sku' => $a->variante?->codigo_barras,
                    'producto' => $a->variante?->producto?->nombre,
                    'minimo' => (int) $a->stock_minimo,
                    'url' => '/app/inventario/kardex?codigo=' . urlencode($a->variante?->codigo_barras ?? ''),
                ])->values();

            // 7. Discrepancias Dropi wallet (dinero sin cobrar)
            $sinCobro = 0; $montoFaltante = 0;
            try {
                $ids = DropiPedido::where('estado', 'pagado')->whereNotNull('pagado_at')->pluck('id');
                $conMov = \App\Modules\Dropi\Models\DropiWalletMovimiento::whereIn('pedido_id', $ids)->pluck('pedido_id')->flip();
                $sinCobroModel = DropiPedido::where('estado', 'pagado')->whereNotIn('id', $conMov->keys())->get(['monto_esperado_proveedor']);
                $sinCobro = $sinCobroModel->count();
                $montoFaltante = (float) $sinCobroModel->sum('monto_esperado_proveedor');
            } catch (\Throwable) {}

            return [
                'generado_at' => now()->format('Y-m-d H:i'),
                'pedidosB2BLentos' => $pedidosB2BLentos,
                'garantiasSinDecidir' => $garantiasSinDecidir,
                'facturasVencidas' => $facturasVencidas,
                'clientesDormidos' => $clientesDormidos,
                'fantasma' => $fantasma,
                'criticos' => $criticos,
                'wallet' => ['sin_cobro' => $sinCobro, 'monto_faltante' => $montoFaltante],
            ];
        });

        /*
         * Las aprobaciones se arman FUERA del caché, porque dependen de quién
         * mira.
         *
         * El caché de arriba es uno solo para todo el mundo: si esto entrara
         * ahí, el primero que abriera la pantalla dejaría su versión guardada
         * cinco minutos y el siguiente vería los montos del otro. Son cinco
         * COUNT y cinco SUM, no vale la pena cachearlas.
         */
        $data['aprobaciones'] = $this->loQueEsperaFirma($r->user());

        return Inertia::render('Cosas/DelDia', $data);
    }

    /**
     * Todo lo que está esperando una firma, junto.
     *
     * «Cosas del día» ya juntaba siete cosas operativas —pedidos sin aprobar,
     * garantías sin decidir, cartera vencida—, pero las aprobaciones de plata
     * quedaban cada una en su propia pantalla: la orden de compra en Compras,
     * el asiento en Contabilidad, el gasto en Gastos, la solicitud de crédito
     * en Cartera y la liquidación de comisión en CRM. Para saber qué estaba
     * frenado había que abrir cinco pantallas y acordarse de las cinco.
     *
     * Y lo que se frena acá no es un trámite: una orden de compra sin aprobar
     * es mercancía que no se pidió, y un pedido retenido esperando crédito es
     * una venta detenida.
     *
     * Cada grupo trae monto además de conteo. «3 pendientes» no dice si son
     * tres gastos de taxi o tres órdenes de importación.
     */
    private function loQueEsperaFirma(?\App\Models\User $u): array
    {
        if (! $u) {
            return ['grupos' => [], 'total' => 0, 'monto' => 0.0];
        }

        /*
         * Cada grupo se muestra solo a quien puede entrar a resolverlo.
         *
         * La puerta de esta pantalla es el equipo de bodega, que es mas ancha
         * que las pantallas a las que mandan estas tarjetas: un AdminBodega
         * entra aca y antes veia los montos de gastos, comisiones, asientos y
         * solicitudes de credito, que sus propias pantallas le niegan con un
         * 403. Una tarjeta que dice "$836.611 esperando tu firma" ya es el
         * dato, aunque el clic despues rebote.
         *
         * Los permisos son los MISMOS que piden esas pantallas, no unos
         * nuevos: si se inventara un permiso aca, nadie lo tendria -el
         * sembrador solo siembra lo que crea en su propia corrida- y el bloque
         * entero desapareceria para todos.
         */
        $contable = $u->esContable();
        $grupos = [];

        /*
         * Órdenes de compra sin aprobar: mercancía que todavía no se pidió.
         *
         * Van los dos estados que `AprobarOrdenCompra` acepta. Antes sólo se
         * contaba `borrador` y las que ya se le mandaron al proveedor
         * —`enviada`, que siguen esperando firma— no aparecían.
         */
        $oc = DB::table('compras_ordenes')->whereIn('estado', ['borrador', 'enviada'])->whereNull('deleted_at');
        if (Permisos::puede($u, 'compras_oc')) $grupos[] = [
            'clave' => 'ordenes_compra',
            'titulo' => 'Órdenes de compra sin aprobar',
            'detalle' => 'Mientras no se aprueben, la mercancía no se pidió.',
            'cantidad' => (clone $oc)->count(),
            'monto' => round((float) (clone $oc)->sum('total'), 2),
            // El menú las llama «Órdenes de compra» y apunta acá.
            // `/app/compras/ordenes` no existe: el clic caía en un 404.
            'url' => '/app/compras',
        ];

        // Gastos esperando visto bueno.
        // Con `DB::table` el borrado suave no se aplica solo: sin esto, un
        // gasto borrado seguiría pidiendo firma.
        // La pantalla de gastos le muestra al comun solo LOS SUYOS; el total de
        // todos es cosa de contabilidad.
        $gastos = DB::table('gastos_operativos')->where('estado', 'pendiente')->whereNull('deleted_at');
        if ($contable) $grupos[] = [
            'clave' => 'gastos',
            'titulo' => 'Gastos sin aprobar',
            'detalle' => 'Quien los pidió ya puso la plata o está esperando para ponerla.',
            'cantidad' => (clone $gastos)->count(),
            'monto' => round((float) (clone $gastos)->sum('monto'), 2),
            'url' => '/app/gastos',
        ];

        /*
         * Solicitudes de crédito: cada una es un pedido retenido.
         *
         * Es la que más cuesta dejar quieta: del otro lado hay una venta
         * parada y un cliente esperando.
         */
        $credito = DB::table('solicitudes_credito')->where('estado', 'pendiente');
        if (Permisos::puede($u, 'excepciones_credito')) $grupos[] = [
            'clave' => 'credito',
            'titulo' => 'Solicitudes de crédito',
            'detalle' => 'Cada una es un pedido retenido esperando respuesta.',
            'cantidad' => (clone $credito)->count(),
            'monto' => round((float) (clone $credito)->sum('monto_pedido'), 2),
            'url' => '/app/cartera/solicitudes',
        ];

        /*
         * Asientos que esperan firma: los CUADRADOS, no los borradores.
         *
         * Acá estaba el error: se consultaba `borrador`, que es el asiento que
         * todavía se está escribiendo y no espera nada de nadie. El que pide
         * firma es el que ya cuadró —debe igual a haber— y espera el «aprobar»
         * para salir a SIIGO; es el único estado desde el que `aprobar()` deja
         * pasar. Con la consulta vieja, las aprobaciones contables no
         * aparecían NUNCA en esta pantalla.
         */
        $asientos = DB::table('asientos_manuales')->where('estado', 'cuadrado')->whereNull('deleted_at');
        if ($contable) $grupos[] = [
            'clave' => 'asientos',
            'titulo' => 'Asientos manuales por aprobar',
            'detalle' => 'Hasta que se aprueben no llegan a SIIGO, que es la contabilidad oficial.',
            'cantidad' => (clone $asientos)->count(),
            'monto' => round((float) (clone $asientos)->sum('valor_total'), 2),
            'url' => '/app/contabilidad/asientos-manuales',
        ];

        // Liquidaciones de comisión en borrador: plata que el vendedor espera.
        $comisiones = DB::table('comisiones_calculadas')->where('estado', 'borrador');
        if (Permisos::puede($u, 'comisiones')) $grupos[] = [
            'clave' => 'comisiones',
            'titulo' => 'Liquidaciones de comisión en borrador',
            'detalle' => 'Es plata que el vendedor ya se ganó y está esperando.',
            'cantidad' => (clone $comisiones)->count(),
            'monto' => round((float) (clone $comisiones)->sum('total_a_pagar'), 2),
            'url' => '/app/cartera/comisiones',
        ];

        $conPendientes = array_values(array_filter($grupos, fn ($g) => $g['cantidad'] > 0));

        return [
            'grupos' => $conPendientes,
            'total' => array_sum(array_column($conPendientes, 'cantidad')),
            'monto' => round(array_sum(array_column($conPendientes, 'monto')), 2),
        ];
    }
}
