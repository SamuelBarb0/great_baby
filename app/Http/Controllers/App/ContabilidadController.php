<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Support\OrigenContable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class ContabilidadController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Re-audit M5 SEG-C2 · usar `esContable()` unificado (Aracely, Gerencia,
            // Gerente, Contador). Antes solo `esAracely()` → un Contador legítimo
            // recibía 403 accediendo a Balance general.
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        // Re-audit M5 SEG-A3 · validar tipo `date`. Sin esto un `desde=abc`
        // produce SQLSTATE excepción no manejada que expone stack en debug.
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'incluir_anulados' => ['nullable', 'boolean'],
            // Balance acumulado: desde el primer asiento del libro hasta `hasta`.
            'acumulado' => ['nullable', 'boolean'],
            // Filtrar por un tercero y/o abrir cada cuenta por tercero.
            'tercero_id' => ['nullable', 'integer', 'exists:contactos,id'],
            'agrupar_tercero' => ['nullable', 'boolean'],
        ]);

        $desdeElegido = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->toDateString();
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);
        $acumulado = (bool) ($data['acumulado'] ?? false);
        $terceroId = isset($data['tercero_id']) ? (int) $data['tercero_id'] : null;
        $agruparTercero = (bool) ($data['agrupar_tercero'] ?? false);
        $desde = $acumulado ? null : $desdeElegido;

        $base = fn () => MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->when($desde, fn ($q) => $q->where('fecha', '>=', $desde))
            ->where('fecha', '<=', $hasta)
            ->when($terceroId, fn ($q) => $q
                ->where('tercero_type', Contacto::class)
                ->where('tercero_id', $terceroId));

        // Re-audit R4 SEG-M1 · bitácora consulta con anulados (dato sensible DIAN).
        if ($incluirAnulados) {
            \Illuminate\Support\Facades\Log::channel(config('logging.channels.audit') ? 'audit' : 'stack')
                ->info('contabilidad.index.incluir_anulados', [
                    'user_id' => $request->user()?->id, 'rango' => [$desde, $hasta],
                ]);
        }

        $tercero = $terceroId ? Contacto::find($terceroId, ['id', 'nombre_completo', 'razon_social', 'numero_documento']) : null;

        return Inertia::render('Contabilidad/Index', [
            'filtros' => [
                'desde' => $desdeElegido, 'hasta' => $hasta, 'incluir_anulados' => $incluirAnulados,
                'acumulado' => $acumulado, 'tercero_id' => $terceroId, 'agrupar_tercero' => $agruparTercero,
                'tercero' => $tercero ? [
                    'id' => $tercero->id,
                    'nombre' => $tercero->nombreDisplay(),
                    'documento' => $tercero->numero_documento,
                ] : null,
            ],
            'kpis' => $this->kpis($base),
            'porCuenta' => $this->balanceComprobacion($base, $agruparTercero),
            'movimientosRecientes' => $this->movimientosRecientes($base, 50),
        ]);
    }

    /**
     * Re-audit M5 PATRÓN A · TODOS los métodos usan Eloquent + `withTrashed()`
     * opcional. Antes `balanceComprobacion` usaba `DB::table()` crudo que
     * IGNORA SoftDeletes → KPIs (Eloquent) excluían anulados pero el balance
     * los incluía → descuadre fantasma imposible de cuadrar.
     *
     * Re-audit M5 DATOS-A3 · 1 sola query para los 3 KPIs (antes 3 full-scans).
     */
    private function kpis(\Closure $base): array
    {
        $r = $base()
            ->selectRaw('COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h, COUNT(*) n')
            ->first();

        $totalDebe = (float) $r->d;
        $totalHaber = (float) $r->h;
        $balance = round($totalDebe - $totalHaber, 2);

        return [
            'total_debe' => $totalDebe,
            'total_haber' => $totalHaber,
            'balance' => $balance,
            'unbalanced' => abs($balance) > 0.01,
            'movimientos' => (int) $r->n,
        ];
    }

    /**
     * Sumas y saldos por cuenta. Con `$agruparTercero` cada cuenta se abre en
     * una fila por tercero (proveedor, cliente, socio): es el «cuánto le debo a
     * quién» que la contadora pedía mirar directo en el balance. Las líneas sin
     * tercero quedan juntas como «Sin tercero».
     */
    private function balanceComprobacion(\Closure $base, bool $agruparTercero = false): array
    {
        $grupo = $agruparTercero ? ['cuenta_puc', 'tercero_type', 'tercero_id'] : ['cuenta_puc'];

        $filas = $base()
            ->selectRaw(implode(', ', $grupo).', SUM(debe) as debe, SUM(haber) as haber, SUM(debe - haber) as saldo')
            ->groupBy($grupo)
            ->orderBy('cuenta_puc')
            ->get();

        $nombres = $agruparTercero
            ? Contacto::query()
                ->whereIn('id', $filas->where('tercero_type', Contacto::class)->pluck('tercero_id')->filter()->unique())
                ->get(['id', 'nombre_completo', 'razon_social', 'numero_documento'])
                ->mapWithKeys(fn ($c) => [$c->id => trim($c->nombreDisplay().' · '.($c->numero_documento ?? ''), ' ·')])
            : collect();

        return $filas->map(fn ($r) => [
            'cuenta_puc' => $r->cuenta_puc,
            'tercero' => $agruparTercero
                ? ($r->tercero_id ? ($nombres[$r->tercero_id] ?? class_basename((string) $r->tercero_type).' #'.$r->tercero_id) : 'Sin tercero')
                : null,
            'debe' => (float) $r->debe,
            'haber' => (float) $r->haber,
            'saldo' => (float) $r->saldo,
        ])->all();
    }

    private function movimientosRecientes(\Closure $base, int $limit = 50): array
    {
        return $base()
            ->with(['user:id,name'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : (string) $m->fecha,
                'cuenta_puc' => $m->cuenta_puc,
                'debe' => (float) $m->debe,
                'haber' => (float) $m->haber,
                'descripcion' => $m->descripcion,
                'origen' => $m->origen_type ? OrigenContable::etiqueta($m->origen_type) : '',
                'origen_url' => OrigenContable::urlAsiento($m->origen_type, $m->origen_id),
                'usuario' => $m->user?->name,
                // Re-audit M5 PATRÓN A · flag anulado explícito para pintar badge en UI.
                'anulado' => $m->deleted_at !== null,
            ])->all();
    }
}
