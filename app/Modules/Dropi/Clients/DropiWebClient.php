<?php

namespace App\Modules\Dropi\Clients;

use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\DTOs\PagoWalletDTO;
use App\Modules\Dropi\Services\DropiWebAuth;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Driver `web`: habla con la API interna que usa app.dropi.co, con la sesión de
 * una cuenta real (ver DropiWebAuth). Puente mientras Dropi entrega la API oficial
 * (§26); el resto del ERP no se entera porque depende del interface.
 *
 * El sync casi en tiempo real vive en SincronizarDropiWeb (tres ritmos); los
 * métodos del interface quedan para el `dropi:sync` clásico.
 */
class DropiWebClient implements DropiClientInterface
{
    public function __construct(protected DropiWebAuth $auth) {}

    public function driver(): string
    {
        return 'web';
    }

    /**
     * Una página cruda de «Mis pedidos», tal cual la devuelve Dropi.
     *
     * @return array{objects: array<int, array>, count: int|null}
     */
    public function pedidosCrudos(array $filtros = [], int $pagina = 0, int $porPagina = 50): array
    {
        $resp = $this->auth->request()->get('orders/myorders/v2', array_filter([
            'result_number' => $porPagina,
            'start' => $pagina * $porPagina,
        ] + $filtros, fn ($v) => $v !== null && $v !== ''));

        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! DropiWebAuth::exito($body)) {
            throw new \RuntimeException('Dropi respondió HTTP ' . $resp->status() . ' al listar pedidos: ' . ($body['status_reason'] ?? $body['message'] ?? ''));
        }

        return [
            'objects' => $body['data']['objects'] ?? [],
            'count' => $body['data']['count'] ?? null,
        ];
    }

    /**
     * Detalle completo de un pedido (ítems con cantidad y precio de proveedor,
     * historial de estados). La lista no los trae.
     */
    public function pedidoDetalle(int $ordenId): array
    {
        $resp = $this->auth->request()->get("orders/myorders/v2/{$ordenId}", ['warranty' => 'false']);
        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! DropiWebAuth::exito($body) || empty($body['data']['objects'])) {
            throw new \RuntimeException("Dropi no devolvió el pedido {$ordenId} (HTTP {$resp->status()}): " . ($body['status_reason'] ?? ''));
        }

        return $body['data']['objects'];
    }

    /**
     * Cambia el estatus del pedido EN DROPI (lo mismo que el botón «Actualizar
     * Estatus» de su web). Devuelve lo que Dropi deja en el pedido: estatus,
     * guía, transportadora y sticker. Lanza con el mensaje de Dropi si lo rechaza.
     *
     * @return array{shipping_guide: ?string, shipping_company: ?string, sticker: ?string}
     */
    public function actualizarEstatus(int $ordenId, string $estatus, ?string $motivo = null): array
    {
        $resp = $this->legacy()->put("orders/myorders/{$ordenId}", array_filter([
            'status' => $estatus,
            'reasonComment' => $motivo,
        ], fn ($v) => $v !== null && $v !== ''));

        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! ($body['isSuccess'] ?? false)) {
            $error = $body['error_guias_generadas'][0]['error'] ?? $body['message'] ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException("Dropi no cambió el pedido {$ordenId}: {$error}");
        }

        return [
            'shipping_guide' => $body['order']['shipping_guide'] ?? null,
            'shipping_company' => $body['order']['shipping_company'] ?? null,
            'sticker' => $body['order']['sticker'] ?? null,
        ];
    }

    /**
     * Etiquetas de la cuenta (las que se pueden poner a un pedido).
     *
     * @return array<int, array{id:int, nombre:string, color:?string}>
     */
    public function etiquetasCuenta(): array
    {
        $body = $this->legacyJson($this->legacy()->get('tags/showtagsselect'), 'listar etiquetas');

        return array_map(fn ($t) => self::normalizarEtiqueta($t), $body['objects'] ?? []);
    }

    /**
     * Etiquetas que tiene hoy el pedido. El detalle no las trae; la lista sí.
     *
     * @return array<int, array{id:int, nombre:string, color:?string}>
     */
    public function etiquetasPedido(int $ordenId): array
    {
        $o = collect($this->pedidosCrudos(['textToSearch' => (string) $ordenId], 0, 10)['objects'])
            ->first(fn ($o) => (int) ($o['id'] ?? 0) === $ordenId);

        return array_map(fn ($t) => self::normalizarEtiqueta($t), $o['tags'] ?? []);
    }

    public function crearEtiqueta(string $nombre, string $color): void
    {
        $this->legacyJson($this->legacy()->post('tags/addtag', ['tag_name' => $nombre, 'color' => $color]), "crear la etiqueta «{$nombre}»");
    }

    public function asignarEtiqueta(int $ordenId, int $tagId): void
    {
        $this->legacyJson($this->legacy()->post('tags/createorderstags', ['order_id' => $ordenId, 'tag_id' => $tagId]), "poner la etiqueta al pedido {$ordenId}");
    }

    public function quitarEtiqueta(int $ordenId, int $tagId): void
    {
        // La web de Dropi manda los datos del DELETE como query string.
        $this->legacyJson(
            $this->legacy()->delete('tags/deleteordertag?' . http_build_query(['tag_id' => $tagId, 'order_id' => $ordenId])),
            "quitar la etiqueta del pedido {$ordenId}",
        );
    }

    /**
     * Todos los productos de la cuenta (POST products/index del API clásico,
     * el mismo listado de «Productos» de la web), con variaciones y stock por
     * bodega. ~330 productos = 4 páginas de 100.
     *
     * @return array<int, array>
     */
    public function catalogo(): array
    {
        $todos = [];
        foreach ([false, true] as $privados) {
            for ($inicio = 0; $inicio < 10000; $inicio += self::PAGINA_CATALOGO) {
                $pagina = $this->catalogoPagina($privados, $inicio);
                foreach ($pagina as $p) {
                    $todos[(int) $p['id']] = $p;
                }
                if (count($pagina) < self::PAGINA_CATALOGO) {
                    break;
                }
            }
        }

        return array_values($todos);
    }

    /**
     * 25 productos por página. Medido el 19-nov-2026: una página de 50 tarda
     * 2–8 s pero Dropi a veces se demora 20–25 s; con 25 cada petición queda
     * lejos de cualquier límite de tiempo del servidor web.
     */
    public const PAGINA_CATALOGO = 25;

    /**
     * Una página del listado de productos, ya reducida a lo que usa el ERP.
     *
     * Dropi lista públicos (privated_product=false) y privados (=true) por
     * separado; con null devuelve CERO productos sin error (probado el
     * 19-nov-2026), así que quien quiera todo pide las dos listas.
     *
     * @return array<int, array>
     */
    public function catalogoPagina(bool $privados, int $inicio, int $tamano = self::PAGINA_CATALOGO, string $palabras = ''): array
    {
        // Sin reintentos aquí: si Dropi se demora, falla rápido y quien llama
        // (la pantalla) reintenta la misma página. Así una petición nunca pasa
        // de ~25 s aunque Dropi esté lento.
        $resp = $this->legacy()
            ->timeout(25)
            ->post('products/index', [
                'pageSize' => $tamano, 'startData' => $inicio, 'no_count' => true,
                'order_by' => 'id', 'order_type' => 'desc', 'keywords' => $palabras,
                'active' => null, 'userVerified' => false, 'privated_product' => $privados,
            ]);
        $body = $this->legacyJson($resp, 'listar los productos');

        return array_map([self::class, 'resumirProducto'], $body['objects'] ?? []);
    }

    /**
     * Búsqueda corta (una petición por lista, ~1 s): la usa el buscador de
     * «un producto» para no leer el catálogo entero.
     *
     * @return array<int, array>
     */
    public function buscarProductos(string $palabras, int $tamano = 15): array
    {
        $hits = [];
        foreach ([false, true] as $privados) {
            foreach ($this->catalogoPagina($privados, 0, $tamano, $palabras) as $p) {
                $hits[(int) $p['id']] = $p;
            }
        }

        return array_values($hits);
    }

    /**
     * Lo que el ERP necesita de un producto del listado (el original trae
     * descripción, galería, usuario, favoritos… ~9 KB por producto).
     */
    public static function resumirProducto(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'name' => (string) ($p['name'] ?? ''),
            'sku' => $p['sku'] ?? null,
            'type' => $p['type'] ?? 'SIMPLE',
            'privated_product' => (bool) ($p['privated_product'] ?? false),
            'categories' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'] ?? '', 'parent_category' => (int) ($c['parent_category'] ?? 0)], $p['categories'] ?? []),
            'warehouse_product' => array_map(fn ($w) => ['warehouse_id' => (int) $w['warehouse_id'], 'stock' => (int) ($w['stock'] ?? 0)], $p['warehouse_product'] ?? []),
            'variations' => array_map(fn ($v) => [
                'id' => (int) $v['id'],
                'sku' => $v['sku'] ?? null,
                'attribute_values' => array_map(fn ($a) => ['value' => $a['value'] ?? ''], $v['attribute_values'] ?? []),
                'warehouse_product_variation' => array_map(fn ($w) => ['warehouse_id' => (int) $w['warehouse_id'], 'stock' => (int) ($w['stock'] ?? 0)], $v['warehouse_product_variation'] ?? []),
            ], $p['variations'] ?? []),
        ];
    }

    /**
     * Producto completo tal como lo carga la pantalla de edición de Dropi
     * (GET /products/{id} del API clásico): atributos, variaciones con su stock
     * por bodega, categorías, inventarios privados.
     */
    public function producto(int $productoId): array
    {
        $body = $this->legacyJson($this->legacy()->get("products/{$productoId}"), "leer el producto {$productoId}");
        if (empty($body['objects']['id'])) {
            throw new \RuntimeException("Dropi no devolvió el producto {$productoId}.");
        }

        return $body['objects'];
    }

    /**
     * Stock por bodega de un producto simple o de una variación, en el formato
     * que la web de Dropi usa como base de un movimiento (POST
     * products/get_warehouses_stock).
     *
     * @return array<int, array{warehouse_id:int, stock:int, fullName?:string, warehouse_white_brand?:bool, variation_id?:int}>
     */
    public function stockPorBodega(int $productoId, ?int $variacionId): array
    {
        $payload = $variacionId
            ? ['product_id' => null, 'type' => 'VARIABLE', 'variation_id' => $variacionId]
            : ['product_id' => $productoId, 'type' => 'SIMPLE'];
        $body = $this->legacyJson($this->legacy()->post('products/get_warehouses_stock', $payload), 'leer el stock por bodega');

        return array_map(function (array $w) {
            unset($w['city']);
            if (! empty($w['fullName']) && ! empty($w['warehouse_id'])) {
                Cache::forever(self::claveBodega((int) $w['warehouse_id']), $w['fullName']);
            }

            return $w;
        }, $body['objects'] ?? []);
    }

    /**
     * «Nombre(dirección)» de la bodega, que Dropi exige (`fullName`) cuando una
     * variación entra por primera vez a una bodega. Sale de cualquier lectura
     * previa de stock en esa bodega; si nunca se vio, se busca en las otras
     * variaciones del mismo producto.
     */
    protected function nombreBodega(int $bodegaId, array $producto): ?string
    {
        if ($nombre = Cache::get(self::claveBodega($bodegaId))) {
            return $nombre;
        }
        foreach ($producto['variations'] ?? [] as $v) {
            foreach ($this->stockPorBodega((int) $producto['id'], (int) $v['id']) as $w) {
                if ((int) $w['warehouse_id'] === $bodegaId && ! empty($w['fullName'])) {
                    return $w['fullName'];
                }
            }
        }

        return null;
    }

    protected static function claveBodega(int $bodegaId): string
    {
        return "dropi.web.bodega.{$bodegaId}.nombre";
    }

    /**
     * Registra movimientos de stock en Dropi: lo mismo que el modal «Stock» de la
     * edición de producto. Dropi no fija el stock: suma (ENTRADA) o resta
     * (SALIDA) con un concepto, y lo deja en su historial de stock.
     *
     * Se manda el producto completo releído (PUT /products/{id}) con
     * `variations` vacío —así lo hace la propia web cuando solo guarda stock—,
     * para no tocar variaciones, fotos ni precios.
     *
     * @param  array<int, array{variation_id:?int, warehouse_id:int, quantity:int, type_movement:string, concept:string}>  $movimientos
     */
    public function moverStock(int $productoId, array $movimientos): array
    {
        if ($movimientos === []) {
            throw new \InvalidArgumentException("No hay movimientos de stock para el producto {$productoId}.");
        }
        $p = $this->producto($productoId);

        $variable = ($p['type'] ?? '') === 'VARIABLE';
        $seleccion = [];
        foreach ($movimientos as $m) {
            if ($variable && empty($m['variation_id'])) {
                throw new \InvalidArgumentException("El producto {$productoId} tiene variaciones: el movimiento necesita variation_id.");
            }
            if (! in_array($m['type_movement'], ['ENTRADA', 'SALIDA'], true) || (int) $m['quantity'] <= 0) {
                throw new \InvalidArgumentException('Movimiento de stock inválido: ' . json_encode($m));
            }
            $bodegas = collect($this->stockPorBodega($productoId, $m['variation_id'] ?? null))
                ->keyBy(fn ($w) => (int) $w['warehouse_id']);
            $base = $bodegas->get((int) $m['warehouse_id']);
            if (! $base) {
                // Primera vez de esta variación en la bodega: la web manda
                // id, nombre completo y variación, sin stock (no existe aún).
                $nombre = $this->nombreBodega((int) $m['warehouse_id'], $p);
                if (! $nombre) {
                    throw new \RuntimeException("No sé el nombre de la bodega {$m['warehouse_id']} en Dropi: carga primero stock de cualquier producto en esa bodega desde la web.");
                }
                $base = ['warehouse_id' => (int) $m['warehouse_id'], 'fullName' => $nombre]
                    + (empty($m['variation_id']) ? [] : ['variation_id' => (int) $m['variation_id']])
                    + ['warehouse_white_brand' => false];
            }

            $seleccion[] = $base + [
                'quantity' => (string) (int) $m['quantity'],
                'type_movement' => $m['type_movement'],
                'concept' => $m['concept'],
            ];
        }

        // Formato copiado de una edición real en la web (18-nov-2026):
        //  - simple: `warehouselected` es una lista de bodegas y `variations` va vacío;
        //  - variable: las variaciones van completas y `warehouselected` es una
        //    lista POR VARIACIÓN ([[{…variation_id…}], …]). Con la lista plana el
        //    API responde «Trying to access array offset on value of type int».
        if ($variable) {
            $seleccion = collect($seleccion)->groupBy('variation_id')->map(fn ($g) => $g->values()->all())->values()->all();
        }

        $body = array_merge(self::cuerpoEdicion($p), [
            'user_id' => $this->auth->usuarioId() ?? (int) $p['user_id'],
        ]) + [
            'variations' => $variable ? self::variacionesEdicion($p) : [],
            'warehouselected' => $seleccion,
            'warehouse_previus' => null,
            'controlChangesPhotos' => [],
        ];

        return $this->legacyJson($this->legacy()->put("products/{$productoId}", $body), "mover el stock del producto {$productoId}");
    }

    /**
     * Campos que la web de Dropi reenvía al guardar un producto
     * (ProductsService.update), copiados del producto releído.
     */
    public static function cuerpoEdicion(array $p): array
    {
        $atributos = array_map(fn (array $a) => [
            'id' => $a['id'] ?? null,
            'product_id' => $a['product_id'] ?? null,
            'description' => $a['description'] ?? '',
            'isVariation' => $a['isVariation'] ?? null, // la web lo reenvía tal cual (suele ser null)
            'deleted_at' => null,
            'values' => array_map(fn (array $v) => [
                'id' => $v['id'] ?? null,
                'value' => $v['value'] ?? '',
                'attribute_id' => $v['attribute_id'] ?? null,
                'attribute_name' => $v['attribute_name'] ?? '',
                'deleted_at' => null,
            ], $a['values'] ?? []),
        ], $p['attributes'] ?? []);

        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'description' => $p['description'] ?? '',
            'dropi_app_description' => $p['dropi_app_description'] ?? null,
            'type' => $p['type'],
            'sale_price' => (float) ($p['sale_price'] ?? 0),
            'suggested_price' => (float) ($p['suggested_price'] ?? 0),
            'user_id' => (int) $p['user_id'],
            'privated_product' => (bool) ($p['privated_product'] ?? false),
            'active' => (bool) ($p['active'] ?? true),
            'sku' => $p['sku'] ?? null,
            'weight' => (float) ($p['weight'] ?? 0),
            'length' => (float) ($p['length'] ?? 0),
            'width' => (float) ($p['width'] ?? 0),
            'height' => (float) ($p['height'] ?? 0),
            'attribute' => $atributos,
            'categories' => $p['categories'] ?? [],
            'add_stock_in_return' => (bool) ($p['add_stock_in_return'] ?? false),
            'private_product_inventories' => $p['private_product_inventories'] ?? [],
            'user_profit_percentage_product' => $p['user_profit_percentage_product'] ?? [],
            'is_warehouse_white_brand' => (bool) ($p['is_warehouse_white_brand'] ?? false),
            'name_in_guide' => $p['name_in_guide'] ?? null,
            'shipping_method' => $p['shipping_method'] ?? null,
            'bar_code' => $p['bar_code'] ?? null,
        ]
            + (isset($p['dropi_percent_product_supplier']) ? ['dropi_percent_product_supplier' => $p['dropi_percent_product_supplier']] : [])
            + (isset($p['dropi_product_supplier_increment_fixed']) ? ['dropi_product_supplier_increment_fixed' => $p['dropi_product_supplier_increment_fixed']] : []);
    }

    /** Las cuatro garantías que la web exige al crear (ProductForm.warranties). */
    public const GARANTIAS = ['Incomplete order', 'Malfunction', 'Broken product', 'Different order'];

    /**
     * Crea un producto en Dropi con el mismo cuerpo que la pantalla «Crear
     * producto» (POST /products, capturado el 18-nov-2026) y luego sus
     * garantías (POST productwarranty/create), que la web manda aparte. El
     * stock NO va aquí: la web lo carga después con movimientos (moverStock).
     *
     * @param  array{nombre:string, descripcion?:?string, sku?:?string, privado?:bool,
     *               peso:float, largo:float, ancho:float, alto:float,
     *               categorias:array<int, array{id:int, name:string}>,
     *               precio?:?float, sugerido?:?float,
     *               atributo?:?string,
     *               variaciones?:array<int, array{valor:string, sku?:?string, precio:float, sugerido:float}>,
     *               garantia_dias?:int, garantia_texto:string}  $d
     * @return array{id:int, variaciones: array<string, int>}  variaciones: valor → id de variación
     */
    public function crearProducto(array $d): array
    {
        $variable = ! empty($d['variaciones']);
        $atributo = mb_strtoupper(trim((string) ($d['atributo'] ?? 'COLOR')));

        $body = [
            'user_id' => $this->auth->usuarioId(),
            'id' => null,
            'name' => $d['nombre'],
            'description' => (string) ($d['descripcion'] ?? ''),
            'type' => $variable ? 'VARIABLE' : 'SIMPLE',
            'sale_price' => $variable ? null : (float) $d['precio'],
            'suggested_price' => $variable ? null : (float) $d['sugerido'],
            'privated_product' => (bool) ($d['privado'] ?? false),
            'active' => false,
            'sku' => $d['sku'] ?? '',
            'weight' => (float) $d['peso'],
            'length' => (float) $d['largo'],
            'width' => (float) $d['ancho'],
            'height' => (float) $d['alto'],
            'attribute' => $variable ? [[
                'id' => 0, 'product_id' => 0, 'description' => $atributo, 'isVariation' => true, 'deleted_at' => null,
                'values' => array_map(fn ($v) => [
                    'id' => 0, 'value' => $v['valor'], 'attribute_id' => 0, 'attribute_name' => $atributo, 'deleted_at' => null,
                ], $d['variaciones']),
            ]] : [],
            'variations' => $variable ? array_map(fn ($v) => [
                'id' => 0,
                'sale_price' => (float) $v['precio'],
                'suggested_price' => (float) $v['sugerido'],
                'product_id' => 0,
                'stock' => '',
                'sku' => $v['sku'] ?? '',
                'attribute_values' => [['id' => $v['valor'], 'value' => $v['valor'], 'attribute_name' => $atributo, 'deleted_at' => null]],
                'deleted_at' => null,
            ], $d['variaciones']) : [],
            'categories' => $d['categorias'],
            'dropi_app_description' => '',
            'add_stock_in_return' => false,
            'private_product_inventories' => [],
            'user_profit_percentage_product' => [],
            'is_warehouse_white_brand' => false,
            'warehouselected' => [],
            'name_in_guide' => '',
            'shipping_method' => null,
            'bar_code' => null,
        ];

        $resp = $this->legacyJson($this->legacy()->post('products', $body), "crear el producto «{$d['nombre']}»");
        $id = (int) ($resp['objects']['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException("Dropi dijo que creó «{$d['nombre']}» pero no devolvió el id.");
        }

        $dias = (int) ($d['garantia_dias'] ?? 10);
        $this->legacyJson($this->legacy()->post('productwarranty/create', [
            'id' => $id,
            'warranties' => array_map(fn ($n) => ['name' => $n, 'time' => 'DIAS', 'quantity' => $dias, 'description' => $d['garantia_texto']], self::GARANTIAS),
        ]), "poner las garantías al producto {$id}");

        // La respuesta del POST no trae los valores de cada variación: se relee.
        $ids = [];
        foreach ($variable ? ($this->producto($id)['variations'] ?? []) : [] as $v) {
            $valor = $v['attribute_values'][0]['value'] ?? null;
            if ($valor !== null) {
                $ids[$valor] = (int) $v['id'];
            }
        }

        return ['id' => $id, 'variaciones' => $ids];
    }

    /** Variaciones sin cambios, en el formato de ProductsService.cargarVariations. */
    public static function variacionesEdicion(array $p): array
    {
        return array_map(fn (array $v) => [
            'id' => $v['id'],
            'sale_price' => (float) ($v['sale_price'] ?? 0),
            'suggested_price' => (float) ($v['suggested_price'] ?? 0),
            'product_id' => $v['product_id'] ?? $p['id'],
            'stock' => (int) ($v['stock'] ?? 0),
            'sku' => $v['sku'] ?? '',
            'deleted_at' => $v['deleted_at'] ?? null,
            'attribute_values' => array_map(fn (array $a) => [
                'id' => $a['id'] ?? null,
                'value' => $a['value'] ?? '',
                'attribute_id' => $a['attribute_id'] ?? null,
                'attribute_name' => $a['attribute_name'] ?? '',
                'deleted_at' => $a['deleted_at'] ?? null,
            ], $v['attribute_values'] ?? []),
        ], $p['variations'] ?? []);
    }

    /**
     * Petición al API clásico de Dropi (escrituras, etiquetas, wallet) con la
     * sesión de la cuenta. El driver `api` lo hereda tal cual para lo que la
     * API de integraciones no expone.
     */
    protected function legacy(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->auth->requestLegacy();
    }

    /** Respuesta del API clásico: {isSuccess, message, objects}. Lanza con el mensaje de Dropi. */
    protected function legacyJson(\Illuminate\Http\Client\Response $resp, string $que): array
    {
        $body = $resp->json() ?? [];
        if (! $resp->successful() || ! ($body['isSuccess'] ?? false)) {
            throw new \RuntimeException("Dropi no dejó {$que}: " . ($body['message'] ?? $body['error'] ?? ('HTTP ' . $resp->status())));
        }

        return $body;
    }

    /** @return array{id:int, nombre:string, color:?string} */
    protected static function normalizarEtiqueta(array $t): array
    {
        return [
            'id' => (int) ($t['id'] ?? $t['tag_id'] ?? 0),
            'nombre' => (string) ($t['tag_name'] ?? $t['name'] ?? ''),
            'color' => $t['color'] ?? $t['tag_color'] ?? null,
        ];
    }

    public function pedidosDesde(CarbonImmutable $desde): Collection
    {
        $filtros = [
            'orderBy' => 'id', 'orderDirection' => 'desc', 'filter_date_by' => 'Order Date',
            'from' => $desde->format('Y-m-d'), 'until' => now()->format('Y-m-d'),
        ];
        $mapper = app(MapearPedidoDropiWeb::class);
        $pedidos = collect();
        for ($p = 0; $p < 50; $p++) {
            $objetos = $this->pedidosCrudos($filtros, $p, 100)['objects'];
            foreach ($objetos as $o) {
                $pedidos->push($mapper->desdeDetalle($this->pedidoDetalle((int) $o['id'])));
            }
            if (count($objetos) < 100) {
                break;
            }
        }

        return $pedidos;
    }

    public function consultarGuia(string $guia): ?PedidoDropiDTO
    {
        $o = collect($this->pedidosCrudos(['textToSearch' => $guia], 0, 5)['objects'])
            ->first(fn ($o) => strcasecmp((string) ($o['shipping_guide'] ?? ''), $guia) === 0);

        return $o ? app(MapearPedidoDropiWeb::class)->desdeDetalle($this->pedidoDetalle((int) $o['id'])) : null;
    }

    /**
     * Una página cruda del historial de la wallet (GET /historywallet de la API
     * legacy, lo mismo que la pantalla «Historial de cartera» de Dropi). La
     * cuenta de Great Baby solo lo ve desde que Dropi le dio el rol nuevo.
     *
     * @param  array<string, mixed>  $filtros  from, until (AAAA-MM-DD), type, textToSearch…
     */
    public function walletCrudo(array $filtros = [], int $inicio = 0, int $cantidad = 50): array
    {
        $resp = $this->legacy()->get('historywallet', array_filter([
            'orderBy' => 'id',
            'orderDirection' => 'desc',
            'result_number' => $cantidad,
            'start' => $inicio,
            'textToSearch' => '',
        ] + $filtros, fn ($v) => $v !== null));

        return $this->legacyJson($resp, 'leer el historial de la wallet');
    }

    /** Catálogo de tipos de movimiento de la wallet, tal como lo usa la web de Dropi. */
    public function tiposWallet(): array
    {
        return $this->legacyJson($this->legacy()->get('wallet/consultconstantshistorywallets'), 'leer los tipos de la wallet');
    }

    /**
     * Movimientos de la wallet desde una fecha, del más viejo al más nuevo.
     *
     * Dropi IGNORA los filtros from/until/type de /historywallet (probado: con o
     * sin ellos devuelve el total, 55 155 al 28-sep-2026), así que se pagina
     * hacia atrás —el historial viene del más nuevo al más viejo— y se corta al
     * pasar la fecha. ~1,3 s por página de 200.
     *
     * @return Collection<int, PagoWalletDTO>
     */
    public function walletDesde(CarbonImmutable $desde): Collection
    {
        $porPagina = 200;
        $movimientos = [];

        for ($pagina = 0; $pagina < 2000; $pagina++) {
            $filas = $this->walletCrudo([], $pagina * $porPagina, $porPagina)['objects'] ?? [];

            foreach ($filas as $fila) {
                $dto = MapearMovimientoWalletDropi::map($fila);
                if ($dto->fecha->lt($desde)) {
                    return collect(array_reverse($movimientos));
                }
                $movimientos[] = $dto;
            }

            if (count($filas) < $porPagina) {
                break;
            }
        }

        return collect(array_reverse($movimientos));
    }
}
