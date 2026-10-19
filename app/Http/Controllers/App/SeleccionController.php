<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Enums\ValoracionCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\OrdenContratacion;
use App\Modules\Rrhh\Models\Vacante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F2 · El proceso de selección, desde que llega la hoja de vida.
 *
 * Es el tramo que Elena lleva hoy en su cabeza y en carpetas: recibe hojas de
 * vida por varios lados, revisa antecedentes, cita a la entrevista con el jefe
 * y a la de psicología, hace pruebas, valida referencias y arma la terna que
 * le presenta al jefe que pidió el personal.
 *
 * Dos reglas del proceso quedan en el código y no en la memoria de nadie:
 * no se cita a entrevista sin haber revisado antecedentes, y una terna es de
 * tres.
 */
class SeleccionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_seleccion'), 403);

            return $next($r);
        })];
    }

    /** El tablero de una vacante: en qué columna va cada candidato. */
    public function tablero(int $vacante)
    {
        $v = Vacante::with('requisicion:id,numero,solicitante_id')->findOrFail($vacante);

        $candidatos = Candidato::where('vacante_id', $v->id)
            ->orderByDesc('id')->get();

        /*
         * El estado de la orden de la Junta, por candidato seleccionado.
         *
         * Sin esto el tablero ofrecía «Abrir contratación» a secas y el
         * servidor lo rebotaba: un botón que falla siempre es peor que no
         * tenerlo. Ahora la tarjeta dice qué toca —pedir la orden, esperar a
         * la Junta, o contratar—.
         */
        $ordenes = OrdenContratacion::whereIn('candidato_id', $candidatos->pluck('id'))
            ->get()->keyBy('candidato_id');

        // Y en qué va la contratación, si ya arrancó. Sin esto el tablero le
        // ofrecía «Pedir orden a la Junta» a alguien que ya estaba contratado.
        $contrataciones = \App\Modules\Rrhh\Models\Contratacion::whereIn('candidato_id', $candidatos->pluck('id'))
            ->get(['id', 'candidato_id', 'estado', 'empleado_id'])->keyBy('candidato_id');

        return \Inertia\Inertia::render('Rrhh/Seleccion/Tablero', [
            'vacante' => [
                'id' => $v->id,
                'titulo' => $v->titulo,
                'area' => $v->area,
                'estado' => $v->estado,
                'fuente' => $v->fuente,
                'requisicion' => $v->requisicion
                    ? ['id' => $v->requisicion->id, 'numero' => $v->requisicion->numero]
                    : null,
            ],
            // Las columnas se arman desde el enum: agregar una etapa no exige
            // tocar la pantalla.
            'columnas' => collect(EtapaCandidato::delTablero())->map(fn ($e) => [
                'valor' => $e->value,
                'label' => $e->label(),
                'color' => $e->color(),
                'responsable' => $e->responsable(),
                'exige_antecedentes' => $e->exigeAntecedentes(),
            ])->values(),
            'candidatos' => $candidatos->map(fn ($c) => $this->ficha(
                $c, $ordenes->get($c->id), $contrataciones->get($c->id)
            ))->values(),
            'descartados' => $candidatos->where('etapa', EtapaCandidato::Descartado)->count(),
            'fuentes' => collect(Vacante::FUENTES)->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            'valoraciones' => collect(ValoracionCandidato::cases())->map(fn ($v) => [
                'valor' => $v->value,
                'label' => $v->label(),
                'exige_observacion' => $v->exigeObservacion(),
            ])->values(),
            'terna_llena' => $candidatos->where('etapa', EtapaCandidato::Terna)->count() >= Candidato::TAMANO_TERNA,
        ]);
    }

    public function crear(Request $r, int $vacante): RedirectResponse
    {
        $v = Vacante::findOrFail($vacante);

        $data = $r->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'documento' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'fuente' => ['nullable', 'string', 'max:60'],
            'notas' => ['nullable', 'string', 'max:2000'],
            // La hoja de vida: PDF o imagen, hasta 8 MB. No se guarda en el
            // disco público porque lleva datos personales — ver `verHojaDeVida`.
            'cv' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:8192'],
        ], [], ['cv' => 'hoja de vida']);

        $c = DB::transaction(function () use ($data, $v, $r) {
            $c = Candidato::create([
                ...collect($data)->except('cv')->all(),
                'vacante_id' => $v->id,
                'etapa' => EtapaCandidato::Recibido->value,
            ]);

            if ($r->hasFile('cv')) {
                $c->cv_path = $r->file('cv')->store("rrhh/hojas-de-vida/{$v->id}");
                $c->save();
            }

            $fuente = $data['fuente'] ?? null;
            $c->anotar('recibido', 'Hoja de vida recibida'.(
                $fuente ? ' · '.(Vacante::FUENTES[$fuente] ?? $fuente) : ''
            ));

            return $c;
        });

        return back()->with('success', "{$c->nombre} quedó registrado. Revisá los antecedentes antes de citarlo.");
    }

    /**
     * Mueve a un candidato de etapa.
     *
     * Las reglas viven en el modelo, no acá: así el tablero, un botón suelto o
     * lo que venga después no pueden saltárselas por caminos distintos.
     */
    public function mover(Request $r, int $candidato): RedirectResponse
    {
        $data = $r->validate([
            'etapa' => ['required', new \Illuminate\Validation\Rules\Enum(EtapaCandidato::class)],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        $c = Candidato::with('vacante.requisicion')->findOrFail($candidato);
        $destino = EtapaCandidato::from($data['etapa']);

        if ($motivo = $c->porQueNoPuedeIrA($destino)) {
            return back()->withErrors(['etapa' => $motivo]);
        }

        DB::transaction(function () use ($c, $destino, $data) {
            // `validate()` NO devuelve las claves que no vinieron en la
            // petición, así que un campo opcional se lee siempre con `??`.
            if (! $c->moverA($destino, ($data['nota'] ?? null) ?: "Pasó a {$destino->label()}")) {
                return;
            }

            // Cuando hay alguien en la terna, el jefe tiene que enterarse: es
            // su turno de decidir y hoy se entera por una llamada.
            if ($destino === EtapaCandidato::Terna) {
                $this->avisarAlJefeDeLaTerna($c);
            }
        });

        return back()->with('success', "{$c->nombre} pasó a {$destino->label()}.");
    }

    /** Lo saca del proceso, diciendo por qué. */
    public function descartar(Request $r, int $candidato): RedirectResponse
    {
        // Sin motivo, dentro de tres meses nadie sabe si a esta persona se la
        // puede volver a llamar para otra vacante.
        $data = $r->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['motivo' => 'motivo del descarte']);

        $c = Candidato::findOrFail($candidato);
        abort_if($c->etapa->esFinal(), 422, 'Este candidato ya salió del proceso.');

        $c->motivo_descarte = $data['motivo'];
        $c->moverA(EtapaCandidato::Descartado, "Descartado: {$data['motivo']}");

        return back()->with('success', "{$c->nombre} quedó descartado.");
    }

    /** Deja escrito que se revisaron los antecedentes judiciales y qué salió. */
    public function antecedentes(Request $r, int $candidato): RedirectResponse
    {
        $data = $r->validate([
            'resultado' => ['required', 'string', 'max:300'],
        ], [], ['resultado' => 'resultado de la consulta']);

        $c = Candidato::findOrFail($candidato);
        $c->update([
            'antecedentes_revisados' => true,
            'antecedentes_resultado' => $data['resultado'],
        ]);
        $c->anotar('antecedentes', "Antecedentes revisados: {$data['resultado']}");

        return back()->with('success', 'Antecedentes registrados. Ya se puede citar a entrevista.');
    }

    /** El dictamen de Gestión Humana sobre el candidato. */
    public function valorar(Request $r, int $candidato): RedirectResponse
    {
        $data = $r->validate([
            'valoracion' => ['required', new \Illuminate\Validation\Rules\Enum(ValoracionCandidato::class)],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $valoracion = ValoracionCandidato::from($data['valoracion']);
        $observacion = $data['observacion'] ?? null;

        // «Apto con restricción» sin decir cuál no le sirve al jefe que elige.
        if ($valoracion->exigeObservacion() && blank($observacion)) {
            return back()->withErrors([
                'observacion' => "«{$valoracion->label()}» necesita que expliques por qué.",
            ]);
        }

        $c = Candidato::findOrFail($candidato);
        $c->update([
            'valoracion' => $valoracion->value,
            'valoracion_observacion' => $observacion,
        ]);
        $c->anotar('valoracion', trim($valoracion->label().' · '.$observacion, ' ·'));

        return back()->with('success', 'Valoración registrada.');
    }

    /**
     * La hoja de vida, servida sólo a quien puede verla.
     *
     * No va en el disco público —como sí van las fotos de producto— porque
     * lleva cédula, dirección y teléfono de alguien que ni siquiera trabaja
     * acá. En el disco público bastaría con tener el enlace.
     */
    public function verHojaDeVida(int $candidato): StreamedResponse
    {
        $c = Candidato::findOrFail($candidato);

        abort_unless($c->cv_path && Storage::exists($c->cv_path), 404, 'Este candidato no tiene hoja de vida cargada.');

        return Storage::response(
            $c->cv_path,
            'HV-'.str($c->nombre)->slug().'.'.pathinfo($c->cv_path, PATHINFO_EXTENSION),
            ['Content-Disposition' => 'inline'],
        );
    }

    // ───────────────────────────── internos ─────────────────────────────

    /**
     * Le avisa al jefe que ya hay gente esperando su decisión.
     *
     * Avisa con el PRIMERO que entra a la terna, no al llegar a tres. Dos
     * razones, las dos vistas en pantalla:
     *
     *   · Una vacante puede cerrarse con dos finalistas, o con uno. Avisar
     *     sólo al llegar a tres dejaba a esos procesos sin aviso nunca, que es
     *     exactamente el problema que el módulo vino a resolver.
     *   · Y avisar por cada uno que entra sería ruido. Por eso se manda una
     *     sola vez: cuando el de la terna es el primero.
     */
    private function avisarAlJefeDeLaTerna(Candidato $c): void
    {
        $jefe = $c->vacante?->requisicion?->solicitante_id;
        if (! $jefe) {
            return;
        }

        $enTerna = Candidato::where('vacante_id', $c->vacante_id)->deLaTerna()->count();
        if ($enTerna !== 1) {
            return;
        }

        NotificacionErp::crear([
            'user_id' => $jefe,
            'tipo' => 'rrhh_terna',
            'titulo' => 'Hay candidatos para que elijas · '.$c->vacante->titulo,
            'mensaje' => 'Ya podés ver la terna y elegir a quién se contrata.',
            'url' => "/app/rrhh/requisiciones/{$c->vacante->requisicion->id}",
            'icono' => 'heroicon-o-user-group',
            'color' => 'success',
        ]);
    }

    private function ficha(
        Candidato $c,
        ?OrdenContratacion $orden = null,
        ?\App\Modules\Rrhh\Models\Contratacion $contratacion = null,
    ): array {
        return [
            'contratacion' => $contratacion ? [
                'id' => $contratacion->id,
                'estado' => $contratacion->estado,
                'empleado_id' => $contratacion->empleado_id,
            ] : null,
            'orden' => $orden ? [
                'numero' => $orden->numero,
                'estado' => $orden->estado,
                'label' => $orden->etiquetaEstado(),
                'color' => $orden->colorEstado(),
                'autoriza' => $orden->autoriza(),
                'motivo' => $orden->motivo_decision,
            ] : null,
            'id' => $c->id,
            'nombre' => $c->nombre,
            'documento' => $c->documento,
            'email' => $c->email,
            'telefono' => $c->telefono,
            'fuente' => $c->fuente ? (Vacante::FUENTES[$c->fuente] ?? $c->fuente) : null,
            'etapa' => $c->etapa->value,
            'etapa_label' => $c->etapa->label(),
            'tiene_hv' => (bool) $c->cv_path,
            'notas' => $c->notas,
            'calificacion' => $c->calificacion,
            'valoracion' => $c->valoracion?->value,
            'valoracion_label' => $c->valoracion?->label(),
            'valoracion_color' => $c->valoracion?->color(),
            'valoracion_observacion' => $c->valoracion_observacion,
            'antecedentes_revisados' => (bool) $c->antecedentes_revisados,
            'antecedentes_resultado' => $c->antecedentes_resultado,
            'motivo_descarte' => $c->motivo_descarte,
            'elegido' => (bool) $c->elegido_at,
            'desde' => $c->created_at?->format('Y-m-d'),
        ];
    }
}
