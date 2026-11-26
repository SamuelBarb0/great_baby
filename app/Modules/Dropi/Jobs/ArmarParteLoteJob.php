<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Arma una parte del PDF de un lote (200 stickers) y encola la siguiente; con
 * la última, los pedidos quedan impresos (ImprimirGuiasDropi::armarParte).
 */
class ArmarParteLoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(public int $loteId, public int $parte)
    {
        // En la cola de las guías: el reporte del corte no espera detrás de SIIGO.
        $this->onQueue(GenerarGuiasEnBloqueJob::COLA);
    }

    public function handle(ImprimirGuiasDropi $imprimir): void
    {
        if ($lote = DropiLoteImpresion::find($this->loteId)) {
            $imprimir->armarParte($lote, $this->parte);
        }
    }

    public function failed(\Throwable $e): void
    {
        DropiLoteImpresion::whereKey($this->loteId)->update(['estado' => DropiLoteImpresion::ERROR, 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
