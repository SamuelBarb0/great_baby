<?php

namespace App\Modules\Siigo\Jobs;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Models\DevolucionProveedor;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Exceptions\SiigoRateLimitedException;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoSyncLog;
use App\Modules\Siigo\Support\ClaveIdempotencia;
use App\Modules\Siigo\Support\CuentasSiigo;
use Illuminate\Support\Facades\DB;
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
use RuntimeException;

/**
 * COMP-B1 · Push a SIIGO de una devolución a proveedor.
 *
 * HALLAZGO doc oficial (developers.siigolatam.com): el endpoint
 * `POST /v1/credit-notes` es **exclusivo para NC de ventas**. SIIGO Nube
 * API no tiene un endpoint dedicado para "nota crédito de compra".
 * Las devoluciones a proveedor en SIIGO se registran como **comprobante
 * contable** vía `POST /v1/journals` con la partida doble.
 *
 * Patrón idéntico a PushLiquidacionImportacionASiigo (BUG-IMP):
 *   - lee los MovimientoContable que el Action ya escribió
 *     (DB 2205 CxP · CR 1435 Inventario · CR 2408 IVA)
 *   - valida partida doble antes de llamar a SIIGO
 *   - POST /v1/journals · idempotencia por `siigo_id`
 */
class PushDevolucionProveedorASiigo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public bool $manual = false;

    public function __construct(public int $devolucionId)
    {
        $this->onQueue(config('siigo.queue', 'siigo'));
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
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
            (new WithoutOverlapping("siigo:devprov:{$this->devolucionId}"))->releaseAfter(60)->expireAfter(180),
        ];
    }

    public static function dispatchManual(int $id): void
    {
        $job = new static($id);
        $job->manual = true;
        dispatch($job);
    }

    public function handle(SiigoClient $client): void
    {
        if (! $this->manual && ! SiigoConfig::pushAutoActivo()) {
            $this->log('omitido', 0, "[kill-switch] dev={$this->devolucionId}");
            return;
        }

        $d = DevolucionProveedor::with(['proveedor', 'ubicacion', 'items.variante', 'items.producto'])
            ->find($this->devolucionId);
        if (! $d) { $this->log('omitido', 0, "[not-found] dev={$this->devolucionId}"); return; }
        if ($d->siigo_id) { $this->log('omitido', 0, "[idempotente] dev {$d->id} ya tiene siigo_id={$d->siigo_id}"); return; }
        if ($d->estado !== 'confirmada') { $this->log('omitido', 0, "[estado] dev {$d->id} está {$d->estado}"); return; }

        // Lee los asientos que el Action ya escribió (partida doble).
        $movs = MovimientoContable::where('origen_type', DevolucionProveedor::class)
            ->where('origen_id', $d->id)
            ->orderBy('id')
            ->get();
        if ($movs->isEmpty()) {
            $this->log('omitido', 0, "[sin-movimientos] dev {$d->id} confirmada pero sin MovimientoContable");
            return;
        }

        // Validación de partida doble antes de pedirle nada a SIIGO.
        $totalDebe  = round((float) $movs->sum('debe'), 2);
        $totalHaber = round((float) $movs->sum('haber'), 2);
        if (abs($totalDebe - $totalHaber) > 0.01) {
            throw new RuntimeException(
                "Asiento desbalanceado en DEV {$d->numero}: debe={$totalDebe} haber={$totalHaber}"
            );
        }

        // CONT-C5 · Fail-fast: valida que todas las cuentas PUC usadas tengan
        // una auxiliar transaccional resolvible. Sin esto, SIIGO responde
        // `account_not_allowed` después de 3-5 round-trips (auth + settings),
        // consume rate limit y los errores quedan ocultos en logs técnicos.
        $validador = app(\App\Modules\Siigo\Support\ValidadorMapeoPucSiigo::class);
        $diag = $validador->validarCuentas($movs->pluck('cuenta_puc')->all());
        if (! $diag['ok']) {
            $msgs = array_map(fn ($f) => "{$f['puc']} ({$f['mensaje']})", $diag['faltantes']);
            throw new RuntimeException(
                "DEV {$d->numero}: cuentas PUC sin mapeo SIIGO · ".implode(' · ', $msgs)
                .' · Revisar en /app/contabilidad/validacion-puc-siigo'
            );
        }

        // SIIGO exige `customer.identification` por línea en journals · usamos
        // el proveedor de la devolución como tercero de todas las líneas
        // (es el único tercero involucrado: la operación entera es contra él).
        $nitProveedor = (string) ($d->proveedor?->numero_documento ?? '');
        if ($nitProveedor === '') {
            throw new RuntimeException("DEV {$d->numero}: proveedor sin número de documento, no se puede pushear a SIIGO");
        }

        // SIIGO rechaza cuentas de agrupación: si el ERP escribió una cuenta
        // padre (p.ej. 2205, 1435, 2408), hay que mandar la auxiliar nivel 5+.
        //
        // Prioridad de resolución:
        //   1. Setting editable por la contadora en /app/empresa/reglas
        //      (siigo.cta_cxp_proveedor_siigo / cta_inventario_siigo /
        //       cta_iva_dev_compra_siigo) → fuente de verdad, lo cambia ella
        //      sin tocar código cuando el PUC real de la empresa cambia.
        //   2. Fallback: baja al primer `plan_cuentas.permite_movimiento=1`
        //      descendiente del PUC guardado en MovimientoContable.
        $overridesSettings = [
            '2205' => 'siigo.cta_cxp_proveedor_siigo',
            '1435' => 'siigo.cta_inventario_siigo',
            '2408' => 'siigo.cta_iva_dev_compra_siigo',
        ];
        // La traducción PUC local → código SIIGO la hace `CuentasSiigo`, que
        // aplica el mismo orden que el validador de la pantalla de diagnóstico:
        // mapeo explícito de la cuenta → override por setting → la propia si es
        // auxiliar → primera descendiente transaccional. Antes esta clase tenía
        // su propia copia de esa lógica y se saltaba `plan_cuentas.siigo_cuenta_id`,
        // así que lo que la contadora mapeaba no llegaba a los journals.
        $resolverCuenta = fn (string $puc): string => \App\Modules\Siigo\Support\CuentasSiigo::codigo($puc);

        $centroCostoId = (int) setting('siigo.centro_costo_siigo', 0);
        $ivaTaxId = (int) setting('siigo.tax_id_iva_19');
        $ivaPct = (float) setting('siigo.iva_porcentaje');

        try {
            $t0 = microtime(true);
            $items = [];
            foreach ($movs as $m) {
                $pucOrig = (string) $m->cuenta_puc;
                $value = round(($m->debe > 0 ? $m->debe : $m->haber), 2);
                $cuenta = $resolverCuenta($pucOrig);
                $movimiento = $m->debe > 0 ? 'Debit' : 'Credit';

                // La línea de inventario (1435*) la expandimos: SIIGO exige
                // product.{code, quantity} por línea de inventario. Una línea
                // ERP agregada → N líneas SIIGO, una por ítem de la devolución.
                if (str_starts_with($pucOrig, '1435')) {
                    foreach ($d->items as $it) {
                        $cant = (float) $it->cantidad;
                        $vLinea = round($cant * (float) $it->costo_unit, 2);
                        if ($vLinea <= 0) continue;
                        $v = $it->variante;
                        $p = $v?->producto ?? $it->producto;
                        $code = (string) ($v?->codigo_barras ?? $p?->referencia ?? "ITEM-{$it->id}");
                        $line = [
                            'account' => ['code' => CuentasSiigo::codigo($cuenta), 'movement' => $movimiento],
                            'customer' => ['identification' => $nitProveedor],
                            'product' => ['code' => $code, 'quantity' => $cant],
                            'value' => $vLinea,
                            'description' => mb_substr((string) ($p?->nombre ?? 'Devolución'), 0, 160),
                        ];
                        if ($centroCostoId > 0) $line['cost_center'] = $centroCostoId;
                        $items[] = $line;
                    }
                    continue;
                }

                $item = [
                    'account' => ['code' => CuentasSiigo::codigo($cuenta), 'movement' => $movimiento],
                    'customer' => ['identification' => $nitProveedor],
                    'value' => $value,
                    'description' => mb_substr((string) $m->descripcion, 0, 160),
                ];
                if ($centroCostoId > 0) $item['cost_center'] = $centroCostoId;
                // SIIGO exige `tax` en las líneas cuya cuenta está asociada a un impuesto
                // (PUC 2408*). Base = valor del impuesto / (porcentaje/100).
                if (str_starts_with($pucOrig, '2408') && $ivaTaxId > 0 && $ivaPct > 0) {
                    $item['tax'] = [
                        'id' => $ivaTaxId,
                        'base_value' => round($value / ($ivaPct / 100), 2),
                    ];
                }
                $items[] = $item;
            }

            $payload = [
                'date' => $d->fecha->format('Y-m-d'),
                'items' => $items,
                'observations' => "ERP DEV #{$d->numero} · {$d->proveedor?->nombre_completo} · {$d->motivo} · {$movs->count()} líneas",
            ];
            // document.id OBLIGATORIO en /v1/journals · guardamos ahí el id del
            // document-type SIIGO tipo "AC" (Ajustes de Cartera/Proveedores),
            // que es como SIIGO Nube registra las devoluciones a proveedor
            // (no hay un endpoint dedicado para NC de compra).
            $docId = (int) setting('siigo.doc_type_nc_compra', 0);
            if ($docId <= 0) {
                throw new RuntimeException(
                    'Falta configurar siigo.doc_type_nc_compra (ID del document-type AC en SIIGO). '
                    .'Ir a /app/empresa/reglas y setearlo con el id de "Ajustes de Cartera/Proveedores".'
                );
            }
            $payload['document'] = ['id' => $docId];

            // Idempotency: SIIGO devuelve el journal previo si repetimos la misma
            // clave en un reintento, en vez de duplicar el asiento.
            $resp = $client->request('POST', '/v1/journals', $payload, 1, ClaveIdempotencia::para('dev', $d));
            if (! $resp->ok()) {
                throw new RuntimeException("SIIGO rechazó journal: HTTP {$resp->status()} · ".mb_substr((string) $resp->body(), 0, 400));
            }
            $journalId = $resp->json('id');
            if (! $journalId) {
                throw new RuntimeException("SIIGO devolvió journal sin id · body: ".mb_substr((string) $resp->body(), 0, 300));
            }

            DB::transaction(function () use ($d, $journalId, $movs) {
                $d->forceFill(['siigo_id' => (string) $journalId, 'siigo_sync_at' => now()])->save();
                // Marca cada MovimientoContable con el journal creado · habilita
                // trazabilidad en Contabilidad/Reportes (siigo_journal_id).
                $movs->each(fn ($m) => $m->forceFill(['siigo_journal_id' => (string) $journalId])->save());
            });

            $ms = (int) round((microtime(true) - $t0) * 1000);
            $this->log('exitoso', $ms, "[OK] dev {$d->numero} → journal {$journalId} · {$movs->count()} líneas");
        } catch (SiigoRateLimitedException $e) {
            $delay = $e->retryAfter + random_int(0, min(10, (int) ($e->retryAfter * 0.2)));
            $this->release($delay);
        }
    }

    public function failed(\Throwable $e): void
    {
        SiigoSyncLog::create([
            'recurso' => 'devoluciones_proveedor', 'estado' => 'fallido',
            'nuevos' => 0, 'actualizados' => 0, 'errores' => 1, 'duracion_ms' => 0,
            'mensaje' => "[job-failed] dev={$this->devolucionId} · {$e->getMessage()}",
            'detalle' => ['devolucion_id' => $this->devolucionId, 'exception' => class_basename($e)],
            'user_id' => null,
        ]);
        Log::channel('siigo')->error('PushDevolucionProveedorASiigo agotó reintentos', [
            'devolucion_id' => $this->devolucionId, 'error' => $e->getMessage(),
        ]);
    }

    private function log(string $estado, int $ms, string $msg): void
    {
        SiigoSyncLog::create([
            'recurso' => 'devoluciones_proveedor', 'estado' => $estado,
            'nuevos' => $estado === 'exitoso' ? 1 : 0, 'actualizados' => 0,
            'errores' => 0, 'duracion_ms' => $ms, 'mensaje' => $msg,
            'detalle' => ['devolucion_id' => $this->devolucionId, 'manual' => $this->manual],
            'user_id' => null,
        ]);
    }
}
