<?php

namespace App\Modules\Siigo\Actions;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Models\SiigoSyncLog;
use App\Modules\Siigo\Support\ProductoPayloadBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * POST /v1/products · crea el producto (o cada variante si desglose_stock=true)
 * en Siigo Nube. Si ya tiene siigo_id, no hace nada (idempotencia mínima).
 *
 * Fixes auditoría:
 *   C4 · Cache::lock por producto para evitar race condition (2 requests
 *        concurrentes generarían dos POST + already_exists).
 *   A2 · duracion_ms real medida con microtime.
 *   A3 · Manejo de `already_exists`: recupera siigo_id via GET /v1/products?code=
 *        y linkea sin romper.
 *   A5 · desglose_stock null → default false explícito.
 *   A6 · setRelation('producto') antes de iterar variantes para evitar N+1.
 *   M5 · Guarda `response_body` completo en el log para debug.
 *   M7 · Valida que la respuesta traiga `id` antes de guardar.
 */
class CrearProductoEnSiigo
{
    use AsAction;

    public function __construct(
        private readonly SiigoClient $client,
        private readonly ProductoPayloadBuilder $builder,
    ) {}

    public function handle(Producto $producto): array
    {
        // B3-P1 · eager load `variantes.preciosVigentes` evita N+1 en granular.
        $producto->loadMissing([
            'variantes.preciosVigentes',
            'marca',
            'categoriaMaestra',
            'impuesto',
            'unidadMedida',
        ]);

        // RAÍZ · el modo (agregado vs granular) lo DECIDE el sistema según si
        // el producto tiene variantes, no una config que el usuario entiende.
        //   - Tiene variantes → se crean como N productos SIIGO independientes.
        //   - No tiene        → se crea 1 producto SIIGO con el padre.
        // El campo `desglose_stock` queda como preferencia interna del kardex
        // local pero NO decide qué pasa en SIIGO.
        $tieneVariantes = $producto->variantes->isNotEmpty();
        return $tieneVariantes
            ? $this->crearGranular($producto)
            : $this->crearAgregado($producto);
    }

    /**
     * Método público para crear UNA sola variante · usado por Actualizar
     * cuando encuentra variantes sin siigo_id. Evita reprocesar todo el
     * producto (fix C1).
     */
    public function crearVariante(ProductoVariante $v): array
    {
        $t0 = microtime(true);
        $v->loadMissing([
            'preciosVigentes',
            'producto.marca',
            'producto.categoriaMaestra',
            'producto.impuesto',
            'producto.unidadMedida',
        ]);
        return $this->postVariante($v, $t0);
    }

    private function esGranular(Producto $p): bool
    {
        // A5 · null → default false (config-driven).
        return is_null($p->desglose_stock)
            ? (bool) config('siigo.desglose_default', false)
            : (bool) $p->desglose_stock;
    }

    private function crearAgregado(Producto $p): array
    {
        $t0 = microtime(true);

        // C4 + B4-M7 · lock con try/catch de LockTimeoutException. Antes: si el
        // wait de 5s expiraba, el timeout burbujeaba al Job → gastaba `try` y
        // entraba al backoff (amplificación 5×). Ahora: skip benigno sin
        // consumir try (el próximo debounce reintenta).
        try {
            return Cache::lock("siigo:producto:{$p->id}", 30)->block(5, function () use ($p, $t0) {
                $p->refresh();
                if ($p->siigo_id) {
                    return ['skipped' => true, 'motivo' => 'ya existe siigo_id', 'siigo_id' => $p->siigo_id];
                }

            $payload = $this->builder->paraProducto($p);
            $response = $this->client->request('POST', '/v1/products', $payload);

            // A3 · manejo already_exists → reconciliar
            if ($response->failed() && $this->esAlreadyExists($response->json())) {
                $recovered = $this->recuperarPorCode($payload['code']);
                if ($recovered) {
                    $p->forceFill([
                        'siigo_id' => $recovered['id'],
                        'siigo_code' => $recovered['code'] ?? $payload['code'],
                        'siigo_sync_at' => now(),
                    ])->save();
                    $this->log($p, 'crear-agregado', 'reconciliado', 200, 'linkeado con siigo_id existente',
                        $payload, ['recovered' => $recovered], $t0);
                    return ['reconciliado' => true, 'siigo_id' => $p->siigo_id];
                }
            }

            if ($response->failed()) {
                $this->log($p, 'crear-agregado', 'fallido', $response->status(),
                    'POST /v1/products falló', $payload, $response->json(), $t0);
                throw new RuntimeException("SIIGO POST /v1/products falló · status {$response->status()} · producto {$p->referencia}");
            }

            $data = $response->json();
            // M7 · validar id
            if (! isset($data['id'])) {
                $this->log($p, 'crear-agregado', 'fallido', $response->status(),
                    'respuesta sin id', $payload, $data, $t0);
                throw new RuntimeException("SIIGO POST OK pero respuesta sin id · producto {$p->referencia}");
            }

            $p->forceFill([
                'siigo_id' => $data['id'],
                'siigo_code' => $data['code'] ?? $payload['code'],
                'siigo_sync_at' => now(),
            ])->save();

            $this->log($p, 'crear-agregado', 'exitoso', $response->status(), 'OK', $payload, $data, $t0);
                return ['creados' => 1, 'siigo_id' => $p->siigo_id, 'siigo_code' => $p->siigo_code];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::channel('siigo')->info('siigo:producto lock timeout · skip sin gastar try', [
                'producto_id' => $p->id, 'esperado' => 5,
            ]);
            return ['skipped' => true, 'motivo' => 'lock timeout · otro job maneja este producto'];
        }
    }

    private function crearGranular(Producto $p): array
    {
        $t0 = microtime(true);
        $creados = 0; $errores = 0; $skipped = 0; $reconciliados = 0;
        $detalle = [];

        foreach ($p->variantes as $v) {
            if ($v->siigo_id) { $skipped++; continue; }
            // A6 · pre-cargar relación producto para evitar N+1
            $v->setRelation('producto', $p);

            $r = $this->postVariante($v, microtime(true));
            if (! empty($r['siigo_id'])) {
                if (! empty($r['reconciliado'])) $reconciliados++;
                else $creados++;
                $detalle[] = ['variante' => $v->codigo_barras, 'siigo_id' => $r['siigo_id']];
            } else {
                $errores++;
                $detalle[] = ['variante' => $v->codigo_barras, 'error' => $r['error'] ?? 'desconocido'];
            }
        }

        // El padre también, para poder facturarlo. Ver crearPadreFacturable().
        $padre = $this->crearPadreFacturable($p);
        if (! empty($padre['siigo_id'])) {
            $creados++;
        } elseif (! empty($padre['error'])) {
            $errores++;
        }
        $detalle['padre'] = $padre;

        $this->log($p, 'crear-granular', $errores === 0 ? 'exitoso' : 'parcial', 200,
            "creados={$creados} reconciliados={$reconciliados} errores={$errores} skipped={$skipped}",
            ['modo' => 'granular'], ['variantes' => $detalle], $t0);

        return compact('creados', 'reconciliados', 'errores', 'skipped', 'detalle');
    }

    /**
     * El producto padre de un granular, creado en SIIGO sólo para facturarlo.
     *
     * Hasta el 2026-11-05 un producto con variantes ponía en SIIGO SÓLO sus
     * variantes, nunca el padre. Y la factura B2B vende el padre: 19 de las 21
     * líneas del sistema traen `producto_id` y `variante_id` en NULL, porque
     * se vende «Set bodys x5 · pack niña» como unidad y la talla es asunto de
     * bodega.
     *
     * Resultado: el emisor buscaba `producto->siigo_id`, lo encontraba vacío y
     * tiraba «el producto todavía no está en SIIGO: sincronizalo». Pero ya
     * estaba — como variantes. Sincronizar de nuevo no arreglaba nada y la
     * factura no salía NUNCA. En desarrollo eran los 28 productos activos sin
     * `siigo_id`, los 28 con sus variantes ya allá.
     *
     * Se crea con `stock_control = false` a propósito: el inventario lo
     * llevan las variantes, y si el padre también lo controlara, SIIGO
     * contaría dos veces la misma mercancía. El padre existe allá para que
     * haya qué facturar, no para tener saldo.
     *
     * @return array{siigo_id?:string,error?:string,skipped?:bool}
     */
    private function crearPadreFacturable(Producto $p): array
    {
        $p->refresh();
        if ($p->siigo_id) {
            return ['skipped' => true, 'siigo_id' => $p->siigo_id];
        }

        try {
            $payload = $this->builder->paraProducto($p);
            $payload['stock_control'] = false;

            $response = $this->client->request('POST', '/v1/products', $payload);

            // Si ya existía con ese code, se adopta en vez de duplicar.
            if ($response->failed() && $this->esAlreadyExists($response->json())) {
                // Devuelve ['id'=>…,'code'=>…], y sólo si el match fue único.
                $recuperado = $this->recuperarPorCode($payload['code'])['id'] ?? null;
                if ($recuperado) {
                    $p->forceFill(['siigo_id' => $recuperado])->saveQuietly();

                    return ['siigo_id' => $recuperado, 'reconciliado' => true];
                }
            }

            if ($response->failed()) {
                return ['error' => 'HTTP '.$response->status().' '.mb_substr((string) $response->body(), 0, 160)];
            }

            $siigoId = $response->json('id');
            if (! $siigoId) {
                return ['error' => 'SIIGO respondió sin id'];
            }

            $p->forceFill(['siigo_id' => $siigoId])->saveQuietly();

            return ['siigo_id' => $siigoId];
        } catch (\Throwable $e) {
            return ['error' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /**
     * POST de una variante con lock + reconciliación + logs completos.
     */
    private function postVariante(ProductoVariante $v, float $t0): array
    {
        try {
            return Cache::lock("siigo:variante:{$v->id}", 30)->block(5, function () use ($v, $t0) {
            $v->refresh();
            if ($v->siigo_id) {
                return ['skipped' => true, 'siigo_id' => $v->siigo_id];
            }

            try {
                $payload = $this->builder->paraVariante($v);
            } catch (\Throwable $e) {
                $this->log($v->producto, 'crear-variante', 'fallido', 0,
                    'payload builder: '.$e->getMessage(), [], null, $t0, $v->id);
                return ['error' => $e->getMessage()];
            }

            $response = $this->client->request('POST', '/v1/products', $payload);

            // A3 · already_exists → reconciliar
            if ($response->failed() && $this->esAlreadyExists($response->json())) {
                $rec = $this->recuperarPorCode($payload['code']);
                if ($rec) {
                    $v->forceFill([
                        'siigo_id' => $rec['id'],
                        'siigo_code' => $rec['code'] ?? $payload['code'],
                        'siigo_sync_at' => now(),
                    ])->save();
                    $this->log($v->producto, 'crear-variante', 'reconciliado', 200,
                        'linkeado con siigo_id existente', $payload, $rec, $t0, $v->id);
                    return ['reconciliado' => true, 'siigo_id' => $v->siigo_id];
                }
            }

            if ($response->failed()) {
                $this->log($v->producto, 'crear-variante', 'fallido', $response->status(),
                    'POST /v1/products falló', $payload, $response->json(), $t0, $v->id);
                return ['error' => "http {$response->status()}"];
            }

            $data = $response->json();
            if (! isset($data['id'])) {
                $this->log($v->producto, 'crear-variante', 'fallido', $response->status(),
                    'respuesta sin id', $payload, $data, $t0, $v->id);
                return ['error' => 'respuesta sin id'];
            }

            $v->forceFill([
                'siigo_id' => $data['id'],
                'siigo_code' => $data['code'] ?? $payload['code'],
                'siigo_sync_at' => now(),
            ])->save();

            $this->log($v->producto, 'crear-variante', 'exitoso', $response->status(), 'OK',
                $payload, $data, $t0, $v->id);

                return ['siigo_id' => $v->siigo_id, 'siigo_code' => $v->siigo_code];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::channel('siigo')->info('siigo:variante lock timeout · skip', [
                'variante_id' => $v->id, 'esperado' => 5,
            ]);
            return ['error' => 'lock timeout · otro job maneja esta variante'];
        }
    }

    private function esAlreadyExists(?array $body): bool
    {
        if (! $body) return false;
        $errores = $body['Errors'] ?? [];
        foreach ($errores as $e) {
            if (($e['Code'] ?? null) === 'already_exists') return true;
        }
        return false;
    }

    /**
     * A3 · busca en SIIGO un producto por code (para reconciliar tras already_exists).
     * Retorna [id, code] o null.
     *
     * B2-A1 · MATCH EXACTO: SIIGO puede hacer prefix/fuzzy match en el filtro
     * `code=`. Si tomamos ciegamente `results[0]` podemos linkear el `siigo_id`
     * al producto EQUIVOCADO (corrupción de datos). Ahora filtramos en PHP
     * por match exacto y solo aceptamos si queda EXACTAMENTE 1 resultado.
     */
    private function recuperarPorCode(string $code): ?array
    {
        try {
            $resp = $this->client->request('GET', '/v1/products', ['code' => $code]);
            if ($resp->failed()) return null;
            $data = $resp->json();
            $results = $data['results'] ?? (isset($data['id']) ? [$data] : []);
            if (empty($results)) return null;

            $exactos = array_values(array_filter(
                $results,
                fn ($r) => isset($r['code']) && (string) $r['code'] === $code,
            ));

            if (count($exactos) !== 1) {
                Log::channel('siigo')->warning('recuperarPorCode · match no único · abortando reconciliación', [
                    'code' => $code,
                    'total_results' => count($results),
                    'exactos' => count($exactos),
                ]);
                return null;
            }

            return ['id' => $exactos[0]['id'] ?? null, 'code' => $exactos[0]['code']];
        } catch (\Throwable $e) {
            Log::channel('siigo')->warning('recuperarPorCode falló', ['code' => $code, 'err' => $e->getMessage()]);
            return null;
        }
    }

    private function log(Producto $p, string $accion, string $estado, int $httpStatus, string $mensaje,
                          array $payload = [], ?array $responseBody = null, ?float $t0 = null, ?int $varianteId = null): void
    {
        SiigoSyncLog::create([
            'recurso' => 'productos',
            'estado' => $estado,
            'nuevos' => in_array($estado, ['exitoso', 'reconciliado'], true) ? 1 : 0,
            'actualizados' => 0,
            'errores' => $estado === 'fallido' ? 1 : 0,
            'duracion_ms' => $t0 ? (int) round((microtime(true) - $t0) * 1000) : 0,
            'mensaje' => "[{$accion}] p={$p->referencia}".($varianteId ? " v={$varianteId}" : '')." http={$httpStatus} · {$mensaje}",
            'detalle' => [
                'accion' => $accion,
                'producto_id' => $p->id,
                'variante_id' => $varianteId,
                'http_status' => $httpStatus,
                'payload' => $payload,
                // B4-M3 · sanitizar antes de persistir (antes iba raw a BD).
                'response_body' => $this->client->sanitizarRespuestaArray($responseBody),
            ],
            'user_id' => auth()->id(),
        ]);
    }
}
