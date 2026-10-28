<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Induccion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * F8 · La inducción, con temario.
 *
 * Antes era un campo de texto libre que de siete personas llenó una. La
 * inducción es lo que la empresa tiene para demostrar que le explicó a
 * alguien cómo se trabaja y qué riesgos corre; «se le dio la inducción» no
 * demuestra nada.
 */
class InduccionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

            return $next($r);
        })];
    }

    /**
     * Registra o corrige la inducción.
     *
     * El temario se copia de la regla la primera vez y de ahí en adelante se
     * conserva el que quedó: si el área agrega un tema en marzo, la inducción
     * de enero no se vuelve incompleta por algo que no existía.
     */
    public function guardar(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        $data = $r->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'responsable_nombre' => ['required', 'string', 'max:160'],
            'responsable_cargo' => ['nullable', 'string', 'max:120'],
            'temas' => ['required', 'array', 'min:1'],
            'temas.*.tema' => ['required', 'string', 'max:250'],
            'temas.*.cubierto' => ['nullable', 'boolean'],
            'temas.*.responsable' => ['nullable', 'string', 'max:120'],
            'temas.*.observacion' => ['nullable', 'string', 'max:250'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            /*
             * La firma del colaborador es lo que convierte el registro en
             * prueba: una cosa es que la empresa diga que la dio y otra que
             * la persona diga que la recibió.
             */
            'firmada_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            // Ver la nota de `EntrevistaCandidatoController`: el `:date` de
            // `before_or_equal:today` no se traduce desde `attributes`, así
            // que el mensaje se escribe acá o sale «today» en inglés.
            'fecha.before_or_equal' => 'La inducción no puede tener fecha futura.',
            'firmada_at.before_or_equal' => 'La firma no puede tener fecha futura.',
        ], [
            'fecha' => 'fecha de la inducción',
            'responsable_nombre' => 'nombre de quien la dio',
            'temas' => 'temario',
            'temas.*.tema' => 'tema',
            'firmada_at' => 'fecha de firma',
        ]);

        $induccion = Induccion::firstOrNew(['empleado_id' => $e->id]);

        DB::transaction(function () use ($e, $induccion, $data, $r) {
            $induccion->fill([
                'empleado_id' => $e->id,
                'fecha' => $data['fecha'],
                'responsable_nombre' => $data['responsable_nombre'],
                'responsable_cargo' => $data['responsable_cargo'] ?? null,
                'temas' => array_map(fn (array $t) => [
                    'tema' => $t['tema'],
                    'cubierto' => (bool) ($t['cubierto'] ?? false),
                    'responsable' => $t['responsable'] ?? '',
                    'observacion' => $t['observacion'] ?? '',
                ], $data['temas']),
                'observaciones' => $data['observaciones'] ?? null,
                'firmada_at' => $data['firmada_at'] ?? null,
                'registrada_por_id' => $r->user()->id,
            ])->save();

            /*
             * La ficha sigue siendo la que manda para el resto del sistema
             * —el menú, los avisos y el cierre de la contratación miran
             * `estado_induccion`—, así que se mantiene en sincronía. El
             * estado pasa a completada sólo cuando no falta ningún tema.
             */
            $e->update([
                'estado_induccion' => $induccion->completa() ? 'completada' : 'pendiente',
                'induccion_at' => $induccion->completa() ? $induccion->fecha : null,
                'induccion_por_id' => $r->user()->id,
                'induccion_temas' => collect($induccion->temario())
                    ->filter(fn (array $t) => $t['cubierto'])
                    ->pluck('tema')->implode(' · '),
            ]);

            $e->anotar('induccion', 'Inducción registrada por '.$r->user()->name
                .' · '.$induccion->cubiertos().' de '.count($induccion->temario()).' temas');
        });

        $faltan = $induccion->pendientes();

        return back()->with('success', $faltan === []
            ? ($induccion->firmada()
                ? "Inducción de {$e->nombre} completa y firmada."
                : "Inducción de {$e->nombre} completa. Falta que firme la constancia.")
            : 'Inducción guardada. Faltan '.count($faltan).' temas: '.implode(', ', array_slice($faltan, 0, 3))
                .(count($faltan) > 3 ? '…' : '').'.');
    }

    /** La constancia que firma quien recibió la inducción. */
    public function pdf(Request $r, int $empleado)
    {
        $e = Empleado::findOrFail($empleado);

        $induccion = Induccion::with('registradaPor:id,name')
            ->where('empleado_id', $e->id)->firstOrFail();

        return Pdf::loadView('rrhh.pdf.constancia-induccion', [
            'e' => $e,
            'i' => $induccion,
            'empresa' => \App\Models\EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('induccion-'.str($e->nombre)->slug().'.pdf');
    }
}
