<?php

namespace App\Modules\Siigo\Jobs;

use App\Modules\Siigo\Exceptions\SiigoRateLimitedException;
use App\Modules\Siigo\Jobs\Middleware\EsperarCredencialSiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoSyncLog;
use App\Modules\Siigo\Services\ComprobanteDropiSiigo;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda a SIIGO el asiento de un movimiento de la wallet de Dropi (o la
 * reversa de un recibo de caja de Dropi) como comprobante contable.
 * La lógica vive en ComprobanteDropiSiigo; esto es la cola, el kill-switch y
 * la bitácora, igual que los demás push a SIIGO.
 */
class PushAsientoDropiASiigo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public bool $manual = false;

    /** Un rechazo real de SIIGO corta a los 5. */
    public int $maxExceptions = 5;

    public function __construct(public string $origenType, public int $origenId)
    {
        $this->onQueue(config('siigo.queue', 'siigo'));
    }

    public function backoff(): array
    {
        return [10 + random_int(0, 5), 30 + random_int(0, 10), 60 + random_int(0, 15), 120 + random_int(0, 30), 300 + random_int(0, 60)];
    }

    /** Con la credencial vencida el job espera en la cola hasta 12 horas. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function middleware(): array
    {
        return [
            new EsperarCredencialSiigo(),
            new RateLimited('siigo-api'),
            (new WithoutOverlapping('siigo:dropi:'.class_basename($this->origenType).':'.$this->origenId))
                ->releaseAfter(60)->expireAfter(180),
        ];
    }

    public static function dispatchManual(string $origenType, int $origenId): void
    {
        $job = new static($origenType, $origenId);
        $job->manual = true;
        dispatch($job);
    }

    public function handle(ComprobanteDropiSiigo $comprobante): void
    {
        $quien = class_basename($this->origenType).' #'.$this->origenId;

        if (! $this->manual && ! SiigoConfig::pushAutoActivo()) {
            $this->log('omitido', 0, "[kill-switch] {$quien}");

            return;
        }

        if (ComprobanteDropiSiigo::pendientes($this->origenType, $this->origenId)->isEmpty()) {
            $this->log('omitido', 0, "[nada-pendiente] {$quien}");

            return;
        }

        try {
            $t0 = microtime(true);
            $ids = $comprobante->emitir($this->origenType, $this->origenId);
            $this->log('exitoso', (int) round((microtime(true) - $t0) * 1000), "[OK] {$quien} → SIIGO ".implode(', ', $ids));
        } catch (SiigoRateLimitedException $e) {
            $this->release($e->retryAfter + random_int(0, min(10, (int) ($e->retryAfter * 0.2))));
        }
    }

    public function failed(\Throwable $e): void
    {
        SiigoSyncLog::create([
            'recurso' => 'wallet_dropi', 'estado' => 'fallido',
            'nuevos' => 0, 'actualizados' => 0, 'errores' => 1, 'duracion_ms' => 0,
            'mensaje' => '[job-failed] '.class_basename($this->origenType).' #'.$this->origenId.' · '.$e->getMessage(),
            'detalle' => ['origen_type' => $this->origenType, 'origen_id' => $this->origenId, 'exception' => class_basename($e)],
            'user_id' => null,
        ]);
        Log::channel('siigo')->error('PushAsientoDropiASiigo agotó reintentos', [
            'origen' => $this->origenType, 'id' => $this->origenId, 'error' => $e->getMessage(),
        ]);
    }

    private function log(string $estado, int $ms, string $msg): void
    {
        SiigoSyncLog::create([
            'recurso' => 'wallet_dropi', 'estado' => $estado,
            'nuevos' => $estado === 'exitoso' ? 1 : 0, 'actualizados' => 0, 'errores' => 0, 'duracion_ms' => $ms,
            'mensaje' => $msg,
            'detalle' => ['origen_type' => $this->origenType, 'origen_id' => $this->origenId, 'manual' => $this->manual],
            'user_id' => null,
        ]);
    }
}
