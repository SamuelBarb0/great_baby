<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Candidato;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\OrdenContratacion;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F6 · Sacar la información del sistema.
 *
 * Elena lo pidió en la reunión con esas palabras: «todo va a ser exportable en
 * PDF y exportable también en Excel, de manera masiva y de manera de un solo
 * candidato». Y no es un capricho: la normativa le exige tener las historias
 * laborales «tanto físicas como digitales», así que esto se imprime y se
 * archiva en la carpeta de cada quien.
 *
 * Los PDF llevan la bitácora completa a propósito. Sin el rastro de quién hizo
 * qué y cuándo, el papel sólo dice cómo están las cosas hoy; con él, prueba
 * cómo se llegó hasta acá, que es lo que pregunta una auditoría.
 */
class ExportacionRrhhController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            // Entran los dos perfiles; cada acción vuelve a mirar qué puede
            // ver quien la pide.
            abort_unless(
                Permisos::puede($r->user(), 'rrhh')
                || Permisos::puede($r->user(), 'rrhh_requisiciones'),
                403,
            );

            return $next($r);
        })];
    }

    /** La carpeta completa de una persona, para imprimir y archivar. */
    public function historiaLaboral(Request $r, int $empleado)
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

        $e = Empleado::with([
            'vacante.requisicion.solicitante:id,name',
            'induccionPor:id,name',
            'pruebaJefePor:id,name',
            'pruebaEvaluadaPor:id,name',
            'documentos.subidoPor:id,name',
        ])->findOrFail($empleado);

        $contratacion = Contratacion::where('empleado_id', $e->id)->first();

        return Pdf::loadView('rrhh.pdf.historia-laboral', [
            'e' => $e,
            'contratacion' => $contratacion,
            'orden' => $contratacion?->orden()->with('decididaPor:id,name')->first(),
            'afiliaciones' => Contratacion::AFILIACIONES,
            // La bitácora del empleado y la de su contratación son la misma
            // historia contada en dos tablas: si se imprimieran por separado,
            // el papel no mostraría el proceso completo.
            'bitacora' => $this->rastroCompleto($e, $contratacion),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->download('historia-laboral-'.str($e->nombre)->slug().'.pdf');
    }

    /** El recorrido de un candidato por el proceso de selección. */
    public function procesoCandidato(Request $r, int $candidato)
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh_seleccion'), 403);

        $c = Candidato::with(['vacante.requisicion', 'elegidoPor:id,name'])->findOrFail($candidato);

        return Pdf::loadView('rrhh.pdf.proceso-candidato', [
            'c' => $c,
            'fuente' => $c->fuente ? (Vacante::FUENTES[$c->fuente] ?? $c->fuente) : null,
            'orden' => OrdenContratacion::where('candidato_id', $c->id)->first(),
            'bitacora' => $c->bitacora()->with('usuario:id,name')->get()->sortBy('created_at'),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->download('seleccion-'.str($c->nombre)->slug().'.pdf');
    }

    /** Todos los empleados, para abrir en Excel. */
    public function empleadosExcel(Request $r): StreamedResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

        $estado = $r->query('estado', 'activo');

        return $this->csv('empleados-'.$estado, [
            'Nombre', 'Tipo doc', 'Documento', 'Correo', 'Teléfono', 'Cargo', 'Área',
            'Tipo contrato', 'Salario', 'Ingreso', 'Retiro', 'Estado', 'Inducción',
            'Inducción dada el', 'Prueba hasta', 'Concepto jefe', 'Decisión GH',
            'EPS', 'ARL', 'Pensión', 'Caja',
        ], function () use ($estado) {
            /*
             * `lazy()` y no `chunk()`: el `yield` dentro del closure de
             * `chunk` crea un generador que nadie consume, así que el archivo
             * salía con la cabecera y ni una fila. `lazy()` sí se recorre
             * desde acá, y tampoco carga la tabla entera en memoria.
             */
            $filas = Empleado::with('contratacion')
                ->when($estado !== 'todos', fn ($q) => $q->where('estado', $estado))
                ->orderBy('nombre')->lazy(300);

            foreach ($filas as $e) {
                yield [
                    $e->nombre, $e->tipo_documento, $e->numero_documento,
                    $e->email, $e->telefono, $e->cargo, $e->area, $e->tipo_contrato,
                    $this->plata($e->salario),
                    $e->fecha_ingreso?->toDateString(), $e->fecha_retiro?->toDateString(),
                    $e->estado, $e->estado_induccion,
                    $e->induccion_at?->toDateString(),
                    $e->prueba_hasta?->toDateString(),
                    $e->prueba_jefe_resultado, $e->prueba_resultado,
                    $e->contratacion?->eps, $e->contratacion?->arl,
                    $e->contratacion?->pension, $e->contratacion?->caja,
                ];
            }
        });
    }

    /** Los candidatos de una vacante, con en qué quedó cada uno. */
    public function candidatosExcel(Request $r, int $vacante): StreamedResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh_seleccion'), 403);

        $v = Vacante::findOrFail($vacante);

        return $this->csv('candidatos-vacante-'.$v->id, [
            'Nombre', 'Documento', 'Correo', 'Teléfono', 'Fuente', 'Etapa',
            'Antecedentes', 'Resultado antecedentes', 'Valoración', 'Observación',
            'Motivo del descarte', 'Elegido', 'Se postuló',
        ], function () use ($v) {
            foreach (Candidato::where('vacante_id', $v->id)->orderBy('nombre')->get() as $c) {
                yield [
                    $c->nombre, $c->documento, $c->email, $c->telefono,
                    $c->fuente ? (Vacante::FUENTES[$c->fuente] ?? $c->fuente) : '',
                    $c->etapa->label(),
                    $c->antecedentes_revisados ? 'Sí' : 'No',
                    $c->antecedentes_resultado,
                    $c->valoracion?->label(),
                    $c->valoracion_observacion,
                    $c->motivo_descarte,
                    $c->elegido_at ? 'Sí' : 'No',
                    $c->created_at?->toDateString(),
                ];
            }
        });
    }

    /**
     * Las solicitudes de personal.
     *
     * El líder de área también la puede sacar, pero sólo con las suyas: es el
     * mismo recorte que ve en pantalla.
     */
    public function requisicionesExcel(Request $r): StreamedResponse
    {
        $gh = Permisos::puede($r->user(), 'rrhh');

        return $this->csv('solicitudes-de-personal', [
            'Número', 'Cargo', 'Área', 'Vacantes', 'Solicita', 'Estado',
            'Salario desde', 'Salario hasta', 'Se necesita', 'Creada',
            'Enviada', 'Aprobada', 'Motivo del rechazo',
        ], function () use ($r, $gh) {
            $q = Requisicion::with('solicitante:id,name')
                ->when(! $gh, fn ($x) => $x->deSolicitante($r->user()->id))
                ->orderByDesc('id');

            foreach ($q->get() as $x) {
                yield [
                    $x->numero, $x->cargo, $x->area, $x->numero_vacantes,
                    $x->solicitante?->name, $x->estado->label(),
                    $this->plata($x->salario_min), $this->plata($x->salario_max),
                    $x->fecha_requerida?->toDateString(),
                    $x->created_at?->toDateString(),
                    $x->enviada_at?->toDateString(),
                    $x->aprobada_at?->toDateString(),
                    $x->motivo_rechazo,
                ];
            }
        });
    }

    // ───────────────────────────── internos ─────────────────────────────

    /**
     * Un CSV que Excel abre bien en español.
     *
     * Dos detalles que parecen menores y arruinan el archivo:
     *
     *   · El BOM. Sin él, Excel en Windows lee el UTF-8 como ANSI y los
     *     nombres salen «PÃ©rez» en vez de «Pérez» — y acá casi todo son
     *     nombres de personas.
     *   · El separador. Excel en configuración regional de Colombia espera
     *     punto y coma; con coma mete la fila entera en una sola celda.
     *
     * @param  callable(): \Generator  $filas
     */
    private function csv(string $nombre, array $cabecera, callable $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($cabecera, $filas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $cabecera, ';');
            foreach ($filas() as $fila) {
                fputcsv($out, $fila, ';');
            }

            fclose($out);
        }, $nombre.'-'.now()->toDateString().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Sin separador de miles: el separador lo pone Excel al abrirlo. */
    private function plata(mixed $n): string
    {
        return $n ? number_format((float) $n, 2, ',', '') : '';
    }

    /**
     * La historia del empleado y la de su contratación, en una sola línea de
     * tiempo ordenada. Son la misma historia guardada en dos tablas.
     */
    private function rastroCompleto(Empleado $e, ?Contratacion $contratacion)
    {
        $del = fn ($modelo) => $modelo
            ? $modelo->bitacora()->with('usuario:id,name')->get()
            : collect();

        return $del($e)->concat($del($contratacion))
            ->concat($del($e->candidato_id ? Candidato::find($e->candidato_id) : null))
            ->sortBy('created_at')
            ->values();
    }
}
