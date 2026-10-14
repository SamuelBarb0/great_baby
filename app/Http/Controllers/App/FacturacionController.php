<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rol Facturador · bandeja de pedidos listos para facturar en SIIGO.
 *
 * Flujo del negocio:
 *   Vendedor levanta → Gerencia aprueba → Alistador alista → Despachador
 *   despacha → [FACTURADOR] revisa + emite factura en SIIGO.
 *
 * El Facturador NO puede editar el pedido (si hay error, lo devuelve al
 * vendedor por nota interna). Solo decide:
 *   • Factura o no
 *   • send_dian (true/false) · si tilda false, la factura queda en SIIGO
 *     sin radicar en DIAN hasta que alguien decida enviarla manualmente.
 *   • send_mail (true/false) · si tilda true, SIIGO manda la factura al
 *     correo del cliente al crearla.
 *
 * Reusa `PedidosB2BController::facturar` para no duplicar la lógica de
 * cartera/crédito/semáforo. Lo que agrega es la DECISIÓN manual + la
 * trazabilidad de quién la emitió (`facturado_por_user_id`).
 */
class FacturacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            abort_unless($u && ($u->esAracely() || $u->esFacturador()), 403,
                'Esta pantalla es del equipo de facturación.');
            return $next($r);
        })];
    }

    public function bandeja(Request $r): Response
    {
        /*
         * Pedidos aprobados sin factura todavia.
         *
         * Antes exigia ademas `alistado_fin_at`: que el pedido ya estuviera
         * alistado. Eso contradice el flujo real y lo dejaba en punto muerto:
         * la cola de alistamiento sólo muestra pedidos con
         * `estado = 'facturado'` y sus acciones cortan con «Sólo se alistan
         * pedidos facturados». Para facturar habia que alistar, y para alistar
         * habia que facturar. Un pedido aprobado no llegaba a ninguna de las
         * dos pantallas.
         *
         * El orden correcto lo dice el propio despacho: «la factura ya está
         * impresa y acompaña físicamente la mercancía». Primero se factura,
         * despues bodega alista con esa factura en la mano, y al final se
         * despacha. Encontrado el 2026-10-13 llevando un pedido de punta a
         * punta por la interfaz.
         *
         * El filtro de novedades se mantiene: un pedido alistado con problema
         * sin resolver no se factura.
         */
        $pendientes = PedidoCliente::query()
            ->where('estado', 'aprobado')
            ->whereNull('factura_id')
            ->where(function ($q) {
                $q->whereNull('alistado_con_novedad')
                  ->orWhere('alistado_con_novedad', false)
                  ->orWhereNotNull('novedad_resuelta_at');
            })
            ->with([
                'contacto:id,nombre_completo,razon_social,ciudad,email,telefono,numero_documento',
                'vendedor:id,name',
                'alistador:id,name',
                'ubicacionOrigen:id,codigo,nombre',
                'items:id,pedido_id',
            ])
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
                'cliente_doc' => $p->contacto?->numero_documento,
                'ciudad' => $p->contacto?->ciudad,
                'total' => (float) $p->total,
                'items_count' => $p->items->count(),
                'vendedor' => $p->vendedor?->name,
                'alistador' => $p->alistador?->name,
                'ubicacion' => $p->ubicacionOrigen?->codigo,
                'alistado_fin_at' => $p->alistado_fin_at?->format('Y-m-d H:i'),
                'horas_desde_alistado' => $p->alistado_fin_at
                    ? (int) now()->diffInHours($p->alistado_fin_at)
                    : null,
                'tiene_email_cliente' => !! $p->contacto?->email,
            ])
            ->values();

        // KPIs rápidos para el header.
        $kpis = [
            'pendientes' => $pendientes->count(),
            'monto_pendiente' => $pendientes->sum('total'),
            'facturadas_hoy' => \App\Modules\Cartera\Models\FacturaVenta::query()
                ->whereDate('created_at', today('America/Bogota'))
                ->whereNotNull('facturado_por_user_id')
                ->count(),
        ];

        return Inertia::render('Facturacion/Bandeja', [
            'pendientes' => $pendientes,
            'kpis' => $kpis,
        ]);
    }

    public function preview(Request $r, PedidoCliente $pedido): Response
    {
        abort_unless($pedido->estado === 'aprobado', 422,
            'Este pedido no está en estado aprobado.');
        abort_if($pedido->factura_id, 422,
            'Este pedido ya tiene factura · ID ' . $pedido->factura_id);

        $pedido->load([
            'contacto', 'vendedor:id,name', 'alistador:id,name',
            'ubicacionOrigen', 'items.variante.producto:id,referencia,nombre,impuesto_id',
            'items.variante.producto.impuesto:id,porcentaje',
        ]);

        $items = $pedido->items->map(fn ($it) => [
            'descripcion' => $it->descripcion_snapshot,
            'sku' => $it->sku_snapshot,
            'cantidad' => (int) $it->cantidad,
            'precio' => (float) $it->precio_unitario,
            'iva_pct' => (float) $it->iva_porcentaje,
            'subtotal' => (float) $it->subtotal,
            'iva_valor' => (float) $it->iva_valor,
            'total' => (float) $it->total,
        ]);

        $esCredito = \App\Modules\Cartera\Models\CondicionCredito::query()
            ->where('contacto_id', $pedido->contacto_id)
            ->where('activa', true)->exists();

        return Inertia::render('Facturacion/Preview', [
            'pedido' => [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'subtotal' => (float) $pedido->subtotal,
                'iva' => (float) $pedido->iva,
                'total' => (float) $pedido->total,
                'notas_cliente' => $pedido->notas_cliente,
                'ubicacion' => $pedido->ubicacionOrigen?->nombre,
                'ubicacion_codigo' => $pedido->ubicacionOrigen?->codigo,
                'tipo' => $esCredito ? 'credito' : 'contado',
                'alistado_at' => $pedido->alistado_fin_at?->format('Y-m-d H:i'),
                'vendedor' => $pedido->vendedor?->name,
                'alistador' => $pedido->alistador?->name,
            ],
            'contacto' => $pedido->contacto ? [
                'nombre' => $pedido->contacto->razon_social ?: $pedido->contacto->nombre_completo,
                'nit' => $pedido->contacto->numero_documento,
                'email' => $pedido->contacto->email,
                'telefono' => $pedido->contacto->telefono,
                'ciudad' => $pedido->contacto->ciudad,
                'direccion' => $pedido->contacto->direccion,
            ] : null,
            'items' => $items,
            'advertencias' => $this->advertencias($pedido),
        ]);
    }

    public function facturar(Request $r, PedidoCliente $pedido): RedirectResponse
    {
        $data = $r->validate([
            'send_dian' => ['boolean'],
            'send_mail' => ['boolean'],
        ]);

        // Guardo decisión del Facturador para que el controller real la lea.
        session([
            'facturador.send_dian' => (bool) ($data['send_dian'] ?? true),
            'facturador.send_mail' => (bool) ($data['send_mail'] ?? false),
            'facturador.user_id' => $r->user()->id,
        ]);

        // Delego al controller existente (reusa toda la lógica de cartera).
        $delegado = app(\App\Http\Controllers\App\PedidosB2BController::class);
        return $delegado->facturar($pedido->id);
    }

    /**
     * Lista de alertas visibles al Facturador antes de emitir.
     *   Son pistas, no bloquean · él decide si pasa o no.
     */
    private function advertencias(PedidoCliente $p): array
    {
        $w = [];
        if (! $p->contacto?->email) {
            $w[] = ['tipo' => 'warning',
                'msg' => 'El cliente NO tiene email cargado · el envío por mail no podrá activarse.'];
        }
        if (! $p->contacto?->numero_documento) {
            $w[] = ['tipo' => 'danger',
                'msg' => 'El cliente NO tiene NIT · SIIGO rechazará la factura.'];
        }
        if (! $p->ubicacion_origen_id) {
            $w[] = ['tipo' => 'warning',
                'msg' => 'Pedido sin ubicación de origen · se usará la resolución SIIGO default.'];
        }
        if ($p->items->isEmpty()) {
            $w[] = ['tipo' => 'danger',
                'msg' => 'Pedido sin ítems · no se puede facturar.'];
        }
        if ((float) $p->total <= 0) {
            $w[] = ['tipo' => 'danger',
                'msg' => 'Pedido con total $0 · no se puede facturar.'];
        }
        return $w;
    }
}
