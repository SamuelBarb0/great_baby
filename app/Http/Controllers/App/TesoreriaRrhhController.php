<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\EntregaCargo;
use App\Modules\Rrhh\Models\Liquidacion;
use App\Modules\Rrhh\Models\Nomina;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que espera la mano de tesorería.
 *
 * ── Por qué existe esta pantalla ────────────────────────────────────────────
 *
 * Tres flujos del módulo tienen tres manos, y la tercera es de tesorería:
 * pagar la nómina, pagar la liquidación y firmar el paz y salvo. El backend
 * lo exigía correctamente —cada acción pide `contabilidad`— pero los botones
 * vivían en pantallas que piden `rrhh`, que el Contador NO tiene.
 *
 * El resultado práctico era el peor posible: el control existía en el código
 * y en la práctica lo saltaba alguien de Gestión Humana o de gerencia
 * apretando el botón «por» tesorería. Un control que obliga a saltárselo para
 * trabajar no es un control.
 *
 * ── Por qué una pantalla aparte y no abrir la ficha del empleado ────────────
 *
 * Porque la ficha lleva el expediente entero: hoja de vida, documentos,
 * evaluación del período de prueba, conceptos psicotécnicos. Tesorería no
 * necesita nada de eso para pagar; necesita saber a quién, cuánto y con qué
 * concepto. Abrirle la ficha para que apriete un botón sería darle el
 * expediente de toda la empresa.
 */
class TesoreriaRrhhController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'contabilidad'), 403,
                'Esta bandeja es de tesorería.');

            return $next($r);
        })];
    }

    public function index(): Response
    {
        return Inertia::render('Rrhh/Tesoreria/Index', [
            /*
             * Nóminas aprobadas sin pagar. Va el total y nada más: el
             * desglose por persona ya lo revisó quien liquidó y quien aprobó.
             */
            'nominas' => Nomina::with(['aprobadaPor:id,name'])
                ->where('estado', Nomina::APROBADA)
                ->orderBy('periodo')
                ->get()
                ->map(fn (Nomina $n) => [
                    'id' => $n->id,
                    'periodo' => $n->periodo,
                    'titulo' => $n->titulo(),
                    'personas' => $n->lineas()->count(),
                    'neto' => (float) $n->total_neto,
                    'aportes' => (float) $n->total_aportes,
                    'aprobada' => $n->aprobada_at?->format('Y-m-d H:i'),
                    'aprobada_por' => $n->aprobadaPor?->name,
                ]),

            'liquidaciones' => Liquidacion::with('aprobadaPor:id,name')
                ->where('estado', Liquidacion::APROBADA)
                ->orderBy('fecha_retiro')
                ->get()
                ->map(fn (Liquidacion $l) => [
                    'empleado_id' => $l->empleado_id,
                    'nombre' => $l->nombre,
                    'documento' => $l->documento,
                    'retiro' => $l->fecha_retiro?->toDateString(),
                    'neto' => (float) $l->neto,
                    'aprobada' => $l->aprobada_at?->format('Y-m-d H:i'),
                    'aprobada_por' => $l->aprobadaPor?->name,
                ]),

            /*
             * Entregas de cargo esperando el paz y salvo.
             *
             * Se muestran los conceptos que tesorería misma escribió, para
             * que vea sobre qué está firmando. Las funciones y la dotación no
             * son asunto suyo y no se mandan.
             */
            'entregas' => EntregaCargo::with(['empleado:id,nombre,numero_documento,fecha_retiro', 'items'])
                ->whereNull('tesoreria_confirmo_at')
                ->get()
                ->map(fn (EntregaCargo $x) => [
                    'empleado_id' => $x->empleado_id,
                    'nombre' => $x->empleado?->nombre,
                    'documento' => $x->empleado?->numero_documento,
                    'retiro' => $x->empleado?->fecha_retiro?->toDateString(),
                    'conceptos' => $x->de('paz_salvo')->map(fn ($i) => [
                        'nombre' => $i->nombre, 'detalle' => $i->detalle,
                    ])->values(),
                    'observaciones' => $x->observaciones,
                    'faltan' => $x->faltanFirmas(),
                ]),
        ]);
    }
}
