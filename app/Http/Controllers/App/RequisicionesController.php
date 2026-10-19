<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F1 · La solicitud de personal, de punta a punta.
 *
 * Hoy esto es un papel. Elena lo describió así: «a veces sí me dan la hojita,
 * a veces no me dan la hojita, se me pierde el rastro». Mientras sea papel,
 * nadie puede responder en qué va una contratación sin preguntarle a alguien,
 * y el jefe que pidió el personal termina llamando para saber.
 *
 * Va en controlador aparte de `RrhhController` a propósito: el líder de área
 * entra ACÁ, pero no a Gestión Humana, donde se ven salarios y hojas de vida
 * de toda la empresa. Son dos permisos distintos porque son dos trabajos
 * distintos.
 */
class RequisicionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_requisiciones'), 403);

            return $next($r);
        })];
    }

    /** Quien no gestiona el proceso sólo ve lo suyo. */
    private function esGestionHumana(Request $r): bool
    {
        return Permisos::puede($r->user(), 'rrhh');
    }

    public function index(Request $r): Response
    {
        $gh = $this->esGestionHumana($r);

        $q = Requisicion::with('solicitante:id,name')->withCount('vacantes')
            ->when(! $gh, fn ($x) => $x->deSolicitante($r->user()->id))
            ->when($r->query('estado'), fn ($x, $e) => $x->where('estado', $e))
            ->orderByDesc('id');

        return Inertia::render('Rrhh/Requisiciones/Index', [
            'requisiciones' => $q->paginate(25)->through(fn ($x) => $this->fila($x)),
            'esGestionHumana' => $gh,
            'kpis' => $this->kpis($r, $gh),
            'estados' => collect(EstadoRequisicion::cases())
                ->map(fn ($e) => ['valor' => $e->value, 'label' => $e->label()])->values(),
        ]);
    }

    public function show(Request $r, int $requisicion): Response
    {
        $x = Requisicion::with(['solicitante:id,name', 'aprobadaPor:id,name', 'vacantes:id,requisicion_id,titulo,estado'])
            ->findOrFail($requisicion);

        $this->autorizarVer($r, $x);

        return Inertia::render('Rrhh/Requisiciones/Show', [
            'requisicion' => $this->fila($x) + [
                'motivo' => $x->motivo,
                'funciones' => $x->funciones,
                'competencias' => $x->competencias,
                'estudios' => $x->estudios,
                'experiencia' => $x->experiencia,
                'tipo_contrato' => $x->tipo_contrato,
                'observaciones' => $x->observaciones,
                'motivo_rechazo' => $x->motivo_rechazo,
                'aprobada_por' => $x->aprobadaPor?->name,
                'aprobada_at' => $x->aprobada_at?->format('Y-m-d H:i'),
                'vacantes' => $x->vacantes->map(fn ($v) => [
                    'id' => $v->id, 'titulo' => $v->titulo, 'estado' => $v->estado,
                ])->values(),
            ],
            'bitacora' => $x->bitacora()->with('usuario:id,name')->get()->map(fn ($b) => [
                'resumen' => $b->resumen,
                'usuario' => $b->usuario?->name ?? 'Sistema',
                'cuando' => $b->created_at?->format('Y-m-d H:i'),
            ])->values(),
            // Lo que Elena pidió textual: que el jefe abra el móvil y vea en
            // qué etapa van sus candidatos sin preguntarle a nadie. Ve el
            // recorrido y el dictamen —que es lo que necesita para elegir—,
            // no las hojas de vida ni lo que se le ofrece a otras áreas.
            'candidatos' => $this->comoVanLosCandidatos($x),
            'puede' => [
                'editar' => $x->estado === EstadoRequisicion::Borrador && $x->solicitante_id === $r->user()->id,
                'enviar' => $x->estado === EstadoRequisicion::Borrador && $x->solicitante_id === $r->user()->id,
                'decidir' => $this->esGestionHumana($r) && $x->estado === EstadoRequisicion::Enviada,
                'abrir_vacante' => $this->esGestionHumana($r) && $x->estado->permiteVacante() && $x->vacantes_pendientes > 0,
            ],
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $this->validar($r);

        $x = Requisicion::create($data + [
            'numero' => Requisicion::siguienteNumero(),
            // El solicitante es quien la crea, siempre. No se elige: es el dato
            // que después gobierna quién puede ver su estado.
            'solicitante_id' => $r->user()->id,
            'estado' => EstadoRequisicion::Borrador->value,
        ]);

        $x->anotar('creada', "Requisición {$x->numero} creada");

        return redirect()->route('app.rrhh.requisicion.show', $x->id)
            ->with('success', "Requisición {$x->numero} creada. Revisala y enviala a Gestión Humana.");
    }

    public function update(Request $r, int $requisicion): RedirectResponse
    {
        $x = Requisicion::findOrFail($requisicion);
        abort_unless($x->solicitante_id === $r->user()->id, 403, 'Sólo quien la creó puede editarla.');
        abort_unless($x->estado === EstadoRequisicion::Borrador, 422,
            'Una requisición ya enviada no se edita. Pedile a Gestión Humana que la devuelva.');

        $x->update($this->validar($r));
        $x->anotar('editada', 'Se corrigieron los datos de la solicitud');

        return back()->with('success', 'Solicitud actualizada.');
    }

    /** El líder la manda a Gestión Humana. */
    public function enviar(Request $r, int $requisicion): RedirectResponse
    {
        $x = Requisicion::findOrFail($requisicion);
        abort_unless($x->solicitante_id === $r->user()->id, 403, 'Sólo quien la creó puede enviarla.');
        abort_unless($x->estado === EstadoRequisicion::Borrador, 422, 'Esta solicitud ya fue enviada.');

        DB::transaction(function () use ($x, $r) {
            $x->enviada_at = now();
            $x->cambiarEstado(EstadoRequisicion::Enviada->value,
                "{$r->user()->name} envió la solicitud a Gestión Humana");

            $this->avisarAGestionHumana($x);
        });

        return back()->with('success', 'Solicitud enviada. Gestión Humana ya fue notificada.');
    }

    public function aprobar(Request $r, int $requisicion): RedirectResponse
    {
        $x = $this->paraDecidir($r, $requisicion);

        DB::transaction(function () use ($x, $r) {
            $x->aprobada_por_id = $r->user()->id;
            $x->aprobada_at = now();
            $x->cambiarEstado(EstadoRequisicion::Aprobada->value,
                "Aprobada por {$r->user()->name}");

            $this->avisarAlSolicitante($x, 'Solicitud aprobada',
                "Tu solicitud {$x->numero} ({$x->cargo}) fue aprobada. Ya se puede abrir la vacante.",
                'success');
        });

        return back()->with('success', "Requisición {$x->numero} aprobada.");
    }

    public function rechazar(Request $r, int $requisicion): RedirectResponse
    {
        $x = $this->paraDecidir($r, $requisicion);

        // Sin motivo el rechazo no le sirve al líder: no sabe qué corregir.
        $data = $r->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ], [], ['motivo' => 'motivo del rechazo']);

        DB::transaction(function () use ($x, $r, $data) {
            $x->rechazada_at = now();
            $x->motivo_rechazo = $data['motivo'];
            $x->cambiarEstado(EstadoRequisicion::Rechazada->value,
                "Rechazada por {$r->user()->name}: {$data['motivo']}");

            $this->avisarAlSolicitante($x, 'Solicitud rechazada',
                "Tu solicitud {$x->numero} ({$x->cargo}) fue rechazada. Motivo: {$data['motivo']}",
                'danger');
        });

        return back()->with('success', 'Solicitud rechazada. El solicitante fue notificado.');
    }

    /**
     * Abre la vacante que nace de esta requisición.
     *
     * Es el control que hoy se pierde: sin requisición aprobada no hay vacante,
     * y por lo tanto no hay proceso de selección empezado por las buenas.
     */
    public function abrirVacante(Request $r, int $requisicion): RedirectResponse
    {
        abort_unless($this->esGestionHumana($r), 403);

        $x = Requisicion::withCount('vacantes')->findOrFail($requisicion);

        abort_unless($x->estado->permiteVacante(), 422,
            'Sólo se abre vacante desde una requisición aprobada.');
        abort_unless($x->vacantes_pendientes > 0, 422,
            "Esta requisición pidió {$x->numero_vacantes} vacante(s) y ya están abiertas.");

        $v = DB::transaction(function () use ($x, $r) {
            $v = Vacante::create([
                'requisicion_id' => $x->id,
                'titulo' => $x->cargo,
                'area' => $x->area ?: 'Sin área',
                'descripcion' => $x->funciones,
                'requisitos' => $x->competencias,
                'salario_min' => $x->salario_min,
                'salario_max' => $x->salario_max,
                'tipo_contrato' => $x->tipo_contrato,
                'modalidad' => 'presencial',
                'estado' => 'abierta',
                'fecha_apertura' => now()->toDateString(),
                'creado_por' => $r->user()->id,
            ]);

            $v->anotar('creada', "Vacante abierta desde la requisición {$x->numero}");
            $x->anotar('vacante_abierta', "Se abrió la vacante #{$v->id}", ['vacante_id' => $v->id]);

            $this->avisarAlSolicitante($x, 'Vacante abierta',
                "Ya se abrió la vacante de {$x->cargo} que solicitaste.", 'success');

            return $v;
        });

        return redirect()->route('app.rrhh.vacante.show', $v->id)
            ->with('success', 'Vacante abierta. Ya se le pueden cargar candidatos.');
    }

    /**
     * El jefe elige a quién de la terna se contrata.
     *
     * Hoy esa decisión se toma por teléfono y no queda en ninguna parte: Elena
     * no puede demostrar quién pidió a quién, y el jefe no puede demostrar que
     * ya respondió. Acá queda con nombre y hora.
     */
    public function elegirDeLaTerna(Request $r, int $candidato): RedirectResponse
    {
        $c = Candidato::with('vacante.requisicion')->findOrFail($candidato);
        $req = $c->vacante?->requisicion;

        abort_unless($req, 422, 'Esta vacante no nació de una requisición.');
        abort_unless(
            $req->solicitante_id === $r->user()->id || $this->esGestionHumana($r),
            403,
            'Elige el jefe que pidió el personal.',
        );
        abort_unless($c->etapa === EtapaCandidato::Terna, 422,
            'Sólo se elige entre los candidatos que están en la terna.');

        DB::transaction(function () use ($c, $r, $req) {
            $c->elegido_at = now();
            $c->elegido_por_id = $r->user()->id;
            $c->moverA(EtapaCandidato::Seleccionado, "{$r->user()->name} lo eligió de la terna");

            // Los otros de la terna no se descartan solos: puede que el
            // elegido no acepte la oferta y haya que volver a ellos.
            foreach (User::permission(Permisos::permiso('rrhh'))->get(['id']) as $u) {
                NotificacionErp::crear([
                    'user_id' => $u->id,
                    'tipo' => 'rrhh_terna',
                    'titulo' => 'El jefe ya eligió · '.$c->vacante->titulo,
                    'mensaje' => "{$r->user()->name} eligió a {$c->nombre}. Sigue la contratación.",
                    'url' => "/app/rrhh/vacantes/{$c->vacante_id}",
                    'icono' => 'heroicon-o-user-group',
                    'color' => 'success',
                ]);
            }
        });

        return back()->with('success', "{$c->nombre} quedó seleccionado. Gestión Humana ya fue notificada.");
    }

    // ───────────────────────────── internos ─────────────────────────────

    /**
     * El recorrido de los candidatos de esta requisición, por vacante.
     *
     * Los descartados no se listan: al jefe le interesa a quién tiene, no a
     * quién ya no.
     */
    private function comoVanLosCandidatos(Requisicion $x): array
    {
        if ($x->vacantes->isEmpty()) {
            return [];
        }

        return Candidato::whereIn('vacante_id', $x->vacantes->pluck('id'))
            ->where('etapa', '!=', EtapaCandidato::Descartado->value)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'vacante_id' => $c->vacante_id,
                'etapa' => $c->etapa->value,
                'etapa_label' => $c->etapa->label(),
                'etapa_color' => $c->etapa->color(),
                'valoracion_label' => $c->valoracion?->label(),
                'valoracion_color' => $c->valoracion?->color(),
                'valoracion_observacion' => $c->valoracion_observacion,
                'en_terna' => $c->etapa === EtapaCandidato::Terna,
                'elegido' => (bool) $c->elegido_at,
            ])->values()->all();
    }

    /**
     * Lo que importa mirar al entrar, segun quien entra.
     *
     * Gestion Humana necesita saber cuantas esperan decision y hace cuanto
     * espera la mas vieja —ese es el reclamo de los lideres, que piden y no
     * saben nada—. El lider solo necesita saber como van las suyas.
     */
    private function kpis(Request $r, bool $gh): array
    {
        $mias = Requisicion::deSolicitante($r->user()->id);

        $kpis = [
            'mias_borrador' => (clone $mias)->where('estado', EstadoRequisicion::Borrador->value)->count(),
            'mias_en_tramite' => (clone $mias)->whereIn('estado', [
                EstadoRequisicion::Enviada->value, EstadoRequisicion::Aprobada->value,
            ])->count(),
        ];

        if (! $gh) {
            return $kpis;
        }

        $masVieja = Requisicion::pendientesDeAprobar()->min('enviada_at');

        return $kpis + [
            'por_aprobar' => Requisicion::pendientesDeAprobar()->count(),
            // `diffInDays` sobre una fecha pasada da negativo en Carbon 3, y
            // ademas devuelve float. Se toma el valor absoluto, entero.
            'espera_dias' => $masVieja
                ? (int) abs(now()->diffInDays(\Illuminate\Support\Carbon::parse($masVieja)))
                : 0,
        ];
    }

    private function validar(Request $r): array
    {
        return $r->validate([
            'cargo' => ['required', 'string', 'max:160'],
            'area' => ['nullable', 'string', 'max:120'],
            'numero_vacantes' => ['required', 'integer', 'min:1', 'max:99'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'funciones' => ['nullable', 'string', 'max:4000'],
            'competencias' => ['nullable', 'string', 'max:4000'],
            'estudios' => ['nullable', 'string', 'max:300'],
            'experiencia' => ['nullable', 'string', 'max:300'],
            'salario_min' => ['nullable', 'numeric', 'min:0'],
            // Un rango al revés es un error de tipeo que después confunde al
            // publicar la vacante.
            'salario_max' => ['nullable', 'numeric', 'min:0', 'gte:salario_min'],
            'tipo_contrato' => ['nullable', 'string', 'max:60'],
            'fecha_requerida' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [], ['salario_max' => 'salario máximo']);
    }

    private function autorizarVer(Request $r, Requisicion $x): void
    {
        abort_unless(
            $this->esGestionHumana($r) || $x->solicitante_id === $r->user()->id,
            403,
            'Esta solicitud es de otra área.',
        );
    }

    private function paraDecidir(Request $r, int $id): Requisicion
    {
        abort_unless($this->esGestionHumana($r), 403, 'Sólo Gestión Humana aprueba o rechaza.');

        $x = Requisicion::findOrFail($id);
        abort_unless($x->estado === EstadoRequisicion::Enviada, 422,
            'Sólo se decide sobre una solicitud enviada.');

        return $x;
    }

    /** Avisa a todo el que pueda resolverla, no a una persona fija. */
    private function avisarAGestionHumana(Requisicion $x): void
    {
        $permiso = Permisos::permiso('rrhh');

        User::permission($permiso)->get(['id'])->each(fn ($u) => NotificacionErp::crear([
            'user_id' => $u->id,
            'tipo' => 'rrhh_requisicion',
            'titulo' => "Nueva solicitud de personal · {$x->numero}",
            'mensaje' => "{$x->solicitante?->name} solicita {$x->numero_vacantes} × {$x->cargo}",
            'url' => "/app/rrhh/requisiciones/{$x->id}",
            'icono' => 'heroicon-o-user-plus',
            'color' => 'warning',
        ]));
    }

    private function avisarAlSolicitante(Requisicion $x, string $titulo, string $mensaje, string $color): void
    {
        NotificacionErp::crear([
            'user_id' => $x->solicitante_id,
            'tipo' => 'rrhh_requisicion',
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => "/app/rrhh/requisiciones/{$x->id}",
            'icono' => 'heroicon-o-user-plus',
            'color' => $color,
        ]);
    }

    private function fila(Requisicion $x): array
    {
        return [
            'id' => $x->id,
            'numero' => $x->numero,
            'cargo' => $x->cargo,
            'area' => $x->area,
            'numero_vacantes' => $x->numero_vacantes,
            'estado' => $x->estado->value,
            'estado_label' => $x->estado->label(),
            'estado_color' => $x->estado->color(),
            'solicitante' => $x->solicitante?->name,
            'salario_min' => (float) $x->salario_min,
            'salario_max' => (float) $x->salario_max,
            'fecha_requerida' => $x->fecha_requerida?->toDateString(),
            'creada' => $x->created_at?->format('Y-m-d'),
            'vacantes_abiertas' => (int) ($x->vacantes_count ?? 0),
        ];
    }
}
