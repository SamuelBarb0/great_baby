<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\EntrevistaCandidato;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Fase C · Las dos entrevistas del proceso de selección.
 *
 * El tablero movía la tarjeta de «entrevista con el jefe» a «entrevista de
 * psicología» sin guardar una palabra de lo que se preguntó. La terna llegaba
 * al jefe con tres nombres y una calificación, y él elegía sin ver por qué
 * alguien valoró así.
 */
class EntrevistaCandidatoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_seleccion'), 403);

            return $next($r);
        })];
    }

    /**
     * Guarda o corrige una entrevista.
     *
     * Una de cada tipo por candidato: si se vuelve a mandar, se corrige la
     * que hay. Dos versiones de la misma entrevista dejarían al jefe sin
     * saber cuál leer.
     */
    public function guardar(Request $r, int $candidato): RedirectResponse
    {
        $c = Candidato::findOrFail($candidato);

        $data = $r->validate([
            'tipo' => ['required', Rule::in(array_keys(EntrevistaCandidato::TIPOS))],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'entrevistador_nombre' => ['required', 'string', 'max:160'],
            'entrevistador_cargo' => ['nullable', 'string', 'max:120'],
            'respuestas' => ['nullable', 'array'],
            'respuestas.*' => ['nullable'],
            /*
             * Las seis notas van de 1 a 5. Un 0 o un 6 no son «una nota
             * rara»: son un error de digitación que después se promedia con
             * las demás y ensucia la comparación entre candidatos.
             */
            'calificaciones' => ['nullable', 'array'],
            'calificaciones.*' => ['nullable', 'integer', 'min:1', 'max:5'],
            'observaciones' => ['nullable', 'string', 'max:3000'],
        ], [
            /*
             * El mensaje va acá y no en `lang/es/validation.php`.
             *
             * Para `before_or_equal:today`, Laravel NO traduce el `:date` con
             * el bloque `attributes`: como «today» se parsea como fecha, entra
             * por `getDisplayableValue()`, que busca `validation.values.fecha.today`.
             * Con `attributes.today => 'hoy'` el mensaje salía
             * «…no puede ser posterior a today», en inglés, en la cara del
             * usuario. El molde es `PagosController`, que lo resuelve así.
             */
            'fecha.before_or_equal' => 'La entrevista no puede tener fecha futura.',
        ], [
            'fecha' => 'fecha de la entrevista',
            'entrevistador_nombre' => 'nombre del entrevistador',
            'calificaciones.*' => 'calificación',
        ]);

        $entrevista = EntrevistaCandidato::firstOrNew([
            'candidato_id' => $c->id,
            'tipo' => $data['tipo'],
        ]);

        $entrevista->fill([
            'candidato_id' => $c->id,
            'fecha' => $data['fecha'],
            'entrevistador_nombre' => $data['entrevistador_nombre'],
            'entrevistador_cargo' => $data['entrevistador_cargo'] ?? null,
            'respuestas' => $this->limpiar($data['respuestas'] ?? []),
            'calificaciones' => array_filter($data['calificaciones'] ?? [], fn ($v) => filled($v)),
            'observaciones' => $data['observaciones'] ?? null,
            'registrada_por_id' => $r->user()->id,
        ]);

        // El total se calcula del lado del servidor: si viniera del cliente,
        // el papel podría salir con un número que no suma.
        $entrevista->total = $entrevista->totalCalculado();
        $entrevista->save();

        /*
         * La bitácora dice el total, y en la del ser también la banda.
         *
         * El total solo —«24/30»— no le dice nada a quien lee la ficha seis
         * meses después. La banda sí: es la palabra con la que el área decide.
         */
        $banda = $entrevista->banda();

        $c->anotar('entrevista', $entrevista->tipoLabel().' registrada por '.$r->user()->name
            .($entrevista->total ? " · total {$entrevista->total}/30" : '')
            .($banda ? " · promedio {$entrevista->promedioCalculado()} · {$banda->label()}" : ''));

        $faltan = $entrevista->sinContestar();

        return back()->with('success', $faltan === 0
            ? "{$entrevista->tipoLabel()} guardada."
            : "{$entrevista->tipoLabel()} guardada. Quedaron {$faltan} preguntas sin contestar.");
    }

    /** El impreso, con el formato del área. */
    public function pdf(Request $r, int $entrevista)
    {
        $e = EntrevistaCandidato::with('candidato.vacante')->findOrFail($entrevista);

        /*
         * Tres tipos, tres formatos.
         *
         * Era binario —saber hacer, y si no, la psicológica—, así que al
         * agregar la entrevista del ser su impreso salía con el formato
         * equivocado: el de otra entrevista, con el nombre del candidato
         * encima. Con `match`, un tipo nuevo sin vista se nota acá y no en la
         * cara de quien imprime.
         */
        $vista = match ($e->tipo) {
            EntrevistaCandidato::SABER_HACER => 'rrhh.pdf.entrevista-saber-hacer',
            EntrevistaCandidato::SER => 'rrhh.pdf.entrevista-ser',
            default => 'rrhh.pdf.entrevista-psicologica',
        };

        return Pdf::loadView($vista, [
            'e' => $e,
            'c' => $e->candidato,
            'empresa' => \App\Models\EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('entrevista-'.$e->tipo.'-'.str($e->candidato->nombre)->slug().'.pdf');
    }

    /**
     * Quita las respuestas vacías antes de guardar.
     *
     * Una entrevista a medias es normal; veinte claves con cadena vacía en el
     * JSON sólo hacen más difícil leer lo que sí se contestó.
     *
     * @param  array<string, mixed>  $respuestas
     * @return array<string, mixed>
     */
    private function limpiar(array $respuestas): array
    {
        $out = [];

        foreach ($respuestas as $clave => $valor) {
            if (is_array($valor)) {
                // Las tablas que crecen: se quitan las filas en blanco.
                $filas = array_values(array_filter(
                    $valor,
                    fn ($fila) => filled(array_filter(array_map('trim', array_map('strval', (array) $fila)))),
                ));

                if ($filas !== []) {
                    $out[$clave] = $filas;
                }

                continue;
            }

            if (filled($valor)) {
                $out[$clave] = trim((string) $valor);
            }
        }

        return $out;
    }
}
