<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Models\GarantiaTicket;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * MEJORAS-B · Notificaciones tipo real-time via polling AJAX.
 * El cliente hace GET cada 15s con ?desde=timestamp; retorna eventos nuevos.
 *
 * 2026-10-19 · La campana NUNCA leyó la tabla `notificaciones_erp`.
 *
 *   Se encontró probando la solicitud de personal en pantalla: Elena abre la
 *   campana, dice «Sin novedades», y en la tabla hay dos avisos dirigidos a
 *   ella. `NotificacionErp::crear()` se llama desde tres lugares (cola de
 *   Jorge, pedidos B2B y ahora las requisiciones) y escribe bien; lo que
 *   faltaba era leerla. Seis filas escritas desde el 13-oct, ninguna visible.
 *
 *   Y había un segundo problema encima: los cuatro bloques de abajo son
 *   GLOBALES —no filtran por usuario— y sólo traen lo ocurrido DESPUÉS de
 *   abrir la pestaña. Un aviso que sólo ves si justo estabas mirando no es un
 *   aviso. Por eso ahora la respuesta trae también `pendientes`: lo que te
 *   espera sin leer, hayas tenido o no la pantalla abierta.
 */
class NotificacionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(['auth', 'throttle:120,1'])];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $desde = $request->input('desde');
        $cutoff = $desde ? \Carbon\Carbon::parse($desde) : now()->subMinutes(15);
        $eventos = [];
        $userId = $request->user()->id;

        // 0. Avisos dirigidos a ESTA persona (o a todos, con user_id nulo).
        //    Es la única fuente que sabe a quién le importa cada cosa.
        foreach ($this->misAvisos($userId)->where('created_at', '>', $cutoff)->get() as $n) {
            $eventos[] = $this->comoEvento($n);
        }

        // 1. Pedidos B2B nuevos
        $b2b = PedidoCliente::where('created_at', '>', $cutoff)
            ->where('estado', 'enviado')
            ->with('contacto:id,razon_social,nombre_completo')
            ->orderByDesc('id')->limit(10)->get();
        foreach ($b2b as $p) {
            $eventos[] = [
                'id' => 'b2b-' . $p->id,
                'tipo' => 'pedido_b2b',
                'titulo' => 'Nuevo pedido B2B',
                'body' => ($p->contacto?->razon_social ?: $p->contacto?->nombre_completo) . ' · $' . number_format($p->total, 0, ',', '.'),
                'url' => '/app/pedidos-b2b/' . $p->id,
                'at' => $p->created_at->toIso8601String(),
                'icon' => '📦', 'color' => 'blue',
            ];
        }

        // 2. Pagos recibidos
        //    Sin los cobros automáticos de Dropi (uno por pedido, cientos al
        //    día): taparían todo lo demás. Esos se ven en la wallet.
        $pagos = PagoVenta::where('created_at', '>', $cutoff)
            ->where(fn ($q) => $q->whereNull('medio_pago')->orWhere('medio_pago', '!=', 'wallet_dropi'))
            ->orderByDesc('id')->limit(10)->get();
        foreach ($pagos as $pg) {
            $eventos[] = [
                'id' => 'pago-' . $pg->id,
                'tipo' => 'pago',
                'titulo' => '💰 Pago recibido',
                'body' => '$' . number_format($pg->monto_aplicado, 0, ',', '.') . ' · ' . ($pg->medio_pago ?? ''),
                'url' => '/app/pagos',
                'at' => $pg->created_at->toIso8601String(),
                'icon' => '💰', 'color' => 'emerald',
            ];
        }

        // 3. Garantías nuevas
        $gar = GarantiaTicket::where('created_at', '>', $cutoff)
            ->orderByDesc('id')->limit(5)->get();
        foreach ($gar as $g) {
            $eventos[] = [
                'id' => 'gar-' . $g->id,
                'tipo' => 'garantia',
                'titulo' => 'Nueva garantía',
                'body' => $g->numero . ' · ' . $g->cliente_nombre,
                'url' => '/app/garantias/' . $g->id,
                'at' => $g->created_at->toIso8601String(),
                'icon' => '🛡', 'color' => 'amber',
            ];
        }

        // 4. Facturas emitidas
        //    Tampoco las de Dropi: una por pedido despachado.
        $facts = FacturaVenta::where('created_at', '>', $cutoff)
            ->where(fn ($q) => $q->whereNull('origen_type')
                ->orWhere('origen_type', '!=', \App\Modules\Dropi\Models\DropiPedido::class))
            ->orderByDesc('id')->limit(5)->get();
        foreach ($facts as $f) {
            $eventos[] = [
                'id' => 'fact-' . $f->id,
                'tipo' => 'factura',
                'titulo' => 'Factura emitida',
                'body' => $f->numero . ' · $' . number_format($f->total, 0, ',', '.'),
                'url' => '/app/facturas/' . $f->id,
                'at' => $f->created_at->toIso8601String(),
                'icon' => '📄', 'color' => 'brand',
            ];
        }

        // Ordenar por fecha desc
        usort($eventos, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return response()->json([
            'eventos' => $eventos,
            // Lo que estaba esperando desde antes de abrir la pantalla. Sin
            // esto, quien llega en la mañana no se entera de lo de anoche.
            'pendientes' => $this->misAvisos($userId)->limit(30)->get()
                ->map(fn ($n) => $this->comoEvento($n))->values(),
            'ahora' => now()->toIso8601String(),
        ]);
    }

    /**
     * Marca como leídos los avisos de quien abre la campana.
     *
     * SÓLO los suyos. Un aviso general (`user_id` nulo) es UNA fila compartida
     * con UNA sola marca de leído: si se marcara, el primero que abriera la
     * campana lo borraría para todos los demás. Se vio en pantalla —Elena abrió
     * y el aviso general quedó leído también para Aracely—, y es peor que el
     * problema original.
     */
    public function marcarLeidas(Request $request): JsonResponse
    {
        $n = NotificacionErp::whereNull('leida_at')
            ->where('user_id', $request->user()->id)
            ->update(['leida_at' => now()]);

        return response()->json(['leidas' => $n]);
    }

    /**
     * Lo que le espera sin leer a esta persona.
     *
     * Los generales se limitan a la última semana justamente porque no se
     * marcan: sin eso, un aviso de hace meses encendería la campana para
     * siempre. Un aviso general es una novedad, no una tarea pendiente.
     *
     * @return \Illuminate\Database\Eloquent\Builder<NotificacionErp>
     */
    private function misAvisos(int $userId)
    {
        return NotificacionErp::whereNull('leida_at')
            ->where(fn ($q) => $q
                ->where('user_id', $userId)
                ->orWhere(fn ($g) => $g->whereNull('user_id')
                    ->where('created_at', '>=', now()->subWeek())))
            ->orderByDesc('id');
    }

    private function comoEvento(NotificacionErp $n): array
    {
        return [
            'id' => 'erp-'.$n->id,
            'tipo' => $n->tipo,
            'titulo' => $n->titulo,
            'body' => $n->mensaje,
            'url' => $n->url,
            'at' => $n->created_at->toIso8601String(),
            'icon' => '🔔',
            // La tabla guarda nombres de Filament (warning, danger, success);
            // la campana pinta con su propia paleta.
            'color' => [
                'warning' => 'amber', 'danger' => 'red',
                'success' => 'emerald', 'info' => 'blue',
            ][$n->color] ?? 'brand',
        ];
    }
}
