<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F5b · La gente que entró por las solicitudes de un jefe de área.
 *
 * El jefe no entra a Gestión Humana —ahí están los salarios y las hojas de vida
 * de toda la empresa—, pero sí tiene que poder hacer dos cosas con SU gente:
 * ver quién entró por lo que él pidió, y dar su concepto al final del período
 * de prueba.
 *
 * Eso último lo pidió Elena textual: «ese período de prueba debe estar validado
 * por el jefe del área y por gestión humana». Eran dos conceptos, no uno.
 *
 * Lo que ve es deliberadamente poco: nombre, cargo, cuándo entró y cómo va la
 * prueba. NO el salario, que es de Gestión Humana.
 */
class MiEquipoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_requisiciones'), 403);

            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        $gente = Empleado::with('vacante.requisicion:id,solicitante_id', 'pruebaJefePor:id,name')
            ->deJefe($r->user()->id)
            ->orderByDesc('fecha_ingreso')
            ->get();

        return Inertia::render('Rrhh/MiEquipo/Index', [
            'equipo' => $gente->map(fn ($e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'cargo' => $e->cargo,
                'area' => $e->area,
                'ingreso' => $e->fecha_ingreso?->toDateString(),
                'estado' => $e->estado,
                'induccion' => $e->estado_induccion,
                'prueba_hasta' => $e->prueba_hasta?->toDateString(),
                'prueba_dias' => $e->diasDePrueba(),
                // Lo que falta de MI parte.
                'debo_conceptuar' => $e->estado === 'activo'
                    && $e->prueba_hasta
                    && ! $e->tieneConceptoDelJefe()
                    && ! $e->prueba_evaluada_at,
                'mi_concepto' => $e->prueba_jefe_resultado,
                'mi_concepto_at' => $e->prueba_jefe_at?->format('Y-m-d'),
                'cerrado' => (bool) $e->prueba_evaluada_at,
                'resultado_final' => $e->prueba_resultado,
            ])->values(),
            'pendientes' => $gente->filter(fn ($e) => $e->estado === 'activo'
                && $e->prueba_hasta && ! $e->tieneConceptoDelJefe() && ! $e->prueba_evaluada_at)->count(),
        ]);
    }

    /**
     * El concepto del jefe sobre el período de prueba de uno de los suyos.
     *
     * Va primero que el de Gestión Humana: ella decide viendo lo que él opinó.
     */
    public function conceptoDePrueba(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'resultado' => ['required', 'in:continua,no_continua'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $e = Empleado::with('vacante.requisicion')->findOrFail($empleado);

        abort_unless(
            $e->jefeDelArea() === $r->user()->id || Permisos::puede($r->user(), 'rrhh'),
            403,
            'Este concepto lo da el jefe que pidió el personal.',
        );
        abort_if($e->prueba_jefe_at, 422, 'Ya diste tu concepto sobre esta persona.');
        abort_if($e->prueba_evaluada_at, 422, 'Gestión Humana ya cerró este período de prueba.');

        $observacion = $data['observacion'] ?? null;

        // Decir «no continúa» sin explicar deja a la empresa sin con qué
        // sustentar la decisión si después alguien la reclama.
        if ($data['resultado'] === 'no_continua' && blank($observacion)) {
            return back()->withErrors(['observacion' => 'Decí por qué considerás que no continúa.']);
        }

        $e->update([
            'prueba_jefe_at' => now(),
            'prueba_jefe_por_id' => $r->user()->id,
            'prueba_jefe_resultado' => $data['resultado'],
            'prueba_jefe_observacion' => $observacion,
        ]);
        $e->anotar('prueba_concepto_jefe', trim(
            "Concepto del jefe ({$r->user()->name}): ".
            ($data['resultado'] === 'continua' ? 'continúa' : 'no continúa').' · '.$observacion, ' ·'
        ));

        // Gestión Humana es quien cierra: tiene que enterarse de que ya puede.
        foreach (User::permission(Permisos::permiso('rrhh'))->get(['id']) as $u) {
            NotificacionErp::crear([
                'user_id' => $u->id,
                'tipo' => 'rrhh_prueba',
                'titulo' => "Concepto del jefe listo · {$e->nombre}",
                'mensaje' => "{$r->user()->name} conceptuó: ".
                    ($data['resultado'] === 'continua' ? 'continúa' : 'NO continúa').'. Falta cerrar el período.',
                'url' => "/app/rrhh/empleados/{$e->id}",
                'icono' => 'heroicon-o-clock',
                'color' => $data['resultado'] === 'continua' ? 'success' : 'warning',
            ]);
        }

        return back()->with('success', 'Concepto registrado. Gestión Humana ya fue notificada.');
    }
}
