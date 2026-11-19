<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * §12 Diseño Dropi — Registrar devolución de pedido completo.
 *  - El alistador escanea la guía o la busca.
 *  - Sistema trae contexto: productos, cliente, remisión/factura.
 *  - Alistador decide destino de inventario (§12).
 *  - Pedido queda marcado como "devuelto" y "no cobrar" (elimina falso pendiente en conciliación §15).
 *  - Si tiene factura ARI, se marca para nota crédito (posible gracias a la remisión 1:1 §21).
 */
class RegistrarDevolucion
{
    use AsAction;

    /**
     * @return array{devolucion:DropiDevolucion, movimientos:int}
     */
    public function handle(
        string $guia,
        DestinoDevolucion $destino,
        int $userId,
        ?string $notas = null,
    ): array {
        // Re-audit DR-ε (FUNC-C5) · normalizar guía (upper+trim). Pistolas con
        //   mayúsculas/espacios/lowercase fallaban el match. Sync guarda UPPER.
        $guia = strtoupper(trim($guia));
        if ($guia === '') {
            throw new RuntimeException('Guía vacía.');
        }

        return DB::transaction(function () use ($guia, $destino, $userId, $notas) {
            $pedido = DropiPedido::where('guia', $guia)->firstOrFail();

            if ($pedido->devolucion) {
                throw new RuntimeException("La guía {$guia} ya tiene una devolución registrada.");
            }

            // NO pre-setear genero_nota_credito — el observer EmitirNotaCreditoDropi
            // lo activa recién cuando confirma dispatch a SIIGO. Antes mentíamos.
            $devolucion = DropiDevolucion::create([
                'pedido_id' => $pedido->id,
                'recibido_at' => now(),
                'destino_inventario' => $destino->value,
                'decision_por' => $userId,
                'genero_nota_credito' => false,
                'notas' => $notas,
            ]);

            // Reingresar inventario si aplica §12.
            // Re-audit DR-β UX (UX-C4) · NoLlegoFisicamente y BajaTotal NO
            //   generan movimiento de kardex. `reingresaInventario()`
            //   centraliza la decisión.
            $movimientos = $destino->reingresaInventario()
                ? $this->reingresarInventario($pedido, $devolucion, $destino, $userId)
                : 0;

            // Cambio de estado del pedido a través de la state machine central.
            // Fuente 'sistema': esta Action ES la autoridad para pasar cualquier
            // pedido (Despachado, Entregado, Empacado) a Devuelto. El registro
            // manual del alistador dispara la Action; la Action es sistema.
            $pedido->transicionar(
                EstadoPedidoDropi::Devuelto,
                'sistema',
                $userId,
                ['motivo' => 'devolucion_recibida', 'destino' => $destino->value, 'user_id' => $userId],
                ['devuelto_at' => now()],
            );

            return ['devolucion' => $devolucion, 'movimientos' => $movimientos];
        });
    }

    protected function reingresarInventario(DropiPedido $pedido, DropiDevolucion $devolucion, DestinoDevolucion $destino, int $userId): int
    {
        $ubicacion = InventarioUbicacion::query()
            ->where('categoria', $destino->categoriaUbicacion()->value)
            ->where('activa', true)
            ->first();

        if (! $ubicacion) {
            return 0;
        }

        $movimientos = 0;
        foreach ($pedido->items as $item) {
            $cantidad = (int) $item->cantidad;
            // Granular (por variante) o agregado (solo producto): el empaque
            // descuenta los dos, así que la devolución tiene que reingresar los
            // dos. Antes saltaba los agregados y su stock no volvía.
            if ((! $item->variante_id && ! $item->producto_id) || $cantidad <= 0) {
                continue;
            }

            // Idempotente por (devolución, variante | producto agregado). El
            // movimiento apunta al documento de devolución, no al pedido: desde
            // el kardex se llega a la devolución y de ahí al pedido.
            $clave = [
                'referencia_tipo' => DropiDevolucion::class,
                'referencia_id' => $devolucion->id,
            ] + ($item->variante_id
                ? ['variante_id' => $item->variante_id]
                : ['variante_id' => null, 'producto_id' => $item->producto_id]);

            $creado = InventarioMovimiento::firstOrCreate($clave, [
                'producto_id' => $item->producto_id,
                'ubicacion_id' => $ubicacion->id,
                'tipo' => 'ingreso',
                'cantidad' => $cantidad,
                'user_id' => $userId,
                'notas' => mb_substr("Devolución guía {$pedido->guia} → {$destino->label()}"
                    .($devolucion->notas ? " · {$devolucion->notas}" : ''), 0, 255),
            ]);
            if ($creado->wasRecentlyCreated) $movimientos++;
        }

        return $movimientos;
    }
}
