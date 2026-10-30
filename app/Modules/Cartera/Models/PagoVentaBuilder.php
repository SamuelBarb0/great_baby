<?php

namespace App\Modules\Cartera\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Borrar pagos en masa pasa por el modelo, uno por uno.
 *
 * `PagoVenta::where(...)->delete()` es un UPDATE masivo: no instancia los
 * modelos, así que no dispara `deleted` y `PagoVentaObserver` nunca corre.
 * El pago queda con `deleted_at` y su asiento sigue vivo en el libro.
 *
 * Pasó en producción el 8 de septiembre de 2026: seis pagos de $1.200.000
 * borrados sobre la misma factura dejaron doce líneas huérfanas —$7.200.000
 * de plata que nunca entró— y la cuenta 1305 Clientes en negativo, o sea un
 * balance que decía que la empresa le debía dinero a sus clientes.
 *
 * El libro contable no puede depender de por cuál puerta se borró la fila, y
 * una regla que hay que recordar es una regla que algún día se olvida. Acá la
 * puerta rápida deja de existir: ambos borrados cargan los modelos y los
 * borran de a uno, con sus eventos. Son más consultas, pero borrar pagos es
 * raro y en lotes chicos; descuadrar el libro no es raro, es definitivo.
 */
class PagoVentaBuilder extends Builder
{
    public function delete()
    {
        return $this->borrarDeAUno(fn (PagoVenta $pago) => $pago->delete());
    }

    public function forceDelete()
    {
        return $this->borrarDeAUno(fn (PagoVenta $pago) => $pago->forceDelete());
    }

    /** Devuelve cuántas filas se borraron, como lo haría el borrado masivo. */
    protected function borrarDeAUno(callable $borrar): int
    {
        $pagos = $this->get();

        foreach ($pagos as $pago) {
            $borrar($pago);
        }

        return $pagos->count();
    }
}
