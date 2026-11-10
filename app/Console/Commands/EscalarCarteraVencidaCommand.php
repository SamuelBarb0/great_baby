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

        /*
         * Sin destinatario no se escala NADA, y a propósito.
         *
         * Marcar la factura como escalada sin haberle avisado a nadie la saca
         * del barrido para siempre: la próxima corrida ya no la ve. Vale más
         * que falle ruidoso hoy a que se pierda en silencio.
         */
        $gerencia = $this->gerencia();

        if (! $seco && $gerencia === []) {
            $this->error('Nadie tiene acceso a cartera: no hay a quién escalarle. '
                .'Corré el sembrador de permisos y volvé a intentar.');

            return self::FAILURE;
        }

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

            /*
             * El escalamiento va a Gerencia, no a todo el ERP.
             *
             * `NotificacionErp` sin `user_id` es un broadcast: lo ve TODO el
             * mundo que abra la campana, alistadores incluidos. Y lo que dice
             * el aviso es el nombre del cliente, su saldo y cuántos días lleva
             * sin pagar —información de cartera que no le toca a bodega—.
             * Además, un aviso que le aparece a todos no le pertenece a nadie
             * y nadie lo atiende, que es el problema que este comando venía a
             * resolver.
             */
            foreach ($gerencia as $userId) {
                NotificacionErp::crear([
                    'user_id' => $userId,
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
            }

            $f->forceFill(['escalada_gerencia_at' => now()])->saveQuietly();
        }

        $this->newLine();
        $this->line($candidatas->count().' factura(s) por $'.number_format($monto, 0, ',', '.')
            .($seco ? ' · dry-run, no se avisó' : ' · escaladas a Gerencia'));

        return self::SUCCESS;
    }

    /**
     * A quién se escala. Si no hay nadie, no se inventa un broadcast.
     *
     * Un aviso sin destinatario lo ve todo el ERP, que es peor que no mandarlo:
     * se filtra plata de cartera y encima nadie se da por aludido. Si la
     * instalación todavía no tiene a nadie con acceso a cartera, queda dicho
     * en la consola y la factura NO se marca como escalada, para que el
     * barrido de mañana lo vuelva a intentar.
     */
    private function gerencia(): array
    {
        return \App\Auth\Permisos::usuariosQuePueden('cartera_dashboard')
            ->pluck('id')->all();
    }
}
