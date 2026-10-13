<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Actions\ConciliarWalletDropi;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F15 · Re-matcheo periódico de movimientos wallet huérfanos con pedidos que
 * sincronizaron después. Si un `pago_guia` llegó a wallet antes de que el
 * pedido apareciera en el sync, quedó `pedido_id = NULL`.
 *
 * Re-audit H2 (func) / H3 (datos) · No basta con linkear el pedido: hay que
 * aplicar la MISMA lógica de la conciliación. Por eso delega en
 * ConciliarWalletDropi::reconciliarHuerfano() en vez de copiarla: la copia se
 * había quedado atrás (solo buscaba por `fuente.guia`, que la wallet real no
 * trae, y comparaba cada línea contra el total del pedido).
 *
 * Programado en Kernel cada 30 min con withoutOverlapping.
 */
class ReconciliarWalletHuerfanos extends Command
{
    protected $signature = 'dropi:reconciliar-huerfanos {--limit=500}';

    protected $description = 'Re-matchea movimientos wallet huérfanos con pedidos que llegaron después + aplica lógica de conciliación.';

    public function handle(ConciliarWalletDropi $conciliar): int
    {
        $huerfanos = DropiWalletMovimiento::query()
            ->whereNull('pedido_id')
            ->orderByDesc('fecha')
            ->limit((int) $this->option('limit'))
            ->get();

        $enlazados = 0;
        $errores = 0;

        foreach ($huerfanos as $mov) {
            try {
                $resultado = DB::transaction(fn () => $conciliar->reconciliarHuerfano($mov));
                if ($resultado !== null) {
                    $enlazados++;
                }
            } catch (\Throwable $e) {
                $errores++;
                Log::error('dropi.wallet.huerfano_error', ['movimiento' => $mov->id, 'msg' => $e->getMessage()]);
            }
        }

        $this->info(sprintf('Huérfanos revisados: %d · enlazados a su pedido: %d · errores: %d', $huerfanos->count(), $enlazados, $errores));

        return self::SUCCESS;
    }
}
