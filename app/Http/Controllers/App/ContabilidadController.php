<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Support\FiltroEjes;
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
        ]);

        $desde = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->toDateString();
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);
        // `?linea=dropi` · el panel de Dropi abre el libro ya filtrado. Sólo la
        // línea: el destino fiscal no se pide desde aquí.
        $ejes = FiltroEjes::de($request->input('linea'), null);

        // Re-audit R4 SEG-M1 · bitácora consulta con anulados (dato sensible DIAN).
        if ($incluirAnulados) {
            \Illuminate\Support\Facades\Log::channel(config('logging.channels.audit') ? 'audit' : 'stack')
                ->info('contabilidad.index.incluir_anulados', [
                    'user_id' => $request->user()?->id, 'rango' => [$desde, $hasta],
                ]);
        }

        return Inertia::render('Contabilidad/Index', [
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'incluir_anulados' => $incluirAnulados, 'linea' => $ejes->linea],
            'kpis' => $this->kpis($desde, $hasta, $incluirAnulados, $ejes),
            'porCuenta' => $this->balanceComprobacion($desde, $hasta, $incluirAnulados, $ejes),
            'movimientosRecientes' => $this->movimientosRecientes($desde, $hasta, 50, $incluirAnulados, $ejes),
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
    private function kpis(string $desde, string $hasta, bool $incluirAnulados = false, ?FiltroEjes $ejes = null): array
    {
        $r = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
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

    private function balanceComprobacion(string $desde, string $hasta, bool $incluirAnulados = false, ?FiltroEjes $ejes = null): array
    {
        $filas = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->selectRaw('cuenta_puc, SUM(debe) as debe, SUM(haber) as haber, SUM(debe - haber) as saldo')
            ->groupBy('cuenta_puc')
            ->orderBy('cuenta_puc')
            ->get();

        // El nombre real del plan de cuentas. El diccionario de apodos del
        // front resuelve por prefijo, y desde que el ingreso se parte por
        // categoria dos subcuentas distintas de 4135 salian con el mismo texto.
        $nombres = \App\Modules\Contabilidad\Support\NombresDeCuenta::para($filas->pluck('cuenta_puc'));

        return $filas
            ->map(fn ($r) => [
                'cuenta_puc' => $r->cuenta_puc,
                'cuenta_nombre' => $nombres[$r->cuenta_puc] ?? null,
                'debe' => (float) $r->debe,
                'haber' => (float) $r->haber,
                'saldo' => (float) $r->saldo,
            ])->all();
    }

    private function movimientosRecientes(string $desde, string $hasta, int $limit = 50, bool $incluirAnulados = false, ?FiltroEjes $ejes = null): array
    {
        $filas = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->with(['user:id,name'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit($limit)
            ->get();

        $nombres = \App\Modules\Contabilidad\Support\NombresDeCuenta::para($filas->pluck('cuenta_puc'));

        return $filas
            ->map(fn ($m) => [
                'id' => $m->id,
                'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : (string) $m->fecha,
                'cuenta_puc' => $m->cuenta_puc,
                'cuenta_nombre' => $nombres[$m->cuenta_puc] ?? null,
                'debe' => (float) $m->debe,
                'haber' => (float) $m->haber,
                'descripcion' => $m->descripcion,
                'origen' => class_basename($m->origen_type ?? ''),
                'usuario' => $m->user?->name,
                // Re-audit M5 PATRÓN A · flag anulado explícito para pintar badge en UI.
                'anulado' => $m->deleted_at !== null,
            ])->all();
    }
}
