<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\RetencionConfig;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Models\SiigoConfig;
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
                abort_unless($r->user()?->esAracely(), 403);
                return $next($r);
            }),
        ];
    }

    /**
     * Multiempresa · una pestaña por razón social (Great Baby, Arsutex,
     * e-commerce). La principal se ve igual que antes; las demás muestran
     * además su cuenta de SIIGO, porque cada una es otra empresa ante la DIAN.
     */
    public function index(Request $r): Response
    {
        $e = EmpresaConfig::porId($r->integer('empresa') ?: null);
        return Inertia::render('Empresa/Index', [
            'empresas' => EmpresaConfig::query()->orderByDesc('es_principal')->orderBy('id')->get()
                ->map(fn ($x) => ['id' => $x->id, 'nombre' => $x->nombreCorto(), 'activa' => $x->activa,
                                  'es_principal' => $x->es_principal])->values(),
            'siigo' => $e->es_principal ? null : $this->siigoDe($e),
            'empresa' => $e->only([
                'id', 'codigo', 'es_principal', 'activa', 'razon_social', 'nombre_comercial', 'nit', 'regimen', 'actividad_economica',
                'direccion', 'ciudad', 'departamento', 'pais', 'telefono', 'email', 'web',
                'resolucion_dian', 'resolucion_desde', 'resolucion_hasta', 'prefijo_dian',
                'rango_desde', 'rango_hasta', 'banco_nombre', 'banco_swift', 'banco_cuenta',
                'banco_iban', 'banco_moneda', 'financiero_nombre', 'financiero_email', 'financiero_telefono',
            ]),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $empresa = EmpresaConfig::porId($request->integer('id') ?: null);
        // Una empresa activa factura: sin NIT ni prefijo saldría a la DIAN a medias.
        $activa = $empresa->es_principal || $request->boolean('activa');
        $data = $request->validate([
            'activa' => ['nullable', 'boolean'],
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'nit' => [$activa ? 'required' : 'nullable', 'string', 'max:30'],
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
            'prefijo_dian' => [$activa && ! $empresa->es_principal ? 'required' : 'nullable', 'string', 'max:20'],
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
            'resolucion_dian', 'prefijo_dian', 'nit',
            'banco_nombre', 'banco_swift', 'banco_cuenta', 'banco_iban', 'banco_moneda',
            'financiero_nombre', 'financiero_email', 'financiero_telefono',
        ] as $campo) {
            if (array_key_exists($campo, $data) && $data[$campo] === null) {
                $data[$campo] = '';
            }
        }
        // La principal no se apaga: es la que factura todo lo que no elige empresa.
        $data['activa'] = $activa;
        $empresa->update($data);
        return back()->with('success', '✅ Configuración guardada');
    }

    /**
     * Cuenta de SIIGO de una razón social que no es la principal. La de Great
     * Baby sigue en Integración SIIGO, con sus catálogos y su cola.
     */
    public function guardarSiigo(Request $r, int $empresa): RedirectResponse
    {
        $e = EmpresaConfig::findOrFail($empresa);
        abort_if($e->es_principal, 422, 'La cuenta de SIIGO de la empresa principal se configura en Integración SIIGO.');

        $data = $r->validate([
            'username' => ['nullable', 'string', 'max:190'],
            'access_key' => ['nullable', 'string', 'max:500'],
            'partner_id' => ['nullable', 'string', 'max:120'],
            'ambiente' => ['required', 'in:sandbox,produccion'],
            'activo' => ['boolean'],
            'tipo_documento_id' => ['nullable', 'integer', 'min:1'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'payment_type_id' => ['nullable', 'integer', 'min:1'],
            'iva' => ['array'],
            'iva.*' => ['nullable', 'integer', 'min:1'],
            'retenciones' => ['array'],
            'retenciones.*' => ['nullable', 'integer', 'min:1'],
        ]);

        $cfg = SiigoConfig::deEmpresa($e->id);
        $cambiaCredencial = ($data['username'] ?? null) !== $cfg->username || ! empty($data['access_key']);
        $cfg->fill([
            'username' => $data['username'] ?? null,
            'partner_id' => $data['partner_id'] ?? null,
            'ambiente' => $data['ambiente'],
            'activo' => (bool) ($data['activo'] ?? false),
            'tipo_documento_id' => $data['tipo_documento_id'] ?? null,
            'seller_id' => $data['seller_id'] ?? null,
            'payment_type_id' => $data['payment_type_id'] ?? null,
            'mapa_impuestos' => [
                'iva' => array_filter($data['iva'] ?? []),
                'retenciones' => array_filter($data['retenciones'] ?? []),
            ],
        ]);
        if (! empty($data['access_key'])) {
            $cfg->access_key = $data['access_key'];
        }
        if ($cambiaCredencial) {
            $cfg->forceFill(['token_cache' => null, 'token_expires_at' => null,
                'ultimo_auth_ok' => null, 'ultimo_auth_at' => null, 'ultimo_auth_error' => null]);
        }
        $cfg->save();

        return back()->with('success', "Cuenta de SIIGO de {$e->nombreCorto()} guardada.");
    }

    public function probarSiigo(int $empresa): RedirectResponse
    {
        $e = EmpresaConfig::findOrFail($empresa);
        abort_if($e->es_principal, 422);

        $r = (new SiigoClient(SiigoConfig::deEmpresa($e->id)))->probarConexion();

        return back()->with($r['ok'] ? 'success' : 'error', "{$e->nombreCorto()}: {$r['mensaje']}");
    }

    /** Lo que la pantalla necesita de la cuenta de SIIGO de una empresa. */
    private function siigoDe(EmpresaConfig $e): array
    {
        $cfg = SiigoConfig::deEmpresa($e->id);

        return [
            'username' => $cfg->username,
            'tiene_clave' => filled($cfg->access_key),
            'partner_id' => $cfg->partner_id,
            'ambiente' => $cfg->ambiente,
            'activo' => (bool) $cfg->activo,
            'tipo_documento_id' => $cfg->tipo_documento_id,
            'seller_id' => $cfg->seller_id,
            'payment_type_id' => $cfg->payment_type_id,
            'ultimo_auth_ok' => $cfg->ultimo_auth_ok,
            'ultimo_auth_at' => $cfg->ultimo_auth_at?->format('Y-m-d H:i'),
            'ultimo_auth_error' => $cfg->ultimo_auth_error,
            // Los IDs de esta cuenta, por cada IVA y retención que usa el ERP.
            'ivas' => \DB::table('impuestos')->where('tipo', 'iva')->where('activo', true)
                ->where('porcentaje', '>', 0)->orderBy('porcentaje')->get(['nombre', 'porcentaje'])
                ->map(function ($i) use ($cfg) {
                    $pct = rtrim(rtrim(number_format((float) $i->porcentaje, 3, '.', ''), '0'), '.');
                    return ['clave' => $pct, 'nombre' => $i->nombre, 'siigo_id' => $cfg->ivaSiigoId($pct)];
                })->values(),
            'retenciones' => RetencionConfig::where('activa', true)->orderBy('tipo')->get(['id', 'tipo', 'concepto'])
                ->map(fn ($x) => ['clave' => (string) $x->id, 'nombre' => "{$x->tipo} · {$x->concepto}",
                                  'siigo_id' => $cfg->retencionSiigoId($x->id)])->values(),
        ];
    }
}
