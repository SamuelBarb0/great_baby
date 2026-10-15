<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Siigo\Jobs\PushProductoASiigo;
use App\Modules\Siigo\Jobs\ReconciliarProductosDesdeSiigo;
use Illuminate\Support\Facades\Cache;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F8 · Panel Vue "CONFIGURACIÓN → SIIGO" en /app/siigo.
 * Reutiliza SiigoConfig, SiigoSyncLog y PushProductoASiigo existentes · sin
 * crear nuevos modelos ni tablas. Todo el estado (kill-switch, KPIs, cola,
 * fallidos) sale de las tablas siigo_sync_log + siigo_config + jobs.
 */
class SiigoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // F8 · ampliado: antes solo Aracely · ahora Aracely + Gerente
                // (Gerente puede monitorear pero solo Aracely toggleaKillSwitch).
                $u = $r->user();
                abort_unless(
                    $u?->esAracely() || $u?->hasAnyRole(['Gerente', 'Gerencia']),
                    403
                );
                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        $cfg = SiigoConfig::current();
        $ultimosLogs = SiigoSyncLog::query()->orderByDesc('id')->limit(20)->get();

        return Inertia::render('Siigo/Index', [
            'config' => [
                'id' => $cfg->id,
                'ambiente' => $cfg->ambiente,
                'activo' => (bool) $cfg->activo,
                'usuario' => $cfg->username,
                'partner_id' => $cfg->partner_id,
                'push_auto' => SiigoConfig::pushAutoActivo(),
                'push_auto_source' => $cfg->push_auto !== null ? 'ui' : 'env',
                'push_auto_updated_at' => $cfg->push_auto_updated_at?->diffForHumans(),
                'sync_productos_at' => $cfg->sync_productos_at?->diffForHumans(),
                'sync_clientes_at' => $cfg->sync_clientes_at?->diffForHumans(),
                'sync_catalogos_at' => $cfg->sync_catalogos_at?->diffForHumans(),
                // Estado REAL de la conexión. La casilla `activo` sólo dice que
                // alguien prendió el interruptor: con la llave vencida el panel
                // se veía verde mientras cada llamada moría con 401.
                // La access_key no sale nunca al navegador, sólo si existe.
                'tiene_access_key' => ! empty($cfg->access_key),
                'ultimo_auth_ok' => $cfg->ultimo_auth_ok,
                'ultimo_auth_at' => $cfg->ultimo_auth_at?->diffForHumans(),
                'ultimo_auth_error' => $cfg->ultimo_auth_error,
            ],
            'kpis' => $this->kpis(),
            'cola' => $this->cola(),
            // UBIC-7 · estado del setup de Facturación Electrónica para el wizard.
            'setup_fe' => $this->estadoSetupFacturacionElectronica(),
            // Las cuentas PUC configuradas, contrastadas con el plan real.
            'cuentas_puc' => $this->estadoCuentasPuc(),
            'fallidos_recientes' => $this->fallidosRecientes(),
            // PROD-15 · productos con falla permanente (≥3 intentos fallidos).
            'fallas_permanentes' => $this->fallasPermanentes(),
            // INV-A3 · movimientos kardex contables sin asiento SIIGO · da
            // visibilidad del "stock" de pendientes para que Aracely vea de un
            // vistazo si hay backlog de asientos sin empujar.
            'movs_pendientes_siigo' => $this->movsPendientesSiigo(),
            'logs' => $ultimosLogs->map(fn ($l) => $this->serializarLog($l))->all(),
            'puede_toggle' => (bool) auth()->user()?->esAracely(),
            'reconciliar' => $this->estadoReconciliarVista(),
        ]);
    }

    /**
     * Credenciales de SIIGO · usuario, access key, Partner-Id y ambiente.
     *
     * Vivía sólo en la pantalla de Filament. Al dejar `/admin` para Dropi, sin
     * esto Aracely no tenía dónde pegar una llave nueva cuando SIIGO la rota,
     * y con la llave vencida el ERP deja de mandar todo en silencio.
     *
     * La access key se guarda encriptada (mutador del modelo) y nunca vuelve al
     * navegador: dejarla vacía conserva la actual.
     */
    public function guardarCredenciales(Request $r): RedirectResponse
    {
        abort_unless($r->user()?->esAracely(), 403, 'Sólo gerencia cambia las credenciales de SIIGO.');

        $datos = $r->validate([
            'usuario' => ['required', 'string', 'max:190'],
            'access_key' => ['nullable', 'string', 'max:500'],
            'partner_id' => ['nullable', 'string', 'max:120'],
            'ambiente' => ['required', 'in:sandbox,produccion'],
        ]);

        $cfg = SiigoConfig::current();
        $cfg->username = $datos['usuario'];
        $cfg->partner_id = $datos['partner_id'] ?: null;
        $cfg->ambiente = $datos['ambiente'];

        if (! empty($datos['access_key'])) {
            $cfg->access_key = $datos['access_key'];
        }

        // Cambió la credencial: el token viejo y el último resultado ya no
        // valen, y el estado vuelve a "sin probar" hasta que alguien pruebe.
        $cfg->forceFill([
            'token_cache' => null,
            'token_expires_at' => null,
            'ultimo_auth_ok' => null,
            'ultimo_auth_at' => null,
            'ultimo_auth_error' => null,
        ])->save();

        return back()->with('success', 'Credenciales guardadas. Probá la conexión para confirmar que SIIGO responde.');
    }

    /**
     * Prueba la autenticación contra SIIGO y deja el resultado a la vista.
     * `SiigoClient::authenticate()` escribe `ultimo_auth_*`, así que después de
     * esto la tarjeta de estado muestra lo que de verdad contestó SIIGO.
     */
    public function probarConexion(Request $r): RedirectResponse
    {
        abort_unless($r->user()?->esAracely(), 403);

        $cfg = SiigoConfig::current();
        $cfg->forceFill(['token_cache' => null, 'token_expires_at' => null])->save();

        try {
            app(\App\Modules\Siigo\Clients\SiigoClient::class)->authenticate();

            /*
             * Una conexión que responde ES la prueba de que está configurado.
             *
             * `siigo_config.activo` es la puerta que miran TODOS los jobs de
             * push: con ella en false salen en 6 ms sin llamar a nada. Y no
             * había una sola pantalla que la prendiera —el interruptor del
             * panel vive dentro de un `v-if="config.activo"`, o sea que el
             * botón para encenderlo estaba escondido porque estaba apagado—.
             *
             * El resultado, comprobado el 2026-10-14: Aracely pega usuario,
             * llave y Partner ID, prueba la conexión, le dice «la integración
             * está viva»… y el panel sigue mostrando «SIIGO aún no
             * configurado» mientras 71 documentos esperan en la cola sin que
             * nadie los mande.
             */
            $recienActivada = ! $cfg->activo;
            if ($recienActivada) {
                $cfg->forceFill(['activo' => true])->save();
                SiigoConfig::invalidarPushAutoCache();
            }

            return back()->with('success',
                ($recienActivada ? 'Integración ACTIVADA · ' : '')
                .'SIIGO respondió correctamente: la integración está viva. '
                .$this->reencolarPendientes()
                .($cfg->push_auto ? '' : ' OJO: el push automático está en pausa — prendelo abajo para que salgan solos.'));
        } catch (\Throwable $t) {
            return back()->with('error', 'SIIGO no aceptó las credenciales · '.$t->getMessage());
        }
    }

    /**
     * Apenas la llave vuelve a servir, se reencola lo que quedó pendiente.
     *
     * Sin esto había que esperar a la pasada de las 04:15. Y pasó de verdad:
     * la llave del sandbox se cayó la tarde del 2026-10-09, los 29 trabajos
     * encolados aguantaron sus 12 horas de `retryUntil` esperándola, se
     * vencieron de madrugada y a la mañana siguiente el ERP seguía sin mandar
     * nada aunque la credencial ya estuviera arreglada.
     *
     * No se pierde trabajo por eso —`siigo:empujar-pendientes` barre las
     * tablas y encuentra lo que no tiene `siigo_id`—, pero sí se pierde un día.
     * Acá se dispara ese mismo barrido en el momento en que alguien prueba la
     * conexión con éxito, que es justo cuando Aracely acaba de pegar la llave.
     */
    private function reencolarPendientes(): string
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('siigo:empujar-pendientes');
            $salida = \Illuminate\Support\Facades\Artisan::output();

            if (preg_match('/(\d+) documentos encolados/', $salida, $m) && (int) $m[1] > 0) {
                return "Se reencolaron {$m[1]} documento(s) que estaban esperando.";
            }

            return 'No había nada pendiente por reenviar.';
        } catch (\Throwable $e) {
            // Que un fallo acá no tumbe el mensaje de «conexión OK», que es la
            // información que la persona vino a buscar.
            \Illuminate\Support\Facades\Log::channel('siigo')
                ->warning('No se pudo reencolar tras probar la conexión', ['error' => $e->getMessage()]);

            return 'Revisá los pendientes a mano: el reenvío automático falló.';
        }
    }

    /**
     * F8 · toggle del kill-switch push_auto. Solo Aracely.
     * Persiste en siigo_config.push_auto y limpia cache.
     */
    public function toggleKillSwitch(Request $r): RedirectResponse
    {
        abort_unless($r->user()?->esAracely(), 403);

        $r->validate(['activo' => 'required|boolean']);

        $cfg = SiigoConfig::current();
        $cfg->forceFill([
            'push_auto' => (bool) $r->boolean('activo'),
            'push_auto_updated_at' => now(),
            'push_auto_updated_by' => $r->user()->id,
        ])->save();

        SiigoConfig::invalidarPushAutoCache();

        return back()->with('flash', [
            'type' => 'success',
            'message' => $cfg->push_auto ? 'Sync automático ENCENDIDO ✅' : 'Sync automático APAGADO ⏸',
        ]);
    }

    /**
     * F8 · reintentar un job fallido. Toma el producto_id del log.detalle y
     * dispara dispatchManual (bypasea kill-switch y debounce).
     */
    public function reintentar(SiigoSyncLog $log, Request $r): RedirectResponse
    {
        $productoId = (int) ($log->detalle['producto_id'] ?? 0);
        $accion = (string) ($log->detalle['accion'] ?? 'actualizar');

        if (! $productoId) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => 'Log sin producto_id · no se puede reintentar.',
            ]);
        }

        // Recuperar siigoId por si el producto ya no existe (hard delete recovery).
        $siigoId = Producto::withTrashed()->where('id', $productoId)->value('siigo_id');

        PushProductoASiigo::dispatchManual($productoId, $accion, $siigoId);

        return back()->with('flash', [
            'type' => 'success',
            'message' => "Sync manual encolado · producto {$productoId} · {$accion}",
        ]);
    }

    /**
     * Sincronizar catálogos de SIIGO al ERP (taxes, account-groups, warehouses,
     * price-lists, document-types, payment-types) + propagar siigo_id a las
     * tablas locales (impuestos, categorias, listas_precios).
     *
     * Esto es la base para que los selectores del form de productos tengan
     * opciones reales de SIIGO en vez de datos inventados localmente.
     */
    public function sincronizarCatalogos(): JsonResponse
    {
        try {
            $svc = new \App\Modules\Siigo\Services\SiigoService(
                new \App\Modules\Siigo\Clients\SiigoClient(SiigoConfig::current())
            );
            $r = $svc->sincronizarCatalogos();
            return response()->json(['ok' => true, 'resumen' => $r]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 500);
        }
    }

    /**
     * Catálogo /v1/document-types agrupado por type · lo consumen los selectores
     * de settings doc_type_* en el panel Reglas. Devuelve solo activos, ordenados
     * por nombre, en el formato que Vue necesita para `<select>`.
     */
    public function documentTypes(Request $r): JsonResponse
    {
        $soloActivos = ! $r->boolean('incluir_inactivos', false);
        $q = \App\Modules\Siigo\Models\SiigoDocumentType::query();
        if ($soloActivos) $q->where('active', true);
        $rows = $q->orderBy('type')->orderBy('name')
            ->get(['type', 'siigo_id', 'code', 'name', 'active'])
            ->groupBy('type')
            ->map(fn ($g) => $g->map(fn ($x) => [
                'id' => $x->siigo_id,
                'code' => $x->code,
                'name' => $x->name,
                'label' => "{$x->siigo_id} · {$x->code} · {$x->name}",
            ])->values());
        $synced = \App\Modules\Siigo\Models\SiigoDocumentType::max('synced_at');
        return response()->json(['grupos' => $rows, 'ultima_sync' => $synced]);
    }

    /**
     * Dispara `siigo:sync-document-types`. Lo exponemos por POST para que el
     * botón "Actualizar desde SIIGO" del panel Reglas lo refresque sin usar CLI.
     */
    public function documentTypesSync(): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('siigo:sync-document-types');
            $total = \App\Modules\Siigo\Models\SiigoDocumentType::count();
            return response()->json(['ok' => true, 'total' => $total, 'output' => \Illuminate\Support\Facades\Artisan::output()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 500);
        }
    }

    /**
     * Catálogo /v1/taxes agrupado por tipo · lo consumen los selectores de
     * settings tax_id_iva_19, tax_id_retefuente, etc. en el panel Reglas.
     */
    public function taxes(Request $r): JsonResponse
    {
        $soloActivos = ! $r->boolean('incluir_inactivos', false);
        $q = \App\Modules\Siigo\Models\SiigoTax::query();
        if ($soloActivos) $q->where('active', true);
        $rows = $q->orderBy('type')->orderBy('percentage', 'desc')->orderBy('name')
            ->get(['siigo_id', 'type', 'name', 'percentage', 'active'])
            ->groupBy('type')
            ->map(fn ($g) => $g->map(fn ($x) => [
                'id' => $x->siigo_id,
                'name' => $x->name,
                'percentage' => $x->percentage,
                'label' => "{$x->siigo_id} · {$x->name} ({$x->percentage}%)",
            ])->values());
        $synced = \App\Modules\Siigo\Models\SiigoTax::max('synced_at');
        return response()->json(['grupos' => $rows, 'ultima_sync' => $synced]);
    }

    public function taxesSync(): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('siigo:sync-taxes');
            $total = \App\Modules\Siigo\Models\SiigoTax::count();
            return response()->json(['ok' => true, 'total' => $total, 'output' => \Illuminate\Support\Facades\Artisan::output()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 500);
        }
    }

    /**
     * Importar UN producto puntual desde SIIGO al ERP por su `code`.
     * Útil para sandbox compartido (evita traer basura del tenant) y
     * para cuando el usuario quiere traer un producto específico.
     * Si ya existe en el ERP (match por siigo_id o referencia=code), lo actualiza.
     */
    public function importarPorCode(Request $r): JsonResponse
    {
        $code = trim((string) $r->input('code'));
        if ($code === '') {
            return response()->json(['ok' => false, 'mensaje' => 'Falta el code'], 422);
        }
        $client = new \App\Modules\Siigo\Clients\SiigoClient(SiigoConfig::current());
        $resp = $client->request('GET', '/v1/products', ['code' => $code]);
        if ($resp->failed()) {
            return response()->json(['ok' => false, 'mensaje' => 'SIIGO HTTP '.$resp->status()], 502);
        }
        $items = $resp->json('results') ?? [];
        if (empty($items)) {
            return response()->json(['ok' => false, 'mensaje' => "SIIGO no tiene ningún producto con code={$code}"]);
        }
        $svc = new \App\Modules\Siigo\Services\SiigoService($client);
        $metodo = (new \ReflectionClass($svc))->getMethod('guardarProducto');
        $metodo->setAccessible(true);
        $nuevos = 0; $actualizados = 0;
        foreach ($items as $p) {
            $existente = \App\Modules\Dropi\Models\Producto::where('siigo_id', $p['id'])
                ->orWhere('referencia', $p['code'] ?? null)->first();
            $creado = $metodo->invoke($svc, $p, $existente);
            $creado ? $nuevos++ : $actualizados++;
        }
        return response()->json([
            'ok' => true,
            'resumen' => compact('nuevos', 'actualizados'),
            'mensaje' => "+{$nuevos} nuevos · {$actualizados} actualizados · total {$this->totalErp()}",
        ]);
    }

    private function totalErp(): int
    {
        return \App\Modules\Dropi\Models\Producto::count();
    }

    /**
     * Reconciliación bajo demanda · dispara el pull completo SIIGO→ERP
     * con detección de zombies. Devuelve resumen para toast en el panel.
     */
    /**
     * B2/B3 · Despacha reconciliación INCREMENTAL (modo seguro) a la cola.
     *
     * Trae solo lo que cambió en SIIGO desde siigo_config.sync_productos_at
     * (típicamente <30 seg). El pull completo con detección de zombies
     * NO está expuesto en la UI · se corre con
     *     php artisan siigo:reconciliar --full --confirmar
     * para evitar accidentes como traer 20k productos del sandbox compartido.
     */
    public function reconciliar(): JsonResponse
    {
        $estado = Cache::get(ReconciliarProductosDesdeSiigo::CACHE_KEY);
        if (($estado['estado'] ?? null) === 'corriendo') {
            return response()->json([
                'ok' => true,
                'yaCorriendo' => true,
                'mensaje' => 'Ya hay una sincronización en curso · espera a que termine.',
                'inicio' => $estado['inicio'] ?? null,
            ]);
        }

        ReconciliarProductosDesdeSiigo::dispatch(full: false)->onQueue('siigo');

        $desde = SiigoConfig::current()->sync_productos_at;
        Cache::put(ReconciliarProductosDesdeSiigo::CACHE_KEY, [
            'estado' => 'corriendo',
            'modo' => 'incremental',
            'inicio' => now()->toIso8601String(),
            'desde' => $desde?->toIso8601String(),
            'resumen' => null,
            'error' => null,
        ], 3600);

        return response()->json([
            'ok' => true,
            'encolado' => true,
            'modo' => 'incremental',
            'desde' => $desde?->toIso8601String(),
            'mensaje' => $desde
                ? "Trayendo cambios desde {$desde->diffForHumans()}…"
                : 'Primera sincronización · trayendo últimos 7 días…',
        ]);
    }

    /** Polling · consulta estado de la última reconciliación. */
    /**
     * FASE E · Semáforo ligero · consulta rápida para el header global.
     * Retorna {color, label, pendientes, errores24h, push_auto}.
     */
    public function semaforo(): JsonResponse
    {
        $pendientes = 0;
        try {
            $pendientes = (int) \DB::table('jobs')
                ->where('queue', config('siigo.queue', 'siigo'))
                ->count();
        } catch (\Throwable) {}

        $errores24h = 0;
        try {
            $errores24h = (int) \App\Modules\Siigo\Models\SiigoSyncLog::query()
                ->where('estado', 'fallido')
                ->where('created_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable) {}

        $pushAuto = SiigoConfig::pushAutoActivo();

        [$color, $label] = match (true) {
            ! $pushAuto             => ['amber', 'Push automático pausado'],
            $errores24h > 0          => ['red',   "{$errores24h} errores 24h"],
            $pendientes > 20         => ['amber', "{$pendientes} en cola"],
            default                  => ['emerald','Al día'],
        };

        return response()->json([
            'color' => $color,
            'label' => $label,
            'pendientes' => $pendientes,
            'errores24h' => $errores24h,
            'push_auto' => $pushAuto,
        ]);
    }

    public function reconciliarEstado(): JsonResponse
    {
        $e = Cache::get(ReconciliarProductosDesdeSiigo::CACHE_KEY, [
            'estado' => 'idle',
            'resumen' => null,
            'error' => null,
        ]);
        return response()->json($e);
    }

    /**
     * A2 · Deshacer la última reconciliación · soft-delete de todos los
     * productos SIIGO traídos en la ventana (inicio..fin) de la última
     * corrida. Protege productos con movimientos, variantes o editados
     * manualmente. Reutiliza el comando siigo:limpiar-sandbox.
     */
    public function deshacerUltimaReconciliacion(Request $req): JsonResponse
    {
        abort_unless($req->user()?->esAracely(), 403, 'Solo Aracely puede deshacer sincs.');

        $estado = Cache::get(ReconciliarProductosDesdeSiigo::CACHE_KEY);
        if (! $estado || ($estado['estado'] ?? null) !== 'completado'
            || empty($estado['inicio']) || empty($estado['fin'])) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'No hay reconciliación completada en el cache para deshacer.',
            ], 400);
        }

        $confirmar = (bool) $req->boolean('confirmar');

        $exit = \Artisan::call('siigo:limpiar-sandbox', [
            '--desde' => $estado['inicio'],
            '--hasta' => $estado['fin'],
            '--confirmar' => $confirmar,
        ]);
        $salida = \Artisan::output();

        return response()->json([
            'ok' => $exit === 0,
            'confirmado' => $confirmar,
            'ventana' => ['desde' => $estado['inicio'], 'fin' => $estado['fin']],
            'salida' => $salida,
        ]);
    }

    /**
     * Visor en vivo · hace GET /v1/products/{uuid} contra SIIGO sandbox para que
     * el usuario vea en el mismo preview lo que SIIGO tiene (verificación
     * bidireccional del CRUD, sin salir del ERP ni usar Postman).
     * Si el producto es granular, trae la 1ra variante que tenga siigo_id.
     */
    public function verificarProducto(Producto $producto): JsonResponse
    {
        $producto->loadMissing('variantes');
        $siigoId = $producto->siigo_id;
        $siigoCode = $producto->siigo_code;
        $origen = 'producto';
        if (! $siigoId) {
            $v = $producto->variantes->firstWhere(fn ($x) => (bool) $x->siigo_id);
            if ($v) { $siigoId = $v->siigo_id; $siigoCode = $v->siigo_code; $origen = "variante #{$v->id}"; }
        }
        if (! $siigoId) {
            return response()->json([
                'ok' => false,
                'motivo' => 'Este producto no tiene siigo_id · nunca se sincronizó',
                'producto' => ['id' => $producto->id, 'referencia' => $producto->referencia, 'nombre' => $producto->nombre],
            ]);
        }
        $client = new \App\Modules\Siigo\Clients\SiigoClient(SiigoConfig::current());
        $r = $client->request('GET', "/v1/products/{$siigoId}");
        $en_variantes = $producto->variantes->map(fn ($v) => [
            'id' => $v->id, 'siigo_id' => $v->siigo_id, 'siigo_code' => $v->siigo_code,
            'color' => $v->color_nombre, 'talla' => $v->talla, 'sync_at' => $v->siigo_sync_at,
        ])->values();
        return response()->json([
            'ok' => $r->ok(),
            'http' => $r->status(),
            'origen_consulta' => $origen,
            'siigo_id_consultado' => $siigoId,
            'siigo_code' => $siigoCode,
            'respuesta_siigo' => $r->json(),
            'producto_local' => [
                'id' => $producto->id,
                'referencia' => $producto->referencia,
                'nombre' => $producto->nombre,
                'siigo_id' => $producto->siigo_id,
                'siigo_sync_at' => $producto->siigo_sync_at,
                'variantes' => $en_variantes,
            ],
        ]);
    }

    /**
     * PROD-14 · Página de discrepancias ERP↔SIIGO.
     * Compara en bulk todos los productos locales con siigo_id contra los
     * datos vivos de SIIGO. Para no reventar el rate limit con N llamadas
     * individuales, usa el GET /v1/products paginado (25/página) y hace el
     * match en memoria por siigo_id. Si hay 134 productos, cuesta ~6 páginas.
     */
    public function discrepancias(Request $r): Response
    {
        return Inertia::render('Siigo/Discrepancias', [
            'generado_at' => null,  // se completa cuando el user clickea "Calcular"
        ]);
    }

    /**
     * PROD-14 · endpoint JSON que de verdad calcula las diferencias.
     * Lo llama el front cuando el usuario pide "Calcular ahora". Lo separo
     * de la ruta Inertia para no bloquear el primer paint con 6 llamadas HTTP
     * a SIIGO (que pueden tardar 10-15s con 500 productos).
     */
    public function discrepanciasCalcular(Request $r): JsonResponse
    {
        $client = new \App\Modules\Siigo\Clients\SiigoClient(SiigoConfig::current());

        // 1. Trae todos los productos SIIGO en memoria, paginando (cap 25).
        //    Recorrer decenas de páginas puede pasarse del timeout o cortarse a
        //    mitad: si eso ocurre avisamos y comparamos con lo que alcanzamos a
        //    traer, en vez de tumbar la pantalla con un 500.
        $siigoProductos = [];
        $pagina = 1;
        $hardCap = 50;  // 50 pags × 25 = 1250 productos; suficiente para el catálogo real.
        $avisoParcial = null;
        do {
            try {
                $resp = $client->request('GET', '/v1/products', ['page' => $pagina, 'page_size' => 25]);
            } catch (\Throwable $e) {
                $avisoParcial = 'SIIGO dejó de responder en la página '.$pagina
                    .'. El comparativo se hizo con los '.count($siigoProductos)
                    .' productos que alcanzamos a traer; volvé a intentarlo para verlo completo.';
                break;
            }
            if ($resp->failed()) break;
            $items = $resp->json('results') ?? [];
            if (empty($items)) break;
            foreach ($items as $it) {
                if (! empty($it['id'])) {
                    $siigoProductos[$it['id']] = $it;
                }
            }
            $pagina++;
        } while (count($items) === 25 && $pagina <= $hardCap);

        // 2. Locales con siigo_id · comparar en memoria.
        $locales = Producto::whereNotNull('siigo_id')
            ->select('id', 'referencia', 'nombre', 'activo', 'siigo_id', 'siigo_code', 'siigo_sync_at', 'precio_proveedor')
            ->with(['marca:id,nombre'])
            ->get();

        $filas = [];
        $zombiesSiigo = [];  // en SIIGO pero sin contraparte local
        $huerfanosLocal = []; // en ERP con siigo_id pero NO están en SIIGO

        foreach ($locales as $p) {
            $sg = $siigoProductos[$p->siigo_id] ?? null;
            if (! $sg) {
                $huerfanosLocal[] = [
                    'id' => $p->id, 'referencia' => $p->referencia, 'nombre' => $p->nombre,
                    'siigo_id' => $p->siigo_id, 'siigo_code' => $p->siigo_code,
                ];
                continue;
            }

            $localPrecio = (float) $p->precio_proveedor;
            $siigoPrecio = (float) ($sg['prices'][0]['price_list'][0]['value'] ?? 0);
            $localBrand  = optional($p->marca)->nombre;
            $siigoBrand  = $sg['additional_fields']['brand'] ?? null;

            $difs = [];
            if (trim((string) $p->nombre) !== trim((string) ($sg['name'] ?? ''))) {
                $difs[] = ['campo' => 'nombre', 'erp' => $p->nombre, 'siigo' => $sg['name'] ?? '—'];
            }
            if (abs($localPrecio - $siigoPrecio) > 0.01) {
                $difs[] = ['campo' => 'precio', 'erp' => $localPrecio, 'siigo' => $siigoPrecio];
            }
            if ((bool) $p->activo !== (bool) ($sg['active'] ?? true)) {
                $difs[] = ['campo' => 'activo', 'erp' => $p->activo, 'siigo' => (bool) ($sg['active'] ?? true)];
            }
            if ($localBrand && $siigoBrand && mb_strtolower(trim($localBrand)) !== mb_strtolower(trim($siigoBrand))) {
                $difs[] = ['campo' => 'marca', 'erp' => $localBrand, 'siigo' => $siigoBrand];
            }

            if (empty($difs)) {
                unset($siigoProductos[$p->siigo_id]);  // match limpio · no es zombie
                continue;
            }

            $filas[] = [
                'producto_id'   => $p->id,
                'referencia'    => $p->referencia,
                'nombre'        => $p->nombre,
                'siigo_id'      => $p->siigo_id,
                'siigo_code'    => $p->siigo_code,
                'siigo_sync_at' => optional($p->siigo_sync_at)->format('Y-m-d H:i'),
                'diferencias'   => $difs,
            ];
            unset($siigoProductos[$p->siigo_id]);
        }

        // 3. Lo que queda en $siigoProductos son zombies SIIGO (no están en el ERP).
        foreach ($siigoProductos as $sg) {
            $zombiesSiigo[] = [
                'siigo_id'   => $sg['id'],
                'siigo_code' => $sg['code'] ?? '—',
                'nombre'     => $sg['name'] ?? '—',
                'active'     => (bool) ($sg['active'] ?? true),
            ];
        }

        return response()->json([
            'ok'              => true,
            'parcial'         => $avisoParcial,   // null si se trajo el catálogo completo
            'generado_at'     => now()->format('Y-m-d H:i:s'),
            'total_siigo'     => count($siigoProductos) + count($filas),  // antes de unset
            'total_locales'   => $locales->count(),
            'con_diferencias' => count($filas),
            'huerfanos_local' => $huerfanosLocal,
            'zombies_siigo'   => array_slice($zombiesSiigo, 0, 100),  // tope para payload
            'filas'           => $filas,
        ]);
    }

    /** F8 · bitácora extendida con filtros + paginación. */
    public function logs(Request $r): JsonResponse
    {
        $q = SiigoSyncLog::query()->orderByDesc('id');

        if ($estado = $r->string('estado')->toString()) $q->where('estado', $estado);
        if ($recurso = $r->string('recurso')->toString()) $q->where('recurso', $recurso);
        if ($desde = $r->string('desde')->toString()) $q->whereDate('created_at', '>=', $desde);
        if ($hasta = $r->string('hasta')->toString()) $q->whereDate('created_at', '<=', $hasta);

        $rows = $q->paginate(min(100, (int) $r->integer('per_page', 30)));

        return response()->json([
            'data' => collect($rows->items())->map(fn ($l) => $this->serializarLog($l))->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /**
     * UBIC-10 · Descarga el Excel de "Saldos iniciales de inventario" con el
     * layout nativo de SIIGO. Aracely lo pega en la pantalla
     * /initial-balance-inventory de SIIGO. Opción B: segura, sin push automático.
     *
     * Filtros: `?categorias=venta,garantia` (default = venta). Solo gerencia.
     */
    public function descargarSaldosIniciales(Request $r)
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia descarga saldos iniciales.');

        $cats = array_filter(explode(',', (string) $r->query('categorias', 'venta')));
        if (empty($cats)) $cats = ['venta'];

        $svc = app(\App\Modules\Siigo\Services\ExportSaldosInicialesService::class);
        $path = $svc->generar($cats);
        $filename = 'saldos_iniciales_siigo_'.now('America/Bogota')->format('Ymd_His').'.xlsx';
        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    /** KPIs del día (queries directas · sin cache · corren en <100 ms). */
    private function kpis(): array
    {
        $desdeHoy = now()->startOfDay();
        $desdeSemana = now()->subDays(7);

        return [
            'productos_hoy_exitosos' => SiigoSyncLog::where('recurso', 'productos')
                ->where('estado', 'exitoso')
                ->where('created_at', '>=', $desdeHoy)
                ->sum(DB::raw('nuevos + actualizados')),
            'productos_hoy_fallidos' => SiigoSyncLog::where('recurso', 'productos')
                ->where('estado', 'fallido')
                ->where('created_at', '>=', $desdeHoy)
                ->count(),
            'productos_sin_siigo_id' => Producto::whereNull('siigo_id')->where('activo', true)->count(),
            'total_semana' => SiigoSyncLog::where('created_at', '>=', $desdeSemana)->count(),
        ];
    }

    /** Snapshot de la cola siigo · lee tabla `jobs` directo. */
    private function cola(): array
    {
        try {
            $filas = DB::table('jobs')
                ->where('queue', config('siigo.queue', 'siigo'))
                ->orderBy('id')
                ->limit(10)
                ->get(['id', 'payload', 'attempts', 'available_at']);
        } catch (\Throwable) {
            return ['pendientes' => 0, 'proximos' => [], 'atascada' => false];
        }

        /*
         * ¿Hay alguien procesando la cola?
         *
         * Encontrado el 2026-10-07: 29 trabajos encolados, el más viejo del día
         * anterior, y ningún `queue:work` corriendo. El ERP encolaba todo para
         * SIIGO y no salía nada — sin un solo aviso en pantalla. Si el servidor
         * del cliente no levanta el worker, pasa exactamente eso.
         *
         * Se mide por la antigüedad del trabajo más viejo: si lleva más de 15
         * minutos esperando, algo no lo está consumiendo.
         */
        //   Se mira `available_at` y no `created_at`: un trabajo que falló y
        //   está esperando su reintento tiene fecha de creación vieja pero
        //   todavía no le toca, y contarlo haría saltar el aviso siempre. Un
        //   aviso que grita sin motivo se vuelve invisible, que es peor que no
        //   tenerlo. Acá sólo cuentan los que YA deberían haberse ejecutado.
        $vencidos = DB::table('jobs')->where('available_at', '<=', time() - 900);
        $pendientesVencidos = (int) $vencidos->count();
        $masViejo = $vencidos->min('available_at');
        $esperaMin = $masViejo ? (int) round((time() - (int) $masViejo) / 60) : 0;
        $atascada = $pendientesVencidos > 0;

        /*
         * Por qué no se mueve la cola.
         *
         * Hay dos motivos y mandan a lados distintos: o no hay `queue:work`
         * corriendo —eso es soporte técnico— o la credencial de SIIGO está
         * muerta y los push se están aplazando solos —eso lo arregla Aracely
         * pegando la llave nueva—. El aviso decía siempre lo primero.
         * Comprobado el 2026-10-09: llave del sandbox caída con 401, el worker
         * corriendo bien y los 30 trabajos aplazándose cada 5 minutos.
         */
        $credencialMuerta = \App\Modules\Siigo\Models\SiigoConfig::current()->credencialMuerta();

        return [
            'pendientes' => (int) DB::table('jobs')
                ->where('queue', config('siigo.queue', 'siigo'))
                ->count(),
            'atascada' => $atascada,
            'motivo' => $credencialMuerta ? 'credencial' : 'worker',
            'espera_minutos' => $esperaMin,
            'total_todas_las_colas' => (int) DB::table('jobs')->count(),
            'proximos' => $filas->map(function ($f) {
                $payload = json_decode($f->payload, true);
                $data = $payload['data']['command'] ?? '';
                // extrae producto id de la firma del job (unserialize sería costoso · regex simple)
                preg_match('/"productoId";i:(\d+)/', $data, $m);
                preg_match('/"accion";s:\d+:"(\w+)"/', $data, $ma);
                return [
                    'id' => $f->id,
                    'producto_id' => (int) ($m[1] ?? 0),
                    'accion' => $ma[1] ?? '?',
                    'attempts' => (int) $f->attempts,
                    'disponible_en' => now()->createFromTimestamp((int) $f->available_at)->diffForHumans(),
                ];
            })->all(),
        ];
    }

    /** Últimos fallidos con datos para el botón reintentar. */
    /**
     * B5 · Snapshot del estado de la última reconciliación para la vista
     * principal de /app/siigo. Lee el cache CACHE_KEY (dashboard de arriba
     * no necesita ser live · basta con mostrar último estado).
     */
    private function estadoReconciliarVista(): array
    {
        $estado = Cache::get(ReconciliarProductosDesdeSiigo::CACHE_KEY) ?: ['estado' => 'idle'];
        return [
            'estado' => $estado['estado'] ?? 'idle',
            'modo' => $estado['modo'] ?? null,
            'inicio' => $estado['inicio'] ?? null,
            'fin' => $estado['fin'] ?? null,
            'resumen' => $estado['resumen'] ?? null,
            'error' => $estado['error'] ?? null,
            'hace' => isset($estado['fin']) ? \Carbon\Carbon::parse($estado['fin'])->diffForHumans() : null,
        ];
    }

    private function fallidosRecientes(): array
    {
        return SiigoSyncLog::where('estado', 'fallido')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'producto_id' => (int) ($l->detalle['producto_id'] ?? 0),
                'accion' => $l->detalle['accion'] ?? '—',
                'http_status' => (int) ($l->detalle['http_status'] ?? 0),
                'mensaje' => $l->mensaje,
                'hace' => $l->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * PROD-15 · Productos con falla PERMANENTE · agrupa los logs fallidos de
     * los últimos 7 días por producto_id. Si un producto acumuló ≥3 fallas,
     * cuenta como falla permanente y se muestra en un card de alerta en el
     * panel. Esto evita que Aracely tenga que revisar producto por producto
     * para detectar los que la cola rindió pero SIIGO siguió rechazando.
     *
     * Agrupar en lugar de listar por log evita contar 3 veces el mismo
     * producto que falló 3 veces seguidas.
     */
    /**
     * UBIC-7 · Estado del setup de Facturación Electrónica para el wizard.
     * Resuelve el semáforo del card al tope de /app/siigo con los 3 pasos:
     *   1. DIAN · trámite legalmente offline (único cada 2 años).
     *   2. SIIGO · sincronización interna automática (nuestro Pull trae el estado).
     *   3. ERP · marcar default + mapear ubicaciones facturadoras.
     *
     * Devuelve totales + lista de ubicaciones de venta sin resolución para que
     * el wizard pinte una checklist operativa en vez de solo "falta algo".
     */
    private function estadoSetupFacturacionElectronica(): array
    {
        $totResoluciones = \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'resolutions-fv')->count();
        $defaultId = (int) setting('siigo.resolucion_fv_default_id', 0);
        $hayDefault = $defaultId > 0 && \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'resolutions-fv')
            ->where('codigo', (string) $defaultId)->exists();

        $ubicVenta = \App\Modules\Dropi\Models\InventarioUbicacion::where('activa', true)
            ->where('disponible_para_venta', true)
            ->get(['id', 'codigo', 'nombre', 'siigo_resolution_id', 'siigo_resolution_prefix']);
        $ubicSinResol = $ubicVenta->whereNull('siigo_resolution_id')->values();

        // Semáforo: verde si todo listo, ámbar si parcial, rojo si vacío.
        $estado = match (true) {
            $totResoluciones === 0 => 'rojo',           // DIAN/SIIGO no entregó aún.
            $ubicSinResol->isNotEmpty() => 'amber',     // Falta mapear ubicaciones.
            ! $hayDefault => 'amber',                   // Falta marcar default.
            default => 'verde',
        };

        return [
            'estado' => $estado,
            'total_resoluciones' => $totResoluciones,
            'hay_default' => $hayDefault,
            'default_resolution_id' => $defaultId ?: null,
            'ubicaciones_venta_total' => $ubicVenta->count(),
            'ubicaciones_sin_resolucion' => $ubicSinResol->map(fn ($u) => [
                'id' => $u->id, 'codigo' => $u->codigo, 'nombre' => $u->nombre,
            ])->all(),
        ];
    }

    /**
     * Las cuentas PUC que el ERP tiene configuradas, contrastadas con el plan
     * de cuentas real.
     *
     * Hallado el 2026-10-09 revisando por qué un asiento de inventario no
     * sube: `siigo.cta_perdida_inventario_default` vale `5299` y esa cuenta
     * **no existe** en `plan_cuentas`; `siigo.cta_conciliacion_default` vale
     * `139535` y tampoco. Las otras tres sí existen pero son cuentas de
     * encabezado —tienen subcuentas colgando— y SIIGO no deja mover en ellas,
     * sólo en las auxiliares.
     *
     * Nada de esto se veía: el asiento salía, SIIGO lo rechazaba y el error
     * quedaba en el log. Qué código usar es decisión del contador de Aracely,
     * así que esto no corrige nada por su cuenta — lo pone a la vista, que es
     * lo que faltaba.
     *
     * @return list<array{clave: string, etiqueta: string, codigo: string, estado: string, detalle: string}>
     */
    private function estadoCuentasPuc(): array
    {
        $claves = [
            'siigo.cta_gasto_default' => 'Gasto por defecto',
            'siigo.cta_banco_default' => 'Banco por defecto',
            'siigo.cta_conciliacion_default' => 'Partida conciliatoria',
            'siigo.cta_perdida_inventario_default' => 'Pérdida por baja de inventario',
            'siigo.cta_sobrante_inventario_default' => 'Sobrante de toma física',
        ];

        /*
         * Qué claves eligió alguien de verdad, para poder distinguirlas de las
         * que vienen del valor por defecto del código.
         */
        $elegidas = \App\Models\ReglaNegocio::todas();

        $filas = [];
        foreach ($claves as $clave => $etiqueta) {
            /*
             * El valor EFECTIVO, que es el que el ERP va a escribir en el
             * asiento. Acá decía `setting($clave, '')`, y ese segundo argumento
             * **anula el valor por defecto** de `Reglas::DEFAULTS` (`??` sólo
             * cae con null, y '' no es null). Resultado: las cinco filas decían
             * «Sin configurar · sin valor» mientras el ERP armaba los asientos
             * con 5195, 1110, 139535, 5299 y 4295 — entre ellas una que no
             * existe en el plan y tres de encabezado.
             *
             * O sea: la tarjeta que existe para avisar «SIIGO va a rechazar
             * estas cuentas» escondía exactamente eso. Lo que se muestra tiene
             * que ser lo que hay. Encontrado el 2026-10-14.
             */
            $codigo = trim((string) setting($clave));
            $heredado = ! array_key_exists($clave, $elegidas);

            if ($codigo === '') {
                $filas[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'codigo' => '',
                    'estado' => 'rojo', 'detalle' => 'Sin configurar.'];
                continue;
            }

            $cuenta = \Illuminate\Support\Facades\DB::table('plan_cuentas')
                ->where('codigo', $codigo)->first(['codigo', 'nombre']);

            $origen = $heredado ? ' · valor por defecto, nadie lo eligió todavía' : '';

            if (! $cuenta) {
                $filas[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'codigo' => $codigo,
                    'estado' => 'rojo', 'detalle' => 'No existe en el plan de cuentas.'.$origen];
                continue;
            }

            // Una cuenta con subcuentas es de encabezado: acumula, no recibe
            // movimiento. El asiento tiene que ir a una auxiliar.
            $hijas = \Illuminate\Support\Facades\DB::table('plan_cuentas')
                ->where('codigo', 'like', $codigo.'_%')->count();

            $filas[] = [
                'clave' => $clave,
                'etiqueta' => $etiqueta,
                'codigo' => $codigo,
                'estado' => $hijas > 0 ? 'amber' : 'verde',
                'detalle' => ($hijas > 0
                    ? $cuenta->nombre." · es cuenta de encabezado ({$hijas} subcuentas): hay que usar una auxiliar."
                    : $cuenta->nombre).$origen,
            ];
        }

        return $filas;
    }

    /**
     * INV-A3 · lista los movimientos kardex contables (traslado/merma/sobrante/
     * ajuste_toma_fisica/reversas) que todavía no tienen siigo_journal_id,
     * agrupados por antigüedad. Permite ver backlog y actuar.
     */
    private function movsPendientesSiigo(): array
    {
        $tiposContables = [
            'traslado_salida', 'traslado_entrada',
            'traslado_reversa_salida', 'traslado_reversa_entrada',
            'merma', 'faltante', 'sobrante', 'ajuste_toma_fisica',
        ];
        $q = \App\Modules\Dropi\Models\InventarioMovimiento::query()
            ->whereIn('tipo', $tiposContables)
            ->whereNull('siigo_journal_id')
            ->where('costo_unit', '>', 0);

        $total = (clone $q)->count();
        if ($total === 0) return ['total' => 0, 'por_antiguedad' => [], 'ejemplos' => []];

        $hoy = (clone $q)->where('created_at', '>=', now()->subDay())->count();
        $semana = (clone $q)->where('created_at', '>=', now()->subWeek())->count();
        $mes = (clone $q)->where('created_at', '>=', now()->subMonth())->count();

        $ejemplos = $q->orderBy('created_at')->with('ubicacion:id,codigo,nombre')
            ->limit(10)->get(['id', 'tipo', 'cantidad', 'costo_unit', 'ubicacion_id', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id, 'tipo' => $m->tipo,
                'ubicacion' => $m->ubicacion?->codigo ?? '—',
                'valor' => round(abs($m->cantidad) * $m->costo_unit, 2),
                'hace' => $m->created_at?->diffForHumans(),
            ])->all();

        return [
            'total' => $total,
            'por_antiguedad' => [
                'hoy' => $hoy,
                'esta_semana' => $semana - $hoy,
                'este_mes' => $mes - $semana,
                'mas_viejos' => $total - $mes,
            ],
            'ejemplos' => $ejemplos,
        ];
    }

    private function fallasPermanentes(): array
    {
        $umbral = 3;
        $desde = now()->subDays(7);

        // JSON_EXTRACT portable (MySQL 5.7+). Si no hay producto_id en detalle,
        // el log viene de otra fuente (reconciliar, etc.) y lo saltamos.
        $rows = DB::table('siigo_sync_log')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(detalle, '$.producto_id')) as producto_id,
                         COUNT(*) as intentos,
                         MAX(created_at) as ultima_falla,
                         MAX(id) as ultimo_log_id,
                         MAX(mensaje) as ultimo_mensaje")
            ->where('estado', 'fallido')
            ->where('created_at', '>=', $desde)
            ->whereNotNull(DB::raw("JSON_EXTRACT(detalle, '$.producto_id')"))
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(detalle, '$.producto_id'))")
            ->havingRaw('COUNT(*) >= ?', [$umbral])
            ->orderByRaw('MAX(created_at) DESC')
            ->limit(30)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Join con la tabla de productos para traer referencia + nombre y
        // decidir qué productos siguen vivos (los soft-deleteados ya no urgen).
        $ids = $rows->pluck('producto_id')->map(fn ($x) => (int) $x)->filter()->all();
        $productos = Producto::whereIn('id', $ids)
            ->get(['id', 'referencia', 'nombre', 'activo', 'siigo_id'])
            ->keyBy('id');

        return $rows->map(function ($r) use ($productos) {
            $pid = (int) $r->producto_id;
            $p = $productos[$pid] ?? null;
            return [
                'producto_id'   => $pid,
                'referencia'    => $p?->referencia ?? '— (eliminado)',
                'nombre'        => $p?->nombre ?? '—',
                'siigo_id'      => $p?->siigo_id,
                'intentos'      => (int) $r->intentos,
                'ultima_falla'  => $r->ultima_falla ? \Carbon\Carbon::parse($r->ultima_falla)->diffForHumans() : null,
                'ultimo_log_id' => (int) $r->ultimo_log_id,
                'ultimo_mensaje' => $r->ultimo_mensaje,
                'ya_no_existe'  => $p === null,
            ];
        })->values()->all();
    }

    private function serializarLog(SiigoSyncLog $l): array
    {
        return [
            'id' => $l->id,
            'recurso' => $l->recurso,
            'estado' => $l->estado,
            'nuevos' => (int) $l->nuevos,
            'actualizados' => (int) $l->actualizados,
            'errores' => (int) $l->errores,
            'duracion_ms' => (int) $l->duracion_ms,
            'mensaje' => $l->mensaje,
            'hace' => $l->created_at?->diffForHumans(),
            'created_at' => $l->created_at?->toIso8601String(),
            'detalle' => $l->detalle,
        ];
    }
}
