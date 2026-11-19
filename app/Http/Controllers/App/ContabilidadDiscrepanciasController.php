<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Jobs\PushAsientoManualASiigo;
use App\Modules\Siigo\Models\SiigoSyncLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CONT-C2/C3/C4/C6 · Panel de discrepancias contables ERP ↔ SIIGO.
 *
 * Compara los asientos del ERP con lo que SIIGO reporta tener. Muestra las
 * discrepancias en 3 bloques:
 *   • Huérfanos  → asiento aprobado hace >24h sin siigo_journal_id (nunca llegó)
 *   • Delta $    → movimientos_contables cuyo total ERP ≠ monto SIIGO esperado
 *   • Fallidos   → tienen error en SIIGO_SYNC_LOG en los últimos 14 días
 *
 * El botón "Ver en SIIGO ahora" trae el journal real de SIIGO por ID y lo
 * compara campo a campo contra el asiento del ERP (CONT-C4). El botón
 * "Reintentar" vuelve a encolar el push (CONT-C3).
 */
class ContabilidadDiscrepanciasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless($r->user()?->esContable(), 403,
                'Solo el equipo contable puede ver discrepancias SIIGO.');
            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        $desde = $r->input('desde')
            ? Carbon::parse($r->input('desde'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $hasta = $r->input('hasta')
            ? Carbon::parse($r->input('hasta'))->endOfDay()
            : Carbon::now()->endOfDay();

        // HUERFANOS · aprobados >24h sin siigo_journal_id. Debería haber llegado.
        $huerfanos = AsientoManual::query()
            ->where('estado', 'aprobado')
            ->whereNull('siigo_journal_id')
            ->where('updated_at', '<', now()->subDay())
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->orderBy('fecha')
            ->limit(200)
            ->get(['id', 'fecha', 'glosa', 'valor_total', 'updated_at'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'fecha' => $a->fecha?->format('Y-m-d'),
                'glosa' => mb_substr((string) $a->glosa, 0, 90),
                'valor_total' => (float) $a->valor_total,
                'edad_horas' => (int) now()->diffInHours($a->updated_at),
            ])
            ->values();

        // DELTA · asientos sincronizados pero cuyos movimientos_contables suman
        //   distinto al valor_total → cuadre interno roto antes de llegar a SIIGO.
        $deltas = DB::table('asientos_manuales as a')
            ->leftJoin('movimientos_contables as m', function ($j) {
                $j->on('m.origen_id', '=', 'a.id')
                  ->where('m.origen_type', '=', AsientoManual::class);
            })
            ->whereNotNull('a.siigo_journal_id')
            ->whereBetween('a.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('a.id', 'a.fecha', 'a.glosa', 'a.valor_total', 'a.siigo_journal_id')
            ->havingRaw('ABS(a.valor_total - COALESCE(SUM(m.debe), 0)) >= 0.01')
            ->select(
                'a.id',
                'a.fecha',
                'a.glosa',
                'a.valor_total',
                'a.siigo_journal_id',
                DB::raw('COALESCE(SUM(m.debe), 0) as suma_debe'),
                DB::raw('COALESCE(SUM(m.haber), 0) as suma_haber'),
            )
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'fecha' => $r->fecha,
                'glosa' => mb_substr((string) $r->glosa, 0, 90),
                'valor_total' => (float) $r->valor_total,
                'suma_debe' => (float) $r->suma_debe,
                'suma_haber' => (float) $r->suma_haber,
                'delta' => round((float) $r->valor_total - (float) $r->suma_debe, 2),
                'siigo_journal_id' => $r->siigo_journal_id,
            ])
            ->values();

        // FALLIDOS · errores recientes del log para asientos del rango.
        $fallidos = SiigoSyncLog::query()
            ->where('recurso', 'asientos_manuales')
            ->where('estado', 'fallido')
            ->where('created_at', '>=', now()->subDays(14))
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'detalle', 'mensaje', 'created_at'])
            ->map(function ($l) {
                $det = is_string($l->detalle) ? json_decode($l->detalle, true) : (array) $l->detalle;
                return [
                    'log_id' => $l->id,
                    'asiento_id' => $det['asiento_id'] ?? null,
                    'mensaje' => mb_substr((string) $l->mensaje, 0, 220),
                    'when' => $l->created_at?->diffForHumans(),
                ];
            })
            ->filter(fn ($x) => $x['asiento_id'] !== null)
            ->values();

        /*
         * El semáforo cuenta las diez familias de documento, no sólo los
         * asientos manuales.
         *
         * Miraba `asientos_manuales` y devolvía 100 cuando no había ninguno
         * aprobado. Como casi nadie hace asientos a mano, la cuenta daba 0/0 y
         * la pantalla pintaba VERDE · 100 % — en producción, el 2026-11-05, con
         * 21 facturas, una nómina y una liquidación sin llegar nunca a SIIGO, y
         * con el panel contable diciendo 0 % sobre los mismos datos.
         *
         * Las listas de huérfanos, deltas y fallidos de más abajo SÍ siguen
         * siendo de asientos manuales: son otra cosa —el cuadre fino contra el
         * journal de SIIGO— y ahí la pregunta sí es por asiento.
         */
        $cobertura = \App\Modules\Contabilidad\Support\CoberturaSiigo::calcular();

        return Inertia::render('Contabilidad/DiscrepanciasSiigo', [
            'rango' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'huerfanos' => $huerfanos,
            'deltas' => $deltas,
            'fallidos' => $fallidos,
            'kpis' => [
                'total_aprobados' => $cobertura['deberian'],
                'sincronizados' => $cobertura['estan'],
                'pct_sync' => $cobertura['pct'],
                'semaforo' => $cobertura['semaforo'],
                'etiqueta_cobertura' => $cobertura['etiqueta'],
                'huerfanos' => $huerfanos->count(),
                'con_delta' => $deltas->count(),
                'fallidos_14d' => $fallidos->count(),
            ],
        ]);
    }

    /**
     * CONT-C4 · "Ver en SIIGO ahora" · trae journal real por ID y hace diff
     *   contra lo que tenemos en el ERP.
     * Si falla el fetch, devuelve el error sin reventar la UI.
     */
    public function verSiigo(int $asiento, SiigoClient $siigo): JsonResponse
    {
        $a = AsientoManual::with(['lineas'])->findOrFail($asiento);

        if (! $a->siigo_journal_id) {
            return response()->json([
                'ok' => false,
                'reason' => 'sin_siigo_id',
                'mensaje' => 'Este asiento todavía no tiene siigo_journal_id. Reintentá primero el envío.',
            ]);
        }

        try {
            $resp = $siigo->request('GET', "/v1/journals/{$a->siigo_journal_id}");
            if (! $resp->successful()) {
                return response()->json([
                    'ok' => false,
                    'reason' => 'siigo_http_error',
                    'status' => $resp->status(),
                    'mensaje' => mb_substr((string) $resp->body(), 0, 400),
                ]);
            }
            $siigoData = $siigo->sanitizarRespuestaArray($resp->json()) ?? [];
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'reason' => 'exception',
                'mensaje' => mb_substr($e->getMessage(), 0, 400),
            ]);
        }

        // Diff · campos clave.
        $erpTotal = (float) $a->valor_total;
        $siigoTotal = (float) ($siigoData['total'] ?? collect($siigoData['items'] ?? [])->sum('value') ?? 0);
        $diff = [
            'fecha' => [
                'erp' => $a->fecha?->toDateString(),
                'siigo' => $siigoData['date'] ?? null,
                'coincide' => ($a->fecha?->toDateString()) === ($siigoData['date'] ?? null),
            ],
            'total' => [
                'erp' => $erpTotal,
                'siigo' => $siigoTotal,
                'delta' => round($erpTotal - $siigoTotal, 2),
                'coincide' => abs($erpTotal - $siigoTotal) < 0.01,
            ],
            'lineas' => [
                'erp' => $a->lineas->count(),
                'siigo' => count($siigoData['items'] ?? []),
                'coincide' => $a->lineas->count() === count($siigoData['items'] ?? []),
            ],
        ];

        return response()->json([
            'ok' => true,
            'siigo_journal_id' => $a->siigo_journal_id,
            'erp' => [
                'id' => $a->id,
                'fecha' => $a->fecha?->toDateString(),
                'glosa' => $a->glosa,
                'valor_total' => $erpTotal,
                'lineas' => $a->lineas->count(),
            ],
            'siigo' => $siigoData,
            'diff' => $diff,
        ]);
    }

    // A3 FIX #6 · método `reintentar()` eliminado · nunca estuvo mapeado en
    //   rutas y el Vue DiscrepanciasSiigo.vue redirige al endpoint existente
    //   `/app/contabilidad/asientos-manuales/{id}/reenviar-siigo` del
    //   AsientosManualesController. Mantenerlo acá confundía.
}
