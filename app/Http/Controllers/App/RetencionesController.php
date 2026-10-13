<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\RetencionConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 4 · B.3 · CRUD Vue de reglas de retención (Retefuente/Reteica/Reteiva).
 * Ruta: /app/cartera/retenciones
 */
class RetencionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);
                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Cartera/Retenciones', [
            'reglas' => RetencionConfig::orderBy('tipo')->orderBy('concepto')->get(),
        ]);
    }

    public function guardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'id' => ['nullable', 'integer'],
            'tipo' => ['required', 'in:retefuente,reteica,reteiva'],
            'concepto' => ['required', 'string', 'max:100'],
            'ciudad' => ['nullable', 'string', 'max:60'],
            'base_minima' => ['nullable', 'numeric', 'min:0'],
            'tarifa_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'cuenta_puc' => ['required', 'string', 'max:30'],
            'siigo_tax_id' => ['nullable', 'integer', 'min:1'],
            'activa' => ['boolean'],
            'notas' => ['nullable', 'string'],
        ]);
        $data['activa'] ??= true;
        $data['base_minima'] ??= 0;

        if (! empty($data['id'])) {
            RetencionConfig::where('id', $data['id'])->update($data);
        } else {
            RetencionConfig::create($data);
        }
        return back()->with('flash', ['type' => 'success', 'message' => 'Regla de retención guardada.']);
    }

    public function eliminar(RetencionConfig $regla): RedirectResponse
    {
        $regla->delete();
        return back()->with('flash', ['type' => 'success', 'message' => 'Regla eliminada.']);
    }
}
