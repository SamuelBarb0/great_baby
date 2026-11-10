<?php

namespace App\Console\Commands;

use App\Models\NotificacionErp;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use App\Modules\Cartera\Models\FacturaVenta;
use Illuminate\Console\Command;

/**
 * Le avisa a Gerencia de la cartera que cruzó los 120 días.
 *
 * El contrato lo pide con esas palabras: «semáforo de cartera por tramos y por
 * vendedor, con escalamiento a Gerencia en 120+». Los tramos existían desde
 * hacía meses; el escalamiento no existía en ninguna parte. Una factura podía
 * cruzar los 120 días sin que nadie se enterara, salvo que alguien se acordara
 * de abrir el reporte.
 *
 * A los 120 días una cartera ya no se cobra sola: o se negocia, o se castiga,
 * y las dos cosas las decide Gerencia. Por eso el aviso es para ella y no para
 * el vendedor, que a esa altura ya hizo lo que podía.
 *
 * Cada factura se escala UNA vez. Avisar todos los días de lo mismo es la
 * forma más rápida de que dejen de leer los avisos.
 */
class EscalarCarteraVencidaCommand extends Command
{
    protected $signature = 'cartera:escalar-vencidas {--dry-run : sólo muestra, no avisa}';

    protected $description = 'Avisa a Gerencia de las facturas que pasaron a 120+ días';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');

        $candidatas = FacturaVenta::query()
            ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->whereNull('escalada_gerencia_at')
            ->where('saldo', '>', 0)
            ->with('contacto:id,nombre_completo', 'vendedor:id,name')
            ->get()
            // El tramo se calcula en el modelo, no en SQL: así no hay dos
            // definiciones de «120 días» que se puedan separar con el tiempo.
            ->filter(fn (FacturaVenta $f) => $f->tramo() === TramoAntiguedad::D120Mas);

        if ($candidatas->isEmpty()) {
            $this->info('Ninguna factura nueva cruzó los 120 días.');

            return self::SUCCESS;
        }

        $monto = 0.0;

        foreach ($candidatas as $f) {
            $monto += (float) $f->saldo;
            $cliente = $f->contacto?->nombre_completo ?? 'cliente sin nombre';
            $this->warn(sprintf('· %s · %s · %s días · $%s',
                $f->numero, $cliente, $f->diasMora(), number_format((float) $f->saldo, 0, ',', '.')));

            if ($seco) {
                continue;
            }

            NotificacionErp::crear([
                'tipo' => 'cartera_escalada',
                'titulo' => "Cartera 120+ · {$cliente}",
                'mensaje' => "La factura {$f->numero} lleva {$f->diasMora()} días de mora por "
                    .'$'.number_format((float) $f->saldo, 0, ',', '.')
                    .($f->vendedor ? ". Vendedor: {$f->vendedor->name}." : '.')
                    .' A esta altura ya no se cobra sola: hay que negociarla o castigarla.',
                'color' => 'danger',
                'icono' => 'heroicon-o-exclamation-triangle',
                'url' => "/app/facturas/{$f->id}",
            ]);

            $f->forceFill(['escalada_gerencia_at' => now()])->saveQuietly();
        }

        $this->newLine();
        $this->line($candidatas->count().' factura(s) por $'.number_format($monto, 0, ',', '.')
            .($seco ? ' · dry-run, no se avisó' : ' · escaladas a Gerencia'));

        return self::SUCCESS;
    }
}
