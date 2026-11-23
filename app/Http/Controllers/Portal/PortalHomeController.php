<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class PortalHomeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('auth:cliente')];
    }

    /**
     * LA LLAVE DEL CACHÉ, EN UN SOLO SITIO.
     *
     * Estaba escrita a mano en tres: acá y en los dos puntos que la invalidan
     * —facturar y crear pedido—. Al subirla a `v2` por la clave nueva, esos dos
     * siguieron borrando la vieja: el cliente creaba un pedido, el tablero leía
     * la v2 sin invalidar y le decía «Pendientes: 0» con el pedido ya hecho,
     * hasta un minuto después. Un caché que no se puede invalidar desde donde
     * cambian los datos es peor que no tenerlo.
     */
    public static function llaveKpis(int $contactoId): string
    {
        return "portal.kpis.v2.{$contactoId}";
    }

    public function __invoke(Request $request): Response
    {
        $cliente = $request->user('cliente');

        /*
         * QA-D Bloque3: cache 60s por cliente. Invalidación al facturar /
         * crear pedido / registrar pago.
         *
         * La llave lleva `v2` desde que se agregó «le falta mercancía»: las
         * entradas viejas no traen esa clave y la pantalla la leería como
         * indefinida durante el minuto que les queda de vida.
         */
        $kpis = Cache::remember(self::llaveKpis($cliente->id), 60, function () use ($cliente) {
            // QA-D Bloque3: timezone Bogotá explícito (evita cambiar frontera 5h antes).
            $inicioMes = now('America/Bogota')->startOfMonth();
            $finMes = now('America/Bogota')->endOfMonth();

            return [
                'pedidos_pendientes' => (int) PedidoCliente::where('contacto_id', $cliente->id)
                    ->whereIn('estado', ['borrador', 'enviado', 'aprobado'])->count(),
                /*
                 * Los que le debemos mercancía, en su propio contador.
                 *
                 * NO se suman a «pendientes»: ése cuenta lo que todavía no se
                 * aprueba o no se factura, y un pedido al que le falta el
                 * segundo viaje ya está facturado. Son dos preguntas distintas
                 * y mezclarlas haría que el cliente no sepa cuál mirar. Antes
                 * este caso no aparecía en ningún KPI: a quien le debíamos 2
                 * de 6 unidades su tablero le decía «Pendientes: 0».
                 */
                'con_resto_pendiente' => (int) PedidoCliente::where('contacto_id', $cliente->id)
                    ->where('resto_pendiente', true)->count(),
                'pedidos_mes' => (int) PedidoCliente::where('contacto_id', $cliente->id)
                    ->whereBetween('created_at', [$inicioMes, $finMes])->count(),
                'saldo' => (float) FacturaVenta::where('contacto_id', $cliente->id)
                    ->whereIn('estado', ['pendiente', 'abonada', 'vencida'])->sum('saldo'),
                'facturas_vencidas' => (int) FacturaVenta::where('contacto_id', $cliente->id)
                    ->where('estado', 'vencida')->count(),
            ];
        });

        $ultimos = PedidoCliente::where('contacto_id', $cliente->id)
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'estado' => $p->estado,
                'total' => (float) $p->total,
                'fecha' => $p->created_at?->format('Y-m-d'),
                // Un parcial vuelve a «facturado» a propósito, así que en esta
                // lista se veía igual que un pedido que no ha salido de bodega.
                'resto_pendiente' => (bool) $p->resto_pendiente,
                'salio_at' => $p->salidaDeBodega()?->format('Y-m-d'),
            ])->all();

        return Inertia::render('Portal/Dashboard', [
            'kpis' => $kpis,
            'ultimos_pedidos' => $ultimos,
        ]);
    }
}
