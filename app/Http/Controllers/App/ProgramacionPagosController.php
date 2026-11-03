<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\ProgramacionPago;
use App\Modules\Compras\Services\CuentasPorPagar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La programación de pagos: qué se paga, cuándo y con la firma de quién.
 *
 * Hoy es una hoja impresa de cuentas por pagar con las filas elegidas
 * marcadas en naranja y, al margen y a lápiz, «autorizaron 22 millones
 * Octubre 27». Si alguien pregunta en enero por qué se le pagó a un proveedor
 * antes que a otro, la respuesta está en un papel que ya nadie encuentra.
 *
 * Quién arma no es quien autoriza: armar la lista es trabajo de contabilidad,
 * soltar la plata es decisión de gerencia. Es el mismo criterio que ya rige
 * los asientos manuales y los pagos a proveedor.
 */
class ProgramacionPagosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'programacion_pagos'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $r, CuentasPorPagar $servicio): Response
    {
        $pendientes = $servicio->pendientes();

        $programaciones = ProgramacionPago::with([
            'items.recepcion:id,numero,factura_proveedor',
            'creadaPor:id,name', 'autorizadaPor:id,name',
        ])->orderByDesc('fecha_programada')->orderByDesc('id')->limit(30)->get();

        return Inertia::render('Compras/ProgramacionPagos', [
            // Lo que se puede meter en una programación nueva.
            'porPagar' => $pendientes,
            'programaciones' => $programaciones->map(fn (ProgramacionPago $p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'fecha_programada' => $p->fecha_programada?->toDateString(),
                'estado' => $p->estado,
                'monto_autorizado' => $p->monto_autorizado !== null ? (float) $p->monto_autorizado : null,
                'total' => $p->total(),
                'sin_repartir' => $p->sinRepartir(),
                'creada_por' => $p->creadaPor?->name,
                'autorizada_por' => $p->autorizadaPor?->name,
                'autorizada_at' => $p->autorizada_at?->format('Y-m-d H:i'),
                'notas' => $p->notas,
                'items' => $p->items->map(fn ($i) => [
                    'recepcion' => $i->recepcion?->numero,
                    'factura' => $i->recepcion?->factura_proveedor,
                    'monto' => (float) $i->monto,
                ]),
            ]),
            'puedeAutorizar' => $this->puedeAutorizar($r->user()),
        ]);
    }

    /**
     * Quién suelta la plata.
     *
     * Armar la lista es trabajo de contabilidad; autorizar el desembolso es
     * decisión de gerencia. Si fuera la misma persona, la firma al margen no
     * significaría nada.
     */
    protected function puedeAutorizar(?\App\Models\User $u): bool
    {
        return (bool) ($u && ($u->esRoot() || $u->hasRole('Gerente')));
    }

    public function guardar(Request $r, CuentasPorPagar $servicio): RedirectResponse
    {
        abort_unless(\App\Auth\Permisos::puedeAccion($r->user(), 'programacion_pagos', 'crear'), 403,
            'Armar una programacion de pagos necesita permiso de creacion.');

        $data = $r->validate([
            'fecha_programada' => ['required', 'date'],
            'monto_autorizado' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.recepcion_id' => ['required', 'integer', 'distinct', 'exists:compras_recepciones,id'],
            'items.*.monto' => ['required', 'numeric', 'gt:0'],
        ], [
            'items.required' => 'Elegí al menos una factura.',
            'items.*.recepcion_id.distinct' => 'La misma factura está dos veces: se sumaría sola.',
        ]);

        // Nadie puede programar más de lo que se debe de esa factura. Pasa
        // cuando se teclea el valor total en vez del saldo, y el error no se
        // nota hasta que el proveedor avisa que le pagaron de más.
        $saldos = $servicio->pendientes()->keyBy('recepcion_id');

        foreach ($data['items'] as $i => $item) {
            $saldo = (float) ($saldos[$item['recepcion_id']]['saldo'] ?? 0);
            if ((float) $item['monto'] > $saldo + 0.01) {
                return back()->withErrors([
                    "items.{$i}.monto" => 'De esa factura se deben $'.number_format($saldo, 0, ',', '.')
                        .': no se pueden programar $'.number_format((float) $item['monto'], 0, ',', '.').'.',
                ])->withInput();
            }
        }

        $p = DB::transaction(function () use ($data, $r) {
            $p = ProgramacionPago::create([
                'numero' => ProgramacionPago::siguienteNumero(),
                'fecha_programada' => $data['fecha_programada'],
                'monto_autorizado' => $data['monto_autorizado'] ?? null,
                'notas' => $data['notas'] ?? null,
                'estado' => 'borrador',
                'creada_por' => $r->user()?->id,
            ]);

            foreach ($data['items'] as $item) {
                $p->items()->create([
                    'recepcion_id' => $item['recepcion_id'],
                    'monto' => $item['monto'],
                ]);
            }

            return $p;
        });

        return back()->with('flash', ['type' => 'success',
            'message' => "Programación {$p->numero} creada por $"
                .number_format($p->fresh()->total(), 0, ',', '.').'. Falta autorizarla.']);
    }

    /**
     * Autorizar el desembolso.
     *
     * Acá queda la firma que en el papel va a lápiz al margen: quién, cuándo
     * y por cuánto. Es el dato que no existe hoy y por el que nadie puede
     * responder tres meses después.
     */
    public function autorizar(Request $r, ProgramacionPago $programacion): RedirectResponse
    {
        abort_unless($this->puedeAutorizar($r->user()), 403,
            'Autorizar el desembolso es de gerencia.');

        if ($programacion->estaAutorizada()) {
            return back()->with('flash', ['type' => 'error',
                'message' => "La programación {$programacion->numero} ya estaba autorizada."]);
        }

        // Quien la armó no la autoriza: si fuera el mismo, la firma no diría
        // nada. Es el mismo control que ya rige los asientos manuales.
        if ($programacion->creada_por === $r->user()?->id && ! $r->user()?->esRoot()) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'Quien arma la programación no la autoriza. Pedile el visto bueno a gerencia.']);
        }

        $programacion->update([
            'estado' => 'autorizada',
            'autorizada_por' => $r->user()?->id,
            'autorizada_at' => now(),
        ]);

        return back()->with('flash', ['type' => 'success',
            'message' => "Programación {$programacion->numero} autorizada por $"
                .number_format($programacion->total(), 0, ',', '.').'.']);
    }

    public function anular(Request $r, ProgramacionPago $programacion): RedirectResponse
    {
        $data = $r->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if ($programacion->estado === 'ejecutada') {
            return back()->with('flash', ['type' => 'error',
                'message' => 'Esa programación ya se ejecutó: los pagos están hechos.']);
        }

        $programacion->update([
            'estado' => 'anulada',
            'notas' => trim(($programacion->notas ? $programacion->notas."\n" : '')
                .'ANULADA: '.$data['motivo']),
        ]);

        return back()->with('flash', ['type' => 'success',
            'message' => "Programación {$programacion->numero} anulada."]);
    }
}
