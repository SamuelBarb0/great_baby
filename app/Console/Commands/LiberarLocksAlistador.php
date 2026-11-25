<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiAlistadorLock;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §6 diseño Dropi — libera locks del alistador vencidos (heartbeat > 10 min).
 * Programar cada 2 minutos.
 *
 * P8 · No basta con borrar el lock: también hay que
 *   1) anular el EmpaqueRegistro `en_curso` (queda como abandonado),
 *   2) revertir el estado del pedido de `Alistando` → `Pending` para que reaparezca
 *      en la cola disponible para otro alistador.
 * De lo contrario el pedido queda zombi: fuera de la cola visible y bloqueado por
 * un EmpaqueRegistro fantasma.
 */
class LiberarLocksAlistador extends Command
{
    protected $signature = 'dropi:liberar-locks {--minutos=10}';

    protected $description = 'Libera locks de alistador con heartbeat mayor al umbral y limpia el estado colgante.';

    public function handle(): int
    {
        $umbral = now()->subMinutes((int) $this->option('minutos'));

        $vencidos = DropiAlistadorLock::query()
            ->where('heartbeat_at', '<', $umbral)
            ->get(['id', 'pedido_id', 'alistador_id']);

        if ($vencidos->isEmpty()) {
            $this->info('Sin locks vencidos.');
            return self::SUCCESS;
        }

        $liberados = 0;
        $empaquesAnulados = 0;
        $pedidosReiniciados = 0;

        foreach ($vencidos as $lock) {
            // H6 func · delete DENTRO de la misma transacción — antes quedaba
            // fuera y si la anulación de EmpaqueRegistro fallaba, el lock ya
            // estaba borrado (estado corrupto sin trazabilidad).
            try {
                DB::transaction(function () use ($lock, &$empaquesAnulados, &$pedidosReiniciados, &$liberados) {
                    $empaquesAnulados += EmpaqueRegistro::where('pedido_id', $lock->pedido_id)
                        ->where('estado', 'en_curso')
                        ->update([
                            'estado' => 'anulado',
                            'fin_at' => now(),
                        ]);

                    $pedido = DropiPedido::find($lock->pedido_id);
                    if ($pedido && $pedido->estado === EstadoPedidoDropi::Alistando) {
                        try {
                            $pedido->transicionar(
                                EstadoPedidoDropi::Pending,
                                'sistema',
                                null,
                                ['origen' => 'lock_expirado', 'alistador_id' => $lock->alistador_id],
                            );
                            // Lo escaneado se borra como en «Cancelar»: si no, el
                            //   siguiente empacador lo abre en «1/1 ✔» y lo confirma
                            //   sin escanear el producto (prueba del 24-nov-2026). Todos los
                            //   ítems: el lock era el único dueño, y un parcial (1 de 2)
                            //   no lleva pickeado_por.
                            \App\Modules\Dropi\Models\DropiPedidoItem::where('pedido_id', $lock->pedido_id)
                                ->update(['cantidad_pickeada' => 0, 'pickeado_at' => null, 'pickeado_por' => null]);
                            $pedidosReiniciados++;
                        } catch (\Throwable $inner) {
                            // Log para trazabilidad — no silencioso.
                            \Illuminate\Support\Facades\Log::warning('dropi.liberar_locks.revert_bloqueado', [
                                'lock_id' => $lock->id, 'pedido_id' => $lock->pedido_id,
                                'msg' => $inner->getMessage(),
                            ]);
                        }
                    }

                    $lock->delete();
                    $liberados++;
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('dropi.liberar_locks.error', [
                    'lock_id' => $lock->id, 'msg' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Locks liberados: %d · Empaques anulados: %d · Pedidos re-encolados: %d',
            $liberados, $empaquesAnulados, $pedidosReiniciados,
        ));

        return self::SUCCESS;
    }
}
