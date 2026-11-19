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
use Illuminate\Validation\Rule;
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
                // Anular es por fila igual que aprobar: lo que ya está en
                // SIIGO no se anula de este lado, y el botón no debe salir
                // donde va a dar error.
                'puedo_anular' => ! $a->anulado_at
                    && $a->estado !== 'sincronizado'
                    && ! $a->siigo_journal_id,
                'anulado_at' => $a->anulado_at?->format('Y-m-d H:i'),
                'motivo_anulacion' => $a->motivo_anulacion,
            ]),
            'kpis' => [
                'total' => AsientoManual::count(),
                'borradores' => AsientoManual::where('estado', 'borrador')->count(),
                'sin_siigo' => AsientoManual::where('estado', 'aprobado')->whereNull('siigo_journal_id')->count(),
                'monto_mes' => (float) AsientoManual::whereMonth('fecha', now()->month)->whereYear('fecha', now()->year)->sum('valor_total'),
            ],
            /*
             * Sólo cuentas que admiten movimiento.
             *
             * Antes se ofrecía todo lo de nivel 4 o más, y el nivel 4 del PUC
             * de Great Baby es en buena parte agrupador: 1110 BANCOS, 130505
             * Clientes nacionales. Un asiento contra una agrupadora cuadra, se
             * guarda, se aprueba y vuelve rechazado desde SIIGO, que sólo
             * acepta auxiliares. Medido en producción el 2026-10-29: la 1110
             * quedó con saldo en el balance general por esa vía.
             */
            'cuentas' => PlanCuenta::query()
                ->where('permite_movimiento', true)
                ->where('activa', true)
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
            //
            // Y tiene que ADMITIR MOVIMIENTO. Existir no alcanza: media tabla
            // son agrupadoras (1110 BANCOS, 130505 Clientes nacionales) que
            // cuadran igual y vuelven rechazadas desde SIIGO, que sólo acepta
            // auxiliares. El 2026-10-29 la 1110 terminó con saldo en el
            // balance general de producción por esta puerta.
            'lineas.*.cuenta_puc' => ['required', 'string', 'max:20',
                Rule::exists('plan_cuentas', 'codigo')
                    ->where('permite_movimiento', true)
                    ->where('activa', true)],
            'lineas.*.tercero_documento' => ['nullable', 'string', 'max:30'],
            'lineas.*.debe' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.haber' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descripcion' => ['required', 'string', 'max:300'],
        ], [
            'lineas.*.cuenta_puc.exists' => 'Esa cuenta no existe en el plan, o es una agrupadora '
                .'que no admite movimiento. Hay que usar la auxiliar que cuelga de ella.',
        ]);

        // Cuadre, libro y línea de negocio: ver Contabilidad\Actions\AsientosManuales.
        try {
            $asiento = \App\Modules\Contabilidad\Actions\AsientosManuales::crear($data, $r->user());
        } catch (\App\Support\PasoNoPermitido $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }
        $totalDebe = (float) $asiento->valor_total;

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
        return \App\Modules\Contabilidad\Actions\AsientosManuales::puedeAprobar($u, $asiento);
    }

    public function aprobar(Request $r, AsientoManual $asientoManual): RedirectResponse
    {
        // Segregación de funciones y envío a SIIGO: ver Contabilidad\Actions\AsientosManuales.
        try {
            \App\Modules\Contabilidad\Actions\AsientosManuales::aprobar($asientoManual, $r->user());
        } catch (\App\Support\PasoNoPermitido $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }
        return back()->with('flash', ['type' => 'success', 'message' => "Asiento #{$asientoManual->id} aprobado · encolado a SIIGO."]);
    }

    /**
     * Anular un asiento equivocado, con firma.
     *
     * El asiento no se borra: queda en la lista marcado `anulado` con su
     * motivo, porque lo que hay que poder auditar seis meses después no es que
     * desapareciera, es quién lo sacó y por qué. Lo que sale del balance son
     * sus líneas, borradas suave — que es exactamente lo que el panel contable
     * ya llama «anulado» en su casilla «Incluir asientos anulados».
     *
     * Un asiento que ya viajó a SIIGO no se toca acá: SIIGO es la contabilidad
     * oficial y borrarlo sólo de este lado deja los dos libros diciendo cosas
     * distintas. Ese caso se arregla con una nota contraria, no con un delete.
     *
     * No se pide cuatro ojos como en aprobar: el error típico es una cuenta
     * mal tecleada que ve quien la tecleó, y exigir a un segundo para
     * corregirlo deja el asiento malo vivo mientras tanto. Queda el rastro.
     */
    public function anular(Request $r, AsientoManual $asientoManual): RedirectResponse
    {
        $data = $r->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ], [], ['motivo' => 'motivo']);

        try {
            \App\Modules\Contabilidad\Actions\AsientosManuales::anular($asientoManual, $r->user(), $data['motivo']);
        } catch (\App\Support\PasoNoPermitido $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success',
            'message' => "Asiento #{$asientoManual->id} anulado · sus líneas salieron del balance."]);
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
