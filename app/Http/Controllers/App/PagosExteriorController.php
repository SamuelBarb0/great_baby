<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\CompromisoPago;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\PlanDePagos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Los vencimientos con China y cuánta plata hay que tener lista cada semana.
 */
class PagosExteriorController extends Controller implements HasMiddleware
{
    public function __construct(private PlanDePagos $plan) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(Permisos::puede($r->user(), 'compras_pagos_exterior'), 403, 'Esta parte de Compras necesita su propio acceso.');

                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        $this->plan->asegurarPlanes();
        $filas = $this->plan->pendientes();

        return Inertia::render('Compras/PagosExterior', [
            'filas' => $filas,
            'resumen' => $this->plan->resumen($filas),
            'proyeccion' => $this->plan->proyeccion($filas),
            'hay_tramos' => ! empty(setting('compras.interes_tramos', [])),
        ]);
    }

    public function plan(Request $r, OrdenCompra $orden): RedirectResponse
    {
        abort_unless($orden->esImportacion(), 422, 'Sólo las órdenes de importación tienen plan de pagos.');

        $data = $r->validate([
            'cuotas' => ['required', 'array', 'min:1', 'max:6'],
            'cuotas.*.concepto' => ['required', 'string', 'max:80'],
            'cuotas.*.porcentaje' => ['required', 'numeric', 'gt:0', 'max:100'],
            'cuotas.*.base' => ['required', Rule::in(array_keys(CompromisoPago::BASES))],
            'cuotas.*.dias' => ['required', 'integer', 'min:0', 'max:720'],
        ]);

        try {
            $this->plan->guardarPlan($orden, $data['cuotas']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Plan de pagos de {$orden->numero} actualizado.");
    }

    public function seguimiento(Request $r, CompromisoPago $compromiso): RedirectResponse
    {
        $data = $r->validate(['nota' => ['required', 'string', 'min:3', 'max:500']]);
        $this->plan->registrarSeguimiento($compromiso, $data['nota'], $r->user()?->id);

        return back()->with('success', 'Seguimiento registrado.');
    }

    public function pagado(Request $r, CompromisoPago $compromiso): RedirectResponse
    {
        $data = $r->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);
        $this->plan->marcarPagado($compromiso, $data['fecha'], $data['nota'] ?? null, $r->user()?->id);

        return back()->with('success', 'Cuota marcada como pagada.');
    }
}
