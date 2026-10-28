<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Modules\Contabilidad\Services\ImportadorPlanCuentas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlador Vue del PLAN DE CUENTAS (PUC).
 * Reutiliza 100% el modelo PlanCuenta y el ImportadorPlanCuentas existentes
 * (que ya se usaban en Filament); solo cambia la UI a Vue/Inertia.
 * Ruta: /app/contabilidad/plan-cuentas
 */
class PlanCuentasController extends Controller implements HasMiddleware
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

    public function index(Request $r): Response
    {
        $q = PlanCuenta::query()->orderBy('codigo');

        if ($busca = trim((string) $r->query('q', ''))) {
            $q->where(function ($qq) use ($busca) {
                $qq->where('codigo', 'like', $busca.'%')
                   ->orWhere('nombre', 'like', '%'.$busca.'%');
            });
        }
        if ($clase = $r->query('clase')) $q->where('clase', $clase);
        if ($nivel = $r->query('nivel')) $q->where('nivel', (int) $nivel);
        if ($r->filled('movimiento')) $q->where('permite_movimiento', $r->boolean('movimiento'));
        if ($r->filled('activa')) $q->where('activa', $r->boolean('activa'));

        $rows = $q->paginate(50)->withQueryString();

        return Inertia::render('Contabilidad/PlanCuentas', [
            /*
             * Eliminar e importar son de root (`esRoot`), pero la pantalla se
             * abre con `esContable`: el Contador veia la papelera de cada fila
             * y el boton «Importar» y recibia un 403 al usarlos. El molde
             * correcto —el controlador manda la bandera, el Vue la respeta— ya
             * estaba en uso en otras tres pantallas del proyecto.
             */
            'puedeAdministrar' => \App\Auth\Permisos::esRoot($r->user()),

            'filtros' => [
                'q' => $r->query('q', ''),
                'clase' => $r->query('clase', ''),
                'nivel' => $r->query('nivel', ''),
                'movimiento' => $r->query('movimiento', ''),
                'activa' => $r->query('activa', ''),
            ],
            'clases' => PlanCuenta::CLASES,
            'kpis' => [
                'total' => PlanCuenta::count(),
                'de_movimiento' => PlanCuenta::where('permite_movimiento', true)->count(),
                'activas' => PlanCuenta::where('activa', true)->count(),
            ],
            'cuentas' => $rows->through(fn ($c) => [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'nombre' => $c->nombre,
                'clase' => $c->clase,
                'clase_nombre' => PlanCuenta::nombreClase($c->clase),
                'nivel' => (int) $c->nivel,
                'naturaleza' => $c->naturaleza,
                'permite_movimiento' => (bool) $c->permite_movimiento,
                'activa' => (bool) $c->activa,
                'siigo_cuenta_id' => $c->siigo_cuenta_id,
            ]),
        ]);
    }

    public function guardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'codigo' => ['required', 'string', 'regex:/^\d+$/', 'max:20'],
            'nombre' => ['required', 'string', 'max:150'],
            'naturaleza' => ['nullable', 'in:debito,credito'],
            'permite_movimiento' => ['boolean'],
            'siigo_cuenta_id' => ['nullable', 'string', 'max:50'],
            'activa' => ['boolean'],
        ]);

        $data['permite_movimiento'] ??= false;
        $data['activa'] ??= true;

        $c = PlanCuenta::updateOrCreate(['codigo' => $data['codigo']], $data);
        PlanCuenta::vincularPadres();

        return back()->with('flash', [
            'type' => 'success',
            'message' => "Cuenta {$c->codigo} guardada.",
        ]);
    }

    public function eliminar(Request $r, PlanCuenta $planCuenta): RedirectResponse
    {
        // A3 FIX · eliminar PUC es destructivo · solo Aracely/Gerencia (esRoot).
        //   Un Contador puede crear/editar cuentas, no borrarlas.
        abort_unless($r->user()?->esRoot(), 403,
            'Solo Aracely/Gerencia puede eliminar cuentas del PUC.');
        // Guard: no eliminar cuentas con hijos.
        if ($planCuenta->hijos()->exists()) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => "No se puede eliminar {$planCuenta->codigo}: tiene subcuentas asociadas.",
            ]);
        }
        $codigo = $planCuenta->codigo;
        $planCuenta->delete();
        return back()->with('flash', [
            'type' => 'success',
            'message' => "Cuenta {$codigo} eliminada.",
        ]);
    }

    public function importar(Request $r, ImportadorPlanCuentas $importador): RedirectResponse
    {
        // A3 FIX · importar PUC reemplaza masivo · solo Aracely/Gerencia (esRoot).
        //   Mismo criterio que CONT-C8 (export CSV): acción crítica de alto impacto.
        abort_unless($r->user()?->esRoot(), 403,
            'Solo Aracely/Gerencia puede importar/reemplazar el PUC.');
        $r->validate([
            'archivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
        ]);

        /** @var UploadedFile $file */
        $file = $r->file('archivo');
        $tmp = $file->getRealPath();

        $resultado = $importador->importar($tmp);

        $ok = $resultado['errores'] === [];
        $msg = "Importación " . ($ok ? 'exitosa' : 'con avisos')
            . ": {$resultado['creados']} creados · {$resultado['actualizados']} actualizados"
            . (empty($resultado['errores']) ? '' : ' · ' . count($resultado['errores']) . ' errores');

        return back()->with('flash', [
            'type' => $ok ? 'success' : 'warning',
            'message' => $msg,
            'errores' => array_slice($resultado['errores'], 0, 20),
        ]);
    }

    public function plantilla(): StreamedResponse
    {
        $filename = 'plantilla_plan_cuentas.csv';
        return response()->streamDownload(function () {
            echo "codigo,nombre,naturaleza,permite_movimiento,siigo_cuenta_id,activa\n";
            echo "1105,Caja,debito,0,,1\n";
            echo "110505,Caja general,debito,1,,1\n";
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
