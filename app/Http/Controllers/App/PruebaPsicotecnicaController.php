<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Enums\ConceptoPsicotecnico;
use App\Modules\Rrhh\Enums\InstrumentoPsicotecnico;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\PruebaPsicotecnica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase C · Las pruebas psicotécnicas del proceso de selección.
 *
 * Lo que se registra acá es el CONCEPTO del profesional, no las respuestas del
 * candidato. El porqué está en la migración; lo que importa en el controlador
 * es que no hay forma de mandar respuestas aunque alguien quisiera: no existe
 * el campo.
 *
 * El informe firmado se guarda en el disco privado y se sirve por acá, con
 * permiso revisado y dejando anotado quién lo abrió. Un informe psicológico es
 * dato sensible de salud: que esté guardado no significa que cualquiera pueda
 * leerlo, y saber quién lo leyó es parte de protegerlo.
 */
class PruebaPsicotecnicaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_seleccion'), 403);

            return $next($r);
        })];
    }

    /**
     * Registra o corrige el resultado de una prueba.
     *
     * Una por instrumento y candidato: si se vuelve a mandar la misma, se
     * corrige la que hay. Dos conceptos distintos del mismo examen dejarían
     * al jefe sin saber cuál creer.
     */
    public function guardar(Request $r, int $candidato): RedirectResponse
    {
        $c = Candidato::findOrFail($candidato);

        $data = $r->validate([
            'instrumento' => ['required', Rule::enum(InstrumentoPsicotecnico::class)],
            // Sólo tiene sentido cuando el instrumento es «otro», y entonces
            // es obligatorio: «otro» sin nombre no dice qué se aplicó.
            'instrumento_otro' => [
                'nullable', 'string', 'max:120',
                Rule::requiredIf(fn () => $r->input('instrumento') === InstrumentoPsicotecnico::Otro->value),
            ],
            'fecha_aplicacion' => ['required', 'date', 'before_or_equal:today'],
            'profesional_nombre' => ['required', 'string', 'max:160'],
            'profesional_tarjeta' => [
                'nullable', 'string', 'max:40',
                /*
                 * El MINIMULT es instrumento clínico: la Ley 1090 de 2006
                 * reserva su aplicación a psicólogos con tarjeta. Si no se
                 * sabe quién lo firmó con qué tarjeta, el concepto no tiene
                 * respaldo de nadie.
                 */
                Rule::requiredIf(fn () => InstrumentoPsicotecnico::tryFrom(
                    (string) $r->input('instrumento')
                )?->exigeProfesional() === true),
            ],
            'concepto' => ['required', Rule::enum(ConceptoPsicotecnico::class)],
            'observaciones' => [
                'nullable', 'string', 'max:3000',
                // Un «no apto» sin sustento no se le puede explicar a nadie.
                Rule::requiredIf(fn () => ConceptoPsicotecnico::tryFrom(
                    (string) $r->input('concepto')
                )?->exigeObservaciones() === true),
            ],
            'informe' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            // Ver la nota de `EntrevistaCandidatoController`: el `:date` de
            // `before_or_equal:today` no se traduce desde `attributes`.
            'fecha_aplicacion.before_or_equal' => 'La prueba no puede tener fecha futura.',
        ], [
            // Sin artículo: lo pone el mensaje de `lang/es/validation.php`.
            'instrumento_otro' => 'nombre de la prueba',
            'fecha_aplicacion' => 'fecha de aplicación',
            'profesional_nombre' => 'nombre del profesional',
            'profesional_tarjeta' => 'tarjeta profesional',
            'informe' => 'informe firmado',
        ]);

        $prueba = PruebaPsicotecnica::firstOrNew([
            'candidato_id' => $c->id,
            'instrumento' => $data['instrumento'],
        ]);

        if ($r->hasFile('informe')) {
            // Al reemplazar el informe se borra el anterior: dejar informes
            // psicológicos sueltos en el disco es guardar dato sensible que
            // ya nadie va a mirar.
            if ($prueba->archivo_path && Storage::exists($prueba->archivo_path)) {
                Storage::delete($prueba->archivo_path);
            }

            $prueba->archivo_path = $r->file('informe')->store("rrhh/psicotecnicas/{$c->id}");
        }

        $prueba->fill([
            'candidato_id' => $c->id,
            'instrumento_otro' => $data['instrumento_otro'] ?? null,
            'fecha_aplicacion' => $data['fecha_aplicacion'],
            'profesional_nombre' => $data['profesional_nombre'],
            'profesional_tarjeta' => $data['profesional_tarjeta'] ?? null,
            'concepto' => $data['concepto'],
            'observaciones' => $data['observaciones'] ?? null,
            'registrado_por_id' => $r->user()->id,
        ])->save();

        $c->anotar(
            'prueba_psicotecnica',
            "{$prueba->comoSeLlama()}: {$prueba->concepto->label()} · firma {$prueba->quienFirma()}",
        );

        $falta = $prueba->loQueFalta();

        return back()->with('success', $falta === []
            ? "{$prueba->comoSeLlama()} registrada: {$prueba->concepto->label()}."
            : "{$prueba->comoSeLlama()} registrada. Falta ".implode(' y ', $falta).'.');
    }

    /**
     * El informe firmado.
     *
     * Se anota quién lo abrió. No es desconfianza del área: es lo que permite
     * responder «quién vio esto» el día que alguien lo pregunte, que con dato
     * sensible de salud es una pregunta que se hace.
     */
    public function verInforme(Request $r, int $prueba): StreamedResponse
    {
        $p = PruebaPsicotecnica::with('candidato')->findOrFail($prueba);

        abort_unless($p->tieneSoporte() && Storage::exists($p->archivo_path), 404,
            'Esta prueba no tiene informe cargado.');

        $p->candidato->anotar(
            'psicotecnica_consultada',
            "{$r->user()->name} abrió el informe de {$p->comoSeLlama()}",
        );

        return Storage::response(
            $p->archivo_path,
            'informe-'.str($p->comoSeLlama())->slug().'-'.str($p->candidato->nombre)->slug().'.pdf',
            ['Content-Disposition' => 'inline'],
        );
    }
}
