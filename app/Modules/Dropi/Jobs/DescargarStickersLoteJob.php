<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Baja los stickers de unos pocos pedidos de un lote de «Imprimir guías» (de a
 * 15: el worker de producción corre 55 s). El último en terminar manda a armar
 * el PDF (ImprimirGuiasDropi::descargar).
 */
class DescargarStickersLoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    /** @param  list<int>  $pedidoIds */
    public function __construct(public int $loteId, public array $pedidoIds) {}

    public function handle(ImprimirGuiasDropi $imprimir): void
    {
        if ($lote = DropiLoteImpresion::find($this->loteId)) {
            $imprimir->descargar($lote, $this->pedidoIds);
        }
    }

    public function failed(\Throwable $e): void
    {
        DropiLoteImpresion::whereKey($this->loteId)->update(['estado' => DropiLoteImpresion::ERROR, 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
