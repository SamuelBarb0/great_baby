<?php

namespace App\Modules\Siigo\Jobs;

use App\Modules\Compras\Models\Importacion;
// El modelo vive en Cartera, no en Contabilidad: con el namespace equivocado
// este job moría con "Class not found" al leer los asientos, así que la
// liquidación de una importación nunca llegaba a SIIGO como comprobante.
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Exceptions\SiigoRateLimitedException;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoSyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use DateTimeInterface;
use App\Modules\Siigo\Jobs\Middleware\EsperarCredencialSiigo;
use App\Modules\Siigo\Support\ClaveIdempotencia;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * BUG-IMP · Push del asiento compuesto de liquidación de importación a SIIGO.
 *
 * Antes de este Job, la reclasificación 1465→1435 + IVA 1355 + CxP agencia solo
 * quedaba en `movimientos_contables` locales. El `InventarioMovimientoObserver`
 * excluye explícitamente `entrada_importacion` (no sirve mandar línea por
 * línea, el asiento real es compuesto). Este Job consolida TODOS los movs
 * contables ligados a la Importacion en un journal multilínea SIIGO.
 *
 * Idempotencia: se guarda `siigo_journal_id` en `importaciones.siigo_journal_id`.
 */
class PushLiquidacionImportacionASiigo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public bool $manual = false;

    public function __construct(public int $importacionId)
    {
        $this->onQueue(config('siigo.queue', 'siigo'));
    }

    public function backoff(): array
    {
        return [
            10 + random_int(0, 5), 30 + random_int(0, 10),
            60 + random_int(0, 15), 120 + random_int(0, 30),
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
            (new WithoutOverlapping("siigo:imp:{$this->importacionId}"))
                ->releaseAfter(60)->expireAfter(180),
        ];
    }

    public static function dispatchManual(int $importacionId): void
    {
        $job = new static($importacionId);
        $job->manual = true;
        dispatch($job);
    }

    public function handle(SiigoClient $client): void
    {
        if (! $this->manual && ! SiigoConfig::pushAutoActivo()) {
            $this->log('omitido', 0, "[kill-switch] imp={$this->importacionId}");
            return;
        }

        $imp = Importacion::find($this->importacionId);
        if (! $imp) {
            $this->log('omitido', 0, "[not-found] imp={$this->importacionId}");
            return;
        }
        if ($imp->siigo_journal_id) {
            $this->log('omitido', 0, "[idempotente] imp {$imp->id} ya tiene journal {$imp->siigo_journal_id}");
            return;
        }

        $movs = MovimientoContable::where('origen_type', Importacion::class)
            ->where('origen_id', $imp->id)
            ->orderBy('id')
            ->get();
        if ($movs->isEmpty()) {
            $this->log('omitido', 0, "[sin-movimientos] imp {$imp->id} liquidada pero sin MovimientoContable");
            return;
        }

        // Validación de partida doble antes de pedirle nada a SIIGO.
        $totalDebe  = round((float) $movs->sum('debe'), 2);
        $totalHaber = round((float) $movs->sum('haber'), 2);
        if (abs($totalDebe - $totalHaber) > 0.01) {
            throw new RuntimeException(
                "Asiento desbalanceado en IMP {$imp->numero}: debe={$totalDebe} haber={$totalHaber}"
            );
        }

        try {
            $t0 = microtime(true);
            $items = $movs->map(fn ($m) => [
                'account'     => ['code' => (string) $m->cuenta_puc, 'movement' => $m->debe > 0 ? 'Debit' : 'Credit'],
                'value'       => round(($m->debe > 0 ? $m->debe : $m->haber), 2),
                'description' => mb_substr((string) $m->descripcion, 0, 160),
            ])->values()->all();

            $payload = [
                'date'         => optional($imp->fecha_liquidacion)->format('Y-m-d') ?? now()->format('Y-m-d'),
                'items'        => $items,
                'observations' => "ERP IMP #{$imp->numero} · liquidación contenedor · {$movs->count()} líneas",
            ];
            if ($docId = (int) setting('siigo.doc_type_gasto', 0)) {
                $payload['document'] = ['id' => $docId];
            }

            // La variable es `$imp`, no `$i`: con el namespace roto esta línea
            // nunca llegaba a ejecutarse, así que el typo estaba escondido. La
            // clave de idempotencia es la que evita duplicar el comprobante si
            // el job se reintenta.
            $resp = $client->request('POST', '/v1/journals', $payload, 1, ClaveIdempotencia::para('imp', $imp));
            if (! $resp->ok()) {
                throw new RuntimeException("SIIGO rechazó journal: HTTP {$resp->status()} · ".mb_substr((string) $resp->body(), 0, 400));
            }

            $journalId = $resp->json('id');
            DB::transaction(function () use ($imp, $journalId, $movs) {
                $imp->forceFill(['siigo_journal_id' => $journalId, 'siigo_sync_at' => now()])->save();
                MovimientoContable::whereIn('id', $movs->pluck('id'))->update(['siigo_journal_id' => $journalId]);
            });

            $ms = (int) round((microtime(true) - $t0) * 1000);
            $this->log('exitoso', $ms, "[OK] IMP {$imp->numero} → journal {$journalId} · {$movs->count()} líneas");
        } catch (SiigoRateLimitedException $e) {
            $delay = $e->retryAfter + random_int(0, min(10, (int) ($e->retryAfter * 0.2)));
            $this->release($delay);
        }
    }

    public function failed(\Throwable $e): void
    {
        SiigoSyncLog::create([
            'recurso' => 'importaciones', 'estado' => 'fallido',
            'nuevos' => 0, 'actualizados' => 0, 'errores' => 1, 'duracion_ms' => 0,
            'mensaje' => "[job-failed] imp={$this->importacionId} · {$e->getMessage()}",
            'detalle' => [
                'importacion_id' => $this->importacionId,
                'exception' => class_basename($e),
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ],
            'user_id' => null,
        ]);
        Log::channel('siigo')->error('PushLiquidacionImportacionASiigo agotó reintentos', [
            'importacion_id' => $this->importacionId, 'error' => $e->getMessage(),
        ]);
    }

    private function log(string $estado, int $ms, string $msg): void
    {
        SiigoSyncLog::create([
            'recurso' => 'importaciones', 'estado' => $estado,
            'nuevos' => $estado === 'exitoso' ? 1 : 0, 'actualizados' => 0,
            'errores' => 0, 'duracion_ms' => $ms, 'mensaje' => $msg,
            'detalle' => ['importacion_id' => $this->importacionId, 'manual' => $this->manual],
            'user_id' => null,
        ]);
    }
}
