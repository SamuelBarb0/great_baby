<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EmpresaConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // La casilla manda. Antes la puerta preguntaba por el rol y la
                //   casilla de /app/roles no la consultaba nadie: el admin creia
                //   repartir accesos y solo movia lo que se ve en el menu.
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'empresa'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        $e = EmpresaConfig::current();
        return Inertia::render('Empresa/Index', [
            'empresa' => $e->only([
                'id', 'razon_social', 'nombre_comercial', 'nit', 'regimen', 'actividad_economica',
                'direccion', 'ciudad', 'departamento', 'pais', 'telefono', 'email', 'web',
                'resolucion_dian', 'resolucion_desde', 'resolucion_hasta', 'prefijo_dian',
                'rango_desde', 'rango_hasta', 'banco_nombre', 'banco_swift', 'banco_cuenta',
                'banco_iban', 'banco_moneda', 'financiero_nombre', 'financiero_email', 'financiero_telefono',
            ]),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'nit' => ['required', 'string', 'max:30'],
            'regimen' => ['nullable', 'string', 'max:100'],
            'actividad_economica' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'pais' => ['nullable', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'web' => ['nullable', 'string', 'max:200'],
            'resolucion_dian' => ['nullable', 'string', 'max:100'],
            'resolucion_desde' => ['nullable', 'date'],
            'resolucion_hasta' => ['nullable', 'date'],
            'prefijo_dian' => ['nullable', 'string', 'max:20'],
            'rango_desde' => ['nullable', 'integer'],
            'rango_hasta' => ['nullable', 'integer'],
            'banco_nombre' => ['nullable', 'string', 'max:120'],
            'banco_swift' => ['nullable', 'string', 'max:20'],
            'banco_cuenta' => ['nullable', 'string', 'max:60'],
            'banco_iban' => ['nullable', 'string', 'max:60'],
            'banco_moneda' => ['nullable', 'string', 'max:10'],
            'financiero_nombre' => ['nullable', 'string', 'max:120'],
            'financiero_email' => ['nullable', 'email', 'max:150'],
            'financiero_telefono' => ['nullable', 'string', 'max:50'],
        ]);
        // Fix demo · varias columnas de `empresa_config` están definidas como
        //   NOT NULL con default ''. El validate `nullable` deja pasar null,
        //   pero el ->update([col=>null]) revienta con "Column cannot be null".
        //   Solución raíz: normalizar los nullable-string a '' antes del update.
        foreach ([
            'nombre_comercial', 'regimen', 'actividad_economica',
            'direccion', 'ciudad', 'departamento', 'pais',
            'telefono', 'email', 'web',
            'resolucion_dian', 'prefijo_dian',
            'banco_nombre', 'banco_swift', 'banco_cuenta', 'banco_iban', 'banco_moneda',
            'financiero_nombre', 'financiero_email', 'financiero_telefono',
        ] as $campo) {
            if (array_key_exists($campo, $data) && $data[$campo] === null) {
                $data[$campo] = '';
            }
        }
        EmpresaConfig::current()->update($data);
        return back()->with('success', '✅ Configuración guardada');
    }
}
