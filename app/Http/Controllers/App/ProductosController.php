<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Catalogo\Models\CatalogoClase;
use App\Modules\Catalogo\Models\CatalogoGrupo;
use App\Modules\Catalogo\Models\CatalogoLinea;
use App\Modules\Catalogo\Models\CatalogoSubgrupo;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 4 · G.3 · CRUD de Productos en Vue con formato SIIGO 4 pestañas.
 *
 * Basado en doc oficial SIIGO Ilimitada · Kardex → Referencias:
 * https://ilimitada.portaldeclientes.siigo.com/archivos-kardex-referencias/
 *
 * Reutiliza el modelo Producto ya existente (Filament sigue funcionando).
 * Ruta: /app/catalogo/productos
 */
class ProductosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'productos'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        // El pull SIIGO → ERP solo se dispara MANUALMENTE con los botones
        // "Sincronizar TODO" / "Traer uno por código" del listado. En prod
        // además corre `php artisan siigo:sync-productos` por cron cada 15min.
        // Antes había auto-sync al cargar la vista; se eliminó porque:
        //   1. Bloqueaba la vista 10-30s esperando a SIIGO.
        //   2. En sandbox compartido traía basura de otros clientes.
        $autoSyncResultados = ['chequeados' => 0, 'actualizados' => 0, 'ts' => null];

        $q = Producto::query()
            ->with(['marca:id,nombre', 'categoriaMaestra:id,nombre', 'linea:id,codigo,nombre'])
            // PROD-12 · contador de variantes activas para la columna del listado.
            ->withCount(['variantes']);

        if ($busca = trim((string) $r->query('q', ''))) {
            $q->where(function ($qq) use ($busca) {
                $qq->where('referencia', 'like', '%'.$busca.'%')
                   ->orWhere('nombre', 'like', '%'.$busca.'%')
                   ->orWhere('siigo_code', 'like', $busca.'%');
            });
        }
        if ($r->filled('activo')) $q->where('activo', $r->boolean('activo'));
        if ($lineaId = $r->query('linea_id')) $q->where('linea_id', (int) $lineaId);
        if ($r->boolean('sin_siigo')) $q->whereNull('siigo_id');

        return Inertia::render('Catalogo/Productos', [
            'filtros' => [
                'q' => $r->query('q', ''),
                'activo' => $r->query('activo', ''),
                'linea_id' => $r->query('linea_id', ''),
                'sin_siigo' => $r->boolean('sin_siigo'),
            ],
            // QA-FIX #13 · cache KPIs 60s · 4 counts fullscan = 400ms del render.
            'kpis' => \Illuminate\Support\Facades\Cache::remember('productos.kpis.v1', 60, fn () => [
                'total' => Producto::count(),
                'activos' => Producto::where('activo', true)->count(),
                'sin_siigo' => Producto::whereNull('siigo_id')->where('activo', true)->count(),
                'protegidos' => Producto::where('proteger_precio', true)->count(),
            ]),
            'productos' => $q->orderByDesc('id')->paginate(30)->through(fn ($p) => $this->serializarBrief($p)),
            'lineas' => CatalogoLinea::where('activa', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            // Resultado del auto-sync (para mostrar en UI "última sync hace Xs")
            'autoSync' => $autoSyncResultados,
            // PROD-2 · últimas 5 cargas de Excel SIIGO para el modal "Carga masiva"
            // · así Aracely ve el histórico sin salir a la Bandeja.
            'ultimasImports' => \App\Models\ImportacionBandeja::where('tipo', 'productos-siigo')
                ->orderByDesc('iniciada_at')
                ->limit(5)
                ->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'archivo' => $i->archivo_nombre,
                    'iniciada' => optional($i->iniciada_at)->format('Y-m-d H:i'),
                    'exitosas' => (int) ($i->ok ?? 0),
                    'fallidas' => (int) ($i->errores ?? 0),
                    'estado' => $i->estado,
                ])->all(),
        ]);
    }

    public function show(Producto $producto): Response
    {
        $producto->load([
            'marca:id,nombre', 'categoriaMaestra:id,nombre', 'coleccion:id,nombre',
            'unidadMedida:id,codigo,nombre', 'unidadCompra:id,codigo,nombre',
            'impuesto:id,nombre,porcentaje',
            'linea:id,codigo,nombre', 'grupo:id,codigo,nombre',
            'subgrupo:id,codigo,nombre', 'clase:id,codigo,nombre',
            'variantes',
            'accesorios:id,referencia,nombre',
            'sustitutos:id,referencia,nombre',
        ]);

        return Inertia::render('Catalogo/ProductoShow', [
            'producto' => $this->serializarCompleto($producto),
            'historial' => $this->historialProducto($producto),
            'catalogos' => $this->catalogosParaForm(),
            'precios_lista' => $this->preciosPorLista($producto),
            'listo_para_vender' => $this->listoParaVender($producto),
        ]);
    }

    public function crearForm(): Response
    {
        return Inertia::render('Catalogo/ProductoShow', [
            'producto' => null,
            'catalogos' => $this->catalogosParaForm(),
        ]);
    }

    public function guardar(Request $r, ?Producto $producto = null): RedirectResponse
    {
        // La referencia debe ser única · incluye soft-deleted para evitar que un
        // producto borrado bloquee silenciosamente la creación de otro con el
        // mismo código. Permite edit sin colisión consigo mismo.
        $referenciaRule = \Illuminate\Validation\Rule::unique('productos', 'referencia')
            ->ignore($producto?->exists ? $producto->id : null)
            ->withoutTrashed();

        $data = $r->validate([
            'referencia' => ['required', 'string', 'max:100', $referenciaRule],
            'nombre' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string'],
            'descripcion_ampliada' => ['nullable', 'string'],
            'ficha_tecnica' => ['nullable', 'string'],
            'activo' => ['boolean'],
            'marca_id' => ['nullable', 'integer', 'exists:marcas,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'coleccion_id' => ['nullable', 'integer', 'exists:colecciones,id'],
            'unidad_medida_id' => ['nullable', 'integer', 'exists:unidades_medida,id'],
            'unidad_compra_id' => ['nullable', 'integer', 'exists:unidades_medida,id'],
            'impuesto_id' => ['nullable', 'integer', 'exists:impuestos,id'],
            'linea_id' => ['nullable', 'integer', 'exists:catalogo_lineas,id'],
            'grupo_id' => ['nullable', 'integer', 'exists:catalogo_grupos,id'],
            'subgrupo_id' => ['nullable', 'integer', 'exists:catalogo_subgrupos,id'],
            'clase_id' => ['nullable', 'integer', 'exists:catalogo_clases,id'],
            'posicion_arancelaria' => ['nullable', 'string', 'max:20'],
            'factor_conversion' => ['nullable', 'numeric', 'min:0'],
            'rentabilidad_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reposicion_max_dias' => ['nullable', 'integer', 'min:0'],
            'descuento_default_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'valor_gasto_venta_niif' => ['nullable', 'numeric', 'min:0'],
            'valor_neto_realizable_niif' => ['nullable', 'numeric', 'min:0'],
            'precio_proveedor' => ['nullable', 'numeric', 'min:0'],
            'peso_gr' => ['nullable', 'numeric', 'min:0'],
            'alto_cm' => ['nullable', 'numeric', 'min:0'],
            'ancho_cm' => ['nullable', 'numeric', 'min:0'],
            'largo_cm' => ['nullable', 'numeric', 'min:0'],
            'proteger_precio' => ['boolean'],
            'maneja_lotes' => ['boolean'],
            'maneja_seriales' => ['boolean'],
            'es_estadistico' => ['boolean'],
            'requiere_talla' => ['boolean'],
            'es_set' => ['boolean'],
            'neto' => ['boolean'],
            'desglose_stock' => ['boolean'],
            'stock_directo' => ['nullable', 'numeric', 'min:0'],
            // Donde entra ese stock inicial. Sin esto el kardex lo dejaba
            // en la primera ubicacion activa, que casi nunca es la que la
            // persona tenia en mente: se monta una bodega nueva, se escriben
            // 60 unidades y aparecen en otra bodega sin que nadie lo note.
            'stock_ubicacion_id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
            // Cuentas contables
            'cta_ingreso' => ['nullable', 'string', 'max:30'],
            'cta_iva_venta' => ['nullable', 'string', 'max:30'],
            'cta_costo' => ['nullable', 'string', 'max:30'],
            'cta_inventario' => ['nullable', 'string', 'max:30'],
            'cta_devolucion' => ['nullable', 'string', 'max:30'],
            'cta_descuento' => ['nullable', 'string', 'max:30'],
            'centro_costo' => ['nullable', 'string', 'max:30'],
            'notas_contables' => ['nullable', 'string'],
            'accesorios' => ['nullable', 'array'],
            'accesorios.*.id' => ['integer', 'exists:productos,id'],
            'accesorios.*.cantidad' => ['integer', 'min:1'],
            'sustitutos' => ['nullable', 'array'],
            'sustitutos.*' => ['integer', 'exists:productos,id'],
            // Sprint Variantes · payload inline del form (crear/actualizar/eliminar).
            'variantes' => ['nullable', 'array'],
            // Fix IDOR · validator debe limitar los `variantes.*.id` a los que
            // realmente pertenecen al producto en edición. Sin este `->where`,
            // un payload malicioso con un id ajeno (pero existente) no
            // actualizaba nada por `whereKey(id)`, pero el `whereNotIn($idsRecibidos)`
            // SÍ marcaba todas las variantes legítimas como "a borrar".
            'variantes.*.id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::exists('producto_variantes', 'id')
                    ->where(fn ($q) => $producto && $producto->exists ? $q->where('producto_id', $producto->id) : $q),
            ],
            'variantes.*.color_id' => ['nullable', 'integer', 'exists:colores,id'],
            'variantes.*.diseno_id' => ['nullable', 'integer', 'exists:disenos,id'],
            'variantes.*.talla_id' => ['nullable', 'integer', 'exists:tallas,id'],
            'variantes.*.codigo_barras' => ['nullable', 'string', 'max:100'],
            'variantes.*.stock_minimo' => ['nullable', 'integer', 'min:0'],
            // ─── Sprint SIIGO Paridad · campos nuevos ─────────────────────
            'tipo_siigo' => ['nullable', 'in:Product,Service,ConsumerGood'],
            'stock_control' => ['boolean'],
            'tax_classification' => ['nullable', 'in:Taxed,Exempt,Excluded'],
            'tax_included' => ['boolean'],
            'tax_consumption_value' => ['nullable', 'numeric', 'min:0'],
            'modelo_siigo' => ['nullable', 'string', 'max:100'],
            'barcode_padre' => ['nullable', 'string', 'max:100'],
            'unit_label' => ['nullable', 'string', 'max:50'],
            'impuestos_ids' => ['nullable', 'array'],
            'impuestos_ids.*' => ['integer', 'exists:impuestos,id'],
            // ─── FASE H · Paridad 1:1 form SIIGO ─────────────────────────
            'visible_en_facturas' => ['boolean'],
            'retencion_siigo_id' => ['nullable', 'integer', 'exists:impuestos,id'],
            'impuesto_cargo_dos_id' => ['nullable', 'integer', 'exists:impuestos,id'],
            'reference_fabrica' => ['nullable', 'string', 'max:60'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            // FASE F2.A4 · override por producto del grupo SIIGO
            'siigo_account_group_override' => ['nullable', 'integer', 'min:1'],
        ]);

        $accesorios = $data['accesorios'] ?? [];
        $sustitutos = $data['sustitutos'] ?? [];
        $impuestosIds = $data['impuestos_ids'] ?? null;
        $variantes = $data['variantes'] ?? null;  // null = no tocar, [] = borrar todas
        $ubicacionStock = $data['stock_ubicacion_id'] ?? null;
        unset($data['accesorios'], $data['sustitutos'], $data['impuestos_ids'], $data['variantes'],
              $data['stock_ubicacion_id']);

        // FASE F2.A1 (ALTO) · envolver save + syncs en transacción para evitar
        // estados parciales si falla el 2º sync y que el Observer ya haya encolado.
        \DB::transaction(function () use (&$producto, $data, $accesorios, $sustitutos, $impuestosIds, $variantes, $ubicacionStock) {
            if ($producto && $producto->exists) {
                $producto->fill($data)->save();
            } else {
                $producto = Producto::conStockInicialEn(
                    $ubicacionStock,
                    fn () => Producto::create($data + ['activo' => true]),
                );
            }

            $accesoriosSync = collect($accesorios)->mapWithKeys(fn ($a) => [$a['id'] => ['cantidad_default' => $a['cantidad'] ?? 1]])->all();
            $producto->accesorios()->sync($accesoriosSync);
            $producto->sustitutos()->sync($sustitutos);

            // FASE F1.C2 (CRÍTICO) · sync() de pivot NO dispara updated() del Observer,
            // así que los cambios en impuestos múltiples nunca llegaban a SIIGO.
            // Comparamos ANTES de sync; si hubo cambio real, encolamos push manual.
            if ($impuestosIds !== null) {
                $antes = $producto->impuestos()->pluck('impuestos.id')->sort()->values()->all();
                $despues = collect($impuestosIds)->sort()->values()->all();
                $producto->impuestos()->sync($impuestosIds);
                if ($antes !== $despues && $producto->siigo_id) {
                    \App\Modules\Siigo\Jobs\PushProductoASiigo::dispatchDebounced(
                        $producto->id, 'actualizar', $producto->siigo_id
                    );
                }
            }

            // Sprint Variantes · sync inline (crear nuevas, actualizar existentes,
            // eliminar las que ya no vienen en el payload).
            if ($variantes !== null) {
                $idsRecibidos = collect($variantes)->pluck('id')->filter()->all();
                // Borrar las que ya no están en el form.
                $producto->variantes()
                    ->when(!empty($idsRecibidos), fn ($q) => $q->whereNotIn('id', $idsRecibidos))
                    ->when(empty($idsRecibidos), fn ($q) => $q)
                    ->delete();
                // Pre-cargo las maestras para hidratar los campos de texto que
                // consume el hook ProductoVariante::saving() cuando genera el
                // código de barras automático. Si solo pasamos los *_id (como
                // hace el form Vue), el hook genera EAN=referencia-padre y las
                // filas chocan en el UNIQUE del codigo_barras.
                //
                // FASE SIIGO-LIVE · también hidratamos los *_nombre porque el
                // PayloadBuilder los usa para construir el nombre completo de
                // la variante en SIIGO ("Producto - Rojo - Talla M"). Sin esto
                // todas las variantes salían en SIIGO con el mismo nombre y
                // Aracely no las podía distinguir en la lista.
                $colorIds  = collect($variantes)->pluck('color_id')->filter();
                $disenoIds = collect($variantes)->pluck('diseno_id')->filter();
                $tallaIds  = collect($variantes)->pluck('talla_id')->filter();
                $cacheColor  = \App\Modules\Catalogo\Models\Color::whereIn('id', $colorIds)->get(['id', 'codigo', 'nombre'])->keyBy('id');
                $cacheDiseno = \App\Modules\Catalogo\Models\Diseno::whereIn('id', $disenoIds)->get(['id', 'codigo', 'nombre'])->keyBy('id');
                $cacheTalla  = \App\Modules\Catalogo\Models\Talla::whereIn('id', $tallaIds)->get(['id', 'nombre'])->keyBy('id');
                foreach ($variantes as $v) {
                    $color  = isset($v['color_id'])  ? $cacheColor[$v['color_id']]   ?? null : null;
                    $diseno = isset($v['diseno_id']) ? $cacheDiseno[$v['diseno_id']] ?? null : null;
                    $talla  = isset($v['talla_id'])  ? $cacheTalla[$v['talla_id']]   ?? null : null;
                    $payload = [
                        'color_id' => $v['color_id'] ?? null,
                        'diseno_id' => $v['diseno_id'] ?? null,
                        'talla_id' => $v['talla_id'] ?? null,
                        // Texto que lee generarCodigoBarras() si el EAN viene vacío.
                        'color_codigo' => $color?->codigo,
                        'color_nombre' => $color?->nombre,
                        'diseno_codigo' => $diseno?->codigo,
                        'diseno_nombre' => $diseno?->nombre,
                        'talla' => $talla?->nombre,
                        'codigo_barras' => $v['codigo_barras'] ?? null,
                        // La columna stock_minimo en producto_variantes es NOT NULL
                        // (default 0 en migración), así que normalizamos null → 0
                        // para que el usuario pueda dejar el campo vacío en la UI.
                        'stock_minimo' => $v['stock_minimo'] ?? 0,
                    ];
                    if (!empty($v['id'])) {
                        $producto->variantes()->whereKey($v['id'])->update($payload);
                    } else {
                        $producto->variantes()->create($payload);
                    }
                }
            }
        });

        $esCreacion = $r->method() === 'POST';
        $msg = $esCreacion
            ? "✓ Producto {$producto->referencia} creado. Enviando a SIIGO en segundo plano…"
            : "✓ Producto {$producto->referencia} actualizado. Cambios enviándose a SIIGO en segundo plano…";

        return redirect()->route('app.catalogo.productos')->with('success', $msg);
    }

    public function eliminar(Request $r, Producto $producto): RedirectResponse
    {
        // Simetría con `restaurar` (que es esRoot). Antes un Gerente podía
        // borrar one-by-one y solo Gerencia podía revertir. Ahora requiere
        // Aracely/Gerencia para ambos lados.
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede eliminar productos.');

        if ($producto->movimientos()->exists()) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => 'No se puede eliminar: el producto tiene movimientos de kardex.',
            ]);
        }
        $ref = $producto->referencia;
        $producto->delete();
        return redirect()->route('app.catalogo.productos')
            ->with('flash', ['type' => 'success', 'message' => "Producto {$ref} eliminado."]);
    }

    /**
     * FASE D3 · Fuerza un push manual al SIIGO (bypasea kill-switch y debounce).
     * Útil cuando el usuario ve diferencias en el diff y quiere reenviar YA.
     */
    public function forzarSync(Request $r, Producto $producto): JsonResponse
    {
        // FASE F3.A14 · solo roots (Aracely/Gerencia) pueden pushear manual.
        // Antes cualquier usuario autenticado podía · un Vendedor podía
        // flood-encolar jobs y gastar el rate limit SIIGO. Combinado con el
        // throttle:20,1 de la ruta cierra el abuso.
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede forzar push a SIIGO.');

        $accion = $producto->siigo_id ? 'actualizar' : 'crear';
        \App\Modules\Siigo\Jobs\PushProductoASiigo::dispatchManual(
            $producto->id, $accion, $producto->siigo_id,
        );
        \Log::channel('siigo')->info('push_siigo manual disparado', [
            'user_id' => $r->user()->id,
            'producto_id' => $producto->id,
            'referencia' => $producto->referencia,
            'siigo_id' => $producto->siigo_id,
            'accion' => $accion,
        ]);
        return response()->json([
            'ok' => true,
            'mensaje' => "Push a SIIGO encolado · acción {$accion}.",
        ]);
    }

    /**
     * FASE C3 · Papelera · lista productos soft-deleted de los últimos 30 días.
     */
    public function papelera(): Response
    {
        $papelera = Producto::onlyTrashed()
            ->where('deleted_at', '>=', now()->subDays(30))
            ->orderByDesc('deleted_at')
            ->limit(500)
            ->get(['id', 'referencia', 'nombre', 'precio_proveedor', 'deleted_at', 'siigo_id']);

        return Inertia::render('Catalogo/ProductosPapelera', [
            'papelera' => $papelera->map(fn ($p) => [
                'id' => $p->id,
                'referencia' => $p->referencia,
                'nombre' => $p->nombre,
                'precio_proveedor' => (float) $p->precio_proveedor,
                'deleted_at' => $p->deleted_at?->toIso8601String(),
                'deleted_hace' => $p->deleted_at?->diffForHumans(),
                'tenia_siigo' => (bool) $p->siigo_id,
            ])->all(),
            'total' => $papelera->count(),
        ]);
    }

    /** FASE C3 · Restaura un producto de la papelera. */
    public function restaurar(Request $r, int $id): RedirectResponse
    {
        // FASE F3.A14 · restaurar también está vedado a roles operativos;
        // reactivar un producto re-dispara el push SIIGO y puede resucitar
        // SKUs que ya fueron deliberadamente dados de baja.
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede restaurar productos.');

        $p = Producto::onlyTrashed()->findOrFail($id);
        $tenia_siigo = $p->siigo_id;
        $deleted_hace = $p->deleted_at?->diffForHumans() ?? 'sin fecha';
        $p->restore();

        // FASE F3.A18 · log auditable del hard-delete-recover · si alguien
        // restaura un producto que ya había sido borrado se queda registro
        // de quién, cuándo, y cuánto tiempo estuvo fuera. Útil para forense
        // cuando un SKU "vuelve a aparecer" en facturas.
        \Log::channel('siigo')->notice('producto restaurado desde papelera', [
            'user_id' => $r->user()->id,
            'user_email' => $r->user()->email,
            'producto_id' => $p->id,
            'referencia' => $p->referencia,
            'siigo_id' => $tenia_siigo,
            'estuvo_fuera' => $deleted_hace,
        ]);

        return redirect()->route('app.catalogo.productos.papelera')
            ->with('flash', ['type' => 'success', 'message' => "Producto {$p->referencia} restaurado."]);
    }

    /**
     * FASE C1 · Clona un producto · copia todos los campos excepto referencia
     * (sufijo -COPY-N) y siigo_id/siigo_code (el Observer lo encola para
     * crearlo en SIIGO).
     */
    public function clonar(Request $r, Producto $producto): RedirectResponse
    {
        // Clonar duplica todos los campos financieros + encola push SIIGO.
        // Un operativo podría inflar el catálogo y gastar el rate-limit.
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede duplicar productos.');

        $base = $producto->referencia;
        $n = 1;
        do {
            $nuevaRef = $base . '-COPY-' . $n;
            $n++;
        } while (Producto::where('referencia', $nuevaRef)->exists() && $n < 100);

        $datos = $producto->toArray();
        unset($datos['id'], $datos['siigo_id'], $datos['siigo_code'], $datos['siigo_sync_at'],
            $datos['created_at'], $datos['updated_at'], $datos['deleted_at'], $datos['dropi_sku']);
        $datos['referencia'] = $nuevaRef;
        $datos['nombre'] = $producto->nombre . ' (copia)';

        $nuevo = Producto::create($datos);

        // Copiar impuestos múltiples.
        if ($producto->impuestos()->exists()) {
            $nuevo->impuestos()->sync($producto->impuestos()->pluck('impuestos.id'));
        }
        // FASE F2.A6 · copiar también accesorios y sustitutos (si no, un
        // producto SET duplicado queda sin ninguno · facturación errónea).
        if ($producto->accesorios()->exists()) {
            $nuevo->accesorios()->sync(
                $producto->accesorios()
                    ->get()
                    ->mapWithKeys(fn ($a) => [$a->id => ['cantidad_default' => $a->pivot->cantidad_default ?? 1]])
                    ->all()
            );
        }
        if ($producto->sustitutos()->exists()) {
            $nuevo->sustitutos()->sync($producto->sustitutos()->pluck('productos.id')->all());
        }

        // PROD-7 · el usuario elige qué incluir en el clon. Default: variantes
        // sí, precios por lista no (listas suelen revisarse en el clon).
        $incluirVariantes = $r->boolean('incluir_variantes', true);
        $incluirPrecios   = $r->boolean('incluir_precios', false);

        // Fix clonar sin variantes · si el padre está en modo granular
        // (desglose_stock=true) pero el clon queda sin variantes, el hook
        // `ProductoVariante::saving` lanzaba DomainException al primer intento
        // de agregar variante al clon. Copiamos la estructura (sin
        // codigo_barras para que el hook los auto-genere con la ref nueva).
        if ($incluirVariantes && $producto->desglose_stock && $producto->variantes()->exists()) {
            foreach ($producto->variantes()->get() as $v) {
                $nuevaVar = $nuevo->variantes()->create([
                    'color_id' => $v->color_id,
                    'color_codigo' => $v->color_codigo,
                    'color_nombre' => $v->color_nombre,
                    'diseno_id' => $v->diseno_id,
                    'diseno_codigo' => $v->diseno_codigo,
                    'diseno_nombre' => $v->diseno_nombre,
                    'talla_id' => $v->talla_id,
                    'talla' => $v->talla,
                    'stock_minimo' => $v->stock_minimo ?? 0,
                    // codigo_barras omitido · el hook regenera con la nueva
                    // referencia del clon (TESTVAR-COPY-1-01-M, etc.).
                ]);

                // PROD-7 · copiar precios por lista si el usuario lo pidió.
                if ($incluirPrecios) {
                    $precios = \App\Modules\Catalogo\Models\PrecioVariante::where('variante_id', $v->id)->get();
                    foreach ($precios as $pr) {
                        \App\Modules\Catalogo\Models\PrecioVariante::create([
                            'variante_id'   => $nuevaVar->id,
                            'lista_id'      => $pr->lista_id,
                            'precio'        => $pr->precio,
                            'vigente_desde' => $pr->vigente_desde,
                            'vigente_hasta' => $pr->vigente_hasta,
                        ]);
                    }
                }
            }
        }

        return redirect()->route('app.catalogo.productos.show', $nuevo->id)
            ->with('flash', [
                'type' => 'success',
                'message' => "Producto duplicado como {$nuevaRef} · listo para editar.",
            ]);
    }

    /**
     * FASE C2 · Bulk edit · aplica el mismo cambio a un conjunto de productos.
     * Campos permitidos: activo (bool), linea_id (int), descuento_pct (float · aplica al precio_proveedor).
     */
    /**
     * PROD-13 · Bulk push a SIIGO · encola un job PushProductoASiigo por cada
     * id recibido. Para cada producto decide:
     *   - 'crear'      si no tiene siigo_id (producto nuevo en SIIGO).
     *   - 'actualizar' si ya tiene siigo_id (sync de cambios locales).
     * No procesa productos inactivos (serían rechazados por SIIGO) ni
     * productos sin referencia (código requerido). Devuelve conteos para
     * mostrar un flash preciso: "N encolados · M saltados".
     */
    public function bulkPushSiigo(Request $r): JsonResponse
    {
        // Mismo guard que forzarSync individual · solo Gerencia encola push
        // masivo (puede saturar el rate limit SIIGO si lo abre a operativos).
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede enviar productos a SIIGO.');

        $datos = $r->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:productos,id'],
        ]);

        $productos = Producto::whereIn('id', $datos['ids'])
            ->select('id', 'referencia', 'activo', 'siigo_id')
            ->get();

        $encolados = 0;
        $saltadosInactivos = 0;
        $saltadosSinRef = 0;
        foreach ($productos as $p) {
            if (! $p->activo) { $saltadosInactivos++; continue; }
            if (! trim((string) $p->referencia)) { $saltadosSinRef++; continue; }

            $accion = $p->siigo_id ? 'actualizar' : 'crear';
            \App\Modules\Siigo\Jobs\PushProductoASiigo::dispatchManual(
                $p->id, $accion, $p->siigo_id,
            );
            $encolados++;
        }

        \Log::channel('siigo')->info('bulk_push_siigo', [
            'user_id'            => $r->user()->id,
            'solicitados'        => count($datos['ids']),
            'encolados'          => $encolados,
            'saltados_inactivos' => $saltadosInactivos,
            'saltados_sin_ref'   => $saltadosSinRef,
        ]);

        $msg = "{$encolados} productos encolados para SIIGO.";
        if ($saltadosInactivos || $saltadosSinRef) {
            $pedazos = [];
            if ($saltadosInactivos) $pedazos[] = "{$saltadosInactivos} inactivos";
            if ($saltadosSinRef)    $pedazos[] = "{$saltadosSinRef} sin referencia";
            $msg .= ' Saltados: ' . implode(', ', $pedazos) . '.';
        }
        return response()->json([
            'ok'        => true,
            'mensaje'   => $msg,
            'encolados' => $encolados,
            'saltados'  => $saltadosInactivos + $saltadosSinRef,
        ]);
    }

    public function bulkEdit(Request $r): JsonResponse
    {
        // FASE F3.A14 · bulk-edit toca hasta 500 productos de un golpe y
        // potencialmente encola 500 pushes a SIIGO. Vedamos a operativos.
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede editar en lote.');

        $datos = $r->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:productos,id'],
            'accion' => ['required', 'in:activo,linea,descuento,eliminar'],
            'activo' => ['nullable', 'boolean'],
            'linea_id' => ['nullable', 'integer', 'exists:catalogo_lineas,id'],
            'descuento_pct' => ['nullable', 'numeric', 'between:-100,100'],
        ]);

        $totalIds = count($datos['ids']);
        $afectados = 0;

        // FASE F2.A10 · si son >100 ítems, procesamos en chunks dentro de una
        // transacción corta por chunk · evita timeout de PHP (max_execution_time=30s)
        // y colas de 500 jobs disparados por el Observer en una sola petición.
        foreach (array_chunk($datos['ids'], 100) as $chunk) {
            \DB::transaction(function () use ($chunk, $datos, &$afectados) {
                $productos = Producto::whereIn('id', $chunk)->get();
                foreach ($productos as $p) {
                    switch ($datos['accion']) {
                        case 'activo':
                            $p->activo = (bool) $datos['activo'];
                            $p->save();
                            break;
                        case 'linea':
                            $p->linea_id = $datos['linea_id'];
                            $p->save();
                            break;
                        case 'descuento':
                            $pct = (float) $datos['descuento_pct'];
                            $p->precio_proveedor = round($p->precio_proveedor * (1 + $pct / 100), 2);
                            $p->save();
                            break;
                        case 'eliminar':
                            if (! $p->movimientos()->exists()) $p->delete();
                            break;
                    }
                    $afectados++;
                }
            });
        }

        return response()->json([
            'ok' => true,
            'afectados' => $afectados,
            'mensaje' => "{$afectados}/{$totalIds} productos actualizados · push a SIIGO encolado.",
        ]);
    }

    /**
     * FASE G1 · Exporta todos los productos activos al xlsx SIIGO (34 cols).
     * Si pasan ?ids=1,2,3 solo exporta esos (bulk desde lista).
     */
    public function exportarExcelSiigo(Request $r)
    {
        // PROD-6 · exportar el catálogo completo expone precios/costos de
        // TODAS las listas (hasta 12). Solo Gerencia descarga; un operativo
        // o Vendedor podría abrir el xlsx y filtrar la base de precios.
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede exportar el catálogo SIIGO.');

        $svc = new \App\Modules\Siigo\Services\ProductosExcelService();

        $q = Producto::with(['marca', 'unidadMedida', 'impuesto', 'retencion', 'impuestoCargoDos']);
        if ($ids = $r->query('ids')) {
            // FASE F4.M8 · validar y recortar el array de ids · antes
            // `?ids=a,b,c` metía strings al whereIn, y sin tope podía
            // llegar un POST con 100k ids reventando la query.
            $arr = collect(explode(',', $ids))
                ->map(fn ($x) => (int) trim($x))
                ->filter(fn ($x) => $x > 0)
                ->take(5000)
                ->values()
                ->all();
            $q->whereIn('id', $arr);
        }
        $productos = $q->orderBy('id')->get();

        $tmp = $svc->generar($productos);
        return response()->download($tmp, $svc->nombreArchivo(false))->deleteFileAfterSend();
    }

    /**
     * FASE G5 · Descarga plantilla vacía (solo encabezados + Hoja2 Listas).
     * Para que el cliente arme el Excel sin salir del ERP.
     */
    public function plantillaExcelSiigo()
    {
        $svc = new \App\Modules\Siigo\Services\ProductosExcelService();
        $tmp = $svc->generar(null);
        return response()->download($tmp, $svc->nombreArchivo(true))->deleteFileAfterSend();
    }

    /**
     * FASE G2 · Importa productos desde xlsx SIIGO. Síncrono por simplicidad
     * (el service corre en segundos para archivos razonables). Devuelve el
     * xlsx de reporte con hojas "Procesados" y "Errores".
     */
    public function importarExcelSiigo(Request $r)
    {
        // Import masivo = N pushes SIIGO + mutación bulk de precios/costos.
        // Mismo nivel que bulkEdit (ya restringido).
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403, 'Solo gerencia puede importar productos.');

        $r->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $svc = new \App\Modules\Siigo\Services\ProductosImportService();
        $resumen = $svc->importar($r->file('archivo')->getRealPath());

        // Guardo el reporte temporal en el storage de la sesión para descarga posterior.
        $reportePath = null;
        if ($resumen['reporte_path']) {
            $final = storage_path('app/public/siigo-reportes/' . basename($resumen['reporte_path']));
            if (! is_dir(dirname($final))) mkdir(dirname($final), 0755, true);
            copy($resumen['reporte_path'], $final);
            @unlink($resumen['reporte_path']);
            $reportePath = basename($final);
        }

        return response()->json([
            'ok' => true,
            'procesados' => $resumen['procesados'],
            'nuevos' => $resumen['nuevos'],
            'actualizados' => $resumen['actualizados'],
            'errores' => $resumen['errores'],
            'reporte_url' => $reportePath
                ? route('app.catalogo.productos.importar-siigo.estado') . '?f=' . urlencode($reportePath)
                : null,
        ]);
    }

    /** GET con `?f=nombre.xlsx` descarga el reporte guardado. */
    public function importarEstado(Request $r)
    {
        $nombre = $r->query('f');
        if (! $nombre) return response()->json(['ok' => true]);
        $path = storage_path('app/public/siigo-reportes/' . basename($nombre));
        abort_unless(file_exists($path), 404);
        return response()->download($path, "reporte-import-siigo-{$nombre}");
    }

    /**
     * FASE H6 · Sube una imagen al producto (hasta 5 slots).
     * Formato aceptado: PNG/JPG · tamaño max 1 MB (regla SIIGO).
     */
    public function subirImagen(Request $r, Producto $producto): JsonResponse
    {
        $datos = $r->validate([
            'imagen' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        // FASE F4.M2 · lock para evitar race de 2 uploads simultáneos pasando
        // el chequeo >=5 y dejando 6-7 imágenes.
        $img = \Cache::lock("prod:{$producto->id}:img-upload", 10)->block(5, function () use ($producto, $datos) {
            if ($producto->imagenes()->count() >= 5) {
                abort(response()->json([
                    'ok' => false,
                    'message' => 'Límite alcanzado: 5 imágenes por producto (regla SIIGO).',
                ], 422));
            }

            $file = $datos['imagen'];
            $path = $file->store("productos/{$producto->id}", 'public');

            return $producto->imagenes()->create([
                'path' => $path,
                'nombre_original' => $file->getClientOriginalName(),
                'tamano_bytes' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'orden' => $datos['orden'] ?? $producto->imagenes()->count(),
            ]);
        });

        return response()->json([
            'ok' => true,
            'imagen' => [
                'id' => $img->id,
                'url' => $img->url(),
                'orden' => $img->orden,
            ],
        ]);
    }

    /** FASE H6 · Elimina una imagen (storage + registro). */
    public function eliminarImagen(Producto $producto, \App\Modules\Dropi\Models\ProductoImagen $imagen): JsonResponse
    {
        abort_unless($imagen->producto_id === $producto->id, 403);

        // Fix doble borrado · el hook `ProductoImagen::deleted` (fase F4.M4)
        // ya borra el archivo físico del disco. Antes aquí había un delete
        // manual + hook = dos intentos; si el primero fallaba por disco
        // transitorio dejaba el registro BD en estado inconsistente.
        $imagen->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Verifica si una referencia ya existe · usado por el form en vivo (blur).
     * Devuelve el nombre del producto conflictivo para que el usuario sepa cuál es.
     */
    public function verificarReferencia(Request $r): JsonResponse
    {
        $ref = trim((string) $r->query('ref', ''));
        $exceptoId = $r->integer('except');
        if ($ref === '') return response()->json(['existe' => false]);

        // FASE F2.A7 · withTrashed() porque el UNIQUE del guardar() también
        // ignora soft-deleted; sin esto, AJAX decía "libre" y el POST explotaba.
        $q = Producto::withTrashed()->where('referencia', $ref);
        if ($exceptoId) $q->where('id', '!=', $exceptoId);
        $otro = $q->first(['id', 'referencia', 'nombre', 'deleted_at']);

        return response()->json([
            'existe' => (bool) $otro,
            'id' => $otro?->id,
            'nombre' => $otro?->nombre,
            'eliminado' => $otro && $otro->deleted_at,
        ]);
    }

    /**
     * Sugiere la siguiente referencia libre de la familia `GB-NNNN`, que es
     * justo el ejemplo que muestra el campo.
     */
    public function sugerirReferencia(): JsonResponse
    {
        // El campo promete "Ej: GB-0001", así que el botón entrega exactamente
        // esa familia. Antes tomaba el ÚLTIMO producto creado sin mirar su
        // prefijo, así que después de importar el Excel del cliente sugería
        // cosas como "PRUEBA-REAL-104018" — nada que ver con lo ofrecido.
        $maximo = Producto::withTrashed()
            ->where('referencia', 'REGEXP', '^GB-[0-9]+$')
            ->pluck('referencia')
            ->map(fn ($r) => (int) substr($r, 3))
            ->max() ?? 0;

        // Ancho mínimo 4 (GB-0001); si el catálogo pasa de 9999 crece solo.
        $formato = fn (int $n) => 'GB-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);

        // Red de seguridad: si alguien creó ese código a mano mientras tanto,
        // se avanza al primer hueco libre en vez de chocar al guardar.
        $n = $maximo + 1;
        while (Producto::withTrashed()->where('referencia', $formato($n))->exists()) {
            $n++;
        }

        return response()->json(['referencia' => $formato($n)]);
    }

    /** Búsqueda AJAX para el selector M:M de accesorios/sustitutos. */
    public function buscar(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json([]);
        return response()->json(
            Producto::where('activo', true)
                ->where(function ($qq) use ($q) {
                    $qq->where('referencia', 'like', '%'.$q.'%')->orWhere('nombre', 'like', '%'.$q.'%');
                })
                ->limit(15)
                ->get(['id', 'referencia', 'nombre'])
                ->all()
        );
    }

    /** Grupos filtrados por línea (dropdown en cascada). */
    public function grupos(Request $r): JsonResponse
    {
        $lineaId = (int) $r->query('linea_id', 0);
        return response()->json(
            CatalogoGrupo::when($lineaId, fn ($q) => $q->where('linea_id', $lineaId))
                ->where('activa', true)->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'linea_id'])->all()
        );
    }

    public function subgrupos(Request $r): JsonResponse
    {
        $grupoId = (int) $r->query('grupo_id', 0);
        return response()->json(
            CatalogoSubgrupo::when($grupoId, fn ($q) => $q->where('grupo_id', $grupoId))
                ->where('activa', true)->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'grupo_id'])->all()
        );
    }

    public function clases(Request $r): JsonResponse
    {
        $subgrupoId = (int) $r->query('subgrupo_id', 0);
        return response()->json(
            CatalogoClase::when($subgrupoId, fn ($q) => $q->where('subgrupo_id', $subgrupoId))
                ->where('activa', true)->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'subgrupo_id'])->all()
        );
    }

    private function serializarBrief(Producto $p): array
    {
        return [
            'id' => $p->id,
            'referencia' => $p->referencia,
            'nombre' => $p->nombre,
            'marca' => $p->marca?->nombre,
            'categoria' => $p->categoriaMaestra?->nombre,
            'linea' => $p->linea ? "{$p->linea->codigo} · {$p->linea->nombre}" : null,
            'precio_proveedor' => (float) $p->precio_proveedor,
            'activo' => (bool) $p->activo,
            'siigo_id' => $p->siigo_id,
            'siigo_code' => $p->siigo_code,
            'proteger_precio' => (bool) $p->proteger_precio,
            // PROD-8 · el front decide si muestra "—" en vez de $0 cuando el
            // producto corre en modo granular (el precio real vive en variantes).
            'desglose_stock' => (bool) $p->desglose_stock,
            // PROD-12 · contador de variantes (withCount(['variantes'])).
            'variantes_count' => (int) ($p->variantes_count ?? 0),
            // PROD-17 · última foto SIIGO para la columna "Última sync" del listado.
            // siigo_sync_at viene como string del Model (no está casteado como datetime)
            // → parseamos manualmente para evitar "diffForHumans on string".
            'siigo_sync_at' => $p->siigo_sync_at ? \Carbon\Carbon::parse($p->siigo_sync_at)->diffForHumans() : null,
            'siigo_sync_iso' => $p->siigo_sync_at ? \Carbon\Carbon::parse($p->siigo_sync_at)->toIso8601String() : null,
        ];
    }

    /**
     * FASE C4 · Últimos 50 eventos de sync SIIGO del producto.
     * Lee siigo_sync_log y filtra por detalle.producto_id.
     */
    private function historialProducto(Producto $p): array
    {
        try {
            $rows = \App\Modules\Siigo\Models\SiigoSyncLog::query()
                ->whereJsonContains('detalle->producto_id', $p->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'recurso', 'estado', 'mensaje', 'detalle', 'created_at']);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(fn ($l) => [
            'id' => $l->id,
            'estado' => $l->estado,
            'accion' => $l->detalle['accion'] ?? '—',
            'mensaje' => $l->mensaje,
            'cuando' => $l->created_at?->toIso8601String(),
            'hace' => $l->created_at?->diffForHumans(),
        ])->all();
    }

    private function serializarCompleto(Producto $p): array
    {
        return array_merge($this->serializarBrief($p), [
            'descripcion' => $p->descripcion,
            'descripcion_ampliada' => $p->descripcion_ampliada,
            'ficha_tecnica' => $p->ficha_tecnica,
            'marca_id' => $p->marca_id,
            'categoria_id' => $p->categoria_id,
            'coleccion_id' => $p->coleccion_id,
            'unidad_medida_id' => $p->unidad_medida_id,
            'unidad_compra_id' => $p->unidad_compra_id,
            'impuesto_id' => $p->impuesto_id,
            'linea_id' => $p->linea_id,
            'grupo_id' => $p->grupo_id,
            'subgrupo_id' => $p->subgrupo_id,
            'clase_id' => $p->clase_id,
            'posicion_arancelaria' => $p->posicion_arancelaria,
            'factor_conversion' => (float) $p->factor_conversion,
            'rentabilidad_pct' => (float) $p->rentabilidad_pct,
            'reposicion_max_dias' => $p->reposicion_max_dias,
            'descuento_default_pct' => (float) $p->descuento_default_pct,
            'valor_gasto_venta_niif' => (float) $p->valor_gasto_venta_niif,
            'valor_neto_realizable_niif' => (float) $p->valor_neto_realizable_niif,
            'peso_gr' => (float) $p->peso_gr,
            'alto_cm' => (float) $p->alto_cm,
            'ancho_cm' => (float) $p->ancho_cm,
            'largo_cm' => (float) $p->largo_cm,
            'maneja_lotes' => (bool) $p->maneja_lotes,
            'maneja_seriales' => (bool) $p->maneja_seriales,
            'es_estadistico' => (bool) $p->es_estadistico,
            'requiere_talla' => (bool) $p->requiere_talla,
            'es_set' => (bool) $p->es_set,
            'neto' => (bool) $p->neto,
            'desglose_stock' => (bool) $p->desglose_stock,
            'stock_directo' => (float) $p->stock_directo,
            'cta_ingreso' => $p->cta_ingreso,
            'cta_iva_venta' => $p->cta_iva_venta,
            'cta_costo' => $p->cta_costo,
            'cta_inventario' => $p->cta_inventario,
            'cta_devolucion' => $p->cta_devolucion,
            'cta_descuento' => $p->cta_descuento,
            'centro_costo' => $p->centro_costo,
            'notas_contables' => $p->notas_contables,
            'variantes_count' => $p->variantes->count(),
            // Sprint Variantes · pasar las filas al form para edición inline.
            // Cada variante en SIIGO viaja como producto independiente.
            'variantes' => $p->variantes->map(fn ($v) => [
                'id' => $v->id,
                'color_id' => $v->color_id,
                'diseno_id' => $v->diseno_id,
                'talla_id' => $v->talla_id,
                'codigo_barras' => $v->codigo_barras,
                'stock_minimo' => $v->stock_minimo !== null ? (int) $v->stock_minimo : null,
                // Para el panel "Verificación en vivo" pestaña 6 SIIGO.
                // ProductoVariante no tiene cast datetime en siigo_sync_at,
                // así que lo normalizamos aquí con \Carbon.
                'siigo_id' => $v->siigo_id,
                'siigo_sync_at' => $v->siigo_sync_at ? \Carbon\Carbon::parse($v->siigo_sync_at)->toIso8601String() : null,
            ])->all(),
            'accesorios' => $p->accesorios->map(fn ($a) => [
                'id' => $a->id, 'referencia' => $a->referencia, 'nombre' => $a->nombre,
                'cantidad' => $a->pivot->cantidad_default,
            ])->all(),
            'sustitutos' => $p->sustitutos->map(fn ($s) => [
                'id' => $s->id, 'referencia' => $s->referencia, 'nombre' => $s->nombre,
            ])->all(),
            // ─── SIIGO Paridad · campos nuevos ─────────────────────
            'tipo_siigo' => $p->tipo_siigo ?: 'Product',
            'stock_control' => (bool) ($p->stock_control ?? true),
            'tax_classification' => $p->tax_classification ?: 'Taxed',
            'tax_included' => (bool) ($p->tax_included ?? false),
            'tax_consumption_value' => $p->tax_consumption_value !== null ? (float) $p->tax_consumption_value : null,
            'modelo_siigo' => $p->modelo_siigo,
            'barcode_padre' => $p->barcode_padre,
            'unit_label' => $p->unit_label ?: 'Unidad',
            'impuestos_ids' => $p->impuestos()->pluck('impuestos.id')->all(),
            // ─── FASE H · Paridad 1:1 form SIIGO ─────────────────────
            'visible_en_facturas' => (bool) ($p->visible_en_facturas ?? true),
            'retencion_siigo_id' => $p->retencion_siigo_id,
            'impuesto_cargo_dos_id' => $p->impuesto_cargo_dos_id,
            'reference_fabrica' => $p->reference_fabrica,
            'stock_minimo' => $p->stock_minimo !== null ? (float) $p->stock_minimo : null,
            'siigo_account_group_override' => $p->siigo_account_group_override,
            'imagenes' => $p->imagenes->map(fn ($img) => [
                'id' => $img->id,
                'url' => $img->url(),
                'nombre_original' => $img->nombre_original,
                'orden' => $img->orden,
            ])->all(),
        ]);
    }

    /**
     * Qué le falta al producto para poder venderse y costearse.
     *
     * Un producto se puede guardar con lo mínimo —referencia, nombre y
     * línea— y queda perfectamente inútil: sin precio de venta no entra en
     * ningún pedido, y sin costo sus movimientos de kardex no se pueden
     * asentar, así que la venta no registra el costo y el margen bruto sale
     * 100%. Las dos cosas fallan DESPUÉS, lejos de esta pantalla y sin
     * explicación.
     *
     * El formulario de contactos ya avisa así («un cliente B2B sin lista de
     * precios no le aparece al vendedor»); este no decía nada.
     *
     * @return array{sin_costo:bool, sin_precio:bool, variantes_sin_precio:int}
     */
    private function listoParaVender(Producto $producto): array
    {
        $sinPrecio = $producto->desglose_stock
            // En un producto granular el precio vive en cada variante.
            ? ! \App\Modules\Catalogo\Models\PrecioVariante::query()
                ->whereIn('variante_id', $producto->variantes->pluck('id'))
                ->exists()
            : ! \App\Modules\Catalogo\Models\PrecioProducto::query()
                ->where('producto_id', $producto->id)->vigentes()->exists();

        $variantesSinPrecio = 0;
        if ($producto->desglose_stock) {
            $conPrecio = \App\Modules\Catalogo\Models\PrecioVariante::query()
                ->whereIn('variante_id', $producto->variantes->pluck('id'))
                ->distinct()->pluck('variante_id')->count();
            $variantesSinPrecio = max(0, $producto->variantes->count() - $conPrecio);
        }

        return [
            'sin_costo' => (float) $producto->precio_proveedor <= 0,
            'sin_precio' => $sinPrecio,
            'variantes_sin_precio' => $variantesSinPrecio,
        ];
    }

    /**
     * Guarda el precio del producto agregado en cada lista.
     *
     * Dejar una casilla vacía BORRA el precio de esa lista, que es como se
     * deja de ofrecerle el producto a los clientes que la tienen asignada.
     * Es lo que la pantalla muestra, así que es lo que tiene que hacer.
     */
    public function guardarPrecios(Request $r, Producto $producto): RedirectResponse
    {
        abort_if($producto->desglose_stock, 422,
            'Este producto se vende por variante: su precio se carga en cada variante, no acá.');

        $data = $r->validate([
            'precios' => ['present', 'array'],
            'precios.*.lista_id' => ['required', 'integer', 'exists:listas_precios,id'],
            'precios.*.precio' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $puestos = 0;
        $quitados = 0;

        DB::transaction(function () use ($data, $producto, &$puestos, &$quitados) {
            foreach ($data['precios'] as $fila) {
                $precio = $fila['precio'];

                if ($precio === null || $precio === '' || (float) $precio <= 0) {
                    $quitados += \App\Modules\Catalogo\Models\PrecioProducto::query()
                        ->where('producto_id', $producto->id)
                        ->where('lista_id', $fila['lista_id'])
                        ->delete();

                    continue;
                }

                \App\Modules\Catalogo\Models\PrecioProducto::updateOrCreate(
                    ['producto_id' => $producto->id, 'lista_id' => $fila['lista_id']],
                    ['precio' => (float) $precio, 'vigente_desde' => now()->toDateString(), 'vigente_hasta' => null],
                );
                $puestos++;
            }
        });

        return back()->with('success', $puestos > 0
            ? "Precios guardados: {$puestos} lista(s)."
                .($quitados > 0 ? " Se quitó el precio de {$quitados}." : '')
            : 'Se quitó el precio de todas las listas: este producto deja de poder pedirse.');
    }

    /**
     * Precio de venta del producto en cada lista.
     *
     * Sólo aplica a productos AGREGADOS: en los granulares el precio vive por
     * variante (`precios_variante`). Los agregados no tenían dónde guardarlo,
     * así que no se podían vender —la línea del pedido se descartaba sin
     * decir por qué— hasta que el 2026-10-08 se creó `precios_producto`.
     *
     * Se devuelven TODAS las listas, con o sin precio, porque la pantalla
     * tiene que dejar ver cuáles faltan: una lista sin precio es un cliente
     * que no le puede comprar este producto.
     */
    private function preciosPorLista(Producto $producto): array
    {
        if ($producto->desglose_stock) {
            return [];
        }

        $puestos = \App\Modules\Catalogo\Models\PrecioProducto::query()
            ->where('producto_id', $producto->id)
            ->vigentes()
            ->pluck('precio', 'lista_id');

        return \App\Modules\Catalogo\Models\ListaPrecios::query()
            ->orderByRaw('siigo_id IS NOT NULL')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'siigo_id'])
            ->map(fn ($l) => [
                'lista_id' => $l->id,
                'nombre' => $l->nombre,
                'grupo' => $l->siigo_id ? 'Listas del catálogo de SIIGO' : 'Listas de Great Baby',
                'precio' => isset($puestos[$l->id]) ? (float) $puestos[$l->id] : null,
            ])
            ->all();
    }

    private function catalogosParaForm(): array
    {
        // QA-FIX #14 · Precargar toda la jerarquía como árbol para evitar 3 AJAX
        // secuenciales al elegir Línea→Grupo→Subgrupo→Clase (~2s cumulativo).
        // Vue filtra localmente ahora que tiene el árbol entero.
        $grupos = CatalogoGrupo::where('activa', true)->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'linea_id']);
        $subgrupos = CatalogoSubgrupo::where('activa', true)->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'grupo_id']);
        $clases = CatalogoClase::where('activa', true)->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'subgrupo_id']);

        // Catálogos SIIGO · opciones reales que vienen del tenant SIIGO.
        // Si están vacíos, el usuario debe correr "Sincronizar catálogos" en
        // el panel SIIGO. En producción se corren con cron cada 24h.
        $siigoAccountGroups = \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'account-groups')
            ->orderBy('nombre')
            ->get(['codigo', 'nombre'])
            ->map(fn ($c) => ['id' => (int) $c->codigo, 'nombre' => $c->nombre])
            ->values();
        $siigoTaxes = \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'taxes')
            ->orderBy('nombre')
            ->get(['codigo', 'nombre', 'payload'])
            ->map(fn ($c) => [
                'id' => (int) $c->codigo,
                'nombre' => $c->nombre,
                'porcentaje' => $c->payload['percentage'] ?? ($c->payload['rate'] ?? null),
                'tipo' => $c->payload['type'] ?? 'IVA',
            ])->values();
        $siigoWarehouses = \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'warehouses')
            ->orderBy('nombre')
            ->get(['codigo', 'nombre'])
            ->map(fn ($c) => ['id' => (int) $c->codigo, 'nombre' => $c->nombre])
            ->values();
        $siigoPriceLists = \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'price-lists')
            ->orderBy('nombre')
            ->get(['codigo', 'nombre'])
            ->map(fn ($c) => ['id' => (int) $c->codigo, 'nombre' => $c->nombre])
            ->values();

        return [
            // Para elegir DONDE entra el stock inicial. Sin esta lista el
            // formulario pedia la cantidad y el kardex la dejaba en la primera
            // ubicacion activa, casi nunca la que la persona tenia en mente.
            'ubicaciones' => \App\Modules\Dropi\Models\InventarioUbicacion::where('activa', true)
                ->where('codigo', 'not like', 'SIIGO-%')
                ->orderByRaw('bodega_id IS NULL DESC')
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
            'marcas' => \App\Modules\Catalogo\Models\Marca::orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => \App\Modules\Catalogo\Models\Categoria::orderBy('nombre')
                ->get(['id', 'nombre', 'siigo_account_group_id']),
            'colecciones' => \App\Modules\Catalogo\Models\Coleccion::orderBy('nombre')->get(['id', 'nombre']),
            // Sprint Variantes · dimensiones para la pestaña "Variantes".
            'colores' => \App\Modules\Catalogo\Models\Color::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'disenos' => \App\Modules\Catalogo\Models\Diseno::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'tallas' => \App\Modules\Catalogo\Models\Talla::orderBy('nombre')->get(['id', 'nombre']),
            'unidades' => \App\Modules\Catalogo\Models\UnidadMedida::orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'codigo_unece']),
            'impuestos' => \App\Modules\Catalogo\Models\Impuesto::orderBy('nombre')
                ->get(['id', 'nombre', 'porcentaje', 'siigo_id']),
            'lineas' => CatalogoLinea::where('activa', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            'grupos' => $grupos,
            'subgrupos' => $subgrupos,
            'clases' => $clases,
            // Catálogos SIIGO para los nuevos selectores
            'siigo' => [
                'account_groups' => $siigoAccountGroups,
                'taxes' => $siigoTaxes,
                'warehouses' => $siigoWarehouses,
                'price_lists' => $siigoPriceLists,
                'types' => [
                    ['id' => 'Product', 'nombre' => 'Producto (bien tangible, con stock)'],
                    ['id' => 'Service', 'nombre' => 'Servicio (sin stock)'],
                    ['id' => 'ConsumerGood', 'nombre' => 'Bien de consumo'],
                ],
                'tax_classifications' => [
                    ['id' => 'Taxed', 'nombre' => 'Gravado (con IVA)'],
                    ['id' => 'Exempt', 'nombre' => 'Exento'],
                    ['id' => 'Excluded', 'nombre' => 'Excluido'],
                ],
                'sync_at' => optional(\App\Modules\Siigo\Models\SiigoConfig::current()->sync_catalogos_at)->toIso8601String(),
            ],
        ];
    }
}
