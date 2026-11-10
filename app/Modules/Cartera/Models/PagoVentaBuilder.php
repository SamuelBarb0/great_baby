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
    /*
     * Mientras se borra un pago por el modelo, NO se vuelve a entrar acá.
     *
     * `$pago->forceDelete()` termina llamando a `newModelQuery()->forceDelete()`
     * —o sea, a este mismo método— y eso se muerde la cola: cargaba la fila,
     * llamaba al modelo, el modelo volvía a entrar, cargaba la fila otra vez…
     * hasta agotar la memoria de PHP. Reventaba de verdad, y con un llamador
     * real: borrar físicamente una factura hace `forceDelete()` sobre cada uno
     * de sus pagos.
     *
     * El `delete()` normal no se notaba porque el borrado suave es un UPDATE y
     * no vuelve a pasar por acá, pero la trampa era la misma y estaba esperando.
     */
    private bool $borrandoDeAUno = false;

    public function delete()
    {
        if ($this->borrandoDeAUno) {
            return parent::delete();
        }

        return $this->borrarDeAUno(fn (PagoVenta $pago) => $pago->delete());
    }

    public function forceDelete()
    {
        if ($this->borrandoDeAUno) {
            return parent::forceDelete();
        }

        return $this->borrarDeAUno(fn (PagoVenta $pago) => $pago->forceDelete());
    }

    /** Devuelve cuántas filas se borraron, como lo haría el borrado masivo. */
    protected function borrarDeAUno(callable $borrar): int
    {
        $pagos = $this->get();

        foreach ($pagos as $pago) {
            /*
             * La bandera viaja en el builder que el MODELO va a construir, no
             * en éste: `newModelQuery()` crea uno nuevo cada vez. Por eso se
             * marca en el modelo y el builder la lee al nacer.
             */
            $pago->borrandoDeAUno = true;
            $borrar($pago);
            $pago->borrandoDeAUno = false;
        }

        return $pagos->count();
    }

    /** Lo marca el modelo para cortar la recursión. */
    public function marcarBorradoDirecto(): static
    {
        $this->borrandoDeAUno = true;

        return $this;
    }
}
