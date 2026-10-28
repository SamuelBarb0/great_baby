<?php

namespace App\Modules\Siigo\Jobs;

use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Siigo\Exceptions\SiigoRateLimitedException;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoSyncLog;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use DateTimeInterface;
use App\Modules\Siigo\Jobs\Middleware\EsperarCredencialSiigo;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PushPagoProveedorASiigo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public bool $manual = false;

    public function __construct(public int $pagoId)
    {
        $this->onQueue(config('siigo.queue', 'siigo'));
    }

    public function backoff(): array
    {
        return [10 + random_int(0, 5), 30 + random_int(0, 10), 60 + random_int(0, 15), 120 + random_int(0, 30), 300 + random_int(0, 60)];
    }

    /**
     * El techo es el reloj, no el contador de intentos.
     *
     * Con `tries = 5` pelado, una credencial vencida mandaba el documento a
     * `failed_jobs` en minutos y de ahi no sale solo: el 2026-10-08 fueron
     * 1008 trabajos, el catalogo entero. `EsperarCredencialSiigo` lo devuelve
     * a la cola mientras la llave este muerta, y `release()` gasta intento,
     * asi que el limite tiene que ser temporal. 12 horas alcanzan para que
     * alguien pegue la llave nueva en /app/siigo sin perder la cola.
     *
     * Los rechazos de verdad siguen acotados por `$maxExceptions`.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    /** Un rechazo real de SIIGO corta a los 5, como antes. */
    public int $maxExceptions = 5;

    public function middleware(): array
    {
        return [
            // Primero de todos: con la credencial muerta no tiene sentido ni
            // pedir turno al rate limiter.
            new EsperarCredencialSiigo(),
            new RateLimited('siigo-api'),
            (new WithoutOverlapping("siigo:pago-prov:{$this->pagoId}"))->releaseAfter(60)->expireAfter(180),
        ];
    }

    public static function dispatchManual(int $pagoId): void
    {
        $job = new static($pagoId);
        $job->manual = true;
        dispatch($job);
    }

    public function handle(SiigoEmisionService $emisor): void
    {
        if (! $this->manual && ! SiigoConfig::pushAutoActivo()) {
            $this->log('omitido', 0, "[kill-switch] pago-prov={$this->pagoId}");
            return;
        }

        // QA-FIX #10 · lockForUpdate para prevenir dobles POST concurrentes.
        DB::transaction(function () use ($emisor) {
            $p = PagoProveedor::whereKey($this->pagoId)->lockForUpdate()->first();
            if (! $p) { $this->log('omitido', 0, "[not-found] pago-prov={$this->pagoId}"); return; }
            if ($p->siigo_voucher_id) { $this->log('omitido', 0, "[idempotente] pago-prov #{$p->id}"); return; }

            /*
             * Segunda compuerta, por si el job llega por otra via (la red de
             * seguridad diaria, un reintento viejo en la cola, un
             * dispatchManual). Un egreso sin revisar no sale a la contabilidad
             * oficial: esa revision es todo el motivo de que el pago tenga dos
             * estados. Mismo patron que `PushAsientoManualASiigo`.
             */
            if ($p->estado !== 'confirmado') {
                $this->log('omitido', 0, "[sin-confirmar] pago-prov #{$p->id} esta en «{$p->estado}»");
                return;
            }

            try {
                $t0 = microtime(true);
                $emisor->emitirVoucherEgreso($p);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $this->log('exitoso', $ms, "[OK] pago-prov #{$p->id} → SIIGO {$p->fresh()->siigo_voucher_id}");
            } catch (SiigoRateLimitedException $e) {
                $this->release($e->retryAfter + random_int(0, min(10, (int) ($e->retryAfter * 0.2))));
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        SiigoSyncLog::create([
            'recurso' => 'pagos_proveedor', 'estado' => 'fallido',
            'nuevos' => 0, 'actualizados' => 0, 'errores' => 1, 'duracion_ms' => 0,
            'mensaje' => "[job-failed] pago-prov={$this->pagoId} · {$e->getMessage()}",
            'detalle' => ['pago_id' => $this->pagoId, 'exception' => class_basename($e)],
            'user_id' => null,
        ]);
        Log::channel('siigo')->error('PushPagoProveedorASiigo agotó reintentos', ['pago_id' => $this->pagoId, 'error' => $e->getMessage()]);
    }

    private function log(string $estado, int $ms, string $msg): void
    {
        SiigoSyncLog::create([
            'recurso' => 'pagos_proveedor', 'estado' => $estado,
            'nuevos' => $estado === 'exitoso' ? 1 : 0, 'actualizados' => 0, 'errores' => 0, 'duracion_ms' => $ms,
            'mensaje' => $msg,
            'detalle' => ['pago_id' => $this->pagoId, 'manual' => $this->manual],
            'user_id' => null,
        ]);
    }
}
