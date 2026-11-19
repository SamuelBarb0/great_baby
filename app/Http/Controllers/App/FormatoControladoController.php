<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\FormatoControlado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase G · El listado maestro de formatos.
 *
 * Los tres datos del recuadro de control —código, versión y fecha— vivían
 * escritos dentro de cada plantilla. Eso significaba que sacar la versión 002
 * de un formato, que es lo normal y para lo que existe el número, obligaba a
 * pedirle a un desarrollador que editara código.
 *
 * Acá los mantiene quien es dueño de ellos. No es una pantalla de
 * configuración técnica: es el listado maestro de documentos del sistema de
 * gestión, y lo que diga se imprime en todos los formatos desde el minuto
 * siguiente.
 *
 * Lo que NO se puede hacer desde acá, a propósito: crear o borrar renglones.
 * La lista es la de los formatos que el ERP sabe imprimir —si no hay
 * plantilla, un renglón sería un formato que no existe— y se agrega desde la
 * semilla cuando se programa uno nuevo.
 */
class FormatoControladoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

            return $next($r);
        })];
    }

    public function index(): Response
    {
        return Inertia::render('Rrhh/Formatos/Index', [
            'formatos' => FormatoControlado::with('actualizadoPor:id,name')
                ->orderBy('proceso')->orderByRaw('codigo IS NULL DESC')->orderBy('codigo')
                ->get()
                ->map(fn (FormatoControlado $f) => [
                    'id' => $f->id,
                    'clave' => $f->clave,
                    'codigo' => $f->codigo,
                    'nombre' => $f->nombre,
                    'proceso' => $f->proceso,
                    'version' => $f->version,
                    'fecha_version' => $f->fecha_version?->toDateString(),
                    'actualizado' => $f->actualizado_at?->format('Y-m-d H:i'),
                    'actualizado_por' => $f->actualizadoPor?->name,
                ]),
            'procesos' => [FormatoControlado::PROCESO_GH, FormatoControlado::PROCESO_SST],
            // Para que la pantalla pueda avisar cuáles siguen sin código sin
            // tener que volver a contarlos ella.
            'sin_codigo' => FormatoControlado::whereNull('codigo')->count(),
        ]);
    }

    public function actualizar(Request $r, int $formato): RedirectResponse
    {
        $f = FormatoControlado::findOrFail($formato);

        $data = $r->validate([
            /*
             * El código es único entre formatos. Dos documentos con el mismo
             * código es justamente lo que el control documental evita: deja
             * de poder decirse de cuál de los dos habla un archivo.
             *
             * Sigue pudiendo ir vacío: dos de los formatos del área llegaron
             * sin código y no se les inventa uno.
             */
            'codigo' => ['nullable', 'string', 'max:30', Rule::unique('formatos_controlados', 'codigo')->ignore($f->id)],
            'nombre' => ['required', 'string', 'max:160'],
            'proceso' => ['required', 'string', 'max:80'],
            'version' => ['required', 'string', 'max:10'],
            'fecha_version' => ['nullable', 'date'],
        ], [], [
            // Sin artículo: lo pone el mensaje («El campo código es
            // obligatorio», «Ese valor de código ya está registrado»).
            'codigo' => 'código',
            'version' => 'versión',
            'fecha_version' => 'fecha de la versión',
        ]);

        $f->update($data + [
            'actualizado_at' => now(),
            'actualizado_por_id' => $r->user()->id,
        ]);

        return back()->with('success', "Formato {$f->codigoImpreso()} actualizado. "
            .'Los impresos que se generen desde ahora salen con la versión '.$f->version.'.');
    }
}
