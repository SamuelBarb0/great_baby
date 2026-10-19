<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Modules\Rrhh\Enums\EtapaCandidato;
use App\Modules\Rrhh\Enums\ValoracionCandidato;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\DocumentoEmpleado;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\OrdenContratacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F3 · De «lo elegimos» a «ya trabaja acá».
 *
 * El tramo más frágil del proceso. Hoy el examen médico, los papeles, la firma
 * del contrato y las cuatro afiliaciones de ley viven en carpetas: si falta
 * una afiliación nadie se entera hasta que pasa algo, y entonces es tarde y es
 * plata.
 *
 * Acá la lista de pendientes NO se guarda: se calcula sobre las fechas que
 * faltan (`Contratacion::loQueFalta()`), y es el MISMO cálculo que frena el
 * cierre. Si fueran dos listas, un día dirían cosas distintas.
 */
class ContratacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

            return $next($r);
        })];
    }

    /** Todas las contrataciones en curso: lo que hay que cerrar esta semana. */
    public function index(Request $r): Response
    {
        $filas = Contratacion::with('candidato:id,nombre,documento', 'vacante:id,titulo,area')
            ->when($r->query('estado', 'en_proceso') !== 'todas',
                fn ($q) => $q->where('estado', $r->query('estado', 'en_proceso')))
            ->orderByDesc('id')->paginate(30);

        return Inertia::render('Rrhh/Contrataciones/Index', [
            'contrataciones' => $filas->through(fn ($c) => [
                'id' => $c->id,
                'nombre' => $c->candidato?->nombre,
                'documento' => $c->candidato?->documento,
                'cargo' => $c->vacante?->titulo,
                'area' => $c->vacante?->area,
                'estado' => $c->estado,
                'fecha_ingreso' => $c->fecha_ingreso?->toDateString(),
                'falta' => count($c->loQueFalta()),
                'frenada' => $c->examenLoFrena(),
            ]),
            'filtro' => $r->query('estado', 'en_proceso'),
            'en_proceso' => Contratacion::where('estado', 'en_proceso')->count(),
        ]);
    }

    /**
     * Abre el expediente de contratación de un candidato elegido.
     *
     * Es idempotente: si ya existe, lleva al que hay. Así el botón se puede
     * apretar dos veces sin abrir dos expedientes de la misma persona.
     */
    public function abrir(int $candidato): RedirectResponse
    {
        $c = Candidato::with('vacante.requisicion')->findOrFail($candidato);

        abort_unless($c->etapa === EtapaCandidato::Seleccionado, 422,
            'La contratación arranca cuando el jefe ya eligió a la persona.');

        /*
         * El control que faltaba: la Junta Directiva.
         *
         * «Solo ingresa la empresa realmente a un contrato si viene autorizado
         * por la junta directiva. No es el líder el que toma la decisión final»
         * (Elena, 19-oct). Que el jefe haya elegido de la terna NO alcanza: lo
         * que la junta autoriza es el gasto.
         */
        $orden = OrdenContratacion::where('candidato_id', $c->id)->first();

        if (! $orden || ! $orden->autoriza()) {
            return redirect()->route('app.rrhh.ordenes')->withErrors([
                'orden' => $orden
                    ? "La orden {$orden->numero} está «{$orden->etiquetaEstado()}». Sin autorización de la Junta no se puede contratar."
                    : "Antes de contratar a {$c->nombre} hay que pedirle la orden de contratación a la Junta Directiva.",
            ]);
        }

        $x = Contratacion::firstOrCreate(
            ['candidato_id' => $c->id],
            [
                'vacante_id' => $c->vacante_id,
                'orden_id' => $orden->id,
                'estado' => 'en_proceso',
                // Lo acordado NO sale de la requisición sino de lo que la
                // Junta autorizó: son dos números que pueden no coincidir, y
                // el que manda es el autorizado.
                'salario' => $orden->salario_propuesto,
                'tipo_contrato' => $orden->tipo_contrato ?: $c->vacante?->tipo_contrato,
            ],
        );

        if ($x->wasRecentlyCreated) {
            $x->anotar('abierta', "Contratación abierta para {$c->nombre}");
        }

        return redirect()->route('app.rrhh.contratacion.show', $x->id);
    }

    public function show(int $contratacion): Response
    {
        $x = Contratacion::with([
            'candidato:id,nombre,documento,email,telefono,vacante_id',
            'vacante:id,titulo,area,requisicion_id',
            'empleado:id,nombre',
        ])->findOrFail($contratacion);

        return Inertia::render('Rrhh/Contrataciones/Show', [
            'contratacion' => [
                'id' => $x->id,
                'estado' => $x->estado,
                'candidato' => [
                    'id' => $x->candidato?->id,
                    'nombre' => $x->candidato?->nombre,
                    'documento' => $x->candidato?->documento,
                    'email' => $x->candidato?->email,
                    'telefono' => $x->candidato?->telefono,
                ],
                'cargo' => $x->vacante?->titulo,
                'area' => $x->vacante?->area,
                'vacante_id' => $x->vacante_id,
                'salario' => (float) $x->salario,
                'tipo_contrato' => $x->tipo_contrato,
                'fecha_ingreso' => $x->fecha_ingreso?->toDateString(),
                'examen_at' => $x->examen_at?->format('Y-m-d H:i'),
                'examen_resultado' => $x->examen_resultado,
                'examen_observacion' => $x->examen_observacion,
                'contrato_firmado_at' => $x->contrato_firmado_at?->format('Y-m-d H:i'),
                'afiliaciones' => collect(Contratacion::AFILIACIONES)->map(fn ($label, $campo) => [
                    'campo' => $campo,
                    'label' => $label,
                    'entidad' => $x->{$campo},
                    'hecha' => (bool) $x->{$campo.'_at'},
                ])->values(),
                'empleado' => $x->empleado ? ['id' => $x->empleado->id, 'nombre' => $x->empleado->nombre] : null,
                'motivo_cancelacion' => $x->motivo_cancelacion,
            ],
            // La misma lista que frena el cierre.
            'falta' => $x->loQueFalta(),
            'frenada' => $x->examenLoFrena(),
            'documentos' => $this->documentosDe($x),
            'tipos_documento' => collect(DocumentoEmpleado::TIPOS)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            'resultados_examen' => collect(ValoracionCandidato::cases())->map(fn ($v) => [
                'valor' => $v->value, 'label' => $v->label(),
            ])->values(),
            'bitacora' => $x->bitacora()->with('usuario:id,name')->get()->map(fn ($b) => [
                'resumen' => $b->resumen,
                'usuario' => $b->usuario?->name ?? 'Sistema',
                'cuando' => $b->created_at?->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    /** Lo que se acordó: salario, tipo de contrato y cuándo entra. */
    public function acuerdo(Request $r, int $contratacion): RedirectResponse
    {
        $data = $r->validate([
            'salario' => ['required', 'numeric', 'min:0'],
            'tipo_contrato' => ['required', 'string', 'max:60'],
            'fecha_ingreso' => ['required', 'date'],
        ]);

        $x = $this->enProceso($contratacion);

        /*
         * Lo que la Junta autorizó es un techo, no una sugerencia.
         *
         * Congelar el salario en la orden no sirve de nada si después se puede
         * firmar por más: la autorización es sobre el gasto, y subirlo acá
         * sería autorizarse a sí mismo el aumento.
         */
        $x->loadMissing('orden');
        $tope = (float) ($x->orden?->salario_propuesto ?? 0);

        if ($tope > 0 && (float) $data['salario'] > $tope) {
            return back()->withErrors([
                'salario' => 'La Junta autorizó hasta $'.number_format($tope, 0, ',', '.').
                    '. Para pagar más hay que pedir una orden nueva.',
            ]);
        }

        $x->update($data);
        $x->anotar('acuerdo', 'Condiciones acordadas · ingreso '.$x->fecha_ingreso->toDateString());

        return back()->with('success', 'Condiciones guardadas.');
    }

    /**
     * El examen médico de ingreso.
     *
     * «No apto» no es un pendiente más: frena la contratación entera, porque
     * seguir sería exponer a la persona y a la empresa.
     */
    public function examen(Request $r, int $contratacion): RedirectResponse
    {
        $data = $r->validate([
            'resultado' => ['required', new \Illuminate\Validation\Rules\Enum(ValoracionCandidato::class)],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $resultado = ValoracionCandidato::from($data['resultado']);
        $observacion = $data['observacion'] ?? null;

        if ($resultado->exigeObservacion() && blank($observacion)) {
            return back()->withErrors([
                'observacion' => "«{$resultado->label()}» necesita que digas por qué.",
            ]);
        }

        $x = $this->enProceso($contratacion);
        $x->update([
            'examen_at' => now(),
            'examen_resultado' => $resultado->value,
            'examen_observacion' => $observacion,
        ]);
        $x->anotar('examen', trim('Examen médico: '.$resultado->label().' · '.$observacion, ' ·'));

        return back()->with('success', $x->examenLoFrena()
            ? 'Quedó registrado como NO APTO: la contratación no se puede cerrar así.'
            : 'Examen médico registrado.');
    }

    public function firmarContrato(int $contratacion): RedirectResponse
    {
        $x = $this->enProceso($contratacion);

        /*
         * Firmar sin saber qué se firmó no tiene sentido, y es lo que hoy pasa
         * cuando el contrato se imprime con los datos de la cabeza de alguien.
         *
         * Va como error de formulario y no como `abort(422)` porque este botón
         * SÍ está visible en ese estado: se vio en pantalla que al apretarlo no
         * pasaba nada y la usuaria no tenía cómo saber por qué.
         */
        if (! $x->salario || ! $x->fecha_ingreso) {
            return back()->withErrors([
                'cierre' => 'Antes de firmar hay que guardar el salario y la fecha de ingreso, acá arriba.',
            ]);
        }

        $x->update(['contrato_firmado_at' => now()]);
        $x->anotar('contrato', 'Contrato firmado');

        return back()->with('success', 'Contrato marcado como firmado.');
    }

    /** Una de las cuatro afiliaciones de ley, con el nombre de la entidad. */
    public function afiliacion(Request $r, int $contratacion): RedirectResponse
    {
        $data = $r->validate([
            'campo' => ['required', 'in:'.implode(',', array_keys(Contratacion::AFILIACIONES))],
            // Sin el nombre de la entidad el dato no sirve después: nadie sabe
            // a qué EPS llamar.
            'entidad' => ['required', 'string', 'max:120'],
        ], [], ['entidad' => 'entidad']);

        $x = $this->enProceso($contratacion);
        $campo = $data['campo'];

        $x->update([$campo => $data['entidad'], $campo.'_at' => now()]);
        $x->anotar('afiliacion', Contratacion::AFILIACIONES[$campo].': '.$data['entidad']);

        return back()->with('success', Contratacion::AFILIACIONES[$campo].' registrada.');
    }

    /** Un papel de la carpeta. */
    public function subirDocumento(Request $r, int $contratacion): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', 'in:'.implode(',', array_keys(DocumentoEmpleado::TIPOS))],
            'nombre' => ['nullable', 'string', 'max:160'],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:8192'],
            'fecha_vencimiento' => ['nullable', 'date'],
        ], [], ['archivo' => 'documento']);

        $x = Contratacion::findOrFail($contratacion);

        DocumentoEmpleado::create([
            // Mientras no haya empleado, el papel cuelga del candidato; al
            // crearse el empleado se heredan todos.
            'candidato_id' => $x->candidato_id,
            'empleado_id' => $x->empleado_id,
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre'] ?? DocumentoEmpleado::TIPOS[$data['tipo']],
            'archivo_path' => $r->file('archivo')->store("rrhh/documentos/{$x->candidato_id}"),
            'subido_por' => $r->user()->id,
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
        ]);

        $x->anotar('documento', 'Documento cargado: '.DocumentoEmpleado::TIPOS[$data['tipo']]);

        return back()->with('success', 'Documento cargado.');
    }

    /**
     * Cierra la contratación y crea al empleado.
     *
     * Acá es donde la persona pasa a existir como empleada. No antes: hasta
     * este momento todavía podía no aceptar la oferta o no pasar el examen.
     */
    public function completar(int $contratacion): RedirectResponse
    {
        $x = $this->enProceso($contratacion);
        $x->load('candidato');

        if ($x->examenLoFrena()) {
            return back()->withErrors([
                'cierre' => 'El examen médico salió NO APTO. Esta contratación no se puede cerrar.',
            ]);
        }

        if ($falta = $x->loQueFalta()) {
            return back()->withErrors([
                'cierre' => 'Todavía falta: '.implode(', ', $falta).'.',
            ]);
        }

        $existe = Empleado::where('numero_documento', $x->candidato->documento)->exists();
        if ($existe) {
            return back()->withErrors([
                'cierre' => 'Ya hay un empleado con la cédula '.$x->candidato->documento.'.',
            ]);
        }

        $empleado = DB::transaction(function () use ($x) {
            $c = $x->candidato;

            // Los datos NO se vuelven a digitar: vienen de lo que la persona
            // ya dio como candidata. Ahí es donde se cuelan los errores.
            $empleado = Empleado::create([
                'candidato_id' => $c->id,
                'vacante_id' => $x->vacante_id,
                'nombre' => $c->nombre,
                'tipo_documento' => 'CC',
                'numero_documento' => $c->documento,
                'email' => $c->email,
                'telefono' => $c->telefono,
                'cargo' => $x->vacante?->titulo ?? 'Sin cargo',
                'area' => $x->vacante?->area ?? 'Sin área',
                'tipo_contrato' => $this->contratoValido($x->tipo_contrato),
                'salario' => $x->salario,
                'fecha_ingreso' => $x->fecha_ingreso,
                'estado' => 'activo',
                'estado_induccion' => 'pendiente',
            ]);

            // La carpeta de papeles lo sigue: ya no es candidato.
            DocumentoEmpleado::where('candidato_id', $c->id)
                ->update(['empleado_id' => $empleado->id]);

            $x->update([
                'estado' => 'completada',
                'empleado_id' => $empleado->id,
                'completada_at' => now(),
            ]);
            $x->anotar('completada', "Contratación cerrada · empleado #{$empleado->id}");
            $empleado->anotar('ingreso', "Ingresa como {$empleado->cargo} el ".$empleado->fecha_ingreso->toDateString());

            return $empleado;
        });

        return redirect()->route('app.rrhh.empleado.show', $empleado->id)
            ->with('success', "{$empleado->nombre} ya es empleado. Sigue la inducción.");
    }

    public function cancelar(Request $r, int $contratacion): RedirectResponse
    {
        $data = $r->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['motivo' => 'motivo']);

        $x = $this->enProceso($contratacion);
        $x->update([
            'estado' => 'cancelada',
            'cancelada_at' => now(),
            'motivo_cancelacion' => $data['motivo'],
        ]);
        $x->anotar('cancelada', "Cancelada: {$data['motivo']}");

        /*
         * El candidato vuelve a la terna, no se descarta: que esta contratación
         * no haya salido —porque no aceptó la oferta, por ejemplo— no lo hace
         * mal candidato, y puede que haya que volver a él o a otro de la terna.
         */
        $x->candidato?->moverA(EtapaCandidato::Terna, 'La contratación se canceló: vuelve a la terna');

        $jefe = $x->candidato?->vacante?->requisicion?->solicitante_id;
        if ($jefe) {
            NotificacionErp::crear([
                'user_id' => $jefe,
                'tipo' => 'rrhh_contratacion',
                'titulo' => 'Contratación cancelada',
                'mensaje' => "{$x->candidato->nombre} no se contrató. Motivo: {$data['motivo']}",
                'url' => "/app/rrhh/vacantes/{$x->vacante_id}",
                'icono' => 'heroicon-o-user-minus',
                'color' => 'danger',
            ]);
        }

        return back()->with('success', 'Contratación cancelada. El candidato volvió a la terna.');
    }

    public function verDocumento(int $documento): StreamedResponse
    {
        $d = DocumentoEmpleado::findOrFail($documento);

        abort_unless(Storage::exists($d->archivo_path), 404, 'El archivo ya no está.');

        return Storage::response($d->archivo_path, basename($d->archivo_path),
            ['Content-Disposition' => 'inline']);
    }

    // ───────────────────────────── internos ─────────────────────────────

    private function enProceso(int $id): Contratacion
    {
        $x = Contratacion::findOrFail($id);
        abort_unless($x->estado === 'en_proceso', 422,
            'Esta contratación ya está '.$x->estado.'.');

        return $x;
    }

    /**
     * `rrhh_empleados.tipo_contrato` es un `enum` de base con cinco valores.
     * La requisición acepta texto libre, así que lo que no encaje entra como
     * indefinido en vez de reventar el cierre con un error de SQL.
     */
    private function contratoValido(?string $tipo): string
    {
        $validos = ['indefinido', 'obra_labor', 'prestacion_servicios', 'aprendizaje', 'temporal'];

        return in_array($tipo, $validos, true) ? $tipo : 'indefinido';
    }

    private function documentosDe(Contratacion $x): array
    {
        return DocumentoEmpleado::with('subidoPor:id,name')
            ->where(fn ($q) => $q->where('candidato_id', $x->candidato_id)
                ->when($x->empleado_id, fn ($e) => $e->orWhere('empleado_id', $x->empleado_id)))
            ->orderByDesc('id')->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'tipo' => $d->tipo,
                'tipo_label' => $d->tipo_label,
                'nombre' => $d->nombre,
                'subido_por' => $d->subidoPor?->name,
                'cuando' => $d->created_at?->format('Y-m-d'),
                'vence' => $d->fecha_vencimiento?->toDateString(),
            ])->values()->all();
    }
}
