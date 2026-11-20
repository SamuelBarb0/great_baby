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

    /**
     * Qué reglas de cuenta apuntan hoy a una cuenta que no recibe movimiento.
     *
     * Devuelve, por clave: qué le pasa a esa cuenta y qué hijas suyas sí
     * admiten movimiento, para que el contador elija sin salir de la
     * pantalla. Si el plan de cuentas todavía no está cargado —instalación
     * nueva— no se inventa nada: devuelve vacío.
     *
     * @param  array<string, array<int, array>>  $grupos
     * @return array<string, array{estado:string, nombre:?string, hijas:array}>
     */
    private function cuentasQueNoSirven(array $grupos): array
    {
        try {
            $plan = \DB::table('plan_cuentas')
                ->get(['codigo', 'nombre', 'permite_movimiento'])
                ->keyBy('codigo');
        } catch (\Throwable) {
            return [];
        }

        if ($plan->isEmpty()) {
            return [];
        }

        $salida = [];

        foreach ($grupos as $reglas) {
            foreach ($reglas as $regla) {
                $clave = $regla['clave'] ?? '';

                // Sólo las que son una cuenta. El resto de reglas —días,
                // porcentajes, interruptores— no tienen nada que ver acá.
                if (! str_contains($clave, 'cta_') && ! str_contains($clave, 'cuenta')) {
                    continue;
                }

                $valor = trim((string) ($regla['valor'] ?? ''));

                if ($valor === '') {
                    continue;
                }

                $cuenta = $plan->get($valor);

                if ($cuenta && $cuenta->permite_movimiento) {
                    continue;
                }

                $salida[$clave] = [
                    'estado' => $cuenta ? 'agrupadora' : 'no_existe',
                    'nombre' => $cuenta?->nombre,
                    // Las hijas que sí reciben movimiento, para elegir.
                    'hijas' => $cuenta
                        ? $plan->filter(fn ($x) => $x->codigo !== $valor
                            && str_starts_with($x->codigo, $valor)
                            && $x->permite_movimiento)
                            ->take(25)
                            ->map(fn ($x) => ['codigo' => $x->codigo, 'nombre' => $x->nombre])
                            ->values()->all()
                        : [],
                ];
            }
        }

        return $salida;
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
            /*
             * Las cuentas que hoy no sirven, marcadas en la propia casilla.
             *
             * `Reglas::set()` ya impide GUARDAR una cuenta agrupadora. Lo que
             * no miraba nadie es el valor de fábrica: las reglas nacieron con
             * cuentas de cuatro dígitos —1435, 4135, 6135— que en el plan real
             * de Great Baby son grupos y no reciben movimiento, y otras dos
             * que ni existen. Son 21 de 79.
             *
             * El contador las encontró de a una, cuando un asiento no cuadró.
             * Acá salen todas juntas, en la pantalla donde se arreglan, con
             * las cuentas hijas que sí admiten movimiento para elegir.
             */
            'cuentasQueNoSirven' => $this->cuentasQueNoSirven($grupos),
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
                // Dropi · la wallet viaja como comprobante contable (CC).
                'siigo.doc_type_wallet_dropi' => 'CC',
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
