<?php

namespace App\Modules\Inventario\Actions;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Services\StockService;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Support\Facades\DB;

/**
 * Aparta en bodega lo que lleva un pedido aprobado.
 *
 * La maquinaria de reservas estaba completa desde hace meses —`ReservarStock`,
 * `LiberarReserva`, `LiberarReservasExpiradas`, y `StockService` calculando
 * disponible = físico − reservado por ubicación— pero **nadie la llamaba desde
 * el flujo de ventas**. El 2026-11-06 el único sitio que invocaba
 * `ReservarStock` en todo el código era un comando de humo.
 *
 * O sea: dos vendedores podían prometer la misma caja, y el segundo se
 * enteraba el día del alistamiento. Eso es lo que el contrato llama «reserva
 * de inventario al confirmar» y lo que hace que el stock reservado deje de
 * ofrecerse.
 *
 * Cuándo se aparta y cuándo se suelta:
 *
 *   · Se aparta al APROBAR, no al crear. Un pedido retenido por cartera no
 *     debe quitarle mercancía a nadie: todavía no se sabe si va a salir.
 *   · Se suelta al FACTURAR, porque ahí el despacho descuenta el stock de
 *     verdad y dejar la reserva lo contaría dos veces.
 *   · Se suelta al RECHAZAR.
 *   · Y si nada de eso pasa, expira sola: `LiberarReservasExpiradas` ya corre
 *     por el programador.
 *
 * No bloquea. Si no alcanza el stock, el pedido se aprueba igual y la acción
 * devuelve qué faltó, para que la pantalla lo muestre. Quién decide si se
 * despacha corto es logística, no esta clase.
 */
class ReservarPedido
{
    /** Cuántos días vive una reserva sin que nadie la facture. */
    private const DIAS_DE_VIDA = 15;

    public function __construct(private StockService $stock) {}

    /**
     * @return array{reservadas:int,faltantes:array<int,array{sku:string,pedido:int,disponible:int}>}
     */
    public function handle(PedidoCliente $pedido): array
    {
        $ubicacion = (int) $pedido->ubicacion_origen_id;
        if ($ubicacion <= 0) {
            // Sin bodega de origen no hay dónde apartar. No es un error del
            // pedido: hay pedidos viejos sin ubicación y no vale tumbarlos.
            return ['reservadas' => 0, 'faltantes' => []];
        }

        return DB::transaction(function () use ($pedido, $ubicacion) {
            // Si ya tenía reservas —una reaprobación— se sueltan primero, o
            // quedarían dos reservas vivas por el mismo pedido.
            app(LiberarReserva::class)->handle($pedido);

            $reservadas = 0;
            $faltantes = [];
            $expira = now()->addDays(self::DIAS_DE_VIDA);

            foreach ($pedido->items()->get() as $item) {
                $sujeto = $this->sujetoDe($item);
                if (! $sujeto) {
                    continue;
                }

                $cantidad = (int) $item->cantidad;
                if ($cantidad <= 0) {
                    continue;
                }

                $disponible = $this->stock->saldoDisponibleSujeto($sujeto, $ubicacion);
                if ($disponible < $cantidad) {
                    $faltantes[] = [
                        'sku' => (string) ($item->sku_snapshot ?: $sujeto->referencia ?? '—'),
                        'pedido' => $cantidad,
                        'disponible' => max(0, $disponible),
                    ];
                }

                // Se aparta lo que haya, aunque no alcance: es mejor tener
                // apartado lo poco que hay que no tener nada apartado.
                $aApartar = min($cantidad, max(0, $disponible));
                if ($aApartar > 0) {
                    app(ReservarStock::class)->handleSujeto(
                        $sujeto, $ubicacion, $aApartar, $pedido, $expira
                    );
                    $reservadas++;
                }
            }

            return ['reservadas' => $reservadas, 'faltantes' => $faltantes];
        });
    }

    /** Suelta lo apartado por un pedido. Devuelve cuántas reservas soltó. */
    public function liberar(PedidoCliente $pedido): int
    {
        return app(LiberarReserva::class)->handle($pedido);
    }

    /**
     * Sobre qué se reserva: la variante si la línea la trae, si no el producto.
     *
     * Es el mismo desglose dual del resto del inventario: granular por
     * variante, agregado por producto.
     */
    private function sujetoDe($item): Producto|ProductoVariante|null
    {
        if ($item->variante_id) {
            return ProductoVariante::find($item->variante_id);
        }

        return $item->producto_id ? Producto::find($item->producto_id) : null;
    }
}
