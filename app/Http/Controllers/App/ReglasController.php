<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Reglas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class ReglasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esAracely(), 403, 'Solo administración puede editar reglas.');
                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        // Sembrar cualquier regla nueva del código que aún no esté en BD.
        Reglas::seedDefaults();

        return Inertia::render('Reglas', [
            'grupos' => Reglas::porGrupo(),
            'gruposLabels' => [
                'empaque' => '📦 Estación de Empaque',
                'dashboard' => '📊 Torre de Control',
                'cartera' => '💰 Cartera',
                'dropi' => '🚚 Dropi',
                'catalogo' => '🏷️ Catálogo',
                'crm' => '👥 CRM',
                'contable' => '📚 Contabilidad',
                'rrhh' => '🧑‍💼 Gestión Humana · novedades',
                // Faltaba: las 24 reglas de SIIGO se pintaban bajo el título
                // crudo «siigo», que es justo el grupo donde están los tipos de
                // documento sin los cuales no sale ni una nota crédito.
                'siigo' => '🔗 SIIGO · documentos y cuentas',
            ],
            // Familia de document-type de SIIGO que corresponde a cada regla.
            // Con esto la pantalla muestra un desplegable con los comprobantes
            // reales de la cuenta en vez de pedir que alguien escriba un id.
            'familiasDocumento' => [
                'siigo.doc_type_compra' => 'FC',
                'siigo.doc_type_importacion' => 'FC',
                // La devolución a proveedor no se emite como nota crédito: va
                // por POST /v1/journals, así que su comprobante es de la
                // familia CC. El nombre de la regla quedó de antes.
                'siigo.doc_type_nc_compra' => 'CC',
                'siigo.doc_type_nota_credito' => 'NC',
                'siigo.doc_type_nota_debito' => 'ND',
                'siigo.doc_type_recibo' => 'RC',
                'siigo.doc_type_egreso' => 'RP',
                'siigo.doc_type_asiento' => 'CC',
                'siigo.doc_type_asiento_manual' => 'CC',
                'siigo.doc_type_gasto' => 'CC',
                'siigo.doc_type_conciliacion' => 'CC',
                'siigo.resolucion_fv_default_id' => 'FV',
            ],
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reglas' => ['required', 'array'],
            'reglas.*.clave' => ['required', 'string'],
            'reglas.*.valor' => ['nullable'],
        ]);

        $actualizadas = 0;
        $errores = [];
        foreach ($data['reglas'] as $r) {
            try {
                Reglas::set($r['clave'], $r['valor'] ?? '');
                $actualizadas++;
            } catch (\InvalidArgumentException $e) {
                $errores[] = $r['clave'] . ': ' . $e->getMessage();
            }
        }

        $flash = ['success' => "✅ {$actualizadas} reglas actualizadas"];
        if (! empty($errores)) {
            $flash['warning'] = "⚠ No se pudieron guardar: " . implode(' · ', $errores);
        }
        return back()->with($flash);
    }
}
