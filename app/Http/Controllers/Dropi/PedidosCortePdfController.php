<?php

namespace App\Http\Controllers\Dropi;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiPedidoItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los pedidos de un corte en PDF, con el corte abierto o cerrado (bodega,
 * 23-nov-2026: «que entre al corte y pueda descargar el PDF de los pedidos»).
 * Todos los del corte, o uno solo con `?pedido=ID`.
 *
 * No es el Manifiesto: ese es la foto oficial del cierre (con hash) y sólo
 * existe al cerrar. Este es la lista de trabajo en vivo.
 *
 * Lleva nombres de clientes, así que pide Cortes Dropi y además Pedidos Dropi
 * (quien ve la lista de pedidos ya ve esos datos).
 */
class PedidosCortePdfController extends Controller
{
    public static function puedeDescargar(): bool
    {
        $u = auth()->user();

        return Permisos::puede($u, 'dropi_cortes') && Permisos::puede($u, 'dropi_pedidos');
    }

    public function descargar(Request $request, DropiCorte $corte): Response
    {
        abort_unless(self::puedeDescargar(), 403);

        $query = $corte->pedidos()
            ->with(['items.variante.producto', 'items.producto'])
            ->orderBy('guia');

        $pedidoId = $request->integer('pedido');
        if ($pedidoId) {
            // Sólo un pedido de ESTE corte: otro id da 404, no un PDF vacío.
            $query->whereKey($pedidoId);
        }

        $pedidos = $query->get();
        abort_if($pedidoId && $pedidos->isEmpty(), 404);

        $filas = $pedidos->map(fn (DropiPedido $p) => [
            'pedido' => $p,
            'productos' => $p->items->map(fn (DropiPedidoItem $i) => [
                'nombre' => self::nombreProducto($i),
                // Nuestra referencia; sin vínculo, la de Dropi (26-nov-2026).
                'sku' => $i->referenciaErp() ?? $i->sku_dropi,
                'cantidad' => (int) $i->cantidad,
            ])->all(),
        ])->all();

        $archivo = $pedidoId
            ? 'pedido-' . $pedidos->first()->guia . '.pdf'
            : 'pedidos-corte-' . $corte->numero . '-' . $corte->fecha->format('Y-m-d') . '.pdf';

        return Pdf::loadView('dropi.pdf.pedidos-corte', [
            'corte' => $corte,
            'filas' => $filas,
            'soloUno' => (bool) $pedidoId,
            'unidades' => (int) $pedidos->sum(fn (DropiPedido $p) => $p->items->sum('cantidad')),
            'monto' => (float) $pedidos->sum('monto_esperado_proveedor'),
            'generado' => now(),
        ])
            ->setPaper('letter')
            ->stream($archivo);
    }

    /** «Coche · Azul · M», el agregado por su nombre, o el SKU de Dropi si aún no está vinculado. */
    protected static function nombreProducto(DropiPedidoItem $i): string
    {
        if ($i->variante) {
            $partes = array_filter([
                $i->variante->producto?->nombre,
                $i->variante->color_nombre,
                $i->variante->diseno_nombre,
                $i->variante->talla,
            ]);

            return implode(' · ', $partes) ?: (string) $i->sku_dropi;
        }

        return $i->producto?->nombre ?? ($i->sku_dropi ?: 'Sin producto vinculado');
    }
}
