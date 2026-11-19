<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Liquidacion;
use App\Modules\Rrhh\Support\LiquidacionDefinitiva;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * La liquidación definitiva de quien se va.
 *
 * Mismas tres manos que la nómina —liquida Gestión Humana, aprueba gerencia,
 * paga tesorería— porque es el mismo tipo de plata y el mismo riesgo: quien
 * calcula lo que se debe no debería ser quien autoriza pagarlo.
 *
 * Vive en la ficha de la persona y no en una pantalla aparte, igual que la
 * entrevista de retiro y la entrega de cargo: las tres son lo que pasa cuando
 * alguien sale, y tenerlas juntas es lo que permite ver si falta alguna.
 */
class LiquidacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_nomina'), 403);

            return $next($r);
        })];
    }

    /**
     * Calcula y guarda.
     *
     * Se puede repetir mientras esté en borrador: aparece un anticipo de
     * cesantías que nadie había anotado y hay que rehacerla. Una vez aprobada
     * se congela, porque de ahí sale un pago.
     */
    public function calcular(Request $r, int $empleado): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403,
            'La liquidación la calcula Gestión Humana.');

        $e = Empleado::findOrFail($empleado);

        if (! $e->fecha_retiro) {
            return back()->withErrors([
                'liquidacion' => 'Esta persona no tiene fecha de retiro: no hay contrato que liquidar.',
            ]);
        }

        $liq = Liquidacion::firstOrNew(['empleado_id' => $e->id], ['estado' => Liquidacion::BORRADOR]);

        if ($liq->exists && ! $liq->sePuedeRehacer()) {
            abort(422, 'Esta liquidación ya está '.strtolower($liq->estadoLabel())
                .': no se puede volver a calcular.');
        }

        $data = $r->validate([
            /*
             * Los tres datos que el sistema no puede saber solo. Ver la
             * migración: no se inventan, se preguntan.
             */
            'vacaciones_tomadas' => ['nullable', 'integer', 'min:0', 'max:400'],
            'cesantias_ya_pagadas' => ['nullable', 'numeric', 'min:0'],
            'indemnizacion' => ['nullable', 'numeric', 'min:0'],
            'indemnizacion_concepto' => [
                'nullable', 'string', 'max:250',
                // Una indemnización sin concepto es plata sin explicación.
                \Illuminate\Validation\Rule::requiredIf(fn () => (float) $r->input('indemnizacion') > 0),
            ],
            'otros_descuentos' => ['nullable', 'numeric', 'min:0'],
            'otros_descuentos_concepto' => [
                'nullable', 'string', 'max:250',
                \Illuminate\Validation\Rule::requiredIf(fn () => (float) $r->input('otros_descuentos') > 0),
            ],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'vacaciones_tomadas' => 'días de vacaciones ya tomados',
            'cesantias_ya_pagadas' => 'cesantías ya pagadas',
            'indemnizacion_concepto' => 'concepto de la indemnización',
            'otros_descuentos_concepto' => 'concepto del descuento',
        ]);

        $calculo = LiquidacionDefinitiva::de($e)->calcular($data);

        if (isset($calculo['error'])) {
            return back()->withErrors(['liquidacion' => $calculo['error']]);
        }

        $liq->fill($calculo + [
            'empleado_id' => $e->id,
            'estado' => Liquidacion::BORRADOR,
            'indemnizacion_concepto' => $data['indemnizacion_concepto'] ?? null,
            'otros_descuentos_concepto' => $data['otros_descuentos_concepto'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'liquidada_at' => now(),
            'liquidada_por_id' => $r->user()->id,
        ])->save();

        $e->anotar('liquidacion', 'Liquidación definitiva calculada por '.$r->user()->name
            .' · neto $'.number_format($liq->neto, 2, ',', '.'));

        return back()->with('success', "Liquidación de {$e->nombre} calculada: neto $"
            .number_format($liq->neto, 0, ',', '.').'.');
    }

    public function aprobar(Request $r, int $empleado): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh_nomina_aprobar'), 403,
            'La liquidación la aprueba gerencia.');

        $liq = Liquidacion::where('empleado_id', $empleado)->firstOrFail();

        abort_unless($liq->estado === Liquidacion::BORRADOR, 422,
            'Sólo se aprueba una liquidación en borrador.');

        /*
         * No se aprueba una liquidación en cero o negativa sin que alguien lo
         * diga por escrito. Casi siempre significa que falta un dato —el
         * salario de la ficha, o un descuento mal puesto— y firmarla así es
         * dejar a alguien sin su liquidación con la firma de gerencia encima.
         */
        if ((float) $liq->neto <= 0 && blank($liq->observaciones)) {
            return back()->withErrors([
                'aprobar' => 'El neto queda en '.number_format($liq->neto, 0, ',', '.')
                    .'. Si es correcto, escribí en observaciones por qué; si no, revisá la ficha y volvé a calcular.',
            ]);
        }

        $liq->update([
            'estado' => Liquidacion::APROBADA,
            'aprobada_at' => now(),
            'aprobada_por_id' => $r->user()->id,
        ]);

        /*
         * Y acá entra al libro, igual que la nómina del mes.
         *
         * Lo que se le paga a quien se va sale de las provisiones: no es un
         * gasto nuevo, es un pasivo que se cancela. Hasta hoy no se registraba
         * nada: hay $1.797.892 aprobados de una persona que se retiró en junio
         * y que la contabilidad no sabe que existen.
         */
        app(\App\Modules\Contabilidad\Actions\RegistrarAsientoLiquidacion::class)
            ->causar($liq->fresh());

        return back()->with('success', "Liquidación de {$liq->nombre} aprobada. Ya se puede pagar.");
    }

    public function pagar(Request $r, int $empleado): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'contabilidad'), 403,
            'El pago lo registra tesorería.');

        $liq = Liquidacion::where('empleado_id', $empleado)->firstOrFail();

        abort_unless($liq->estado === Liquidacion::APROBADA, 422,
            'Primero gerencia tiene que aprobar la liquidación.');

        $data = $r->validate([
            'fecha_pago' => ['required', 'date'],
        ], [], ['fecha_pago' => 'fecha de pago']);

        $liq->update([
            'estado' => Liquidacion::PAGADA,
            'fecha_pago' => $data['fecha_pago'],
            'pagada_at' => now(),
            'pagada_por_id' => $r->user()->id,
        ]);

        // Se cancela contra el banco.
        app(\App\Modules\Contabilidad\Actions\RegistrarAsientoLiquidacion::class)
            ->pagar($liq->fresh());

        return back()->with('success', "Liquidación de {$liq->nombre} marcada como pagada.");
    }

    /** El impreso que firma quien se va. */
    public function pdf(Request $r, int $empleado)
    {
        $liq = Liquidacion::with(['liquidadaPor:id,name', 'aprobadaPor:id,name', 'pagadaPor:id,name'])
            ->where('empleado_id', $empleado)->firstOrFail();

        return Pdf::loadView('rrhh.pdf.liquidacion-definitiva', [
            'liq' => $liq,
            'empresa' => \App\Models\EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('liquidacion-'.str($liq->nombre)->slug().'.pdf');
    }
}
