<?php

namespace App\Console\Commands;

use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use Illuminate\Console\Command;

/**
 * Lleva al libro las facturas que quedaron sin asiento contable.
 *
 * El asiento lo dispara el propio modelo al emitir la factura. Pero una
 * factura que nació por fuera de ese camino —una carga inicial, una semilla,
 * una importación de datos viejos, un `forceFill` de rescate— se queda sin él,
 * y entonces el libro muestra una verdad a medias: encontrado el 2026-10-14
 * con 15 facturas por $11.960.000 sin asentar, mientras el pago de UNA de
 * ellas sí estaba en el libro. La cuenta 1305 Clientes quedaba en **negativo**
 * —el crédito del pago sin el débito de su factura—, o sea el balance decía
 * que la empresa le debe plata a sus clientes.
 *
 * `RegistrarAsientoContable::factura()` limpia los movimientos previos antes de
 * rehacerlos, así que volver a pasar por una factura ya asentada la reescribe
 * en vez de duplicarla. Por eso esto se puede correr cuantas veces haga falta.
 */
class AsentarFacturasPendientesCommand extends Command
{
    protected $signature = 'contabilidad:asentar-facturas-pendientes
                            {--dry-run : Sólo muestra qué haría, sin tocar el libro}
                            {--todas : Rehace también las que YA tienen asiento}';

    protected $description = 'Genera el asiento contable de las facturas de venta que no lo tienen';

    public function handle(RegistrarAsientoContable $accion): int
    {
        $yaAsentadas = MovimientoContable::query()
            ->where('origen_type', FacturaVenta::class)
            ->distinct()->pluck('origen_id');

        $facturas = FacturaVenta::query()
            ->when(! $this->option('todas'), fn ($q) => $q->whereNotIn('id', $yaAsentadas))
            ->orderBy('fecha_emision')
            ->get();

        if ($facturas->isEmpty()) {
            $this->info('No hay facturas pendientes de asentar.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d factura(s) por $%s',
            $this->option('dry-run') ? 'Se asentarían' : 'Asentando',
            $facturas->count(),
            number_format((float) $facturas->sum('total'), 0, ',', '.'),
        ));

        $hechas = 0;
        $errores = 0;

        foreach ($facturas as $f) {
            if ($this->option('dry-run')) {
                $this->line(sprintf('  · %-22s %s  $%s',
                    $f->numero, $f->fecha_emision, number_format((float) $f->total, 0, ',', '.')));
                continue;
            }

            try {
                $lineas = $accion->factura($f);
                $hechas++;
                $this->line("  ✓ {$f->numero} · {$lineas} línea(s)");
            } catch (\Throwable $e) {
                $errores++;
                // No se corta: una factura con datos rotos no puede impedir que
                // las demás entren al libro.
                $this->warn("  ✗ {$f->numero} · ".mb_substr($e->getMessage(), 0, 90));
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Ensayo en seco: no se tocó el libro. Quitá --dry-run para hacerlo.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Asentadas: {$hechas}".($errores ? " · con error: {$errores}" : ''));

        // El dato que de verdad importa después de tocar el libro.
        $debe = (float) MovimientoContable::sum('debe');
        $haber = (float) MovimientoContable::sum('haber');
        $this->line(sprintf('Libro: debe $%s · haber $%s · descuadre $%s',
            number_format($debe, 0, ',', '.'),
            number_format($haber, 0, ',', '.'),
            number_format($debe - $haber, 0, ',', '.')));

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
