<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Enums\MotivoSalida;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntrevistaRetiro;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Fase F1 · La entrevista de retiro.
 *
 * Es el único momento del proceso en que alguien dice la verdad sin costo, y
 * el ERP lo estaba dejando pasar: al salir se guardaba fecha, tipo de retiro y
 * un detalle en texto. Con eso se cuenta cuánta gente se fue; no por qué.
 *
 * La hace Gestión Humana, no el jefe: el formato tiene dos firmas —el
 * colaborador que se retira y quien entrevista— y la mitad de las preguntas
 * son sobre el jefe. Pedírselas a él sería pedirle que se evalúe a sí mismo.
 */
class EntrevistaRetiroController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403,
                'La entrevista de retiro la hace Gestión Humana.');

            return $next($r);
        })];
    }

    /**
     * Guarda la entrevista. Una por persona: si ya hay, se corrige.
     *
     * No exige que el retiro esté registrado. La entrevista suele hacerse
     * ANTES del último día, y bloquearla hasta entonces obligaría a hacerla de
     * memoria o en un papel suelto, que es lo que se quiere dejar de hacer.
     */
    public function guardar(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        $data = $r->validate($this->reglas(), [], [
            'motivo_principal' => 'motivo principal',
            'fecha_entrevista' => 'fecha de la entrevista',
        ]);

        // «Otro» sin decir cuál no sirve para contar ni para entender.
        if (($data['motivo_principal'] ?? null) === MotivoSalida::Otro->value
            && blank($data['motivo_otro'] ?? null)) {
            return back()->withErrors([
                'motivo_otro' => 'Si el motivo es «otro», decí cuál.',
            ]);
        }

        $ev = EntrevistaRetiro::updateOrCreate(
            ['empleado_id' => $e->id],
            $data + ['entrevistador_id' => $r->user()->id],
        );

        $e->anotar('entrevista_retiro', trim(
            'Entrevista de retiro · '.($ev->motivoEnPalabras() ?? 'sin motivo registrado'), ' ·'
        ));

        return back()->with('success', "Entrevista de retiro de {$e->nombre} guardada.");
    }

    /**
     * El impreso del formato.
     *
     * El bloque de paz y salvo NO se escribe a mano: sale de
     * `rrhh_asignaciones`, que es donde ya está lo que se le entregó, con su
     * serial y su estado de devolución. Volver a listarlo en la entrevista
     * sería tener el mismo hecho en dos tablas, y el día que difieran nadie
     * sabría cuál creer.
     */
    public function pdf(Request $r, int $empleado)
    {
        $e = Empleado::with(['asignaciones.entregadaPor:id,name'])->findOrFail($empleado);

        $ev = EntrevistaRetiro::with('entrevistador:id,name')
            ->where('empleado_id', $e->id)
            ->firstOrFail();

        return Pdf::loadView('rrhh.pdf.entrevista-retiro', [
            'e' => $e,
            'ev' => $ev,
            'asignaciones' => $e->asignaciones,
            'pendientes' => $e->pendientesDeDevolver()->get(),
            'motivos' => MotivoSalida::catalogo(),
            'empresa' => \App\Models\EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('entrevista-retiro-'.str($e->nombre)->slug().'.pdf');
    }

    /**
     * Las reglas del formato.
     *
     * Casi todo es opcional: la entrevista se llena mientras se conversa, y
     * exigir campos haría que se guardara al final o no se guardara. Lo que sí
     * se valida es el RANGO de las notas y que el motivo sea uno de la lista.
     */
    private function reglas(): array
    {
        $reglas = [
            'fecha_entrevista' => ['required', 'date'],
            'salida' => ['nullable', 'in:voluntaria,involuntaria'],
            'motivo_principal' => ['nullable', Rule::enum(MotivoSalida::class)],
            'motivo_otro' => ['nullable', 'string', 'max:160'],
            'detalle_motivo' => ['nullable', 'string', 'max:2000'],
            'que_valoro' => ['nullable', 'string', 'max:2000'],
            'que_mejorar' => ['nullable', 'string', 'max:2000'],
            'funciones_correspondian' => ['nullable', 'boolean'],
            'funciones_explicacion' => ['nullable', 'string', 'max:2000'],
            'recomendaria' => ['nullable', 'boolean'],
            'recomendaria_por_que' => ['nullable', 'string', 'max:2000'],
            'accesos_cerrados' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (array_keys(EntrevistaRetiro::CRITERIOS) as $c) {
            $reglas["n_{$c}"] = ['nullable', 'integer', 'min:1', 'max:5'];
            $reglas["c_{$c}"] = ['nullable', 'string', 'max:500'];
        }

        return $reglas;
    }
}
