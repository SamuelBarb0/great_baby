<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\OrdenContratacion;
use Barryvdh\DomPDF\Facade\Pdf;
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
            // Gestión Humana diligencia el formato; la Junta sólo lo lee y firma.
            'es_gestion_humana' => Permisos::puede($r->user(), 'rrhh'),
            'periodicidades' => collect(OrdenContratacion::PERIODICIDADES)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
        ]);
    }

    /**
     * Gestión Humana pide la autorización para contratar a quien se eligió.
     *
     * Idempotente mientras la orden esté PENDIENTE: el botón se puede apretar
     * dos veces sin mandarle dos papeles iguales a la Junta.
     *
     * Después de una NEGATIVA sí deja pedir otra, y es el arreglo principal de
     * este método. Antes usaba `firstOrNew(['candidato_id' => ...])`: con una
     * orden negada en la tabla encontraba esa, el `if` no entraba, no se creaba
     * nada, no se avisaba a nadie — y aun así devolvía «Orden ODC-… enviada a
     * la Junta Directiva». Gestión Humana se quedaba esperando una respuesta a
     * una solicitud que nunca salió, con la persona atascada en `seleccionado`:
     * ni contratable ni descartable. Negar con motivo existe justamente para
     * que se pueda insistir o bajar el salario; ahora se puede.
     */
    public function solicitar(Request $r, int $candidato): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403,
            'La orden la pide Gestión Humana.');

        $c = Candidato::with('vacante.requisicion')->findOrFail($candidato);

        abort_unless($c->etapa === EtapaCandidato::Seleccionado, 422,
            'La orden se pide cuando el jefe ya eligió a la persona.');

        $data = $r->validate($this->reglasDelFormato());

        // La ÚLTIMA, no la primera: después de una negativa hay más de una.
        $ultima = OrdenContratacion::where('candidato_id', $c->id)
            ->orderByDesc('id')->first();

        if ($ultima && $ultima->estado === 'pendiente') {
            return redirect()->route('app.rrhh.ordenes')
                ->with('success', "La orden {$ultima->numero} de {$c->nombre} ya está con la Junta Directiva.");
        }

        if ($ultima && $ultima->autoriza()) {
            return redirect()->route('app.rrhh.ordenes')
                ->with('success', "La orden {$ultima->numero} de {$c->nombre} ya fue autorizada. Seguí con la contratación.");
        }

        // No hay ninguna, o la última fue negada: se presenta una nueva, con
        // su propio consecutivo. La negada queda en la tabla tal como la firmó
        // la Junta, que es lo que un documento firmado tiene que hacer.
        $o = new OrdenContratacion(['candidato_id' => $c->id]);

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
            /*
             * Lo que el formato GH-F-018 pide además del cargo y el
             * salario. Lo que no venga escrito se hereda de la vacante,
             * que es de donde salió el cargo: así la casilla llega con el
             * valor que de verdad rige y no en blanco.
             */
            'area' => $data['area'] ?? $c->vacante?->area,
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'horario' => $data['horario'] ?? null,
            // La ciudad no se hereda: la requisición todavía no la pide.
            // El formato #1 sí la tiene («Ciudad que solicita el cargo»),
            // y entra en la Fase C.
            'ciudad' => $data['ciudad'] ?? null,
            'periodicidad_pago' => $data['periodicidad_pago'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
        ])->save();

        $o->anotar('solicitada', "Orden {$o->numero} solicitada para {$c->nombre}");
        $this->avisarALaJunta($o, $c);

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

    /**
     * Fase D1 · Completar el formato antes de que la Junta lo firme.
     *
     * Las casillas se pueden corregir mientras la orden esté PENDIENTE. Una
     * vez decidida no: lo que la Junta autorizó fue ese papel, con ese horario
     * y esa fecha de inicio, y cambiarlo después sería mover lo autorizado sin
     * que nadie se entere.
     */
    public function actualizar(Request $r, int $orden): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403,
            'La orden la diligencia Gestión Humana.');

        $o = OrdenContratacion::findOrFail($orden);

        abort_unless($o->estado === 'pendiente', 422,
            "La orden {$o->numero} ya fue decidida. Lo que la Junta autorizó no se edita.");

        $o->update($r->validate($this->reglasDelFormato()));

        $faltan = $o->fresh()->faltantesDelFormato();

        return back()->with('success', $faltan === []
            ? "Orden {$o->numero} completa."
            : "Orden {$o->numero} guardada. Todavía falta: ".implode(', ', $faltan).'.');
    }

    /**
     * El formato impreso, para la carpeta y para las firmas.
     *
     * Es GH-F-018 «FORMATO AUTORIZACIÓN DE CONTRATACIÓN». Lo abren los dos
     * lados —Gestión Humana que lo pide y la Junta que lo firma— porque los
     * dos lo necesitan: ella para archivarlo, ellos para leer qué están
     * autorizando sin tener que entrar a la pantalla.
     */
    public function pdf(Request $r, int $orden)
    {
        abort_unless(
            Permisos::puede($r->user(), 'rrhh')
            || Permisos::puede($r->user(), 'rrhh_orden_contratacion'),
            403,
        );

        $o = OrdenContratacion::with([
            'candidato:id,nombre,documento',
            'requisicion.solicitante:id,name',
            'vacante:id,titulo,area',
            'solicitadaPor:id,name',
            'decididaPor:id,name',
        ])->findOrFail($orden);

        return Pdf::loadView('rrhh.pdf.orden-contratacion', [
            'o' => $o,
            'lider' => $o->liderDelArea(),
            'periodicidades' => OrdenContratacion::PERIODICIDADES,
            'faltantes' => $o->faltantesDelFormato(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            // `stream` y no `download`: este formato se mira antes de
            // imprimirlo para firmarlo. Bajarlo a ciegas obliga a abrir el
            // archivo para descubrir que faltaba una casilla.
            ->stream('orden-contratacion-'.str($o->numero)->slug().'.pdf');
    }

    /**
     * Las once casillas del formato.
     *
     * Ninguna es obligatoria para GUARDAR —se diligencia de a poco, como el
     * papel— pero `faltantesDelFormato()` avisa cuáles siguen en blanco antes
     * de mandarlo a firmar.
     */
    private function reglasDelFormato(): array
    {
        return [
            'salario_propuesto' => ['nullable', 'numeric', 'min:0'],
            'justificacion' => ['nullable', 'string', 'max:1000'],
            'area' => ['nullable', 'string', 'max:120'],
            'fecha_inicio' => ['nullable', 'date'],
            'horario' => ['nullable', 'string', 'max:160'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'periodicidad_pago' => ['nullable', 'in:mensual,quincenal'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function paraDecidir(Request $r, int $id): OrdenContratacion
    {
        abort_unless($this->esJunta($r), 403, 'Sólo la Junta Directiva autoriza.');

        $o = OrdenContratacion::findOrFail($id);
        abort_unless($o->estado === 'pendiente', 422, 'Esta orden ya fue decidida.');

        return $o;
    }

    private function avisarALaJunta(OrdenContratacion $o, Candidato $c): void
    {
        Permisos::usuariosQuePueden('rrhh_orden_contratacion')->get(['id'])
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
        $destinos = Permisos::usuariosQuePueden('rrhh')->pluck('id');

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
            // Fase D1 · las casillas del formato GH-F-018.
            'area' => $o->area,
            'fecha_inicio' => $o->fecha_inicio?->toDateString(),
            'horario' => $o->horario,
            'ciudad' => $o->ciudad,
            'periodicidad_pago' => $o->periodicidad_pago,
            'observaciones' => $o->observaciones,
            // Lo que todavía va en blanco. Si se firma así, alguien completa
            // el papel a mano DESPUÉS de la firma.
            'faltantes' => $o->faltantesDelFormato(),
        ];
    }
}
