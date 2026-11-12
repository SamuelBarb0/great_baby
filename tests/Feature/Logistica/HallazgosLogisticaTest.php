<?php

/**
 * Los cuatro defectos que salieron el 21-oct-2026 armando el manual de
 * logística, todos comprobados en el navegador con el usuario real del
 * bodeguero.
 *
 * Tienen un aire de familia: **el sistema sabía algo y la pantalla no lo
 * decía**. Una cola vacía que no explicaba por qué, una lupa que devolvía
 * nada en vez de decir que no tenía permiso, un kardex que respondía
 * «no encontrado» sobre mercancía que estaba en bodega, y un botón
 * «Cancelar» que llevaba derecho a un 403.
 *
 * El daño nunca fue estético. El bodeguero de Bogotá se quedó esperando
 * pedidos que no le iban a llegar, y nadie lo supo hasta que se revisó a
 * mano un mes después.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(rolSembrado('Aracely'));

    // Jorge administra la bodega de Bogotá: crea órdenes de compra y recibe.
    $this->bodeguero = User::factory()->create(['name' => 'Jorge AdminBodega']);
    $this->bodeguero->assignRole(rolSembrado('AdminBodega'));

    // Quien alista. No administra nada.
    $this->alistador = User::factory()->create(['name' => 'Camila Alistadora']);
    $this->alistador->assignRole(rolSembrado('Alistador'));

    $this->contador = User::factory()->create(['name' => 'Silvia']);
    $this->contador->assignRole(rolSembrado('Contador'));

    // Un vendedor no tiene nada que ver con bodega ni con compras.
    $this->vendedor = User::factory()->create(['name' => 'Vendedor']);
    $this->vendedor->assignRole(rolSembrado('Vendedor'));
});

/**
 * Crea el rol con los permisos que le da la MATRIZ.
 *
 * Con `RefreshDatabase` la tabla de roles nace vacía y `PermisosSeeder` sólo
 * reparte entre los que ya existen; en la base de verdad ya están.
 */
function rolSembrado(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->flatMap(fn ($s) => Permisos::permisosDe($s))->all());

    return $rol;
}

// ── 1 · La cola vacía que no decía por qué ───────────────────────────────────

it('le dice al alistador que la cola está vacía por falta de bodegas, no de trabajo', function () {
    /*
     * El filtro es fail-closed a propósito. Lo que estaba mal es que una cola
     * vacía POR CONFIGURACIÓN se veía idéntica a un día sin pedidos.
     */
    expect($this->alistador->bodegasAsignadasIds())->toBe([]);

    $this->actingAs($this->alistador)->get('/app/logistica/cola-jorge')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('sin_bodegas', true)
            // Él no puede arreglarlo: asignar responsables es de gerencia.
            ->where('puede_asignarse_bodegas', false));
});

it('no le muestra el aviso a quien sí ve todas las bodegas', function () {
    $this->actingAs($this->aracely)->get('/app/logistica/cola-jorge')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('sin_bodegas', false));
});

it('no avisa nada cuando el bodeguero sí tiene su bodega', function () {
    $bodega = InventarioUbicacion::create([
        'codigo' => 'BOG-1', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'responsable_user_id' => $this->bodeguero->id,
    ]);

    expect($this->bodeguero->bodegasAsignadasIds())->toContain($bodega->id);

    $this->actingAs($this->bodeguero)->get('/app/logistica/cola-jorge')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('sin_bodegas', false));
});

// ── 2 · La lupa del catálogo ─────────────────────────────────────────────────

it('deja buscar productos a quien puede armar una orden de compra', function () {
    /*
     * `buscar()` no es una pantalla del catálogo: es el buscador de tres
     * formularios. Guardarla tras el permiso `productos` dejaba al bodeguero
     * entrando a Nueva OC y recibiendo 403 al buscar lo que iba a pedir, sin
     * un solo mensaje: la lupa simplemente no devolvía nada.
     */
    unProducto();

    $this->actingAs($this->bodeguero)->getJson('/app/catalogo/productos-buscar?q=body')
        ->assertOk()
        ->assertJsonCount(1);
});

it('no le manda el precio de venta a bodega', function () {
    /*
     * Abrirle la puerta al buscador no tiene por qué abrirle la lista de
     * precios: está armando una orden de COMPRA, ahí el precio de venta no
     * se usa.
     */
    unProducto();

    $deBodega = $this->actingAs($this->bodeguero)
        ->getJson('/app/catalogo/productos-buscar?q=body')->json();

    expect($deBodega[0]['precio'])->toBeNull()
        ->and($deBodega[0]['referencia'])->toBe('AND2512-79/154');
});

it('sigue cerrada para quien no es catálogo, bodega ni contabilidad', function () {
    unProducto();

    $this->actingAs($this->vendedor)->getJson('/app/catalogo/productos-buscar?q=body')
        ->assertForbidden();
});

// ── 4 · El listado de compras ────────────────────────────────────────────────

it('abre el listado de compras a quien crea las órdenes', function () {
    /*
     * Era incoherente: bodega CREA órdenes de compra y hace las recepciones,
     * pero no podía ver la lista de lo que ella misma creó. Y el botón
     * «Cancelar» de Nueva OC apunta justo ahí.
     */
    $this->actingAs($this->bodeguero)->get('/app/compras')->assertOk();
    $this->actingAs($this->contador)->get('/app/compras')->assertOk();
    $this->actingAs($this->vendedor)->get('/app/compras')->assertForbidden();
});

it('el menú no ofrece lo que la puerta niega', function () {
    // Si el menú y la puerta no coinciden, el usuario llega a un 403 por un
    // enlace que el sistema mismo le mostró.
    $menu = App\Auth\MenuApp::permisos($this->bodeguero);

    expect($menu['compras_lista'])->toBeTrue()
        ->and($menu['compras_gestion'])->toBeTrue();
});

// ── 3 · El kardex que no veía lo recién recibido ─────────────────────────────

/** Un producto CON desglose, con su entrada asentada a nivel producto. */
function unProductoRecibidoSinRepartir(): array
{
    $p = Producto::create([
        'referencia' => 'AND2512-79/154', 'nombre' => 'Body león manga larga',
        'activo' => true, 'desglose_stock' => true,
    ]);

    $v = ProductoVariante::create([
        'producto_id' => $p->id, 'codigo_barras' => 'AND2512-79/154-02LEO-12M',
        'color_nombre' => 'azul', 'talla' => '12M',
    ]);

    $ubi = InventarioUbicacion::create([
        'codigo' => 'BOG-DEMO', 'nombre' => 'Bodega Demo Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // Así la asienta la recepción: la orden de compra enlaza al PRODUCTO, no
    // a la variante, así que `variante_id` queda en null aunque el producto
    // tenga variantes.
    InventarioMovimiento::create([
        'producto_id' => $p->id, 'variante_id' => null, 'ubicacion_id' => $ubi->id,
        'tipo' => 'entrada_compra', 'cantidad' => 20, 'costo_unit' => 12000,
    ]);

    return [$p, $v];
}

function unProducto(): Producto
{
    return Producto::create([
        'referencia' => 'AND2512-79/154', 'nombre' => 'Body león manga larga',
        'activo' => true, 'desglose_stock' => false,
    ]);
}

it('encuentra por referencia lo que entró a nivel producto', function () {
    /*
     * El defecto literal: 20 unidades en bodega, su entrada registrada, y
     * buscar la referencia respondía «Variante no encontrada». La búsqueda
     * exigía `desglose_stock = false` y este producto sí se desglosa.
     */
    [$p] = unProductoRecibidoSinRepartir();

    $this->actingAs($this->aracely)
        ->get('/app/inventario/kardex?codigo='.urlencode($p->referencia))
        ->assertOk()
        ->assertInertia(fn ($x) => $x
            ->where('variante.referencia', 'AND2512-79/154')
            ->where('variante.con_desglose', true)
            // Y lo dice sin disimular: están sin repartir por variante.
            ->where('variante.detalle', 'Movimientos a nivel producto · todavía sin repartir por variante')
            ->has('movimientos', 1)
            ->where('saldoTotal', 20));
});

it('avisa en la variante que lo recibido quedó a nivel producto', function () {
    /*
     * La otra mitad. Buscando por el código de barras la variante SÍ aparece,
     * pero su kardex sale vacío, y un kardex vacío se lee como «nunca se
     * movió».
     */
    [, $v] = unProductoRecibidoSinRepartir();

    $this->actingAs($this->aracely)
        ->get('/app/inventario/kardex?codigo='.urlencode($v->codigo_barras))
        ->assertOk()
        ->assertInertia(fn ($x) => $x
            ->has('movimientos', 0)
            ->where('sin_repartir.cuantos', 1)
            ->where('sin_repartir.referencia', 'AND2512-79/154'));
});

it('no avisa nada cuando la variante sí tiene sus movimientos', function () {
    [$p, $v] = unProductoRecibidoSinRepartir();

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'variante_id' => $v->id,
        'ubicacion_id' => InventarioUbicacion::first()->id,
        'tipo' => 'entrada_compra', 'cantidad' => 5, 'costo_unit' => 12000,
    ]);

    $this->actingAs($this->aracely)
        ->get('/app/inventario/kardex?codigo='.urlencode($v->codigo_barras))
        ->assertOk()
        ->assertInertia(fn ($x) => $x->has('movimientos', 1)->where('sin_repartir', null));
});

it('un código que no existe sigue diciendo que no existe', function () {
    $this->actingAs($this->aracely)
        ->get('/app/inventario/kardex?codigo=NO-EXISTE-123')
        ->assertOk()
        ->assertInertia(fn ($x) => $x->where('variante', null));
});

it('la orden de compra puede pedir una talla y un color concretos', function () {
    /*
     * El defecto: el buscador de la OC sólo devolvía productos padre.
     *
     * Se compraba el padre, la recepción heredaba `variante_id = null` y la
     * mercancía entraba como stock AGREGADO. Para un producto granular eso es
     * mercancía invendible: `Producto::stockEn()` bifurca por `desglose_stock`
     * y, si es granular, suma sólo variantes — nunca mira el saldo agregado.
     * Comprobado en producción: 18 unidades recibidas y las cuatro variantes
     * seguían diciendo «sin stock» en el carrito del vendedor.
     *
     * La bandera `variantes=1` es opcional a propósito: la factura manual y el
     * detalle de producto llaman al mismo endpoint y esperan la lista de padres.
     */
    $p = Producto::create([
        'referencia' => 'AND2512-79/154', 'nombre' => 'Body león manga larga',
        'activo' => true, 'desglose_stock' => true,
    ]);
    ProductoVariante::create([
        'producto_id' => $p->id, 'codigo_barras' => 'AND2512-79/154-02LEO-12M',
        'color_nombre' => 'azul', 'talla' => '12M',
    ]);
    ProductoVariante::create([
        'producto_id' => $p->id, 'codigo_barras' => 'AND2512-79/154-05LEO-6M',
        'color_nombre' => 'rosa', 'talla' => '6M',
    ]);

    // Sin la bandera, la forma de siempre: sólo el padre.
    $sinBandera = $this->actingAs($this->bodeguero)
        ->getJson('/app/catalogo/productos-buscar?q=body')->json();

    expect($sinBandera)->toHaveCount(1)
        ->and($sinBandera[0]['variante_id'])->toBeNull();

    // Con la bandera: el padre y sus dos presentaciones.
    $conBandera = $this->actingAs($this->bodeguero)
        ->getJson('/app/catalogo/productos-buscar?q=body&variantes=1')->json();

    expect($conBandera)->toHaveCount(3);

    $variantes = collect($conBandera)->whereNotNull('variante_id')->values();

    expect($variantes)->toHaveCount(2)
        // El `id` sigue siendo el PADRE: es el par (producto, variante) que
        // `RegistrarRecepcion` necesita para mover el kardex donde el motor de
        // stock sí lo va a contar.
        ->and($variantes[0]['id'])->toBe($p->id)
        ->and($variantes->pluck('nombre')->all())
            ->toContain('Body león manga larga · azul 12M')
            ->toContain('Body león manga larga · rosa 6M');
});
