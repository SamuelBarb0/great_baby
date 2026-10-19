<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Modules\Rrhh\Models\DocumentoEmpleado;
use App\Modules\Rrhh\Models\Empleado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F3 + F4 · La ficha del empleado: su historia laboral.
 *
 * Acá vive lo que hoy está repartido entre carpetas y la memoria de Elena:
 * de qué vacante salió, qué papeles tiene, cuándo se le dio la inducción y
 * quién, y —lo más importante— hasta cuándo va su período de prueba.
 *
 * Ese último dato es el que hoy se pasa de largo: el período se vence, nadie
 * decide nada, y a partir de ahí terminar el contrato ya cuesta indemnización.
 */
class EmpleadoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

            return $next($r);
        })];
    }

    public function show(int $empleado): Response
    {
        $e = Empleado::with([
            'vacante:id,titulo,requisicion_id',
            'contratacion:id,empleado_id,eps,arl,pension,caja,examen_resultado,contrato_firmado_at',
            'induccionPor:id,name',
            'documentos.subidoPor:id,name',
        ])->findOrFail($empleado);

        return Inertia::render('Rrhh/Empleados/Show', [
            'empleado' => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'documento' => $e->tipo_documento.' '.$e->numero_documento,
                'email' => $e->email,
                'telefono' => $e->telefono,
                'cargo' => $e->cargo,
                'area' => $e->area,
                'tipo_contrato' => $e->tipo_contrato,
                'salario' => (float) $e->salario,
                'fecha_ingreso' => $e->fecha_ingreso?->toDateString(),
                'estado' => $e->estado,
                'estado_induccion' => $e->estado_induccion,
                'induccion_at' => $e->induccion_at?->format('Y-m-d'),
                'induccion_por' => $e->induccionPor?->name,
                'induccion_temas' => $e->induccion_temas,
                'prueba_hasta' => $e->prueba_hasta?->toDateString(),
                'prueba_sugerida' => $e->pruebaSugerida()?->toDateString(),
                'prueba_dias' => $e->diasDePrueba(),
                'prueba_evaluada_at' => $e->prueba_evaluada_at?->format('Y-m-d'),
                'prueba_resultado' => $e->prueba_resultado,
                'prueba_observacion' => $e->prueba_observacion,
                'tipo_retiro' => $e->tipo_retiro,
                'motivo_retiro' => $e->motivo_retiro,
                'fecha_retiro' => $e->fecha_retiro?->toDateString(),
                'vacante' => $e->vacante ? ['id' => $e->vacante->id, 'titulo' => $e->vacante->titulo] : null,
                'requisicion_id' => $e->vacante?->requisicion_id,
                'afiliaciones' => $e->contratacion ? [
                    'EPS' => $e->contratacion->eps,
                    'ARL' => $e->contratacion->arl,
                    'Pensión' => $e->contratacion->pension,
                    'Caja' => $e->contratacion->caja,
                ] : null,
            ],
            'documentos' => $e->documentos->map(fn ($d) => [
                'id' => $d->id,
                'tipo_label' => $d->tipo_label,
                'nombre' => $d->nombre,
                'subido_por' => $d->subidoPor?->name,
                'cuando' => $d->created_at?->format('Y-m-d'),
                'vence' => $d->fecha_vencimiento?->toDateString(),
            ])->values(),
            'tipos_documento' => collect(DocumentoEmpleado::TIPOS)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            // Para registrar el retiro desde acá: sin el motivo, el indicador
            // de rotación no puede responder la pregunta de la Junta.
            'tipos_retiro' => collect(\App\Modules\Rrhh\Support\IndicadoresRrhh::TIPOS_RETIRO)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            'bitacora' => $e->bitacora()->with('usuario:id,name')->get()->map(fn ($b) => [
                'resumen' => $b->resumen,
                'usuario' => $b->usuario?->name ?? 'Sistema',
                'cuando' => $b->created_at?->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    /**
     * Deja escrita la inducción: cuándo, quién la dio y qué se vio.
     *
     * Antes sólo se podía marcar «completada», sin fecha ni responsable. Para
     * una inducción en seguridad y salud en el trabajo eso es justo lo que hay
     * que poder demostrar.
     */
    public function induccion(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'temas' => ['required', 'string', 'min:10', 'max:2000'],
        ], [], ['temas' => 'temas vistos']);

        $e = Empleado::findOrFail($empleado);

        $e->update([
            'estado_induccion' => 'completada',
            'induccion_at' => now(),
            'induccion_por_id' => $r->user()->id,
            'induccion_temas' => $data['temas'],
        ]);
        $e->anotar('induccion', "Inducción dada por {$r->user()->name}");

        return back()->with('success', 'Inducción registrada.');
    }

    /** Fija hasta cuándo va el período de prueba. */
    public function periodoDePrueba(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        $data = $r->validate([
            // No puede terminar antes de que la persona entre: sería una fecha
            // vencida desde el primer día.
            'hasta' => ['required', 'date', 'after:'.($e->fecha_ingreso?->toDateString() ?? 'today')],
        ], [], ['hasta' => 'fecha']);

        $e->update(['prueba_hasta' => $data['hasta']]);
        $e->anotar('prueba', 'Período de prueba hasta '.$data['hasta']);

        return back()->with('success', 'Período de prueba registrado.');
    }

    /**
     * La decisión al final del período de prueba.
     *
     * Hay que tomarla ANTES de que se venza: después, terminar el contrato ya
     * no se puede sin justa causa ni indemnización.
     */
    public function evaluarPrueba(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'resultado' => ['required', 'in:continua,no_continua'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $e = Empleado::with('vacante.requisicion')->findOrFail($empleado);
        abort_if($e->prueba_evaluada_at, 422, 'Este período de prueba ya fue evaluado.');

        /*
         * Primero el jefe, después Gestión Humana.
         *
         * «Ese período de prueba debe estar validado por el jefe del área y por
         * gestión humana» (Elena, 19-oct). El jefe es el que sabe si la persona
         * rinde en el puesto; decidir sin su concepto es decidir a ciegas.
         *
         * La excepción es la gente que entró por fuera del proceso —los que ya
         * estaban antes del módulo—: ahí no hay jefe a quién preguntarle.
         */
        if ($e->jefeDelArea() && ! $e->tieneConceptoDelJefe()) {
            return back()->withErrors([
                'resultado' => 'Falta el concepto del jefe del área. Sin eso no se cierra.',
            ]);
        }

        $observacion = $data['observacion'] ?? null;

        // «No continúa» sin decir por qué deja a la empresa sin con qué
        // sustentar la decisión si alguien la reclama después.
        if ($data['resultado'] === 'no_continua' && blank($observacion)) {
            return back()->withErrors(['observacion' => 'Decí por qué no continúa.']);
        }

        $e->update([
            'prueba_evaluada_at' => now(),
            // Quién decidió. En la historia laboral impresa, esta columna
            // mostraba un guion donde el concepto del jefe sí lleva nombre.
            'prueba_evaluada_por_id' => $r->user()->id,
            'prueba_resultado' => $data['resultado'],
            'prueba_observacion' => $observacion,
            ...($data['resultado'] === 'no_continua'
                ? ['estado' => 'retirado', 'fecha_retiro' => now()->toDateString()]
                : []),
        ]);

        $e->anotar('prueba_evaluada', ($data['resultado'] === 'continua'
            ? 'Superó el período de prueba'
            : "No continúa: {$observacion}")
            .($e->prueba_jefe_resultado ? " · el jefe conceptuó «{$e->prueba_jefe_resultado}»" : ''));

        return back()->with('success', $data['resultado'] === 'continua'
            ? "{$e->nombre} superó el período de prueba."
            : "Quedó registrado el retiro de {$e->nombre}.");
    }

    /*
     * El concepto del jefe del área vive en `MiEquipoController`.
     *
     * No puede estar acá: este controlador exige el permiso `rrhh`, y el líder
     * de área no lo tiene —ahí están los salarios y las hojas de vida de toda
     * la empresa—. Él entra por su propia pantalla y sólo sobre SU gente.
     */

    public function subirDocumento(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', 'in:'.implode(',', array_keys(DocumentoEmpleado::TIPOS))],
            'nombre' => ['nullable', 'string', 'max:160'],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:8192'],
            'fecha_vencimiento' => ['nullable', 'date'],
        ], [], ['archivo' => 'documento']);

        $e = Empleado::findOrFail($empleado);

        DocumentoEmpleado::create([
            'empleado_id' => $e->id,
            'candidato_id' => $e->candidato_id,
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre'] ?? DocumentoEmpleado::TIPOS[$data['tipo']],
            'archivo_path' => $r->file('archivo')->store("rrhh/documentos/emp-{$e->id}"),
            'subido_por' => $r->user()->id,
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
        ]);

        $e->anotar('documento', 'Documento cargado: '.DocumentoEmpleado::TIPOS[$data['tipo']]);

        return back()->with('success', 'Documento cargado.');
    }

    /**
     * Avisa de los períodos de prueba que están por vencerse.
     *
     * Lo llama el comando programado: es el recordatorio que hoy no existe y
     * por eso el plazo se pasa solo.
     */
    public static function avisarPruebasPorVencer(int $dias = 15): int
    {
        $porVencer = Empleado::pruebaPorVencer($dias)->with('vacante.requisicion')->get();
        if ($porVencer->isEmpty()) {
            return 0;
        }

        $gestionHumana = \App\Models\User::permission(Permisos::permiso('rrhh'))->pluck('id');

        foreach ($porVencer as $e) {
            $faltan = $e->diasDePrueba();
            $titulo = $faltan < 0
                ? "Período de prueba VENCIDO · {$e->nombre}"
                : "Período de prueba vence en {$faltan} día(s) · {$e->nombre}";
            $color = $faltan < 0 ? 'danger' : 'warning';

            foreach ($gestionHumana as $id) {
                NotificacionErp::crear([
                    'user_id' => $id,
                    'tipo' => 'rrhh_prueba',
                    'titulo' => $titulo,
                    'mensaje' => "{$e->cargo} · hasta el {$e->prueba_hasta->toDateString()}. Hay que decidir antes.",
                    'url' => "/app/rrhh/empleados/{$e->id}",
                    'icono' => 'heroicon-o-clock',
                    'color' => $color,
                ]);
            }

            /*
             * Y al jefe del área, que es quien opina PRIMERO.
             *
             * Sin esto, Gestión Humana se enteraba de que el plazo corría pero
             * quien tenía que dar el primer paso no se enteraba nunca, y el
             * período se vencía igual. La pantalla llegó a decir «ya se le
             * pidió» cuando no se le había pedido nada.
             */
            $jefe = $e->jefeDelArea();
            if ($jefe && ! $e->tieneConceptoDelJefe() && ! $gestionHumana->contains($jefe)) {
                NotificacionErp::crear([
                    'user_id' => $jefe,
                    'tipo' => 'rrhh_prueba',
                    'titulo' => $titulo,
                    'mensaje' => "Falta TU concepto sobre {$e->nombre} ({$e->cargo}). Gestión Humana no puede cerrar sin él.",
                    'url' => '/app/rrhh/mi-equipo',
                    'icono' => 'heroicon-o-clock',
                    'color' => $color,
                ]);
            }
        }

        return $porVencer->count();
    }
}
