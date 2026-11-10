<?php

namespace App\Console\Commands;

use App\Models\NotificacionErp;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use App\Modules\Inventario\Models\TomaFisica;
use Illuminate\Console\Command;

/**
 * Avisa qué bodegas llevan demasiado sin contarse.
 *
 * El módulo de conteos estaba completo desde hacía meses: cuenta, compara
 * contra el sistema, exige cuatro ojos para cerrar y hace el asiento contable
 * del sobrante o el faltante. Lo único que no tenía era CADENCIA.
 *
 * El contrato pide inventario semestral, y hasta el 2026-11-06 eso dependía de
 * que alguien se acordara. Nadie llevaba la cuenta de cuándo fue la última
 * toma de cada bodega, así que la forma de enterarse de que hacía un año que
 * no se contaba era que no cuadrara la plata.
 *
 * No crea la toma sola a propósito: contar una bodega es parar una operación,
 * y esa decisión es de logística. El comando avisa, ellos deciden cuándo.
 */
class RecordarConteoFisicoCommand extends Command
{
    protected $signature = 'inventario:recordar-conteo {--dry-run : sólo muestra, no avisa}';

    protected $description = 'Avisa qué bodegas llevan más de lo permitido sin toma física';

    public function handle(): int
    {
        $meses = max(1, (int) setting('inventario.meses_entre_conteos'));
        $limite = now()->subMonths($meses);
        $seco = (bool) $this->option('dry-run');

        $vencidas = [];

        /*
         * Sólo las bodegas que TIENEN algo.
         *
         * La tabla de ubicaciones trae 55 filas, y la mayoría llegaron del
         * ambiente de pruebas compartido de SIIGO: «Almacen QuickPro»,
         * «Bodega CK Ecommerce» y demás, que son de otras empresas. Avisar de
         * todas convertiría el recordatorio en paisaje, que es la forma más
         * segura de que nadie lo lea.
         *
         * Una ubicación sin un solo movimiento de inventario no tiene nada que
         * contar.
         */
        $conMercancia = \App\Modules\Dropi\Models\InventarioMovimiento::query()
            ->select('ubicacion_id')->distinct()->pluck('ubicacion_id');

        $bodegas = InventarioUbicacion::where('activa', true)
            ->whereIn('id', $conMercancia)->get();

        if ($bodegas->isEmpty()) {
            $this->info('Ninguna bodega tiene movimientos de inventario todavía.');

            return self::SUCCESS;
        }

        foreach ($bodegas as $bodega) {
            // Sólo cuenta la última toma CERRADA: una en borrador o anulada no
            // dice nada del inventario.
            $ultima = TomaFisica::query()
                ->where('ubicacion_id', $bodega->id)
                ->where('estado', EstadoTomaFisica::Ajustada)
                ->latest('updated_at')
                ->first();

            $desde = $ultima?->updated_at;
            if ($desde && $desde->isAfter($limite)) {
                continue;
            }

            $vencidas[] = [
                'bodega' => $bodega,
                'ultima' => $desde,
                'meses' => $desde ? (int) $desde->diffInMonths(now()) : null,
            ];
        }

        if ($vencidas === []) {
            $this->info("Todas las bodegas tienen toma física de los últimos {$meses} meses.");

            return self::SUCCESS;
        }

        foreach ($vencidas as $v) {
            $cuando = $v['ultima']
                ? "la última fue hace {$v['meses']} meses ({$v['ultima']->format('d/m/Y')})"
                : 'nunca se ha contado';

            $this->warn("· {$v['bodega']->nombre}: {$cuando}");

            if ($seco) {
                continue;
            }

            NotificacionErp::crear([
                'tipo' => 'conteo_fisico_vencido',
                'titulo' => "Toma física pendiente · {$v['bodega']->nombre}",
                'mensaje' => "Se cuenta cada {$meses} meses y {$cuando}. Programá la toma desde Inventario → Conteos físicos.",
                'color' => 'warning',
                'icono' => 'heroicon-o-clipboard-document-check',
                'url' => '/app/inventario/conteos',
            ]);
        }

        $this->newLine();
        $this->line(count($vencidas).' bodega(s) sin contar'.($seco ? ' · dry-run, no se avisó' : ' · avisadas por la campana'));

        return self::SUCCESS;
    }
}
