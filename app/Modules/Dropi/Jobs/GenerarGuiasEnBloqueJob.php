<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Dropi\Actions\GenerarGuiaDropi;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * «Generar guías» de «Pedidos Dropi»: lo que Aracely hace en Dropi con
 * «Actualizar las órdenes → GUIA_GENERADA», para todos los escogidos (reunión
 * con la señora Lahida, 24-nov-2026: la guía sale cuando se arma el corte).
 *
 * Un job por pedido: son 2-3 llamadas a Dropi cada uno y el worker de
 * producción corta lo que pasa del minuto, así que 200 guías en un solo job no
 * terminarían. `lanzar()` los encola y deja el avance en caché para la pantalla.
 *
 * Sólo pide guía a lo que en Dropi sigue PENDIENTE: una guía es real (la
 * transportadora la registra), y la de un pedido ya generado o cancelado
 * habría que anularla a mano.
 *
 * Con «Generar corte» (25-nov-2026) cada guía es de un reporte: la última en
 * terminar, bien o mal, arranca el reporte (ImprimirGuiasDropi::iniciar).
 */
class GenerarGuiasEnBloqueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(public int $pedidoId, public ?int $userId, public ?int $loteId = null) {}

    /** Encola una guía por pedido y reinicia el avance de este usuario. */
    public static function lanzar(array $pedidoIds, ?int $userId, ?int $loteId = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $pedidoIds)));
        Cache::put(self::claveAvance($userId), [
            'total' => count($ids), 'hechas' => 0, 'saltadas' => 0, 'errores' => [], 'desde' => now()->toIso8601String(),
        ], now()->addHours(12));
        foreach ($ids as $id) {
            self::dispatch($id, $userId, $loteId);
        }

        return count($ids);
    }

    public static function claveAvance(?int $userId): string
    {
        return "dropi:guias-en-bloque:{$userId}";
    }

    /** @return array{total:int, hechas:int, saltadas:int, errores:list<string>, desde:string}|null */
    public static function avance(?int $userId): ?array
    {
        return Cache::get(self::claveAvance($userId));
    }

    public function handle(GenerarGuiaDropi $generar): void
    {
        try {
            $this->generar($generar);
        } finally {
            $this->contarEnReporte();
        }
    }

    /** Se cayó (tiempo agotado): igual cuenta, o el reporte esperaría para siempre. */
    public function failed(\Throwable $e): void
    {
        $this->contarEnReporte();
    }

    private function generar(GenerarGuiaDropi $generar): void
    {
        $p = DropiPedido::with('huella')->find($this->pedidoId);

        if (! $p || ! $p->dropi_orden_id || $p->tieneGuiaReal() || $p->huella?->estatus_dropi !== 'PENDIENTE' || ! $p->listoParaDespachar()) {
            $this->anotar(fn (array $a) => ['saltadas' => $a['saltadas'] + 1] + $a);

            return;
        }

        try {
            $generar->handle($p, $this->userId);
            $this->anotar(fn (array $a) => ['hechas' => $a['hechas'] + 1] + $a);
        } catch (\Throwable $e) {
            Log::warning('dropi.guias_en_bloque.fallo', ['pedido_id' => $p->id, 'msg' => $e->getMessage()]);
            $error = "Orden {$p->dropi_orden_id}: " . mb_substr($e->getMessage(), 0, 160);
            $this->anotar(fn (array $a) => ['errores' => [...$a['errores'], $error]] + $a);
        }
    }

    /** Una guía menos por esperar; con la última, el reporte arranca. */
    private function contarEnReporte(): void
    {
        // Cada pedido cuenta una vez, aunque pase por el finally y por failed().
        if (! $this->loteId || ! Cache::add("dropi:lote-impresion:{$this->loteId}:contado:{$this->pedidoId}", 1, now()->addDay())) {
            return;
        }
        $arrancar = Cache::lock("dropi:lote-impresion:{$this->loteId}", 30)->block(20, function () {
            $l = DropiLoteImpresion::find($this->loteId);
            if (! $l || $l->estado !== DropiLoteImpresion::GENERANDO_GUIAS) {
                return false;
            }
            $quedan = max(0, $l->guias_pendientes - 1);
            $l->update(['guias_pendientes' => $quedan]);

            return $quedan === 0;
        });
        if ($arrancar) {
            app(ImprimirGuiasDropi::class)->iniciar(DropiLoteImpresion::findOrFail($this->loteId));
        }
    }

    /** Los jobs corren a la vez: el avance se actualiza bajo candado. */
    private function anotar(callable $cambio): void
    {
        $clave = self::claveAvance($this->userId);
        Cache::lock("{$clave}:lock", 10)->block(5, function () use ($clave, $cambio) {
            $a = Cache::get($clave);
            if ($a) {
                Cache::put($clave, $cambio($a), now()->addHours(12));
            }
        });
    }
}
