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
    /**
     * El grupo que puede tocar quien entra por `reglas_contables`.
     *
     * Se compara contra el prefijo de la clave (`contable.cta_bancos_default`
     * → `contable`). Está acá y no en la pantalla porque el filtro de verdad
     * tiene que estar del lado del servidor: esconder un campo en el front no
     * impide que alguien mande la clave a mano.
     */
    private const GRUPO_CONTABLE = 'contable';

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                $u = $r->user();

                abort_unless(
                    $u?->esAracely() || \App\Auth\Permisos::puede($u, 'reglas_contables'),
                    403,
                    'Solo administración puede editar reglas.',
                );

                return $next($r);
            }),
        ];
    }

    /** ¿Entra a todo, o sólo al grupo contable? */
    private function veTodo(Request $r): bool
    {
        return (bool) $r->user()?->esAracely();
    }

    public function index(Request $r): Response
    {
        // Sembrar cualquier regla nueva del código que aún no esté en BD.
        Reglas::seedDefaults();

        $grupos = Reglas::porGrupo();

        /*
         * Al contador se le muestra exactamente lo que va a poder guardar.
         *
         * `porGrupo()` agrupa por la columna `grupo`, no por el prefijo de la
         * clave, y dentro de «Contabilidad» hay dos reglas que NO son
         * `contable.*`: `compras.permitir_auto_aprobar` y
         * `contabilidad.permitir_auto_aprobar_asiento`. Son los candados de
         * doble aprobación —si quien crea una OC o un asiento puede aprobarlo
         * solo—, o sea separación de funciones, y eso no lo ajusta quien queda
         * controlado por ellos.
         *
         * Así que se filtra por la CLAVE, no por el grupo. Si se filtrara por
         * grupo, la pantalla mostraría dos campos que el servidor después
         * rechaza, que es justo el defecto que este ERP arrastra en otras
         * pantallas: ofrecer algo y después negarlo.
         */
        if (! $this->veTodo($r)) {
            $grupos = collect($grupos)
                ->map(fn (array $reglas) => array_values(array_filter(
                    $reglas,
                    fn (array $x) => str_starts_with($x['clave'], self::GRUPO_CONTABLE.'.'),
                )))
                ->filter(fn (array $reglas) => $reglas !== [])
                ->all();
        }

        return Inertia::render('Reglas', [
            'grupos' => $grupos,
            'soloContable' => ! $this->veTodo($r),
            'gruposLabels' => [
                'empaque' => '📦 Estación de Empaque',
                'dashboard' => '📊 Torre de Control',
                'cartera' => '💰 Cartera',
                'dropi' => '🚚 Dropi',
                'catalogo' => '🏷️ Catálogo',
                'crm' => '👥 CRM',
                'contable' => '📚 Contabilidad',
                'rrhh' => '🧑‍💼 Gestión Humana · novedades',
                'logistica' => '🚚 Logística · guías y entregas',
                'notificaciones' => '🔔 Avisos · campana y correo',
                'garantias' => '🛠 Garantías · plazos',
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

        $soloContable = ! $this->veTodo($request);

        $actualizadas = 0;
        $errores = [];
        foreach ($data['reglas'] as $r) {
            /*
             * El candado real. Quien entra por `reglas_contables` sólo escribe
             * `contable.*`, aunque mande otra clave a mano: la pantalla filtra
             * por comodidad, esto filtra por seguridad.
             */
            if ($soloContable && ! str_starts_with($r['clave'], self::GRUPO_CONTABLE.'.')) {
                $errores[] = $r['clave'].': sólo administración cambia reglas fuera de Contabilidad.';
                continue;
            }

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
