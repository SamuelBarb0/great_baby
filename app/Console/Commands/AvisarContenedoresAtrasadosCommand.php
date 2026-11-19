<?php

namespace App\Console\Commands;

use App\Modules\Compras\Services\SeguimientoContenedor;
use Illuminate\Console\Command;

/**
 * «Ya cumplió los días que debía estar en puerto»: el aviso que pidió Alejandro
 * para no tener que revisar quince contenedores uno por uno.
 */
class AvisarContenedoresAtrasadosCommand extends Command
{
    protected $signature = 'compras:avisar-contenedores-atrasados';

    protected $description = 'Avisa en la campana de los contenedores atrasados y de los que están por llegar a bodega';

    public function handle(SeguimientoContenedor $seguimiento): int
    {
        $n = $seguimiento->avisarAtrasos();
        $llegadas = $seguimiento->avisarLlegadas();

        $this->info($n === 0 ? 'Ningún contenedor por avisar.' : "Se avisó de {$n} contenedor(es) atrasado(s).");
        $this->info($llegadas === 0 ? 'Ninguna llegada por avisar.' : "Se avisó de {$llegadas} llegada(s) a bodega.");

        return self::SUCCESS;
    }
}
