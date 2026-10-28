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
            'tipo' => ['required', 'in:retefuente,reteica,reteiva,autorrenta,autorreteica'],
            'ambito' => ['required', 'in:compras,ventas'],
            // La otra pata del asiento. En autorretención es la 13 de activo:
            // sin ella el asiento queda cojo y el balance no lo delata, porque
            // le faltan las dos patas a la vez.
            'cuenta_puc_contrapartida' => ['nullable', 'string', 'max:30'],
            // 255 como la columna: los conceptos de la DIAN traen la condición
            // que decide si aplican («…superiores a 204 UVT») y recortarla deja
            // una regla que parece completa y se aplica donde no debe.
            'concepto' => ['required', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:60'],
            'base_minima' => ['nullable', 'numeric', 'min:0'],
            'tarifa_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            // Opcional mientras se revisa: una regla cargada desde la tabla del
            // contador puede no tener cuenta todavía. El seguro está abajo.
            'cuenta_puc' => ['nullable', 'string', 'max:30'],
            'siigo_tax_id' => ['nullable', 'integer', 'min:1'],
            'activa' => ['boolean'],
            'notas' => ['nullable', 'string'],
        ]);
        $data['activa'] ??= true;
        $data['base_minima'] ??= 0;

        /*
         * Sin cuenta no se activa. Una regla activa calcula la retención en
         * cada factura de compra y arma su asiento: si no sabe contra qué
         * cuenta, el asiento sale mal y no lo nota nadie hasta el cierre.
         */
        if ($data['activa'] && empty($data['cuenta_puc'])) {
            return back()->withErrors([
                'cuenta_puc' => 'Para activar la regla hay que decirle a qué cuenta del PUC va la retención.',
            ])->withInput();
        }

        /*
         * Una autorretención sin contrapartida no es un asiento, es media.
         * Mueve la 23 (lo que se le debe a la DIAN) contra la 13 (el anticipo
         * que queda a favor). Activarla con una sola cuenta produciría asientos
         * descuadrados en cada factura emitida.
         */
        $esAutorretencion = in_array($data['tipo'], ['autorrenta', 'autorreteica'], true);
        if ($data['activa'] && $esAutorretencion && empty($data['cuenta_puc_contrapartida'])) {
            return back()->withErrors([
                'cuenta_puc_contrapartida' => 'Una autorretención mueve dos cuentas: falta la de activo (13…), '
                    . 'que es el anticipo que queda a favor.',
            ])->withInput();
        }

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
