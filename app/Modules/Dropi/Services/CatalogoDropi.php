<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Clients\DropiApiClient;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * «Traer productos de Dropi»: lee el catálogo propio por la API oficial y lo
 * enlaza con el del ERP, para que los pedidos que esperaban producto lo tengan.
 *
 * Tres pasos y sólo el último escribe:
 *
 *   1. `actualizar()` lee de Dropi y guarda una FOTO en storage. Es lo lento
 *      (páginas de 40 y Dropi pide esperar ~30 s cada pocas llamadas), por eso
 *      corre en un job o en consola y no en la petición web.
 *   2. `previsualizar()` cruza esa foto con el ERP y dice qué pasaría con cada
 *      llave. No toca nada.
 *   3. `vincular()` hace los vínculos que la vista previa dio por seguros, con
 *      la misma acción que la pantalla «Productos sin vincular», que además
 *      reasigna los ítems de pedidos que esperaban y libera los que ya tienen stock.
 *
 * Lo que NO hace: crear productos (enviaría cada uno a SIIGO; espera el visto
 * bueno de contabilidad), traer stock (la verdad es el kardex del ERP) ni
 * precios (manda el ERP). Esos datos de Dropi sólo se muestran.
 */
class CatalogoDropi
{
    public const FOTO = 'dropi/catalogo.json';

    /** Contador del menú de /admin («Productos de Dropi»), guardado un minuto. */
    public const CACHE_BADGE = 'dropi:catalogo:badge';

    /** Tope de páginas: 40 × 50 = 2.000 productos. Si se llega, algo está mal. */
    private const MAX_PAGINAS = 50;

    /*
     * Ritmo con Dropi (medido el 6-nov): ~5 llamadas seguidas y responde 429
     * «Too Many Attempts» pidiendo 30 s. El cliente reintenta 3 veces, que con
     * la ventana ya gastada no alcanza; esto es una lectura de fondo, así que
     * va despacio a propósito.
     */
    private const PAUSA_ENTRE_PAGINAS_S = 6;
    private const ESPERA_SI_LIMITA_S = 30;
    private const INTENTOS_POR_PAGINA = 5;

    public function __construct(protected DropiWebClient $cliente) {}

    /**
     * Lee el catálogo propio de Dropi y guarda la foto.
     *
     * @return array{productos: int, leido_at: string}
     */
    public function actualizar(): array
    {
        if (! $this->cliente instanceof DropiApiClient) {
            throw new \RuntimeException('Traer productos de Dropi necesita la API oficial: ponga DROPI_DRIVER=api y DROPI_API_KEY en el .env.');
        }

        $bodega = config('dropi.api.bodega_id');
        $dueno = config('dropi.api.proveedor_id');
        if (! $bodega) {
            throw new \RuntimeException('Falta DROPI_BODEGA_ID en el .env: es la bodega de Great Baby en Dropi.');
        }

        $productos = [];
        $porPagina = 40;
        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            if ($pagina > 0) {
                Sleep::for(self::PAUSA_ENTRE_PAGINAS_S)->seconds();
            }
            $lote = $this->pagina($pagina * $porPagina, $porPagina, (int) $bodega);
            foreach ($lote as $p) {
                // Otro proveedor en la misma bodega: no es nuestro catálogo.
                if ($dueno && (int) ($p['user']['id'] ?? 0) !== (int) $dueno) {
                    continue;
                }
                $productos[(int) $p['id']] = self::resumir($p);
            }
            if (count($lote) < $porPagina) {
                break;
            }
        }

        $foto = ['leido_at' => now()->toIso8601String(), 'productos' => array_values($productos)];
        Storage::disk('local')->put(self::FOTO, json_encode($foto, JSON_UNESCAPED_UNICODE));
        \Illuminate\Support\Facades\Cache::forget(self::CACHE_BADGE);

        return ['productos' => count($productos), 'leido_at' => $foto['leido_at']];
    }

    /** Una página, esperando lo que Dropi pida si responde «Too Many Attempts». */
    private function pagina(int $desde, int $porPagina, int $bodega): array
    {
        for ($intento = 1; ; $intento++) {
            try {
                return $this->cliente->productosCrudos($desde, $porPagina, ['warehouse_id' => $bodega]);
            } catch (\Throwable $e) {
                // 429 «Too Many Attempts», o Dropi que tarda más de 30 s en una
                // página pesada (visto el 6-nov: cURL 28 con 73 KB recibidos).
                // Las dos se pasan esperando; cualquier otra cosa es un error de verdad.
                $limitado = $e instanceof \RuntimeException
                    && (str_contains($e->getMessage(), 'Too Many Attempts') || str_contains($e->getMessage(), 'HTTP 429'));
                $lento = $e instanceof \Illuminate\Http\Client\ConnectionException;
                if (! ($limitado || $lento) || $intento >= self::INTENTOS_POR_PAGINA) {
                    throw $e;
                }
                Sleep::for(self::ESPERA_SI_LIMITA_S)->seconds();
            }
        }
    }

    /** @return array{leido_at: string, productos: array<int, array>}|null */
    public function foto(): ?array
    {
        if (! Storage::disk('local')->exists(self::FOTO)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get(self::FOTO), true) ?: null;
    }

    /**
     * Qué pasaría con cada llave de la foto. Estados:
     *   · vinculado — ya tiene vínculo; no se toca.
     *   · enlazar   — hay destino seguro en el ERP; `vincular()` lo hace.
     *   · revisar   — hay algo parecido pero no es seguro; se hace a mano.
     *   · nuevo     — no existe en el ERP (crearlo queda para después).
     *   · sin_catalogo — llave que traen pedidos sin producto pero que no está
     *                    en el catálogo activo de Dropi (archivados, borrados).
     *
     * Cada fila dice cuántos pedidos ACTIVOS esperan esa llave sin producto.
     *
     * @return array{filas: array<int, array>, resumen: array<string, int>, leido_at: ?string}
     */
    public function previsualizar(): array
    {
        // Sin lectura de Dropi igual se listan las llaves que esperan en pedidos
        // (grupo «fuera del catálogo»): es lo que hacía «Productos sin vincular»,
        // que se quitó, y no puede depender de que alguien haya leído Dropi antes.
        $foto = $this->foto();
        $productos = $foto['productos'] ?? [];

        // SKUs que Dropi repite en varios productos: por SKU no se puede decidir.
        $conteoSku = [];
        foreach ($productos as $p) {
            if ($p['sku_valido']) {
                $k = self::norm($p['sku']);
                $conteoSku[$k] = ($conteoSku[$k] ?? 0) + 1;
            }
        }

        $refs = Producto::query()->get(['id', 'referencia', 'nombre', 'desglose_stock'])
            ->keyBy(fn ($p) => self::norm($p->referencia));
        $porNombre = Producto::query()->get(['id', 'referencia', 'nombre'])->groupBy(fn ($p) => self::norm($p->nombre));
        $vinculos = DropiVinculoProducto::query()->with(['variante.producto', 'producto'])->get()->keyBy('sku_dropi');
        $porVariacion = ProductoVariante::query()->whereNotNull('dropi_variacion_id')->get()->keyBy('dropi_variacion_id');

        $esperando = self::pedidosEsperando();

        $filas = [];
        foreach ($productos as $p) {
            $erp = $p['sku_valido'] && ($conteoSku[self::norm($p['sku'])] ?? 0) === 1
                ? $refs->get(self::norm($p['sku']))
                : null;
            $repetido = $p['sku_valido'] && ($conteoSku[self::norm($p['sku'])] ?? 0) > 1;

            foreach ($p['llaves'] as $ll) {
                $fila = [
                    'dropi_id' => $p['id'],
                    'nombre' => $p['nombre'],
                    'tipo' => $p['tipo'],
                    'sku' => $p['sku'],
                    'llave' => $ll['llave'],
                    'variacion' => $ll['variacion'],
                    'stock_dropi' => $ll['stock'],
                    'precio_dropi' => $ll['precio'],
                    'estado' => 'nuevo',
                    'destino' => null,        // ['tipo' => 'variante'|'producto', 'id' => int]
                    'destino_label' => null,
                    'motivo' => null,
                    'pedidos_esperando' => $esperando[$ll['llave']]['pedidos'] ?? 0,
                ];

                $v = $vinculos->get($ll['llave']);
                if ($v) {
                    $fila['estado'] = 'vinculado';
                    $fila['destino_label'] = $v->variante_id
                        ? trim(($v->variante?->producto?->referencia ?? '') . ' · ' . ($v->variante?->codigo_barras ?? ''))
                        : ($v->producto?->referencia ?? '') . ' · sin variantes';
                } elseif ($ll['variacion_id'] && ($var = $porVariacion->get((string) $ll['variacion_id']))) {
                    $fila['estado'] = 'enlazar';
                    $fila['destino'] = ['tipo' => 'variante', 'id' => $var->id];
                    $fila['destino_label'] = $var->codigo_barras;
                    $fila['motivo'] = 'misma variación de Dropi';
                } elseif ($erp && ! $erp->desglose_stock) {
                    $fila['estado'] = 'enlazar';
                    $fila['destino'] = ['tipo' => 'producto', 'id' => $erp->id];
                    $fila['destino_label'] = $erp->referencia . ' · sin variantes';
                    $fila['motivo'] = 'misma referencia';
                } elseif ($erp) {
                    $fila['estado'] = 'revisar';
                    $fila['destino_label'] = $erp->referencia;
                    $fila['motivo'] = 'el producto del ERP lleva el stock por variante: elegir la variante a mano';
                } elseif ($repetido) {
                    $fila['estado'] = 'revisar';
                    $fila['motivo'] = "Dropi usa el SKU «{$p['sku']}» en varios productos";
                } elseif (($candidatos = $porNombre->get(self::norm($p['nombre']))) && $candidatos->count() === 1) {
                    // Mismo nombre, otra referencia: se sugiere, no se enlaza solo.
                    $fila['estado'] = 'revisar';
                    $fila['destino_label'] = $candidatos->first()->referencia;
                    $fila['motivo'] = 'mismo nombre, otra referencia';
                }

                $filas[] = $fila;
            }
        }

        // Llaves que esperan en pedidos y no salieron del catálogo: también se
        // vinculan desde aquí, para no tener que ir a «Productos sin vincular».
        $enCatalogo = array_flip(array_column($filas, 'llave'));
        foreach (self::llavesSinProducto() as $it) {
            if (isset($enCatalogo[$it['llave']])) {
                continue;
            }
            $filas[] = [
                'dropi_id' => null, 'nombre' => $it['nombre'] ?? $it['llave'], 'tipo' => '', 'sku' => null,
                'llave' => $it['llave'], 'variacion' => $it['variacion'], 'stock_dropi' => null, 'precio_dropi' => null,
                'estado' => 'sin_catalogo', 'destino' => null, 'destino_label' => null,
                'motivo' => $foto
                    ? 'llega en pedidos pero no está en el catálogo activo de Dropi'
                    : 'llega en pedidos; lea Dropi para cruzarlo con el catálogo',
                'pedidos_esperando' => $esperando[$it['llave']]['pedidos'] ?? 0,
            ];
        }

        $resumen = ['vinculado' => 0, 'enlazar' => 0, 'revisar' => 0, 'nuevo' => 0, 'sin_catalogo' => 0];
        foreach ($filas as $f) {
            $resumen[$f['estado']]++;
        }
        $resumen['productos'] = count($productos);
        $resumen['llaves'] = count($filas);
        $resumen['con_pedidos'] = count(array_filter($filas, fn ($f) => $f['pedidos_esperando'] > 0 && $f['estado'] !== 'vinculado'));

        return ['filas' => $filas, 'resumen' => $resumen, 'leido_at' => $foto['leido_at'] ?? null];
    }

    /**
     * Hace los vínculos que la vista previa dio por seguros (estado «enlazar»).
     *
     * @return array{vinculos: int, items: int, pedidos_liberados: int, errores: array<int, string>}
     */
    public function vincular(?int $userId): array
    {
        $accion = VincularProductoDropi::make();
        $out = ['vinculos' => 0, 'items' => 0, 'pedidos_liberados' => 0, 'errores' => []];

        foreach ($this->previsualizar()['filas'] as $f) {
            if ($f['estado'] !== 'enlazar') {
                continue;
            }
            $nombres = ['producto_nombre' => mb_substr($f['nombre'], 0, 150), 'variacion' => $f['variacion'] ? mb_substr($f['variacion'], 0, 80) : null];
            try {
                $r = $f['destino']['tipo'] === 'producto'
                    ? $accion->aProducto($f['llave'], $f['destino']['id'], $userId, $nombres)
                    : $accion->handle($f['llave'], $f['destino']['id'], $userId, $nombres);
                $out['vinculos']++;
                $out['items'] += $r['items'];
                $out['pedidos_liberados'] += $r['pedidos_liberados'];
            } catch (\Throwable $e) {
                $out['errores'][] = "{$f['llave']} ({$f['nombre']}): {$e->getMessage()}";
            }
        }
        \Illuminate\Support\Facades\Cache::forget(self::CACHE_BADGE);

        return $out;
    }

    /**
     * Vincula UNA llave a mano, desde la pantalla «Productos de Dropi».
     * `$destino` = `v:<id>` (variante) o `p:<id>` (producto agregado).
     *
     * @return array{items: int, pedidos_liberados: int}
     */
    public function vincularLlave(string $llave, string $destino, ?int $userId): array
    {
        [$tipo, $id] = array_pad(explode(':', $destino, 2), 2, null);
        if (! in_array($tipo, ['v', 'p'], true) || ! ctype_digit((string) $id)) {
            throw new \InvalidArgumentException('Destino no válido.');
        }

        // Nombre y variación de Dropi para la lista de vínculos: de la foto si
        // la llave está en el catálogo; si no, VincularProductoDropi lo toma del pedido.
        $nombres = [];
        foreach ($this->foto()['productos'] ?? [] as $p) {
            foreach ($p['llaves'] as $ll) {
                if ($ll['llave'] === $llave) {
                    $nombres = ['producto_nombre' => mb_substr($p['nombre'], 0, 150), 'variacion' => $ll['variacion'] ? mb_substr($ll['variacion'], 0, 80) : null];
                    break 2;
                }
            }
        }

        $accion = VincularProductoDropi::make();
        $r = $tipo === 'p'
            ? $accion->aProducto($llave, (int) $id, $userId, $nombres)
            : $accion->handle($llave, (int) $id, $userId, $nombres);
        \Illuminate\Support\Facades\Cache::forget(self::CACHE_BADGE);

        return $r;
    }

    /** Estados en que un pedido todavía no sale de bodega: ahí sí estorba no tener producto. */
    private const ESTADOS_ACTIVOS = ['pending', 'pendiente_inventario'];

    /** @return array<string, array{pedidos: int}> llave → pedidos activos esperando producto */
    private static function pedidosEsperando(): array
    {
        return DropiPedidoItem::query()
            ->whereNull('variante_id')->whereNull('producto_id')->whereNotNull('sku_dropi')
            ->whereHas('pedido', fn ($q) => $q->whereIn('estado', self::ESTADOS_ACTIVOS))
            ->selectRaw('sku_dropi, COUNT(DISTINCT pedido_id) as pedidos')
            ->groupBy('sku_dropi')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->sku_dropi => ['pedidos' => (int) $r->pedidos]])
            ->all();
    }

    /** @return array<int, array{llave: string, nombre: ?string, variacion: ?string}> */
    private static function llavesSinProducto(): array
    {
        return DropiPedidoItem::query()
            ->whereNull('variante_id')->whereNull('producto_id')->whereNotNull('sku_dropi')
            ->selectRaw('sku_dropi, MAX(producto_nombre) as nombre, MAX(variacion) as variacion')
            ->groupBy('sku_dropi')
            ->get()
            ->map(fn ($r) => ['llave' => $r->sku_dropi, 'nombre' => $r->nombre, 'variacion' => $r->variacion])
            ->all();
    }

    /**
     * «Mis productos en Dropi»: el catálogo tal cual está en Dropi, producto por
     * producto, con cada variación y si ya está vinculada al ERP. Sólo lee la foto.
     *
     * @return array{leido_at: ?string, productos: array<int, array>}
     */
    public function misProductos(): array
    {
        $foto = $this->foto();
        if (! $foto) {
            return ['leido_at' => null, 'productos' => []];
        }

        $vinculos = DropiVinculoProducto::query()->with(['variante.producto', 'producto'])->get()->keyBy('sku_dropi');

        $productos = array_map(function (array $p) use ($vinculos) {
            $variaciones = array_map(function (array $ll) use ($vinculos) {
                $v = $vinculos->get($ll['llave']);

                return $ll + [
                    'vinculado' => (bool) $v,
                    'erp' => $v ? ($v->variante_id
                        ? trim(($v->variante?->producto?->referencia ?? '') . ' · ' . ($v->variante?->codigo_barras ?? ''))
                        : ($v->producto?->referencia ?? '') . ' · ' . ($v->producto?->nombre ?? '')) : null,
                ];
            }, $p['llaves']);

            $precios = array_filter(array_column($variaciones, 'precio'), fn ($x) => $x !== null);
            $vinculadas = count(array_filter($variaciones, fn ($v) => $v['vinculado']));

            return [
                'id' => $p['id'],
                'nombre' => $p['nombre'],
                'tipo' => $p['tipo'],
                'sku' => $p['sku'],
                'privado' => $p['privado'],
                'imagen' => $p['imagen'] ?? null,
                'categorias' => $p['categorias'] ?? [],
                'descripcion' => $p['descripcion'] ?? null,
                'stock' => array_sum(array_column($variaciones, 'stock')),
                'precio_min' => $precios ? min($precios) : null,
                'precio_max' => $precios ? max($precios) : null,
                'vinculo' => $vinculadas === 0 ? 'no' : ($vinculadas === count($variaciones) ? 'si' : 'parcial'),
                'variaciones' => $variaciones,
            ];
        }, $foto['productos']);

        usort($productos, fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre']));

        return ['leido_at' => $foto['leido_at'], 'productos' => $productos];
    }

    /** Lo que se guarda de cada producto de Dropi: lo justo para decidir y mostrar. */
    public static function resumir(array $p): array
    {
        $sku = trim((string) ($p['sku'] ?? ''));
        $variaciones = $p['variations'] ?? [];
        $llaves = [];

        if ($variaciones) {
            foreach ($variaciones as $v) {
                $llaves[] = [
                    'llave' => MapearPedidoDropiWeb::claveVinculo($v['id'] ?? null, null, null),
                    'variacion_id' => (int) $v['id'],
                    'variacion' => collect($v['attribute_values'] ?? [])->pluck('value')->filter()->implode(' / ') ?: null,
                    'precio' => isset($v['sale_price']) ? (float) $v['sale_price'] : null,
                    'precio_sugerido' => isset($v['suggested_price']) ? (float) $v['suggested_price'] : null,
                    'stock' => (int) collect($v['warehouse_product_variation'] ?? [])->sum('stock'),
                ];
            }
        } else {
            $llaves[] = [
                'llave' => MapearPedidoDropiWeb::claveVinculo(null, $sku, $p['id'] ?? null),
                'variacion_id' => null,
                'variacion' => null,
                'precio' => isset($p['sale_price']) ? (float) $p['sale_price'] : null,
                'precio_sugerido' => isset($p['suggested_price']) ? (float) $p['suggested_price'] : null,
                'stock' => (int) collect($p['warehouse_product'] ?? [])->sum('stock'),
            ];
        }

        // Foto principal: Dropi manda la ruta (`urlS3`) y la sirve desde su CDN.
        $galeria = collect($p['gallery'] ?? []);
        $foto = $galeria->firstWhere('main', true) ?? $galeria->first();
        $rutaFoto = $foto['urlS3'] ?? null;
        $imagen = $foto['url'] ?? ($rutaFoto ? rtrim((string) config('dropi.api.imagenes_url'), '/') . '/' . ltrim($rutaFoto, '/') : null);

        $descripcion = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($p['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return [
            'id' => (int) $p['id'],
            'nombre' => trim((string) ($p['name'] ?? '')),
            'tipo' => (string) ($p['type'] ?? ''),
            'sku' => $sku !== '' ? $sku : null,
            'sku_valido' => MapearPedidoDropiWeb::skuValido($sku),
            'privado' => (bool) ($p['privated_product'] ?? false),
            'imagen' => $imagen,
            'categorias' => collect($p['categories'] ?? [])->pluck('name')->filter()->values()->all(),
            'descripcion' => $descripcion !== '' ? mb_substr($descripcion, 0, 400) : null,
            'llaves' => $llaves,
        ];
    }

    private static function norm(?string $s): string
    {
        $s = \Illuminate\Support\Str::ascii((string) $s);

        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($s)));
    }
}
