<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Siigo\Jobs\PushAsientoManualASiigo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 4 · B.4 · Asientos contables manuales.
 * Ruta: /app/contabilidad/asientos-manuales
 */
class AsientosManualesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        $q = AsientoManual::with(['autor:id,name', 'lineas']);
        if ($busca = trim((string) $r->query('q', ''))) {
            $q->where('glosa', 'like', "%$busca%");
        }
        if ($estado = $r->query('estado')) $q->where('estado', $estado);
        if ($r->boolean('sin_siigo')) $q->whereNull('siigo_journal_id');

        return Inertia::render('Contabilidad/AsientosManuales', [
            'filtros' => [
                'q' => $r->query('q', ''),
                'estado' => $estado,
                'sin_siigo' => $r->boolean('sin_siigo'),
            ],
            /*
             * Quién puede aprobar CADA asiento.
             *
             * No es una bandera del usuario sino de la fila: nadie aprueba el
             * suyo propio salvo gerencia. Mandarla por asiento es lo que
             * permite que el botón no salga donde va a dar 403 — el molde es
             * el de Gastos, que ya lo hace bien.
             */
            'asientos' => $q->orderByDesc('fecha')->orderByDesc('id')->paginate(20)->through(fn ($a) => [
                'puedo_aprobar' => $this->puedeAprobar($r->user(), $a),
                'id' => $a->id,
                'fecha' => $a->fecha->format('Y-m-d'),
                'glosa' => $a->glosa,
                'valor_total' => (float) $a->valor_total,
                'lineas_count' => $a->lineas->count(),
                'estado' => $a->estado,
                'siigo_journal_id' => $a->siigo_journal_id,
                'siigo_sync_at' => $a->siigo_sync_at?->format('Y-m-d H:i'),
                'autor' => $a->autor?->name,
                'creado' => $a->created_at?->diffForHumans(),
            ]),
            'kpis' => [
                'total' => AsientoManual::count(),
                'borradores' => AsientoManual::where('estado', 'borrador')->count(),
                'sin_siigo' => AsientoManual::where('estado', 'aprobado')->whereNull('siigo_journal_id')->count(),
                'monto_mes' => (float) AsientoManual::whereMonth('fecha', now()->month)->whereYear('fecha', now()->year)->sum('valor_total'),
            ],
            'cuentas' => PlanCuenta::query()
                ->where('nivel', '>=', 4)
                ->orderBy('codigo')
                ->limit(1000)
                ->get(['codigo', 'nombre'])
                ->map(fn ($c) => ['codigo' => $c->codigo, 'nombre' => "{$c->codigo} · {$c->nombre}"]),
        ]);
    }

    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'glosa' => ['required', 'string', 'min:10', 'max:500'],
            'lineas' => ['required', 'array', 'min:2', 'max:50'],
            // Tiene que EXISTIR en el plan de cuentas. Sin esto se podia asentar
            // contra una cuenta inventada: en los reportes salia como «Cuenta
            // fuera del plan» y el push a SIIGO reventaba despues, cuando ya
            // nadie recordaba el asiento. `GastosController` ya lo validaba asi.
            'lineas.*.cuenta_puc' => ['required', 'string', 'max:20', 'exists:plan_cuentas,codigo'],
            'lineas.*.tercero_documento' => ['nullable', 'string', 'max:30'],
            'lineas.*.debe' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.haber' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descripcion' => ['required', 'string', 'max:300'],
        ]);

        $totalDebe = 0;
        $totalHaber = 0;
        foreach ($data['lineas'] as $l) {
            $d = (float) ($l['debe'] ?? 0);
            $h = (float) ($l['haber'] ?? 0);
            if (($d > 0 && $h > 0) || ($d == 0 && $h == 0)) {
                return back()->with('flash', ['type' => 'error', 'message' => "Cada línea debe tener solo débito O crédito (no ambos, no ninguno)."]);
            }
            $totalDebe += $d;
            $totalHaber += $h;
        }
        if (abs($totalDebe - $totalHaber) >= 0.01) {
            return back()->with('flash', ['type' => 'error', 'message' => "Débito ($" . number_format($totalDebe, 2) . ") ≠ Crédito ($" . number_format($totalHaber, 2) . "). No cuadra."]);
        }

        $asiento = DB::transaction(function () use ($r, $data, $totalDebe) {
            $a = AsientoManual::create([
                'fecha' => $data['fecha'],
                'glosa' => $data['glosa'],
                'valor_total' => $totalDebe,
                'estado' => 'cuadrado',
                'user_id' => $r->user()->id,
            ]);
            foreach ($data['lineas'] as $i => $l) {
                $a->lineas()->create([
                    'cuenta_puc' => $l['cuenta_puc'],
                    'tercero_documento' => $l['tercero_documento'] ?? null,
                    'debe' => (float) ($l['debe'] ?? 0),
                    'haber' => (float) ($l['haber'] ?? 0),
                    'descripcion' => $l['descripcion'],
                    'orden' => $i,
                ]);
            }
            // QA-FIX #1 · escribir movimientos_contables planos para que los
            // reportes contables (mayor, balance, estado resultados) vean
            // este asiento. Reutiliza el motor con validación de partida doble.
            $movLineas = [];
            foreach ($data['lineas'] as $l) {
                $movLineas[] = [
                    'fecha' => $data['fecha'],
                    'cuenta_puc' => $l['cuenta_puc'],
                    'debe' => (float) ($l['debe'] ?? 0),
                    'haber' => (float) ($l['haber'] ?? 0),
                    'descripcion' => $a->glosa . ' · ' . $l['descripcion'],
                    'origen_type' => AsientoManual::class,
                    'origen_id' => $a->id,
                    'user_id' => $r->user()->id,
                ];
            }
            \App\Modules\Cartera\Models\MovimientoContable::registrarAsientoAtomico($movLineas);
            return $a;
        });

        return redirect('/app/contabilidad/asientos-manuales')
            ->with('flash', ['type' => 'success', 'message' => "Asiento #{$asiento->id} guardado · cuadrado por $" . number_format($totalDebe, 2) . ". Apruebe para enviar a SIIGO."]);
    }

    /**
     * ¿Este usuario puede aprobar ESTE asiento?
     *
     * La misma condición que aplica `aprobar()`, extraída para que la
     * pantalla pueda consultarla antes de pintar el botón. Repetirla no sería
     * un problema; tenerla sólo en la acción sí lo era: el Contador creaba el
     * asiento, veía el check verde y recibía 403 en su flujo normal.
     */
    private function puedeAprobar(?\App\Models\User $u, $asiento): bool
    {
        if (! $u || $asiento->estado !== 'cuadrado') {
            return false;
        }

        if ($asiento->user_id !== $u->id) {
            return true;
        }

        // Es el suyo: sólo gerencia, o si la empresa habilitó la autoaprobación.
        return $u->esRoot() || $u->hasRole('Gerente')
            || (bool) setting('contabilidad.permitir_auto_aprobar_asiento');
    }

    public function aprobar(Request $r, AsientoManual $asientoManual): RedirectResponse
    {
        if ($asientoManual->estado !== 'cuadrado') {
            return back()->with('flash', ['type' => 'error', 'message' => "Solo se pueden aprobar asientos cuadrados."]);
        }
        // QA-FIX #3 · Segregación de funciones (SEG-A1) · quien crea NO aprueba,
        // salvo rol Aracely/Gerencia. Excepción configurable por setting para
        // operación pequeña donde la contadora hace ambas.
        $u = $r->user();
        // A1 FIX #11 · reusar helper en vez de reimplementar hasRole a mano.
        //   esRoot() = Aracely+Gerencia; agregamos Gerente para preservar el
        //   comportamiento original (3 roles aprobaban antes).
        $esGerencia = $u && ($u->esRoot() || $u->hasRole('Gerente'));
        /*
         * Mismo default invertido que en las OC: decia `true` contra su propio
         * comentario, asi que el 403 nunca salia. Y un asiento manual es la
         * herramienta mas poderosa del modulo —cualquier cuenta, cualquier
         * monto— y al aprobarlo viaja a SIIGO, que es la contabilidad oficial.
         */
        $permitirAuto = (bool) (function_exists('setting') ? setting('contabilidad.permitir_auto_aprobar_asiento', false) : false);
        if ($asientoManual->user_id === $u->id && ! $esGerencia && ! $permitirAuto) {
            abort(403, 'Segregación de funciones: quien crea un asiento no puede aprobarlo. '
                .'Pedile la aprobación a Gerencia, o desactivá «Permitir que quien crea un asiento manual '
                .'lo apruebe» en Reglas de negocio → Contable.');
        }
        $asientoManual->update(['estado' => 'aprobado']);
        // A2 FIX · B2 CRÍTICO · afterCommit evita job huérfano si una
        //   transacción externa rollbackea después del update.
        \DB::afterCommit(fn () => PushAsientoManualASiigo::dispatch($asientoManual->id));
        return back()->with('flash', ['type' => 'success', 'message' => "Asiento #{$asientoManual->id} aprobado · encolado a SIIGO."]);
    }

    public function reenviarSiigo(AsientoManual $asientoManual): RedirectResponse
    {
        if (! in_array($asientoManual->estado, ['aprobado', 'sincronizado'])) {
            return back()->with('flash', ['type' => 'error', 'message' => "El asiento debe estar aprobado."]);
        }
        PushAsientoManualASiigo::dispatchManual($asientoManual->id);
        return back()->with('flash', ['type' => 'success', 'message' => "Asiento #{$asientoManual->id} encolado a SIIGO."]);
    }
}
