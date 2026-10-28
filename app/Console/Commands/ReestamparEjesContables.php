<?php

namespace App\Console\Commands;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara los asientos cuyo destino fiscal no coincide con su documento.
 *
 * Dos cosas dejaron plata mal clasificada en el libro:
 *
 *   1) Cambiar el destino de una factura por un camino que no rehace el
 *      asiento. Medido el 2026-10-26: tres facturas marcadas «interno» con sus
 *      seis movimientos en «fiscal», $1.324.500 contados como declarados sin
 *      serlo. Les quedó `motivo_destino` en NULL, y el método oficial lo
 *      exige — o sea que hay otro camino que escribe la columna.
 *
 *   2) El costo de venta del despacho, que hasta hoy caía siempre en «fiscal»
 *      porque su origen es el pedido y los pedidos no tienen los ejes. Eso le
 *      daba al libro interno un margen del 100 %.
 *
 * Lo que hace es simple y por eso es seguro: **no inventa nada**. Para cada
 * movimiento mira qué dice su documento y, si difieren, lo corrige. Los montos
 * no se tocan: cambiar de columna no cambia ni un peso del asiento, así que la
 * partida doble sigue cuadrando por construcción.
 *
 * Un REPARTO no se re-estampa acá: sus líneas están partidas a propósito entre
 * los dos libros y volver a aplanarlas sería romperlas. Para ésos hay que
 * rehacer el asiento con `RegistrarAsientoContable`.
 */
class ReestamparEjesContables extends Command
{
    protected $signature = 'contabilidad:reestampar-ejes {--aplicar : Escribe los cambios. Sin esto sólo muestra qué haría.}';

    protected $description = 'Corrige los asientos cuyo destino fiscal no coincide con el de su documento';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if (! $aplicar) {
            $this->warn('MODO REVISIÓN · no se escribe nada. Agregá --aplicar para corregir.');
            $this->newLine();
        }

        $corregidos = $this->facturas($aplicar) + $this->costosDeVenta($aplicar);

        $this->newLine();
        $this->info($aplicar
            ? "{$corregidos} movimiento(s) corregido(s)."
            : "{$corregidos} movimiento(s) quedarían corregidos. Volvé a correrlo con --aplicar.");

        return self::SUCCESS;
    }

    /** Asientos de factura con un destino distinto al de la factura. */
    private function facturas(bool $aplicar): int
    {
        $total = 0;

        // El reparto queda afuera: sus líneas están partidas a propósito.
        foreach (FacturaVenta::where('destino_fiscal', '!=', DestinoFiscal::Reparto->value)->cursor() as $f) {
            $malos = MovimientoContable::query()
                ->where('origen_type', FacturaVenta::class)
                ->where('origen_id', $f->id)
                ->where('destino_fiscal', '!=', $f->destino_fiscal->value)
                ->get();

            if ($malos->isEmpty()) {
                continue;
            }

            $this->line(sprintf(
                '  %-18s %s → %s   %s',
                $f->numero,
                $malos->first()->destino_fiscal->value,
                $f->destino_fiscal->value,
                number_format((float) $malos->sum('debe'), 0, ',', '.'),
            ));

            if ($aplicar) {
                DB::transaction(fn () => MovimientoContable::whereIn('id', $malos->pluck('id'))
                    ->update(['destino_fiscal' => $f->destino_fiscal->value]));
            }

            $total += $malos->count();
        }

        return $total;
    }

    /**
     * El costo de venta de un despacho va donde va su venta.
     *
     * Un reparto manda su parte interna: el costo de una venta que sólo se
     * declara en parte no se puede descontar entero.
     */
    private function costosDeVenta(bool $aplicar): int
    {
        $total = 0;

        foreach (MovimientoContable::where('origen_type', PedidoCliente::class)->cursor() as $m) {
            $pedido = PedidoCliente::find($m->origen_id);
            $factura = $pedido?->factura_id ? FacturaVenta::find($pedido->factura_id) : null;

            if (! $factura) {
                continue;   // sin factura no hay con qué decidir
            }

            $destino = $factura->destino_fiscal === DestinoFiscal::Reparto
                ? DestinoFiscal::Interno
                : $factura->destino_fiscal;

            $cambiaDestino = $m->destino_fiscal !== $destino;
            $cambiaLinea = $factura->linea_negocio && $m->linea_negocio !== $factura->linea_negocio;

            if (! $cambiaDestino && ! $cambiaLinea) {
                continue;
            }

            $this->line(sprintf(
                '  %-18s costo %s → %s   %s',
                $pedido->numero,
                $m->destino_fiscal->value,
                $destino->value,
                // El cast decimal:2 devuelve "0.00", que en PHP es VERDADERO:
                // con `?:` la linea de contrapartida se imprimia en 0.
                number_format(max((float) $m->debe, (float) $m->haber), 0, ',', '.'),
            ));

            if ($aplicar) {
                $m->forceFill([
                    'destino_fiscal' => $destino,
                    'linea_negocio' => $factura->linea_negocio ?? $m->linea_negocio,
                ])->save();
            }

            $total++;
        }

        return $total;
    }
}
