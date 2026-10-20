<?php

namespace App\Http\Controllers\Dropi;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDespachado;
use App\Modules\Dropi\Jobs\NotificarClienteDespachoJob;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Support\Trazabilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EscanerController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $u = auth()->user();
            abort_unless($u && ($u->esAracely() || $u->hasAnyRole(['Alistador', 'Gerente'])), 403,
                'Solo Aracely, Alistadores y Gerencia pueden operar el escáner.');
            return $next($request);
        });
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
            if ($recienDespachado) {
                return response()->json([
                    'accion' => 'doble_escaneo', 'pedido_id' => $pedido->id, 'guia' => $guia,
                    'titulo' => '⚠️ Doble escaneo bloqueado',
                    'detalle' => 'Esta guía ya se escaneó como despachada hoy a las ' . $pedido->despachado_at->format('H:i') . '.',
                    'color' => 'warning', 'context' => $ctx,
                ]);
            }
            return response()->json([
                'accion' => 'proponer_devolucion', 'pedido_id' => $pedido->id, 'guia' => $guia,
                'titulo' => '↩️ ¿Es una devolución?',
                'detalle' => 'Esta guía salió el ' . $pedido->despachado_at?->format('Y-m-d') . '. Confirma si volvió a bodega.',
                'color' => 'info', 'context' => $ctx,
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

        return response()->json(['ok' => true, 'guia' => $pedido->guia]);
    }
}
