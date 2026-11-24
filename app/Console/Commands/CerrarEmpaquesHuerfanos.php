<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Enums\EstadoEmpaque;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cierra registros de empaque en_curso abandonados (browser cerrado, operario cambió de turno).
 * Los marca como «anulado» (no existe un estado «abandonado»: escribirlo reventaba
 * el comando cada 15 minutos, 23-nov-2026) y devuelve el pedido a Pending si no completó.
 * La nota del registro dice que fue por inactividad.
 * Programado cada 15 min en console/kernel.
 */
class CerrarEmpaquesHuerfanos extends Command
{
    protected $signature = 'empaque:cerrar-huerfanos {--minutos=30 : Cerrar registros con más de N minutos sin actividad}';

    protected $description = 'Cierra registros de empaque en_curso abandonados por más de N minutos';

    public function handle(): int
    {
        $minutos = (int) $this->option('minutos');
        $limite = now()->subMinutes($minutos);

        $huerfanos = EmpaqueRegistro::where('estado', 'en_curso')
            ->where('inicio_at', '<=', $limite)
            ->get();

        if ($huerfanos->isEmpty()) {
            $this->info("Sin registros huérfanos (más de {$minutos} min sin actividad).");
            return self::SUCCESS;
        }

        $cerrados = 0;
        $pedidosReset = 0;

        DB::transaction(function () use ($huerfanos, &$cerrados, &$pedidosReset) {
            foreach ($huerfanos as $r) {
                $r->update([
                    'estado' => EstadoEmpaque::Anulado,
                    'fin_at' => now(),
                    'notas' => trim(($r->notas ?? '') . "\n[auto] cerrado por inactividad > {$this->option('minutos')} min"),
                ]);
                $cerrados++;

                // Si el pedido quedó en Alistando y ningún ítem se pickeó completo, volver a Pending
                $pedido = DropiPedido::find($r->pedido_id);
                if ($pedido && $pedido->estado === EstadoPedidoDropi::Alistando) {
                    $totalPickeado = $pedido->items()->where('cantidad_pickeada', '>', 0)->count();
                    if ($totalPickeado === 0) {
                        // `estado` está en $guarded: el `update()` de antes no
                        // hacía nada y el pedido se quedaba en «Alistando»
                        // para siempre. Se cambia como en LiberarLocksAlistador.
                        try {
                            $pedido->transicionar(EstadoPedidoDropi::Pending, 'sistema', null, ['origen' => 'empaque_huerfano', 'empaque_id' => $r->id]);
                            $pedidosReset++;
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('dropi.empaque_huerfano.revert_bloqueado', [
                                'empaque_id' => $r->id, 'pedido_id' => $r->pedido_id, 'msg' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        });

        $this->info("Cerrados {$cerrados} registros huérfanos, {$pedidosReset} pedidos devueltos a Pending.");
        return self::SUCCESS;
    }
}
