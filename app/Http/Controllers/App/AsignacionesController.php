<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Modules\Rrhh\Models\Asignacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\ReporteAsignacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F9 · Lo que la empresa le entrega a cada persona.
 *
 * «La persona se fue y se llevó la tablet. Y no nos ha entregado la tablet»
 * (Elena, 19-oct). Y la razón de fondo: «no estaba alertada que a ese
 * colaborador le habían dado herramientas de trabajo».
 *
 * La asigna el líder de área —«es el que está ahí en el área y es el que sabe
 * qué recursos necesita el colaborador»— y Gestión Humana lo ve todo, porque
 * es quien arma el paz y salvo cuando alguien se va.
 */
class AsignacionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(
                Permisos::puede($r->user(), 'rrhh')
                || Permisos::puede($r->user(), 'rrhh_requisiciones'),
                403,
            );

            return $next($r);
        })];
    }

    private function esGestionHumana(Request $r): bool
    {
        return Permisos::puede($r->user(), 'rrhh');
    }

    public function index(Request $r): Response
    {
        $gh = $this->esGestionHumana($r);

        // El líder ve lo de SU gente; Gestión Humana, todo.
        $deSuGente = fn ($q) => $gh ? $q : $q->whereIn('empleado_id', $this->miGente($r));

        $asignaciones = Asignacion::with('empleado:id,nombre,cargo,area,estado', 'entregadaPor:id,name')
            ->when($r->query('estado', 'asignada') !== 'todas',
                fn ($q) => $q->where('estado', $r->query('estado', 'asignada')))
            ->tap($deSuGente)
            ->orderByDesc('id')->get();

        $reportes = ReporteAsignacion::with('asignacion.empleado:id,nombre,cargo', 'reportadoPor:id,name')
            ->where('estado', 'abierto')
            ->whereHas('asignacion', fn ($q) => $deSuGente($q))
            ->orderByDesc('id')->get();

        return Inertia::render('Rrhh/Asignaciones/Index', [
            'asignaciones' => $asignaciones->map(fn ($a) => $this->fila($a))->values(),
            'reportes' => $reportes->map(fn ($x) => [
                'id' => $x->id,
                'persona' => $x->asignacion?->empleado?->nombre,
                'cosa' => $x->asignacion?->rotulo,
                'novedad' => $x->novedadLabel(),
                'detalle' => $x->detalle,
                'es_de_gh' => $x->esDeGestionHumana(),
                'cuando' => $x->created_at?->format('Y-m-d H:i'),
            ])->values(),
            'empleados' => Empleado::where('estado', 'activo')
                ->tap(fn ($q) => $gh ? $q : $q->whereIn('id', $this->miGente($r)))
                ->orderBy('nombre')->get(['id', 'nombre', 'cargo', 'area'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'etiqueta' => "{$e->nombre} · {$e->cargo}",
                ])->values(),
            'tipos' => collect(Asignacion::TIPOS)->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            'filtro' => $r->query('estado', 'asignada'),
            'es_gestion_humana' => $gh,
            'sin_devolver' => Asignacion::pendientes()->tap($deSuGente)->count(),
        ]);
    }

    public function entregar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'empleado_id' => ['required', 'integer', 'exists:rrhh_empleados,id'],
            'tipo' => ['required', 'in:'.implode(',', array_keys(Asignacion::TIPOS))],
            'descripcion' => ['required', 'string', 'max:200'],
            // Serial o placa: sin esto, «una tablet» no se puede reclamar
            // después, porque no se sabe cuál.
            'identificador' => ['nullable', 'string', 'max:100'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'fecha_entrega' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ], [], ['empleado_id' => 'persona', 'identificador' => 'serial o placa']);

        $e = $this->empleadoQuePuedoTocar($r, $data['empleado_id']);

        $a = Asignacion::create($data + [
            'entregada_por_id' => $r->user()->id,
            'estado' => 'asignada',
        ]);

        $a->anotar('entregada', "{$a->tipoLabel()} entregada a {$e->nombre}: {$a->rotulo}");
        $e->anotar('asignacion', "Se le entregó: {$a->rotulo}");

        // Que la persona lo sepa: si no, no puede reclamar si nunca lo recibió.
        if ($e->user_id) {
            NotificacionErp::crear([
                'user_id' => $e->user_id,
                'tipo' => 'rrhh_asignacion',
                'titulo' => 'Te entregamos algo',
                'mensaje' => "{$a->tipoLabel()}: {$a->rotulo}. Lo ves en Mi espacio.",
                'url' => '/app/mi-espacio',
                'icono' => 'heroicon-o-wrench-screwdriver',
                'color' => 'info',
            ]);
        }

        return back()->with('success', "Quedó registrado lo que se le entregó a {$e->nombre}.");
    }

    /** Lo devolvió. Acá se cierra el ciclo que hoy se queda abierto. */
    public function devolver(Request $r, int $asignacion): RedirectResponse
    {
        $data = $r->validate([
            'estado' => ['required', 'in:devuelta,perdida,dada_de_baja'],
            'fecha_devolucion' => ['required', 'date'],
            'estado_devolucion' => ['nullable', 'string', 'max:300'],
        ], [], ['estado' => 'qué pasó', 'estado_devolucion' => 'en qué estado llegó']);

        $a = Asignacion::with('empleado')->findOrFail($asignacion);
        $this->empleadoQuePuedoTocar($r, $a->empleado_id);

        abort_unless($a->estaPendiente(), 422, 'Esto ya se cerró antes.');

        // `validate()` NO devuelve las claves que no vinieron en la petición,
        // así que un campo opcional se lee siempre con `??`.
        $detalle = $data['estado_devolucion'] ?? null;

        // «No devuelta» sin explicar deja a la empresa sin con qué reclamarlo.
        if ($data['estado'] !== 'devuelta' && blank($detalle)) {
            return back()->withErrors([
                'estado_devolucion' => 'Contá qué pasó: eso es lo que después sustenta el reclamo.',
            ]);
        }

        $a->update($data + ['estado_devolucion' => $detalle, 'recibida_por_id' => $r->user()->id]);
        $a->anotar('devolucion', $a->estadoLabel().($detalle ? ': '.$detalle : ''));

        return back()->with('success', 'Registrado.');
    }

    /** Responde el aviso que puso el colaborador. */
    public function atenderReporte(Request $r, int $reporte): RedirectResponse
    {
        $data = $r->validate([
            'respuesta' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['respuesta' => 'respuesta']);

        $x = ReporteAsignacion::with('asignacion.empleado')->findOrFail($reporte);
        $this->empleadoQuePuedoTocar($r, $x->asignacion->empleado_id);

        abort_unless($x->estado === 'abierto', 422, 'Este reporte ya fue atendido.');

        $x->update($data + [
            'estado' => 'atendido',
            'atendido_por_id' => $r->user()->id,
            'atendido_at' => now(),
        ]);
        $x->asignacion->anotar('reporte_atendido', "{$r->user()->name}: {$data['respuesta']}");

        // Cerrar el círculo: la persona reportó y merece la respuesta.
        if ($x->asignacion->empleado?->user_id) {
            NotificacionErp::crear([
                'user_id' => $x->asignacion->empleado->user_id,
                'tipo' => 'rrhh_asignacion',
                'titulo' => 'Te respondimos · '.$x->asignacion->rotulo,
                'mensaje' => $data['respuesta'],
                'url' => '/app/mi-espacio',
                'icono' => 'heroicon-o-wrench-screwdriver',
                'color' => 'success',
            ]);
        }

        return back()->with('success', 'Respondido.');
    }

    // ───────────────────────────── internos ─────────────────────────────

    /** Los ids de la gente que entró por las solicitudes de este jefe. */
    private function miGente(Request $r): array
    {
        return Empleado::deJefe($r->user()->id)->pluck('id')->all();
    }

    private function empleadoQuePuedoTocar(Request $r, int $id): Empleado
    {
        $e = Empleado::with('vacante.requisicion')->findOrFail($id);

        abort_unless(
            $this->esGestionHumana($r) || $e->jefeDelArea() === $r->user()->id,
            403,
            'Esta persona no es de tu equipo.',
        );

        return $e;
    }

    private function fila(Asignacion $a): array
    {
        return [
            'id' => $a->id,
            'persona' => $a->empleado?->nombre,
            'cargo' => $a->empleado?->cargo,
            'area' => $a->empleado?->area,
            'persona_retirada' => $a->empleado?->estado !== 'activo',
            'tipo' => $a->tipoLabel(),
            'rotulo' => $a->rotulo,
            'cantidad' => $a->cantidad,
            'valor' => (float) $a->valor,
            'entrega' => $a->fecha_entrega?->toDateString(),
            'entregada_por' => $a->entregadaPor?->name,
            'estado' => $a->estado,
            'estado_label' => $a->estadoLabel(),
            'pendiente' => $a->estaPendiente(),
            'devolucion' => $a->fecha_devolucion?->toDateString(),
            'estado_devolucion' => $a->estado_devolucion,
            'observaciones' => $a->observaciones,
        ];
    }
}
