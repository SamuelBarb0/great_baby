<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Actions\EjecutarOperacionDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiCargaOperacion as Op;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Services\LeerExcelProductosDropi;
use App\Modules\Dropi\Services\PlanificarCargaDropi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Crear productos y cargar su stock en Dropi desde el ERP, por Excel o de a
 * uno. Reemplaza las plantillas «Carga masiva» / «Actualización masiva» de
 * Dropi, que fallaban sin decir qué fila ni por qué.
 *
 * Flujo: subir Excel (o elegir un producto) → vista previa editable →
 * confirmar → la pantalla procesa por tandas (cada operación se verifica
 * releyendo Dropi) → resultado fila por fila. Por tandas y no en una cola
 * porque en Hostinger el worker solo atiende la cola de SIIGO; si se cierra la
 * página, al volver sigue donde iba.
 */
class DropiCargaProductosController extends Controller implements HasMiddleware
{
    /** Tope de productos por llamada a procesar(); el corte real es por tiempo. */
    private const TANDA_MAX = 10;

    private const SEGUNDOS_POR_TANDA = 10;

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->puede('dropi_productos'), 403, 'No tienes permiso para cambiar productos en Dropi.');

                return $next($r);
            }),
        ];
    }

    public function __construct(
        protected DropiWebClient $dropi,
        protected PlanificarCargaDropi $planificador,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Dropi/CargaProductos/Index', [
            'cargas' => Carga::query()->with('user:id,name')->withCount([
                'operaciones',
                'operaciones as ok_count' => fn ($q) => $q->where('estado', Op::OK),
                'operaciones as error_count' => fn ($q) => $q->where('estado', Op::ERROR),
            ])->latest('id')->limit(30)->get()->map(fn (Carga $c) => [
                'id' => $c->id,
                'numero' => $c->numero,
                'origen' => $c->origen,
                'archivo' => $c->archivo_nombre,
                'concepto' => $c->concepto,
                'estado' => $c->estado,
                'operaciones' => $c->operaciones_count,
                'ok' => $c->ok_count,
                'errores' => $c->error_count,
                'usuario' => $c->user?->name,
                'fecha' => $c->created_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
            ]),
        ]);
    }

    /** Sube el Excel del cliente, lo cruza con el catálogo vivo y deja el borrador. */
    public function previsualizar(Request $request, LeerExcelProductosDropi $lector): RedirectResponse
    {
        $data = $request->validate([
            'archivo' => ['required', 'file', 'max:10240', function ($attr, $file, $fail) {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ['xls', 'xlsx'], true)) {
                    $fail('El archivo debe ser .xls o .xlsx.');
                }
            }],
            'modo' => ['required', Rule::in(['reemplazar', 'sumar'])],
            'concepto' => ['required', 'string', 'min:5', 'max:100'],
        ], [], ['concepto' => 'concepto']);

        try {
            $filas = $lector->leer($data['archivo']->getRealPath());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        // No se lee Dropi aquí (~1 min con todo el catálogo, y en Hostinger la
        // petición se corta): la carga queda «leyendo» y la pantalla pide el
        // catálogo de a una página (leer()).
        $carga = $this->nuevaCarga('excel', $data['concepto'], $data['modo'], $data['archivo']->getClientOriginalName(), [], [
            'estado' => Carga::LEYENDO,
            'filas_excel' => $filas,
            'lectura' => ['privados' => false, 'inicio' => 0, 'leidos' => 0],
        ]);

        return redirect()->route('app.dropi.carga-productos.show', $carga);
    }

    /** Un producto suelto que ya existe en Dropi: cambiar el stock de sus variaciones. */
    public function individual(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dropi_producto_id' => ['required', 'integer', 'min:1'],
            'concepto' => ['required', 'string', 'min:5', 'max:100'],
        ]);
        $producto = $this->dropi->producto((int) $data['dropi_producto_id']);
        $op = ['fila' => 1, 'ref_excel' => $producto['sku'] ?? null, 'nombre_excel' => $producto['name'], 'variaciones_excel' => null, 'cantidad_excel' => null]
            + $this->planificador->planFila(['fila' => 1, 'ref' => null, 'nombre' => null, 'cantidad' => null, 'variaciones' => null], $producto, (int) config('dropi.web.bodega_id'), 'elegido a mano');

        $carga = $this->nuevaCarga('individual', $data['concepto'], 'reemplazar', null, [$op]);

        return redirect()->route('app.dropi.carga-productos.show', $carga);
    }

    /** Un producto nuevo de a uno: se llena en la vista previa. */
    public function individualCrear(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'concepto' => ['required', 'string', 'min:5', 'max:100'],
            'nombre' => ['required', 'string', 'min:3', 'max:200'],
            'sku' => ['nullable', 'string', 'max:100'],
        ]);
        $op = [
            'fila' => 1, 'ref_excel' => $data['sku'] ?? null, 'nombre_excel' => $data['nombre'], 'variaciones_excel' => null, 'cantidad_excel' => null,
            'tipo' => Op::CREAR, 'estado' => Op::REVISAR, 'motivo' => 'Falta el precio y el precio sugerido.',
            'detalle' => ['nombre' => $data['nombre'], 'sku' => $data['sku'] ?? '', 'descripcion' => '', 'precio' => null, 'sugerido' => null, 'cantidad' => 0, 'variaciones' => []],
        ];

        $carga = $this->nuevaCarga('individual', $data['concepto'], 'reemplazar', null, [$op]);

        return redirect()->route('app.dropi.carga-productos.show', $carga);
    }

    /**
     * «Crear en Dropi» un producto que ya existe en el ERP (23-nov-2026, la
     * clienta: «cuando se crea el producto, sea en el ERP o en Dropi, que
     * queden conectados»). Abre la misma carga individual, con el nombre y la
     * referencia del ERP como SKU: al crearse en Dropi, EjecutarOperacionDropi
     * le guarda el id de Dropi a la ficha del ERP y quedan vinculados.
     */
    public function desdeErp(\App\Modules\Dropi\Models\Producto $producto): RedirectResponse
    {
        if ($producto->dropi_sku) {
            throw ValidationException::withMessages(['producto' => "«{$producto->nombre}» ya está conectado con Dropi (id {$producto->dropi_sku})."]);
        }
        $op = [
            'fila' => 1, 'ref_excel' => $producto->referencia, 'nombre_excel' => $producto->nombre, 'variaciones_excel' => null, 'cantidad_excel' => null,
            'tipo' => Op::CREAR, 'estado' => Op::REVISAR, 'motivo' => 'Falta el precio y el precio sugerido.',
            'detalle' => ['nombre' => $producto->nombre, 'sku' => $producto->referencia, 'descripcion' => (string) ($producto->descripcion ?? ''),
                'precio' => null, 'sugerido' => null, 'cantidad' => 0, 'variaciones' => []],
        ];

        $carga = $this->nuevaCarga('individual', "Producto del ERP {$producto->referencia}", 'reemplazar', null, [$op]);

        return redirect()->route('app.dropi.carga-productos.show', $carga);
    }

    /** Buscador de productos de Dropi (catálogo con caché corta) para el modo individual. */
    public function buscar(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $bodega = (int) config('dropi.web.bodega_id');
        $palabras = PlanificarCargaDropi::palabras($q);
        $norm = PlanificarCargaDropi::norm($q);

        // Por id: lectura directa. Por texto: la búsqueda de Dropi (una petición
        // corta por lista) y aquí se afina por SKU o por todas las palabras.
        $candidatos = ctype_digit($q)
            ? array_filter([rescue(fn () => DropiWebClient::resumirProducto($this->dropi->producto((int) $q)), null, false)])
            : $this->dropi->buscarProductos($q, 30);

        $hits = collect($candidatos)->filter(function (array $p) use ($q, $palabras, $norm) {
            if ((string) $p['id'] === $q) {
                return true;
            }
            $skus = array_filter(array_merge([$p['sku'] ?? ''], array_column($p['variations'] ?? [], 'sku')));
            foreach ($skus as $s) {
                if ($norm !== '' && str_contains(PlanificarCargaDropi::norm($s), $norm)) {
                    return true;
                }
            }
            $nombre = PlanificarCargaDropi::palabras((string) $p['name']);

            return $palabras && ! array_diff($palabras, $nombre);
        })->take(15)->map(fn (array $p) => [
            'id' => (int) $p['id'],
            'nombre' => $p['name'],
            'sku' => $p['sku'] ?? null,
            'tipo' => $p['type'],
            'privado' => (bool) ($p['privated_product'] ?? false),
            'stock' => array_sum(array_column(PlanificarCargaDropi::lineasDe($p, $bodega), 'stock_plan')),
        ])->values();

        return response()->json($hits);
    }

    /**
     * Lee UNA página del catálogo de Dropi (~3 s) y la guarda. La pantalla lo
     * llama en bucle; con la última página cruza el Excel y deja el borrador.
     */
    public function leer(Carga $carga): JsonResponse
    {
        abort_unless($carga->estado === Carga::LEYENDO, 422, 'Esta carga ya terminó de leer Dropi.');

        $lock = Cache::lock("dropi.carga.{$carga->id}", 120);
        if (! $lock->get()) {
            return response()->json(['ocupado' => true] + $this->avanceLectura($carga));
        }
        try {
            $l = $carga->lectura ?? ['privados' => false, 'inicio' => 0, 'leidos' => 0];
            $pagina = $this->dropi->catalogoPagina((bool) $l['privados'], (int) $l['inicio']);

            $archivo = self::archivoCatalogo($carga);
            $disco = Storage::disk('local');
            $acumulado = $disco->exists($archivo) ? (json_decode($disco->get($archivo), true) ?: []) : [];
            foreach ($pagina as $p) {
                $acumulado[(string) $p['id']] = $p;
            }
            $disco->put($archivo, json_encode($acumulado, JSON_UNESCAPED_UNICODE));

            if (count($pagina) === DropiWebClient::PAGINA_CATALOGO) {
                $l['inicio'] += DropiWebClient::PAGINA_CATALOGO;
            } elseif (! $l['privados']) {
                $l = ['privados' => true, 'inicio' => 0] + $l;
            } else {
                $l['fin'] = true;
            }
            $l['leidos'] = count($acumulado);
            $carga->forceFill(['lectura' => $l])->save();

            if (! empty($l['fin'])) {
                $this->terminarLectura($carga, array_values($acumulado));
                $disco->delete($archivo);
            }
        } finally {
            $lock->release();
        }

        return response()->json($this->avanceLectura($carga->refresh()));
    }

    public function show(Carga $carga): Response
    {
        $carga->load(['operaciones', 'user:id,name', 'cargaInventario']);
        $ops = $carga->operaciones;

        return Inertia::render('Dropi/CargaProductos/Show', [
            'carga' => [
                'id' => $carga->id,
                'numero' => $carga->numero,
                'origen' => $carga->origen,
                'archivo' => $carga->archivo_nombre,
                'modo' => $carga->modo,
                'concepto' => $carga->concepto,
                'estado' => $carga->estado,
                'editable' => $carga->editable(),
                'lectura' => $carga->estado === Carga::LEYENDO ? $this->avanceLectura($carga) : null,
                'filas_excel' => count($carga->filas_excel ?? []),
                'bodega' => $carga->bodega_dropi_id,
                'defaults' => $carga->defaults_crear ?? self::defaultsVacios(),
                'ingreso_erp' => $carga->ingreso_erp ?? ['activo' => false],
                'carga_inventario' => $carga->cargaInventario ? [
                    'id' => $carga->cargaInventario->id,
                    'numero' => $carga->cargaInventario->numero,
                    'total_valor' => (float) $carga->cargaInventario->total_valor,
                    'total_unidades' => (float) $carga->cargaInventario->total_unidades,
                    'url' => "/app/inventario/cargas/{$carga->cargaInventario->id}",
                ] : null,
                'usuario' => $carga->user?->name,
                'fecha' => $carga->created_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
                'confirmado' => $carga->confirmado_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
                'terminado' => $carga->terminado_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
            ],
            'operaciones' => $ops->map(fn (Op $o) => [
                'id' => $o->id,
                'fila' => $o->fila,
                'ref' => $o->ref_excel,
                'nombre' => $o->nombre_excel,
                'variaciones' => $o->variaciones_excel,
                'cantidad' => $o->cantidad_excel,
                'tipo' => $o->tipo,
                'estado' => $o->estado,
                'motivo' => $o->motivo,
                'dropi_id' => $o->dropi_producto_id,
                'dropi_nombre' => $o->dropi_nombre,
                'detalle' => $o->detalle,
                'candidato' => $o->candidato,
                'error' => $o->error,
                'razon' => $o->razon,
                'razon_efectiva' => EjecutarOperacionDropi::razon($carga, $o),
                'razon_enviada' => $o->razon_enviada,
            ])->values(),
            'resumen' => $ops->countBy(fn (Op $o) => $o->estado)->all() + ['crear_listos' => $ops->where('tipo', Op::CREAR)->where('estado', Op::LISTO)->count()],
            'categorias' => $carga->editable() ? $this->categorias($carga) : [],
            // La cuenta que se muestra es la que de verdad usa la carga de
            // Dropi (sus hojas del PUC), no la del motivo en el ERP.
            'motivos' => $carga->editable() ? array_map(fn (array $m) => ['cuenta' => \App\Modules\Dropi\Support\CuentasDropi::contrapartidaCarga(
                \App\Modules\Inventario\Enums\MotivoCargaInventario::from($m['value'])
            )] + $m, \App\Modules\Inventario\Enums\MotivoCargaInventario::opciones(dropi: true)) : [],
            'bodegas_erp' => $carga->editable() ? self::bodegasErp() : [],
        ]);
    }

    /** Edita una fila del borrador: cantidades, datos del producto nuevo, elegir producto, omitir. */
    public function actualizarOperacion(Request $request, Carga $carga, Op $op): RedirectResponse
    {
        abort_unless($op->carga_id === $carga->id, 404);
        abort_unless($carga->editable(), 422, 'La carga ya se confirmó: no se puede cambiar.');

        $data = $request->validate([
            'accion' => ['required', Rule::in(['guardar', 'omitir', 'reactivar', 'usar_producto', 'crear_nuevo'])],
            'dropi_producto_id' => ['nullable', 'integer', 'min:1'],
            'lineas' => ['nullable', 'array'],
            'lineas.*.cantidad' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'detalle' => ['nullable', 'array'],
            'detalle.nombre' => ['nullable', 'string', 'max:200'],
            'detalle.sku' => ['nullable', 'string', 'max:100'],
            'detalle.descripcion' => ['nullable', 'string', 'max:2000'],
            'detalle.precio' => ['nullable', 'numeric', 'min:0'],
            'detalle.sugerido' => ['nullable', 'numeric', 'min:0'],
            'detalle.cantidad' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'detalle.variaciones' => ['nullable', 'array', 'max:50'],
            'detalle.variaciones.*.valor' => ['required_with:detalle.variaciones', 'string', 'max:60'],
            'detalle.variaciones.*.sku' => ['nullable', 'string', 'max:100'],
            'detalle.variaciones.*.cantidad' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $bodega = (int) $carga->bodega_dropi_id;

        switch ($data['accion']) {
            case 'omitir':
                $op->forceFill(['estado' => Op::OMITIDO])->save();
                break;

            case 'reactivar':
                $op->forceFill(['estado' => Op::REVISAR])->save();
                $this->recalcular($op);
                break;

            case 'usar_producto':
                $id = (int) ($data['dropi_producto_id'] ?? $op->candidato['id'] ?? 0);
                abort_if($id <= 0, 422, 'Falta el id del producto de Dropi.');
                $producto = $this->dropi->producto($id);
                $plan = $this->planificador->planFila([
                    'fila' => $op->fila, 'ref' => $op->ref_excel, 'nombre' => $op->nombre_excel,
                    'cantidad' => $op->cantidad_excel, 'variaciones' => $op->variaciones_excel,
                ], $producto, $bodega, 'elegido a mano');
                $op->forceFill($plan + ['candidato' => null, 'error' => null])->save();
                break;

            case 'crear_nuevo':
                $nombre = mb_convert_case(trim((string) $op->nombre_excel), MB_CASE_TITLE);
                [$tipo, $colores] = PlanificarCargaDropi::colores($op->variaciones_excel, (float) $op->cantidad_excel);
                $variaciones = $tipo === 'ok' && count($colores) > 1
                    ? array_map(fn ($c, $k) => ['valor' => mb_convert_case($c[1], MB_CASE_TITLE), 'sku' => "{$op->ref_excel}-{$k}", 'cantidad' => (float) $c[0]], $colores, array_keys($colores))
                    : [];
                $op->forceFill([
                    'tipo' => Op::CREAR, 'dropi_producto_id' => null, 'dropi_nombre' => null, 'candidato' => null,
                    'detalle' => ['nombre' => $nombre, 'sku' => (string) $op->ref_excel, 'descripcion' => '', 'precio' => null, 'sugerido' => null, 'cantidad' => (float) $op->cantidad_excel, 'variaciones' => $variaciones],
                ])->save();
                $this->recalcular($op);
                break;

            default: // guardar
                $detalle = $op->detalle;
                if ($op->tipo === Op::ACTUALIZAR) {
                    foreach ($detalle['lineas'] as $i => &$l) {
                        if (array_key_exists($i, $data['lineas'] ?? [])) {
                            $c = $data['lineas'][$i]['cantidad'] ?? null;
                            $l['cantidad'] = $c === null ? null : (int) $c;
                        }
                    }
                    unset($l);
                } else {
                    $d = $data['detalle'] ?? [];
                    $detalle = array_merge($detalle, array_intersect_key($d, array_flip(['nombre', 'sku', 'descripcion', 'precio', 'sugerido', 'cantidad'])));
                    if (array_key_exists('variaciones', $d)) {
                        $detalle['variaciones'] = array_values(array_map(fn ($v) => [
                            'valor' => trim($v['valor']), 'sku' => $v['sku'] ?? '', 'cantidad' => (int) ($v['cantidad'] ?? 0),
                        ], $d['variaciones'] ?? []));
                    }
                }
                $op->forceFill(['detalle' => $detalle])->save();
                $this->recalcular($op);
        }

        return back();
    }

    /**
     * La razón de una fila, editable SIEMPRE (también después de mandarla): es
     * el porqué que documenta el equipo en el ERP. Vacía = el concepto de la
     * carga. Lo ya mandado a Dropi queda con el texto de ese momento
     * (razon_enviada): Dropi no deja editar su historial de stock.
     */
    public function guardarRazon(Request $request, Carga $carga, Op $op): RedirectResponse
    {
        abort_unless($op->carga_id === $carga->id, 404);
        $data = $request->validate(['razon' => ['nullable', 'string', 'max:200']]);
        $razon = trim((string) ($data['razon'] ?? ''));
        $op->forceFill(['razon' => $razon === '' ? null : $razon])->save();

        return back();
    }

    /** El resultado de la carga en Excel: qué se actualizó, qué se creó y qué no, con su razón. */
    public function exportar(Carga $carga): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $libro = app(\App\Modules\Dropi\Services\ExportarCargaDropi::class)->libro($carga->load(['operaciones', 'cargaInventario']));
        $nombre = "carga-dropi-{$carga->numero}.xlsx";

        return response()->streamDownload(function () use ($libro) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function guardarCarga(Request $request, Carga $carga): RedirectResponse
    {
        abort_unless($carga->editable(), 422, 'La carga ya se confirmó: no se puede cambiar.');
        $data = $request->validate([
            'concepto' => ['required', 'string', 'min:5', 'max:100'],
            'modo' => ['required', Rule::in(['reemplazar', 'sumar'])],
            'defaults.categoria_id' => ['nullable', 'integer'],
            'defaults.peso' => ['nullable', 'numeric', 'min:0'],
            'defaults.largo' => ['nullable', 'numeric', 'min:0'],
            'defaults.ancho' => ['nullable', 'numeric', 'min:0'],
            'defaults.alto' => ['nullable', 'numeric', 'min:0'],
            'defaults.privado' => ['boolean'],
            'defaults.garantia_dias' => ['nullable', 'integer', 'min:10', 'max:365'],
            'defaults.garantia_texto' => ['nullable', 'string', 'max:200'],
            'ingreso_erp.activo' => ['boolean'],
            'ingreso_erp.motivo' => ['nullable', Rule::enum(\App\Modules\Inventario\Enums\MotivoCargaInventario::class)],
            'ingreso_erp.observacion' => ['nullable', 'string', 'max:1000'],
            'ingreso_erp.soporte' => ['nullable', 'string', 'max:60'],
            'ingreso_erp.tercero_id' => ['nullable', 'integer', 'exists:contactos,id'],
            'ingreso_erp.ubicacion_id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
            'ingreso_erp.origen_ubicacion_id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
        ]);
        $def = $data['defaults'] ?? [];
        $categoria = collect($this->categorias($carga))->firstWhere('id', (int) ($def['categoria_id'] ?? 0));

        $ing = $data['ingreso_erp'] ?? [];
        $carga->forceFill([
            'ingreso_erp' => [
                'activo' => (bool) ($ing['activo'] ?? false),
                'motivo' => $ing['motivo'] ?? null,
                'observacion' => isset($ing['observacion']) ? trim($ing['observacion']) : null,
                'soporte' => isset($ing['soporte']) ? (trim($ing['soporte']) ?: null) : null,
                'tercero_id' => isset($ing['tercero_id']) ? (int) $ing['tercero_id'] : null,
                'ubicacion_id' => isset($ing['ubicacion_id']) ? (int) $ing['ubicacion_id'] : null,
                // Traslado desde Great Baby: la bodega de donde sale.
                'origen_ubicacion_id' => isset($ing['origen_ubicacion_id']) ? (int) $ing['origen_ubicacion_id'] : null,
            ],
            'concepto' => $data['concepto'],
            'modo' => $data['modo'],
            'defaults_crear' => [
                'categoria' => $categoria,
                'peso' => $def['peso'] ?? null,
                'largo' => $def['largo'] ?? null,
                'ancho' => $def['ancho'] ?? null,
                'alto' => $def['alto'] ?? null,
                'privado' => (bool) ($def['privado'] ?? false),
                'garantia_dias' => (int) ($def['garantia_dias'] ?? 10),
                'garantia_texto' => $def['garantia_texto'] ?? null,
            ],
        ])->save();

        return back();
    }

    /** Cierra el borrador. Lo que quedó en «revisar» no se manda. */
    public function confirmar(Carga $carga): RedirectResponse
    {
        abort_unless($carga->editable(), 422, 'La carga ya se confirmó.');
        $listas = $carga->operaciones()->where('estado', Op::LISTO)->get();
        if ($listas->isEmpty()) {
            throw ValidationException::withMessages(['confirmar' => 'No hay ninguna fila lista para mandar a Dropi.']);
        }
        if ($listas->where('tipo', Op::CREAR)->isNotEmpty() && ($falta = self::faltaEnDefaults($carga->defaults_crear))) {
            throw ValidationException::withMessages(['confirmar' => "Para crear productos falta: {$falta}."]);
        }

        // Primero la misma mercancía entra al inventario del ERP (CI- con su
        // asiento); si eso falla no se manda nada a Dropi.
        $ingreso = $carga->ingreso_erp ?? [];
        if (! empty($ingreso['activo']) && empty($ingreso['hecho_at'])) {
            if ($falta = self::faltaEnIngreso($carga)) {
                throw ValidationException::withMessages(['confirmar' => "Para registrar el ingreso al inventario del ERP falta: {$falta}."]);
            }
            try {
                app(\App\Modules\Dropi\Actions\RegistrarIngresoErpDesdeCarga::class)->handle($carga);
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['confirmar' => 'No se pudo registrar el ingreso al inventario del ERP, así que no se mandó nada a Dropi: '.$e->getMessage()]);
            }
        }

        $carga->forceFill(['estado' => Carga::PROCESANDO, 'confirmado_at' => now()])->save();

        return back();
    }

    /** Qué le falta al ingreso al ERP para poder registrarse. */
    private static function faltaEnIngreso(Carga $carga): ?string
    {
        $i = $carga->ingreso_erp ?? [];
        $falta = [];
        if ($carga->modo !== 'sumar') {
            $falta[] = 'el modo «lo que entra» (si el Excel dice cuánto debe quedar no es un ingreso: es un ajuste de conteo)';
        }
        $motivo = isset($i['motivo']) ? \App\Modules\Inventario\Enums\MotivoCargaInventario::tryFrom($i['motivo']) : null;
        if (! $motivo) {
            $falta[] = 'el motivo';
        } elseif ($motivo->requiereTercero() && empty($i['tercero_id'])) {
            $falta[] = $motivo === \App\Modules\Inventario\Enums\MotivoCargaInventario::Compra ? 'el proveedor' : 'el socio';
        }
        if (mb_strlen(trim((string) ($i['observacion'] ?? ''))) < 5) {
            $falta[] = 'la observación (de dónde viene la mercancía)';
        }
        if (empty($i['ubicacion_id'])) {
            $falta[] = 'la bodega del ERP';
        }
        if ($motivo === \App\Modules\Inventario\Enums\MotivoCargaInventario::TrasladoGreatBaby) {
            if (empty($i['origen_ubicacion_id'])) {
                $falta[] = 'la bodega de Great Baby de donde sale';
            } elseif ((int) $i['origen_ubicacion_id'] === (int) ($i['ubicacion_id'] ?? 0)) {
                $falta[] = 'una bodega de origen distinta a la de Dropi';
            }
        }

        return $falta ? implode(', ', $falta) : null;
    }

    /**
     * Bodegas del ERP para el ingreso, con las sedes de Great Baby primero
     * (mismo criterio que la carga de inventario: el catálogo de SIIGO trae
     * bodegas de otras empresas y la primera alfabética era ajena).
     */
    private static function bodegasErp(): array
    {
        $sede = fn ($b) => $b->bodega_id === null && $b->responsable_user_id !== null;

        return \App\Modules\Dropi\Models\InventarioUbicacion::query()
            ->where(fn ($q) => $q->where('activa', true)->orWhereNull('activa'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo', 'bodega_id', 'pasillo', 'estante', 'nivel', 'responsable_user_id'])
            ->filter(fn ($b) => $b->esBodega())
            ->sortBy([
                fn ($a, $b) => ($sede($b) ? 1 : 0) <=> ($sede($a) ? 1 : 0),
                fn ($a, $b) => strcasecmp($a->nombre, $b->nombre),
            ])
            ->values()
            ->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->nombre} ({$b->codigo})", 'sede' => $sede($b)])
            ->all();
    }

    /** Procesa la siguiente tanda. La pantalla lo llama en bucle hasta que no queden. */
    public function procesar(Carga $carga, EjecutarOperacionDropi $ejecutar): JsonResponse
    {
        abort_unless($carga->estado === Carga::PROCESANDO, 422, 'La carga no está en proceso.');

        $lock = Cache::lock("dropi.carga.{$carga->id}", 120);
        if (! $lock->get()) {
            return response()->json(['ocupado' => true] + $this->avance($carga));
        }
        try {
            // Por tiempo y no por cantidad: cada producto son 3-5 llamadas a
            // Dropi (~2-6 s); se para al pasar el presupuesto y la pantalla
            // vuelve a llamar. Siempre se hace al menos uno.
            $desde = microtime(true);
            foreach ($carga->operaciones()->where('estado', Op::LISTO)->limit(self::TANDA_MAX)->get() as $op) {
                $ejecutar->handle($op);
                if (microtime(true) - $desde > self::SEGUNDOS_POR_TANDA) {
                    break;
                }
            }
            if (! $carga->operaciones()->where('estado', Op::LISTO)->exists()) {
                $carga->forceFill(['estado' => Carga::TERMINADO, 'terminado_at' => now()])->save();
            }
        } finally {
            $lock->release();
        }

        return response()->json($this->avance($carga->refresh()));
    }

    /** Vuelve a dejar en cola las que fallaron (por ejemplo, si se cayó la sesión de Dropi). */
    public function reintentar(Carga $carga): RedirectResponse
    {
        abort_unless($carga->estado === Carga::TERMINADO, 422, 'Solo se reintenta una carga terminada.');
        $n = $carga->operaciones()->where('estado', Op::ERROR)->update(['estado' => Op::LISTO, 'error' => null]);
        if ($n > 0) {
            $carga->forceFill(['estado' => Carga::PROCESANDO, 'terminado_at' => null])->save();
        }

        return back();
    }

    // ───────────────────────── internos ─────────────────────────

    private function nuevaCarga(string $origen, string $concepto, string $modo, ?string $archivo, array $ops, array $extra = []): Carga
    {
        return DB::transaction(function () use ($origen, $concepto, $modo, $archivo, $ops, $extra) {
            $carga = Carga::create($extra + [
                'numero' => Carga::siguienteNumero(),
                'origen' => $origen,
                'archivo_nombre' => $archivo,
                'modo' => $modo,
                'concepto' => $concepto,
                'bodega_dropi_id' => (int) config('dropi.web.bodega_id'),
                'defaults_crear' => self::defaultsVacios(),
                'estado' => Carga::BORRADOR,
                'user_id' => auth()->id(),
            ]);
            foreach ($ops as $op) {
                $carga->operaciones()->create($op + ['candidato' => $op['candidato'] ?? null]);
            }

            return $carga;
        });
    }

    /** Lista / revisar según lo que tenga la fila. Lo omitido no se toca. */
    private function recalcular(Op $op): void
    {
        if ($op->estado === Op::OMITIDO) {
            return;
        }
        $d = $op->detalle;
        $falta = [];
        if ($op->tipo === Op::ACTUALIZAR) {
            if (! $op->dropi_producto_id) {
                $falta[] = 'elegir el producto de Dropi';
            } elseif (! collect($d['lineas'] ?? [])->contains(fn ($l) => $l['cantidad'] !== null)) {
                $falta[] = 'escribir la cantidad de al menos una variación';
            }
        } else {
            if (trim((string) ($d['nombre'] ?? '')) === '') {
                $falta[] = 'el nombre';
            }
            if ((float) ($d['precio'] ?? 0) <= 0 || (float) ($d['sugerido'] ?? 0) <= 0) {
                $falta[] = 'el precio y el precio sugerido';
            }
            $valores = array_map(fn ($v) => mb_strtoupper(trim($v['valor'] ?? '')), $d['variaciones'] ?? []);
            if (in_array('', $valores, true) || count($valores) !== count(array_unique($valores))) {
                $falta[] = 'colores sin nombre o repetidos';
            }
            if (count($valores) === 1) {
                $falta[] = 'un producto con un solo color va sin variaciones (quita el color)';
            }
        }
        $op->forceFill([
            'estado' => $falta ? Op::REVISAR : Op::LISTO,
            'motivo' => $falta ? 'Falta ' . implode(', ', $falta) . '.' : null,
        ])->save();
    }

    private function avance(Carga $carga): array
    {
        $c = $carga->operaciones()->selectRaw('estado, COUNT(*) n')->groupBy('estado')->pluck('n', 'estado');

        return [
            'estado' => $carga->estado,
            'pendientes' => (int) ($c[Op::LISTO] ?? 0),
            'ok' => (int) ($c[Op::OK] ?? 0),
            'errores' => (int) ($c[Op::ERROR] ?? 0),
        ];
    }

    /** Con la última página: cruza el Excel con el catálogo y deja el borrador editable. */
    private function terminarLectura(Carga $carga, array $catalogo): void
    {
        $ops = $this->planificador->planificar($carga->filas_excel ?? [], $catalogo, (int) $carga->bodega_dropi_id);
        $categorias = self::categoriasDe($catalogo);
        Cache::put('dropi.web.categorias', $categorias, now()->addDay());

        DB::transaction(function () use ($carga, $ops, $categorias) {
            foreach ($ops as $op) {
                $carga->operaciones()->create($op + ['candidato' => $op['candidato'] ?? null]);
            }
            $carga->forceFill([
                'estado' => Carga::BORRADOR,
                'lectura' => ($carga->lectura ?? []) + ['categorias' => $categorias],
            ])->save();
        });
    }

    private function avanceLectura(Carga $carga): array
    {
        $l = $carga->lectura ?? [];

        return [
            'estado' => $carga->estado,
            'leidos' => (int) ($l['leidos'] ?? 0),
            'privados' => (bool) ($l['privados'] ?? false),
        ];
    }

    private static function archivoCatalogo(Carga $carga): string
    {
        return "dropi-cargas/catalogo-{$carga->id}.json";
    }

    /**
     * Categorías que ya usan los productos de la cuenta (Dropi no expone la
     * lista suelta): las de la lectura de esta carga, o las últimas vistas;
     * si nunca se leyó el catálogo, una página de cada lista (~6 s).
     */
    private function categorias(Carga $carga): array
    {
        if (! empty($carga->lectura['categorias'])) {
            return $carga->lectura['categorias'];
        }

        return Cache::remember('dropi.web.categorias', now()->addDay(), fn () => self::categoriasDe(array_merge(
            $this->dropi->catalogoPagina(false, 0),
            $this->dropi->catalogoPagina(true, 0),
        )));
    }

    private static function categoriasDe(array $catalogo): array
    {
        return collect($catalogo)->flatMap(fn ($p) => $p['categories'] ?? [])
            ->unique('id')->sortBy('name')
            ->map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'parent_category' => (int) ($c['parent_category'] ?? 0)])
            ->values()->all();
    }

    private static function defaultsVacios(): array
    {
        return ['categoria' => null, 'peso' => null, 'largo' => null, 'ancho' => null, 'alto' => null, 'privado' => false, 'garantia_dias' => 10, 'garantia_texto' => null];
    }

    private static function faltaEnDefaults(?array $d): ?string
    {
        $falta = [];
        if (empty($d['categoria']['id'])) {
            $falta[] = 'la categoría';
        }
        foreach (['peso' => 'el peso', 'largo' => 'el largo', 'ancho' => 'el ancho', 'alto' => 'el alto'] as $k => $txt) {
            if ((float) ($d[$k] ?? 0) <= 0) {
                $falta[] = $txt;
            }
        }
        if (trim((string) ($d['garantia_texto'] ?? '')) === '') {
            $falta[] = 'el texto de las garantías';
        }

        return $falta ? implode(', ', $falta) : null;
    }
}
