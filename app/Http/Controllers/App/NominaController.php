<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Nomina;
use App\Modules\Rrhh\Models\NominaLinea;
use App\Modules\Rrhh\Support\CuadroDeNovedades;
use App\Modules\Rrhh\Support\LiquidacionNomina;
use App\Support\ExportadorCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La nómina del mes.
 *
 * Tres manos, tres decisiones: Gestión Humana LIQUIDA, gerencia APRUEBA y
 * tesorería PAGA. Es el mismo reparto que la entrega de cargo y por la misma
 * razón: quien calcula lo que se paga no debería ser quien autoriza pagarlo.
 *
 * La puerta de la pantalla es ancha —las tres áreas la abren— y lo estrecho
 * son las acciones.
 */
class NominaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_nomina'), 403);

            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        $periodo = CuadroDeNovedades::normalizarPeriodo($r->query('periodo'));

        $nomina = Nomina::with(['lineas', 'liquidadaPor:id,name', 'aprobadaPor:id,name', 'pagadaPor:id,name'])
            ->where('periodo', $periodo)->first();

        /*
         * Quién entraría HOY si se liquidara este período.
         *
         * Se calcula siempre, exista nómina o no. Antes era
         * `$nomina ? null : ...`, o sea que el número que servía «para notar
         * que falta alguien» se apagaba justo cuando ya había a quién
         * comparar. Pasó de verdad: la nómina de octubre se pagó con 3
         * personas y cuatro horas después había 6 empleados que calificaban.
         * La pantalla no tenía cómo decirlo, y los tres que faltaban no
         * tenían forma de cobrar.
         */
        $califican = LiquidacionNomina::de($periodo)->empleados();
        $faltantes = $nomina
            ? $califican->reject(fn ($e) => $nomina->lineas->contains('empleado_id', $e->id))
            : collect();

        return Inertia::render('Rrhh/Nomina/Index', [
            'periodo' => $periodo,
            'periodos' => CuadroDeNovedades::periodosDisponibles(),
            'nomina' => $nomina ? $this->ficha($nomina) : null,
            'por_liquidar' => $nomina ? null : $califican->count(),
            /*
             * Gente que califica y NO está en la nómina del período. Va con
             * nombre: «faltan 3» obliga a ir a buscarlos; «faltan Camila,
             * Julián y Natalia» se resuelve ahí mismo.
             */
            'faltantes' => $faltantes->map(fn ($e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'ingreso' => $e->fecha_ingreso?->format('Y-m-d'),
            ])->values(),
            'puedo' => [
                'liquidar' => Permisos::puede($r->user(), 'rrhh'),
                'aprobar' => Permisos::puede($r->user(), 'rrhh_nomina_aprobar'),
                'pagar' => Permisos::puede($r->user(), 'contabilidad'),
            ],
        ]);
    }

    /**
     * Calcula el mes y lo guarda.
     *
     * Se puede repetir mientras esté en borrador: llega una incapacidad tarde
     * y hay que rehacerlo, lo cual pasa todos los meses. Cuando se rehace se
     * borran las líneas y se vuelven a escribir, porque una línea vieja que
     * sobreviviera sería un pago a alguien que ya no está en la nómina.
     */
    public function liquidar(Request $r): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403,
            'La nómina la liquida Gestión Humana.');

        $periodo = CuadroDeNovedades::normalizarPeriodo($r->input('periodo'));

        $nomina = Nomina::firstOrNew(['periodo' => $periodo], ['estado' => Nomina::BORRADOR]);

        /*
         * Con `if` y no con `abort_unless`: el mensaje de `abort_unless` se
         * arma SIEMPRE, incluso cuando la condición pasa, y acá el modelo
         * puede ser nuevo —sin estado todavía—. Costó un 500 en pantalla.
         */
        if ($nomina->exists && ! $nomina->sePuedeRehacer()) {
            abort(422, 'Esta nómina ya está '.strtolower($nomina->estadoLabel())
                .': no se puede volver a liquidar.');
        }

        $calculo = LiquidacionNomina::de($periodo);
        $empleados = $calculo->empleados();

        if ($empleados->isEmpty()) {
            return back()->withErrors([
                'periodo' => 'No hay nadie vinculado en ese mes: no hay qué liquidar.',
            ]);
        }

        DB::transaction(function () use ($nomina, $calculo, $empleados, $periodo, $r) {
            $nomina->fill([
                'periodo' => $periodo,
                'estado' => Nomina::BORRADOR,
                'liquidada_at' => now(),
                'liquidada_por_id' => $r->user()->id,
            ])->save();

            $nomina->lineas()->delete();

            $devengado = 0.0;
            $deducido = 0.0;
            $aportes = 0.0;
            $provisiones = 0.0;

            foreach ($empleados as $e) {
                $linea = $calculo->liquidar($e);

                // Lo que de verdad cuesta esa persona: lo que recibe más lo
                // que la empresa pone encima.
                $linea['costo_empresa'] = round(
                    $linea['neto'] + $linea['total_aportes'] + $linea['total_provisiones'], 2,
                );

                NominaLinea::create(['nomina_id' => $nomina->id] + $linea);

                $devengado += $linea['total_devengado'];
                $deducido += $linea['total_deducido'];
                $aportes += $linea['total_aportes'];
                $provisiones += $linea['total_provisiones'];
            }

            $neto = round($devengado - $deducido, 2);

            $nomina->update([
                'total_devengado' => round($devengado, 2),
                'total_deducido' => round($deducido, 2),
                'total_neto' => $neto,
                'total_aportes' => round($aportes, 2),
                'total_provisiones' => round($provisiones, 2),
                'total_costo_empresa' => round($neto + $aportes + $provisiones, 2),
            ]);
        });

        $nomina->refresh()->load('lineas');
        $enCero = $nomina->enCero();

        return back()->with('success', $enCero->isEmpty()
            ? "Nómina de {$nomina->titulo()} liquidada: {$empleados->count()} personas."
            : "Nómina liquidada, pero {$enCero->count()} ".($enCero->count() === 1 ? 'persona queda' : 'personas quedan')
                .' en cero. Revisá que su ficha tenga salario.');
    }

    /**
     * Gerencia aprueba. De acá en adelante los números no se mueven.
     */
    public function aprobar(Request $r, int $nomina): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh_nomina_aprobar'), 403,
            'La nómina la aprueba gerencia.');

        $n = Nomina::with('lineas')->findOrFail($nomina);

        abort_unless($n->estado === Nomina::BORRADOR, 422,
            'Sólo se aprueba una nómina en borrador.');

        /*
         * No se aprueba una nómina con gente en cero.
         *
         * Casi siempre es una ficha sin salario, y aprobarla así es dejar a
         * alguien sin sueldo con la firma de gerencia encima. Si de verdad
         * alguien no cobra este mes, se saca de la nómina o se le arregla la
         * ficha; no se firma el cero.
         */
        $enCero = $n->enCero();

        if ($enCero->isNotEmpty()) {
            return back()->withErrors([
                'aprobar' => 'No se puede aprobar: '.$enCero->pluck('nombre')->implode(', ')
                    .($enCero->count() === 1 ? ' queda' : ' quedan').' en cero. Revisá su ficha y volvé a liquidar.',
            ]);
        }

        $n->update([
            'estado' => Nomina::APROBADA,
            'aprobada_at' => now(),
            'aprobada_por_id' => $r->user()->id,
        ]);

        /*
         * Y acá entra al libro.
         *
         * La obligación con la gente nace al aprobar, no al consignar: entre
         * una cosa y la otra el pasivo existe y tiene que verse. Hasta hoy la
         * nómina no generaba ni un movimiento contable, así que el gasto de
         * personal —de los más grandes de la empresa— no aparecía ni en el
         * balance ni en el estado de resultados.
         */
        app(\App\Modules\Contabilidad\Actions\RegistrarAsientoNomina::class)->causar($n->fresh());

        return back()->with('success', "Nómina de {$n->titulo()} aprobada. Ya se puede pagar.");
    }

    /** Tesorería marca el pago. */
    public function pagar(Request $r, int $nomina): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'contabilidad'), 403,
            'El pago lo registra tesorería.');

        $n = Nomina::findOrFail($nomina);

        abort_unless($n->estado === Nomina::APROBADA, 422,
            'Primero gerencia tiene que aprobar la nómina.');

        $data = $r->validate([
            'fecha_pago' => ['required', 'date'],
        ], [], ['fecha_pago' => 'fecha de pago']);

        $n->update([
            'estado' => Nomina::PAGADA,
            'fecha_pago' => $data['fecha_pago'],
            'pagada_at' => now(),
            'pagada_por_id' => $r->user()->id,
        ]);

        // Se cancela contra el banco lo que se le debía a la gente.
        app(\App\Modules\Contabilidad\Actions\RegistrarAsientoNomina::class)->pagar($n->fresh());

        return back()->with('success', "Nómina de {$n->titulo()} marcada como pagada.");
    }

    /**
     * El desprendible de una persona.
     *
     * Sale de la línea guardada y no de un recálculo: es el soporte de lo que
     * se le pagó, y tiene que decir lo mismo dentro de dos años.
     */
    public function desprendible(Request $r, int $linea)
    {
        $l = NominaLinea::with('nomina')->findOrFail($linea);

        return Pdf::loadView('rrhh.pdf.desprendible-nomina', [
            'l' => $l,
            'nomina' => $l->nomina,
            'empresa' => \App\Models\EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('desprendible-'.str($l->nombre)->slug().'-'.$l->nomina->periodo.'.pdf');
    }

    /** El mes entero para el contador, en Excel. */
    public function exportar(Request $r, int $nomina): StreamedResponse
    {
        $n = Nomina::with('lineas')->findOrFail($nomina);

        $filas = [
            ['Documento', 'Nombre', 'Cargo', 'Salario base', 'Días', 'Salario', 'Auxilio',
                'Otros', 'Horas extra', 'Total devengado', 'Salud', 'Pensión', 'Ausentismo',
                'Total deducido', 'Neto'],
        ];

        foreach ($n->lineas as $l) {
            $filas[] = [
                $l->documento, $l->nombre, $l->cargo, $l->salario_base, $l->dias_trabajados,
                $l->devengado_salario, $l->devengado_auxilio, $l->devengado_adicional,
                $l->devengado_extras, $l->total_devengado, $l->deduccion_salud,
                $l->deduccion_pension, $l->deduccion_ausentismo, $l->total_deducido, $l->neto,
            ];
        }

        // El total abajo, como lo espera quien abre el archivo en Excel.
        $filas[] = [];
        $filas[] = ['', 'TOTALES', '', '', '', '', '', '', '', $n->total_devengado,
            '', '', '', $n->total_deducido, $n->total_neto];

        return ExportadorCsv::deFilas("nomina-{$n->periodo}.csv", $filas);
    }

    private function ficha(Nomina $n): array
    {
        return [
            'id' => $n->id,
            'periodo' => $n->periodo,
            'titulo' => $n->titulo(),
            'estado' => $n->estado,
            'estado_label' => $n->estadoLabel(),
            'color' => $n->colorEstado(),
            'se_puede_rehacer' => $n->sePuedeRehacer(),
            'fecha_pago' => $n->fecha_pago?->toDateString(),
            'total_devengado' => (float) $n->total_devengado,
            'total_deducido' => (float) $n->total_deducido,
            'total_neto' => (float) $n->total_neto,
            'total_aportes' => (float) $n->total_aportes,
            'total_provisiones' => (float) $n->total_provisiones,
            'total_costo_empresa' => (float) $n->total_costo_empresa,
            'liquidada' => $n->liquidada_at?->format('Y-m-d H:i'),
            'liquidada_por' => $n->liquidadaPor?->name,
            'aprobada' => $n->aprobada_at?->format('Y-m-d H:i'),
            'aprobada_por' => $n->aprobadaPor?->name,
            'pagada' => $n->pagada_at?->format('Y-m-d H:i'),
            'pagada_por' => $n->pagadaPor?->name,
            'en_cero' => $n->enCero()->pluck('nombre')->values(),
            // Si una misma persona hizo más de una de las tres, se dice: el
            // control o se cumple o se ve que no se cumplió.
            'manos_repetidas' => $n->hizoVariasManos(),
            'lineas' => $n->lineas->map(fn (NominaLinea $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
                'documento' => $l->documento,
                'cargo' => $l->cargo,
                'dias' => (float) $l->dias_trabajados,
                'devengado' => (float) $l->total_devengado,
                'deducido' => (float) $l->total_deducido,
                'neto' => (float) $l->neto,
                'conceptos' => $l->conceptos(),
                'costo_empresa' => (float) $l->costo_empresa,
                'empresa' => $l->loQuePoneLaEmpresa(),
            ])->values(),
        ];
    }
}
