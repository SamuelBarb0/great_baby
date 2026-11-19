<?php

namespace App\Modules\Siigo\Jobs;

use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Siigo\Exceptions\PagoSinFacturaEnSiigo;
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
use Illuminate\Support\Facades\Log;

/**
 * F10 · Push asíncrono de un VOUCHER (recibo de caja / comprobante de egreso)
 * hacia SIIGO. Mismo patrón que PushRecepcionASiigo:
 *   - Kill-switch, rate limit, WithoutOverlapping, backoff con jitter.
 *   - Log en siigo_sync_log con recurso='pagos'.
 *   - Idempotente (skip si el pago ya tiene siigo_id).
 */
class PushVoucherASiigo implements ShouldQueue
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
        return [
            10  + random_int(0, 5),
            30  + random_int(0, 10),
            60  + random_int(0, 15),
            120 + random_int(0, 30),
            300 + random_int(0, 60),
        ];
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
            (new WithoutOverlapping("siigo:pago:{$this->pagoId}"))
                ->releaseAfter(60)->expireAfter(180),
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
            $this->log('omitido', 0, "[kill-switch] pago={$this->pagoId} · push_auto=off");
            return;
        }

        $pago = PagoVenta::find($this->pagoId);
        if (! $pago) {
            $this->log('omitido', 0, "[not-found] pago={$this->pagoId} · ya no existe");
            return;
        }

        if ($pago->siigo_id) {
            $this->log('omitido', 0, "[idempotente] pago {$pago->id} ya tiene siigo_id={$pago->siigo_id}");
            return;
        }

        try {
            $t0 = microtime(true);
            $emisor->emitirVoucher($pago);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $this->log('exitoso', $ms, "[OK] pago={$pago->id} → SIIGO {$pago->fresh()->siigo_number}");
        } catch (SiigoRateLimitedException $e) {
            $delay = $e->retryAfter + random_int(0, min(10, (int) ($e->retryAfter * 0.2)));
            $this->release($delay);
        } catch (PagoSinFacturaEnSiigo $e) {
            // Su factura no es electrónica: no va a SIIGO ni hoy ni nunca, así
            // que el recibo tampoco. Antes esto se trataba como fallo, gastaba
            // intentos y caía en `failed_jobs`; al día siguiente la red de
            // seguridad lo reencolaba para que muriera igual. Queda como
            // omitido, que es lo que de verdad pasó.
            $this->log('omitido', 0, "[sin-factura-electronica] {$e->getMessage()}");
        } catch (\App\Modules\Siigo\Exceptions\PagoTotalmenteInterno $e) {
            // El abono no toca la parte declarada del reparto: en SIIGO esa
            // factura ya esta pagada. No hay nada que mandar ni que reintentar.
            $this->log('omitido', 0, "[pago-interno-de-reparto] {$e->getMessage()}");
        }
    }

    public function failed(\Throwable $e): void
    {
        SiigoSyncLog::create([
            'recurso' => 'pagos',
            'estado' => 'fallido',
            'nuevos' => 0, 'actualizados' => 0, 'errores' => 1, 'duracion_ms' => 0,
            'mensaje' => "[job-failed] pago={$this->pagoId} · {$e->getMessage()}",
            'detalle' => [
                'pago_id' => $this->pagoId,
                'exception' => class_basename($e),
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ],
            'user_id' => null,
        ]);
        Log::channel('siigo')->error('PushVoucherASiigo agotó reintentos', [
            'pago_id' => $this->pagoId,
            'error' => $e->getMessage(),
        ]);
    }

    private function log(string $estado, int $ms, string $msg): void
    {
        SiigoSyncLog::create([
            'recurso' => 'pagos',
            'estado' => $estado,
            'nuevos' => $estado === 'exitoso' ? 1 : 0,
            'actualizados' => 0,
            'errores' => 0,
            'duracion_ms' => $ms,
            'mensaje' => $msg,
            'detalle' => ['pago_id' => $this->pagoId, 'manual' => $this->manual],
            'user_id' => null,
        ]);
    }
}
