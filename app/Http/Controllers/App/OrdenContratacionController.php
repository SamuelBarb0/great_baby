<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\OrdenContratacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F5a · La orden de contratación que autoriza la Junta Directiva.
 *
 * Es el control que faltaba entre «el jefe eligió» y «arranca la contratación».
 * Elena lo dijo sin rodeos: «solo ingresa la empresa realmente a un contrato si
 * viene autorizado por la junta directiva. No es el líder el que toma la
 * decisión final de la contratación».
 *
 * Gestión Humana PIDE la orden; la Junta la autoriza o la niega. Son permisos
 * distintos a propósito: si `GestionHumana` pudiera autorizar, el control no
 * existiría porque se autorizaría a sí misma.
 */
class OrdenContratacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            // Entran los dos lados: quien pide (Gestión Humana) y quien
            // autoriza (la Junta). Cada acción vuelve a mirar cuál es cuál.
            abort_unless(
                Permisos::puede($r->user(), 'rrhh')
                || Permisos::puede($r->user(), 'rrhh_orden_contratacion'),
                403,
            );

            return $next($r);
        })];
    }

    private function esJunta(Request $r): bool
    {
        return Permisos::puede($r->user(), 'rrhh_orden_contratacion');
    }

    public function index(Request $r): Response
    {
        $filas = OrdenContratacion::with('candidato:id,nombre,documento', 'solicitadaPor:id,name', 'decididaPor:id,name')
            ->when($r->query('estado', 'pendiente') !== 'todas',
                fn ($q) => $q->where('estado', $r->query('estado', 'pendiente')))
            ->orderByDesc('id')->paginate(30);

        return Inertia::render('Rrhh/Ordenes/Index', [
            'ordenes' => $filas->through(fn ($o) => $this->fila($o)),
            'filtro' => $r->query('estado', 'pendiente'),
            'pendientes' => OrdenContratacion::pendientes()->count(),
            'es_junta' => $this->esJunta($r),
        ]);
    }

    /**
     * Gestión Humana pide la autorización para contratar a quien se eligió.
     *
     * Idempotente: si ya se pidió, lleva a la que hay. El botón se puede
     * apretar dos veces sin dejar dos órdenes de la misma persona.
     */
    public function solicitar(Request $r, int $candidato): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403,
            'La orden la pide Gestión Humana.');

        $c = Candidato::with('vacante.requisicion')->findOrFail($candidato);

        abort_unless($c->etapa === EtapaCandidato::Seleccionado, 422,
            'La orden se pide cuando el jefe ya eligió a la persona.');

        $data = $r->validate([
            'salario_propuesto' => ['nullable', 'numeric', 'min:0'],
            'justificacion' => ['nullable', 'string', 'max:1000'],
        ]);

        $o = OrdenContratacion::firstOrNew(['candidato_id' => $c->id]);

        if (! $o->exists) {
            $o->fill([
                'numero' => OrdenContratacion::siguienteNumero(),
                'requisicion_id' => $c->vacante?->requisicion_id,
                'vacante_id' => $c->vacante_id,
                'cargo' => $c->vacante?->titulo ?? 'Sin cargo',
                'tipo_contrato' => $c->vacante?->tipo_contrato,
                // Lo que se propone sale de la requisición, pero se puede
                // ajustar: lo que la junta autoriza es ESTE número.
                'salario_propuesto' => $data['salario_propuesto']
                    ?? $c->vacante?->requisicion?->salario_min,
                'justificacion' => $data['justificacion'] ?? null,
                'estado' => 'pendiente',
                'solicitada_por_id' => $r->user()->id,
                'solicitada_at' => now(),
            ])->save();

            $o->anotar('solicitada', "Orden {$o->numero} solicitada para {$c->nombre}");
            $this->avisarALaJunta($o, $c);
        }

        return redirect()->route('app.rrhh.ordenes')
            ->with('success', "Orden {$o->numero} enviada a la Junta Directiva.");
    }

    public function autorizar(Request $r, int $orden): RedirectResponse
    {
        $o = $this->paraDecidir($r, $orden);

        $data = $r->validate([
            // La junta puede autorizar por un valor distinto al propuesto.
            'salario_autorizado' => ['nullable', 'numeric', 'min:0'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($o, $r, $data) {
            if (! blank($data['salario_autorizado'] ?? null)) {
                $o->salario_propuesto = $data['salario_autorizado'];
            }
            $o->decidida_por_id = $r->user()->id;
            $o->decidida_at = now();
            $o->motivo_decision = $data['motivo'] ?? null;
            $o->cambiarEstado('autorizada', "Autorizada por {$r->user()->name}");

            $this->avisarAGestionHumana($o, 'Contratación autorizada',
                "La Junta autorizó la orden {$o->numero} ({$o->cargo}). Ya se puede contratar.",
                'success');
        });

        return back()->with('success', "Orden {$o->numero} autorizada.");
    }

    public function negar(Request $r, int $orden): RedirectResponse
    {
        $o = $this->paraDecidir($r, $orden);

        // Sin motivo, Gestión Humana no sabe si insistir, bajar el salario o
        // cerrar la vacante.
        $data = $r->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ], [], ['motivo' => 'motivo']);

        DB::transaction(function () use ($o, $r, $data) {
            $o->decidida_por_id = $r->user()->id;
            $o->decidida_at = now();
            $o->motivo_decision = $data['motivo'];
            $o->cambiarEstado('negada', "Negada por {$r->user()->name}: {$data['motivo']}");

            $this->avisarAGestionHumana($o, 'Contratación NO autorizada',
                "La Junta negó la orden {$o->numero}. Motivo: {$data['motivo']}",
                'danger');
        });

        return back()->with('success', "Orden {$o->numero} negada. Gestión Humana fue notificada.");
    }

    // ───────────────────────────── internos ─────────────────────────────

    private function paraDecidir(Request $r, int $id): OrdenContratacion
    {
        abort_unless($this->esJunta($r), 403, 'Sólo la Junta Directiva autoriza.');

        $o = OrdenContratacion::findOrFail($id);
        abort_unless($o->estado === 'pendiente', 422, 'Esta orden ya fue decidida.');

        return $o;
    }

    private function avisarALaJunta(OrdenContratacion $o, Candidato $c): void
    {
        User::permission(Permisos::permiso('rrhh_orden_contratacion'))->get(['id'])
            ->each(fn ($u) => NotificacionErp::crear([
                'user_id' => $u->id,
                'tipo' => 'rrhh_orden',
                'titulo' => "Autorización de contratación · {$o->numero}",
                'mensaje' => "{$c->nombre} · {$o->cargo}".
                    ($o->salario_propuesto ? ' · $'.number_format((float) $o->salario_propuesto, 0, ',', '.') : ''),
                'url' => '/app/rrhh/ordenes',
                'icono' => 'heroicon-o-check-badge',
                'color' => 'warning',
            ]));
    }

    private function avisarAGestionHumana(OrdenContratacion $o, string $titulo, string $mensaje, string $color): void
    {
        $destinos = User::permission(Permisos::permiso('rrhh'))->pluck('id');

        // Y a quien la pidió, que puede no estar en ese grupo si cambió de rol.
        if ($o->solicitada_por_id && ! $destinos->contains($o->solicitada_por_id)) {
            $destinos->push($o->solicitada_por_id);
        }

        $destinos->each(fn ($id) => NotificacionErp::crear([
            'user_id' => $id,
            'tipo' => 'rrhh_orden',
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => '/app/rrhh/ordenes',
            'icono' => 'heroicon-o-check-badge',
            'color' => $color,
        ]));
    }

    private function fila(OrdenContratacion $o): array
    {
        return [
            'id' => $o->id,
            'numero' => $o->numero,
            'candidato' => $o->candidato?->nombre,
            'documento' => $o->candidato?->documento,
            'cargo' => $o->cargo,
            'salario' => (float) $o->salario_propuesto,
            'tipo_contrato' => $o->tipo_contrato,
            'justificacion' => $o->justificacion,
            'estado' => $o->estado,
            'estado_label' => $o->etiquetaEstado(),
            'estado_color' => $o->colorEstado(),
            'solicitada_por' => $o->solicitadaPor?->name,
            'solicitada' => $o->solicitada_at?->format('Y-m-d H:i'),
            'decidida_por' => $o->decididaPor?->name,
            'decidida' => $o->decidida_at?->format('Y-m-d H:i'),
            'motivo_decision' => $o->motivo_decision,
            'candidato_id' => $o->candidato_id,
        ];
    }
}
