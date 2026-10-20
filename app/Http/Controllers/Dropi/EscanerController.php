<?php

namespace App\Http\Controllers\Dropi;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Actions\FacturarPedidoDropi;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDespachado;
use App\Modules\Dropi\Jobs\NotificarClienteDespachoJob;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Support\Trazabilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Throwable;

/**
 * Escáner de guías: despacho y, desde la 4.6, la factura del pedido.
 *
 * Escanear la guía y despachar ya factura (FacturarAlDespachar, tarjeta 9);
 * el escáner ahora dice qué factura salió o por qué no, y deja a Aracely
 * facturar en el momento un pedido despachado que quedó sin factura (lo
 * trajo el sync antes de prender la facturación, o falló al crearse).
 */
class EscanerController extends Controller implements HasMiddleware
{
    /**
     * Antes estaba en el constructor con `$this->middleware()`, que el
     * Controller base de Laravel 12 ya no tiene: TODA petición al escáner
     * (analizar y despachar) respondía 500. Encontrado el 2026-10-20 al probar
     * la 4.6.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                $u = auth()->user();
                abort_unless($u && ($u->esAracely() || $u->hasAnyRole(['Alistador', 'Gerente'])), 403,
                    'Solo Aracely, Alistadores y Gerencia pueden operar el escáner.');

                return $next($request);
            }),
        ];
    }

    public function analizar(Request $request): JsonResponse
    {
        $guia = strtoupper(trim($request->input('guia', '')));
        if ($guia === '') {
            return response()->json(['accion' => 'no_encontrada', 'titulo' => 'Guía vacía', 'color' => 'gray'], 200);
        }

        $pedido = DropiPedido::where('guia', $guia)->first();

        if (! $pedido) {
            return response()->json([
                'accion' => 'no_encontrada',
                'guia' => $guia,
                'titulo' => '❓ Guía no encontrada',
                'detalle' => 'No hay pedido registrado con esta guía.',
                'color' => 'gray',
            ]);
        }

        // S9 · no revelar monto en la respuesta del escáner.
        $ctx = [
            'cliente' => $pedido->cliente_nombre,
            'ciudad' => $pedido->cliente_ciudad,
            'estado' => $pedido->estado->label(),
        ];

        $recienDespachado = $pedido->despachado_at && $pedido->despachado_at->isSameDay(now());

        if (in_array($pedido->estado, [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado], true)) {
            $factura = $this->factura($pedido);
            if ($recienDespachado) {
                return response()->json([
                    'accion' => 'doble_escaneo', 'pedido_id' => $pedido->id, 'guia' => $guia,
                    'titulo' => '⚠️ Doble escaneo bloqueado',
                    'detalle' => 'Esta guía ya se escaneó como despachada hoy a las ' . $pedido->despachado_at->format('H:i') . '.',
                    'color' => 'warning', 'context' => $ctx, 'factura' => $factura,
                ]);
            }
            return response()->json([
                'accion' => 'proponer_devolucion', 'pedido_id' => $pedido->id, 'guia' => $guia,
                'titulo' => '↩️ ¿Es una devolución?',
                'detalle' => 'Esta guía salió el ' . $pedido->despachado_at?->format('Y-m-d') . '. Confirma si volvió a bodega.',
                'color' => 'info', 'context' => $ctx, 'factura' => $factura,
            ]);
        }

        return response()->json([
            'accion' => 'despachar', 'pedido_id' => $pedido->id, 'guia' => $guia,
            'titulo' => '✅ Listo para despachar',
            'detalle' => "Cliente: {$ctx['cliente']} · {$ctx['ciudad']}",
            'color' => 'success', 'context' => $ctx,
        ]);
    }

    public function despachar(Request $request): JsonResponse
    {
        // N4 · guía REQUIRED — antes era nullable y hacía el guard opcional.
        // Ahora exigimos siempre la guía y validamos que coincida con el pedido.
        $data = $request->validate([
            'pedido_id' => ['required', 'integer', 'exists:dropi_pedidos,id'],
            'guia' => ['required', 'string', 'max:100'],
        ] + Trazabilidad::reglas());

        $pedido = DropiPedido::find($data['pedido_id']);
        if (! $pedido) {
            return response()->json(['ok' => false, 'mensaje' => 'Pedido no encontrado.'], 404);
        }

        $incoming = strtoupper(trim($data['guia']));
        if ($incoming !== $pedido->guia) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'La guía escaneada no coincide con el pedido.',
            ], 422);
        }

        // Sólo Empacado puede pasar a Despachado (regla de negocio del flujo real).
        if (! in_array($pedido->estado, [EstadoPedidoDropi::Empacado], true)) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'El pedido debe estar Empacado antes de despacharlo.',
            ], 422);
        }

        try {
            Trazabilidad::exigir($data['gps'] ?? null);
            // Al pasar a Despachado sale la remisión y la factura (FacturarAlDespachar).
            $pedido->transicionar(
                EstadoPedidoDropi::Despachado,
                'manual',
                auth()->id(),
                ['origen' => 'escaner_camara'] + Trazabilidad::payload($data['gps'] ?? null),
                ['despachado_at' => now()],
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        event(new PedidoDespachado($pedido->fresh()));
        NotificarClienteDespachoJob::dispatch($pedido->id);

        return response()->json(['ok' => true, 'guia' => $pedido->guia, 'factura' => $this->factura($pedido->fresh())]);
    }

    /**
     * 4.6 · Facturar ya un pedido despachado que quedó sin factura. Sólo
     * Aracely/Gerencia: es un documento ante la DIAN.
     */
    public function facturar(Request $request): JsonResponse
    {
        abort_unless($request->user()->esAracely(), 403, 'Solo Aracely o Gerencia facturan desde el escáner.');

        $data = $request->validate([
            'pedido_id' => ['required', 'integer', 'exists:dropi_pedidos,id'],
            'guia' => ['required', 'string', 'max:100'],
        ]);
        $pedido = DropiPedido::findOrFail($data['pedido_id']);
        if (strtoupper(trim($data['guia'])) !== $pedido->guia) {
            return response()->json(['ok' => false, 'mensaje' => 'La guía escaneada no coincide con el pedido.'], 422);
        }
        if (! in_array($pedido->estado, [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado], true)) {
            return response()->json(['ok' => false, 'mensaje' => 'Solo se factura un pedido que ya salió de bodega.'], 422);
        }

        $accion = new FacturarPedidoDropi();
        try {
            $factura = $accion->handle($pedido, $request->user()->id, manual: true);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => 'No se pudo facturar: '.$e->getMessage()], 422);
        }
        if (! $factura) {
            return response()->json(['ok' => false, 'mensaje' => 'No se facturó: '.$accion->motivo], 422);
        }

        return response()->json(['ok' => true, 'guia' => $pedido->guia, 'factura' => $this->factura($pedido->fresh())]);
    }

    /**
     * Lo que el escáner muestra de la factura del pedido.
     *
     * @return array{numero:?string, electronica:bool, estado:string, motivo:?string, puede_facturar:bool}
     */
    private function factura(DropiPedido $pedido): array
    {
        $f = $pedido->factura_venta_id ? FacturaVenta::find($pedido->factura_venta_id) : null;

        return [
            'numero' => $f?->numero,
            'electronica' => (bool) $f?->es_electronica,
            'estado' => match (true) {
                ! $f => 'sin_factura',
                ! empty($f->cufe) => 'timbrada',
                (bool) $f->es_electronica => 'enviando_siigo',
                default => 'interna',
            },
            'motivo' => $f ? null : FacturarPedidoDropi::motivoSinFactura($pedido),
            'puede_facturar' => ! $f && (bool) auth()->user()?->esAracely(),
        ];
    }
}
