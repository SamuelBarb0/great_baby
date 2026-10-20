<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Actions\ConfirmarEmpaqueYSiguiente;
use App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Support\Trazabilidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estación de Empaque — versión Inertia+Vue.
 * El `pedidoActivoId` se mantiene en session (sobrevive refresh + F5 accidental).
 */
class EstacionEmpaqueController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                $u = $r->user();
                // A1 FIX · AdminBodega llegaba desde el sidebar y recibía 403.
                abort_unless($u && ($u->esAracely() || $u->hasAnyRole(['Alistador', 'AdminBodega'])), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        // Re-audit UX#1 · deep-link `?pedido=X` desde Alistador → si el pedido
        // pertenece a este operario y está en estado empacable, se pre-selecciona
        // en sesión, cerrando el flujo Tomar → Ir a Estación.
        $deepLinkPedido = (int) $request->input('pedido', 0);
        $pedidoActivoId = (int) $request->session()->get('empaque.pedidoActivoId', 0) ?: null;

        if ($deepLinkPedido > 0) {
            $ownsLock = \App\Modules\Dropi\Models\DropiAlistadorLock::where('pedido_id', $deepLinkPedido)
                ->where('alistador_id', $request->user()->id)->exists();
            $pedidoOkDeep = DropiPedido::where('id', $deepLinkPedido)
                ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando])
                ->exists();
            if ($ownsLock && $pedidoOkDeep) {
                $request->session()->put('empaque.pedidoActivoId', $deepLinkPedido);
                $pedidoActivoId = $deepLinkPedido;
            }
        }

        // Validar que el pedido sigue siendo del operario y en estado empacable.
        // Si no, limpiar la sesión para evitar UI fantasma.
        if ($pedidoActivoId) {
            $registro = EmpaqueRegistro::where('pedido_id', $pedidoActivoId)
                ->where('estado', 'en_curso')
                ->where('operario_id', $request->user()->id)
                ->exists();
            $pedidoOk = DropiPedido::where('id', $pedidoActivoId)
                ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando])
                ->exists();
            // Si el deep-link acaba de guardar pero aún no hay EmpaqueRegistro,
            // dejamos pasar sin borrar la sesión — el usuario todavía no escaneó.
            $recienDeepLinked = $deepLinkPedido === $pedidoActivoId;
            if ((! $registro && ! $recienDeepLinked) || ! $pedidoOk) {
                $request->session()->forget('empaque.pedidoActivoId');
                $pedidoActivoId = null;
            }
        }

        return Inertia::render('EstacionEmpaque', [
            'pedidoActivo' => $pedidoActivoId ? $this->serializarPedido($pedidoActivoId) : null,
            'registroActivo' => $pedidoActivoId ? $this->serializarRegistro($pedidoActivoId, $request->user()->id) : null,
            'metricas' => $this->metricas($request->user()->id),
            'ranking' => $this->ranking(),
            'colaProximos' => $this->colaProximos($pedidoActivoId),
        ]);
    }

    public function escanear(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:200'],
        ]);

        $pedidoActivoId = (int) $request->session()->get('empaque.pedidoActivoId', 0) ?: null;

        $resultado = ProcesarEscaneoEmpaque::run(
            codigo: $data['codigo'],
            pedidoActivoId: $pedidoActivoId,
            operarioId: $request->user()->id,
        );

        if ($resultado['tipo'] === 'pedido_abierto') {
            $request->session()->put('empaque.pedidoActivoId', $resultado['pedido_id']);
        }

        return back()->with([
            'success' => $resultado['sonido'] === 'ok' ? $resultado['mensaje'] : null,
            'warning' => $resultado['sonido'] === 'warn' ? $resultado['mensaje'] : null,
            'error' => $resultado['sonido'] === 'error' ? $resultado['mensaje'] : null,
            'empaqueResultado' => $resultado,
        ]);
    }

    public function guardarFoto(Request $request): RedirectResponse
    {
        $pedidoActivoId = (int) $request->session()->get('empaque.pedidoActivoId', 0);
        if (! $pedidoActivoId) {
            return back()->with([
                'warning' => 'Ya no hay pedido activo. Escaneá una guía primero.',
                'empaqueResultado' => ['sonido' => 'warn', 'mensaje' => '⚠ No hay pedido activo — escaneá una guía'],
            ]);
        }

        $data = $request->validate([
            'dataUri' => ['required', 'string'],
        ]);

        if (! str_starts_with($data['dataUri'], 'data:image/')) {
            return back()->with('error', 'Formato inválido de imagen');
        }

        [$meta, $b64] = explode(',', $data['dataUri'], 2);

        // Límite dinámico desde reglas configurables (default 3MB binario ≈ 4.2MB base64).
        $maxBytes = (int) setting('empaque.max_foto_bytes', 3_000_000);
        $maxBase64 = (int) ($maxBytes * 1.4);
        if (strlen($b64) > $maxBase64) {
            return back()->with('warning', 'La foto pesa demasiado (máx ' . round($maxBytes / 1_000_000, 1) . 'MB)');
        }
        $minBytes = (int) setting('empaque.min_foto_bytes', 30_000);
        if (strlen(base64_decode($b64, strict: true)) < $minBytes) {
            return back()->with([
                'warning' => 'La foto está demasiado vacía — tomá una foto real del paquete',
                'empaqueResultado' => ['sonido' => 'warn', 'mensaje' => '📸 Foto muy pequeña'],
            ]);
        }

        $bin = base64_decode($b64, strict: true);

        $info = $bin ? @getimagesizefromstring($bin) : false;
        if (! $bin || $info === false) {
            return back()->with('error', 'El archivo no es una imagen válida');
        }
        // Derivar la extensión del MIME REAL (no del header cliente-controlado).
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $ext = $extMap[$info['mime'] ?? ''] ?? null;
        if (! $ext) {
            return back()->with('error', 'Formato no permitido — use JPG, PNG o WEBP');
        }

        // Validar registro del operario ANTES de escribir en disco (evita basura huérfana).
        $registro = EmpaqueRegistro::where('pedido_id', $pedidoActivoId)
            ->where('estado', 'en_curso')
            ->where('operario_id', $request->user()->id)
            ->first();

        if (! $registro) {
            return back()->with([
                'error' => 'No podés subir foto de un pedido de otro operario',
                'empaqueResultado' => ['sonido' => 'error', 'mensaje' => 'Pedido no está en tu estación'],
            ]);
        }

        // Borrar foto anterior (evita huérfanas por retoma).
        if ($registro->foto_path && Storage::disk('local')->exists($registro->foto_path)) {
            Storage::disk('local')->delete($registro->foto_path);
        }

        $rel = 'empaques/emp-' . $pedidoActivoId . '-' . now()->format('YmdHis') . '-' . Str::random(6) . '.' . $ext;
        Storage::disk('local')->put($rel, $bin);

        $registro->update(['foto_path' => $rel, 'foto_at' => now()]);

        return back()->with([
            'success' => '📸 Foto guardada — ya puedes confirmar',
            'empaqueResultado' => ['sonido' => 'ok', 'mensaje' => '📸 Foto guardada'],
        ]);
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $pedidoActivoId = (int) $request->session()->get('empaque.pedidoActivoId', 0);
        if (! $pedidoActivoId) {
            return back()->with([
                'warning' => 'Ya no hay pedido activo. Escaneá una guía primero.',
                'empaqueResultado' => ['sonido' => 'warn', 'mensaje' => '⚠ No hay pedido activo'],
            ]);
        }

        // Gate: registro debe ser del operario autenticado (evita robo de crédito).
        $registro = EmpaqueRegistro::where('pedido_id', $pedidoActivoId)
            ->where('estado', 'en_curso')
            ->where('operario_id', $request->user()->id)
            ->latest('inicio_at')->first();

        if (! $registro) {
            $request->session()->forget('empaque.pedidoActivoId');
            return back()->with([
                'error' => 'Este pedido no está en tu estación',
                'empaqueResultado' => ['sonido' => 'error', 'mensaje' => 'Pedido no está en tu estación'],
            ]);
        }

        // Guía sin ítems → empaque fantasma, rechazar.
        $totalItems = \App\Modules\Dropi\Models\DropiPedidoItem::where('pedido_id', $pedidoActivoId)
            ->sum('cantidad');
        if ((int) $totalItems === 0) {
            return back()->with([
                'error' => 'Esta guía no tiene ítems — reportá a Aracely antes de empacar',
                'empaqueResultado' => ['sonido' => 'error', 'mensaje' => 'Guía sin ítems — no se puede empacar'],
            ]);
        }

        // Regla configurable: la foto puede ser opcional si Aracely lo decide.
        $fotoObligatoria = (bool) setting('empaque.foto_obligatoria', true);
        if ($fotoObligatoria && ! $registro->foto_path) {
            return back()->with([
                'warning' => 'Falta la foto del paquete (evidencia contra reclamos)',
                'empaqueResultado' => ['sonido' => 'warn', 'mensaje' => '📸 Falta foto del paquete'],
            ]);
        }

        $gps = $request->validate(Trazabilidad::reglas())['gps'] ?? null;

        try {
            $res = ConfirmarEmpaqueYSiguiente::run($pedidoActivoId, $request->user()->id, $gps);
            $request->session()->forget('empaque.pedidoActivoId');

            $siguiente = $this->colaProximos(null)[0] ?? null;
            $sig = $siguiente ? " Siguiente: {$siguiente['guia']}, {$siguiente['ciudad']}." : '';

            return back()->with([
                'success' => '✅ ' . $res['msg'] . $sig,
                'empaqueResultado' => ['sonido' => 'ok', 'mensaje' => '✅ ' . $res['msg'] . $sig, 'confeti' => true],
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'No se pudo confirmar: ' . $e->getMessage(),
                'empaqueResultado' => ['sonido' => 'error', 'mensaje' => $e->getMessage()],
            ]);
        }
    }

    public function cancelar(Request $request): RedirectResponse
    {
        $pedidoActivoId = (int) $request->session()->get('empaque.pedidoActivoId', 0);
        $request->session()->forget('empaque.pedidoActivoId');
        if (! $pedidoActivoId) {
            return back()->with('info', 'No hay pedido activo');
        }

        DB::transaction(function () use ($pedidoActivoId, $request) {
            // Solo anular el registro propio del operario.
            $registro = EmpaqueRegistro::where('pedido_id', $pedidoActivoId)
                ->where('estado', 'en_curso')
                ->where('operario_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $registro) return;

            $registro->update(['estado' => 'anulado', 'fin_at' => now()]);

            // Borrar foto en disco si quedó.
            if ($registro->foto_path && Storage::disk('local')->exists($registro->foto_path)) {
                Storage::disk('local')->delete($registro->foto_path);
            }

            // Resetear picking SOLO si este operario era quien había pickeado.
            \App\Modules\Dropi\Models\DropiPedidoItem::where('pedido_id', $pedidoActivoId)
                ->where('pickeado_por', $request->user()->id)
                ->update(['cantidad_pickeada' => 0, 'pickeado_at' => null, 'pickeado_por' => null]);

            // Revertir estado del pedido a Pending para que reaparezca en la cola.
            // Solo si no hay OTRO registro en_curso de otro operario.
            $otroEnCurso = EmpaqueRegistro::where('pedido_id', $pedidoActivoId)
                ->where('estado', 'en_curso')->exists();
            if (! $otroEnCurso) {
                DropiPedido::where('id', $pedidoActivoId)
                    ->where('estado', EstadoPedidoDropi::Alistando)
                    ->update(['estado' => EstadoPedidoDropi::Pending]);
            }
        });

        return back()->with([
            'info' => 'Pedido cancelado — vuelve a la cola',
            'empaqueResultado' => ['sonido' => 'warn', 'mensaje' => '↩ Pedido cancelado'],
        ]);
    }

    // ============= helpers de serialización =============

    private function serializarPedido(int $id): ?array
    {
        $p = DropiPedido::with(['items.variante.producto', 'corte'])->find($id);
        if (! $p) return null;

        return [
            'id' => $p->id,
            'guia' => $p->guia,
            'cliente' => $p->cliente_nombre,
            'ciudad' => $p->cliente_ciudad,
            'transportadora' => $p->transportadora,
            'corte' => $p->corte?->numero,
            'items' => $p->items->map(function ($it) {
                // C-F-QA2 · Fallback polimórfico: item agregado usa producto directo.
                $esAgregado = $it->variante_id === null && $it->producto_id !== null;
                return [
                    'id' => $it->id,
                    'producto' => $it->variante?->producto?->nombre ?? $it->producto?->nombre ?? '—',
                    'color' => $esAgregado ? 'colores surtidos' : $it->variante?->color_nombre,
                    'talla' => $it->variante?->talla,
                    'codigo' => $it->variante?->codigo_barras ?? $it->producto?->referencia,
                    'es_agregado' => $esAgregado,
                    'cantidad' => (int) ($it->cantidad ?? 1),
                    'cantidad_pickeada' => (int) ($it->cantidad_pickeada ?? 0),
                    'completo' => (int) ($it->cantidad_pickeada ?? 0) >= (int) ($it->cantidad ?? 1),
                ];
            })->toArray(),
        ];
    }

    private function serializarRegistro(int $id, int $operarioId): ?array
    {
        $r = EmpaqueRegistro::where('pedido_id', $id)
            ->where('estado', 'en_curso')
            ->where('operario_id', $operarioId)
            ->latest('inicio_at')->first();
        if (! $r) return null;
        return [
            'id' => $r->id,
            'foto_path' => $r->foto_path,
            'foto_at' => $r->foto_at?->toIso8601String(),
            'inicio_at' => $r->inicio_at?->toIso8601String(),
        ];
    }

    private function metricas(int $userId): array
    {
        [$ini, $fin] = $this->rangoHoyBogota();
        $aggs = EmpaqueRegistro::where('operario_id', $userId)
            ->where('estado', 'completado')
            ->whereBetween('fin_at', [$ini, $fin])
            ->selectRaw('COUNT(*) as total, AVG(duracion_segundos) as prom, MIN(duracion_segundos) as mejor')
            ->first();

        return [
            'mis_hoy' => (int) ($aggs?->total ?? 0),
            'prom_seg' => (int) ($aggs?->prom ?? 0),
            'mejor_seg' => (int) ($aggs?->mejor ?? 0),
        ];
    }

    private function rangoHoyBogota(): array
    {
        return [
            \Carbon\Carbon::now('America/Bogota')->startOfDay(),
            \Carbon\Carbon::now('America/Bogota')->endOfDay(),
        ];
    }

    private function ranking(): array
    {
        [$ini, $fin] = $this->rangoHoyBogota();
        return DB::table('empaques_registro')
            ->join('users', 'users.id', '=', 'empaques_registro.operario_id')
            ->whereBetween('fin_at', [$ini, $fin])
            ->where('estado', 'completado')
            ->selectRaw('users.id as uid, users.name, COUNT(*) as total, AVG(duracion_segundos) as prom')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(5)
            ->get()->map(fn ($r) => [
                'uid' => (int) $r->uid,
                'nombre' => $r->name,
                'total' => (int) $r->total,
                'prom' => (int) ($r->prom ?? 0),
            ])->toArray();
    }

    private function colaProximos(?int $exceptoId, int $limit = 6): array
    {
        return DropiPedido::query()
            ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando])
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->withCount('items')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'guia' => (string) $p->guia,
                'cliente' => (string) $p->cliente_nombre,
                'ciudad' => (string) $p->cliente_ciudad,
                'items' => (int) $p->items_count,
            ])->toArray();
    }
}
