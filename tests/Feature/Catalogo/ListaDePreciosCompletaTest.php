<?php

/**
 * Cargar una lista de precios entera, y no de a producto.
 *
 * Las listas existían desde el principio y se le podían asignar a un cliente,
 * pero los precios sólo se cargaban entrando a la ficha de cada producto. Con
 * ochenta variantes eso son ochenta pantallas, así que no lo hizo nadie: el
 * 2026-11-07, en producción, las cuatro listas de Great Baby figuraban «sin
 * precios cargados».
 *
 * Y una lista vacía no es un detalle cosmético. El panel del vendedor sólo
 * ofrece clientes con lista asignada, y el catálogo del pedido sólo trae lo
 * que tiene precio en ESA lista. Sin precios, el vendedor abre su panel, busca
 * un cliente y no le aparece nada —que es exactamente lo que pasó en vivo.
 *
 * Lo que se protege acá:
 *   · que se puedan guardar muchos precios de una sola vez, de los dos tipos
 *     (variantes de granulares y productos agregados);
 *   · que guardar una página NO borre lo que está en las otras, porque la
 *     pantalla está paginada y eso vaciaría la lista entera;
 *   · que calcular precios derivados NO guarde nada: un porcentaje mal
 *     tecleado cambia el precio de todo el catálogo;
 *   · que quien no es del equipo comercial no pueda tocar precios.
 */

use App\Models\User;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->mayorista = ListaPrecios::create(['codigo' => 'MAY-T', 'nombre' => 'Mayorista test', 'canal' => 'mayorista', 'activa' => true]);
    $this->distri = ListaPrecios::create(['codigo' => 'DIS-T', 'nombre' => 'Distribuidor test', 'canal' => 'distribuidor', 'activa' => true]);

    // Un granular (precio por variante) y un agregado (precio por producto).
    $this->granular = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'LP-GRAN', 'nombre' => 'Body manga larga',
        'desglose_stock' => true, 'activo' => true, 'precio_proveedor' => 10000,
    ]));

    $this->variante = ProductoVariante::create([
        'producto_id' => $this->granular->id,
        'color_nombre' => 'Azul', 'talla' => '6M', 'combo_key' => 'lp-azul-6m',
    ]);

    $this->agregado = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'LP-AGR', 'nombre' => 'Chupo anatómico',
        'desglose_stock' => false, 'activo' => true, 'stock_directo' => 10, 'precio_proveedor' => 4000,
    ]));

    Role::findOrCreate('Gerencia', 'web');
    $this->jefa = User::factory()->create();
    $this->jefa->assignRole('Gerencia');
});

/** Un precio ya cargado en una lista. */
function precioEnLista($lista, $variante, float $precio): PrecioVariante
{
    return PrecioVariante::create([
        'variante_id' => $variante->id, 'lista_id' => $lista->id,
        'precio' => $precio, 'vigente_desde' => now()->subDay()->toDateString(),
    ]);
}

it('muestra cuántos productos tiene tarifados cada lista', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    $r = $this->actingAs($this->jefa)->get('/app/catalogo/listas-precios');

    $r->assertOk();
    $listas = collect($r->viewData('page')['props']['listas']);

    expect($listas->firstWhere('id', $this->mayorista->id)['tarifados'])->toBe(1)
        ->and($listas->firstWhere('id', $this->distri->id)['tarifados'])->toBe(0);
});

it('cuenta los clientes que dependen de una lista vacía', function () {
    \App\Models\Contacto::create([
        'numero_documento' => '900111222', 'nombre_completo' => 'Pañalera Test',
        'es_cliente' => true, 'lista_precios_id' => $this->distri->id,
    ]);

    $r = $this->actingAs($this->jefa)->get('/app/catalogo/listas-precios');
    $fila = collect($r->viewData('page')['props']['listas'])->firstWhere('id', $this->distri->id);

    // Un cliente con lista vacía es un cliente que no puede comprar nada.
    expect($fila['clientes'])->toBe(1)
        ->and($fila['tarifados'])->toBe(0);
});

it('guarda precios de variantes y de productos agregados en una sola pasada', function () {
    $this->actingAs($this->jefa)
        ->put("/app/catalogo/listas-precios/{$this->mayorista->id}", ['precios' => [
            ['tipo' => 'variante', 'id' => $this->variante->id, 'precio' => 22500],
            ['tipo' => 'producto', 'id' => $this->agregado->id, 'precio' => 8900],
        ]])
        ->assertRedirect();

    expect((float) PrecioVariante::where('lista_id', $this->mayorista->id)->value('precio'))->toBe(22500.0)
        ->and((float) PrecioProducto::where('lista_id', $this->mayorista->id)->value('precio'))->toBe(8900.0);
});

it('una casilla vacía quita el precio: así se saca un producto de la lista', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    $this->actingAs($this->jefa)
        ->put("/app/catalogo/listas-precios/{$this->mayorista->id}", ['precios' => [
            ['tipo' => 'variante', 'id' => $this->variante->id, 'precio' => null],
        ]]);

    expect(PrecioVariante::where('lista_id', $this->mayorista->id)->count())->toBe(0);
});

it('guardar una página no borra lo que está en las otras', function () {
    // Dos productos en la lista; se guarda sólo uno, como haría la paginación.
    precioEnLista($this->mayorista, $this->variante, 22500);
    PrecioProducto::create([
        'producto_id' => $this->agregado->id, 'lista_id' => $this->mayorista->id,
        'precio' => 8900, 'vigente_desde' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($this->jefa)
        ->put("/app/catalogo/listas-precios/{$this->mayorista->id}", ['precios' => [
            ['tipo' => 'variante', 'id' => $this->variante->id, 'precio' => 23000],
        ]]);

    // El agregado, que no vino en la petición, sigue con su precio.
    expect((float) PrecioVariante::where('lista_id', $this->mayorista->id)->value('precio'))->toBe(23000.0)
        ->and((float) PrecioProducto::where('lista_id', $this->mayorista->id)->value('precio'))->toBe(8900.0);
});

it('deriva los precios de otra lista con un porcentaje', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'redondeo' => 100, 'solo_vacios' => false,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id]],
        ]);

    $r->assertOk();
    expect((float) $r->json('precios.0.precio'))->toBe(19800.0);
});

it('calcular no guarda nada: alguien tiene que mirar los números primero', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'ids' => [['tipo' => 'variante', 'id' => $this->variante->id]],
        ])->assertOk();

    // Un porcentaje mal tecleado cambia el precio de todo el catálogo.
    expect(PrecioVariante::where('lista_id', $this->distri->id)->count())->toBe(0);
});

it('deriva del costo del proveedor con un margen', function () {
    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'costo', 'porcentaje' => 80, 'redondeo' => 1, 'solo_vacios' => false,
            'ids' => [['tipo' => 'producto', 'id' => $this->agregado->id]],
        ]);

    // Costo 4.000 + 80% = 7.200.
    expect((float) $r->json('precios.0.precio'))->toBe(7200.0);
});

it('lo que no tiene base no se inventa: se cuenta y se avisa', function () {
    // El granular no tiene precio en Mayorista, así que no hay de dónde derivar.
    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'solo_vacios' => false,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id]],
        ]);

    expect($r->json('precios'))->toBe([])
        ->and($r->json('sin_base'))->toBe(1);
});

it('con «sólo los vacíos» no pisa un precio que alguien ya puso a mano', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);
    precioEnLista($this->distri, $this->variante, 15000);   // puesto a mano

    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'solo_vacios' => true,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id]],
        ]);

    expect($r->json('precios'))->toBe([]);
});

it('un precio de lista no termina en 437: se redondea a lo que se pida', function () {
    precioEnLista($this->mayorista, $this->variante, 22437);

    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => 0, 'redondeo' => 1000, 'solo_vacios' => false,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id]],
        ]);

    expect((float) $r->json('precios.0.precio'))->toBe(22000.0);
});

it('quien no es del equipo comercial no toca precios', function () {
    $cualquiera = User::factory()->create();

    $this->actingAs($cualquiera)
        ->get('/app/catalogo/listas-precios')
        ->assertForbidden();

    $this->actingAs($cualquiera)
        ->put("/app/catalogo/listas-precios/{$this->mayorista->id}", ['precios' => []])
        ->assertForbidden();
});

it('el editor separa lo que ya tiene precio de lo que falta', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    $r = $this->actingAs($this->jefa)
        ->get("/app/catalogo/listas-precios/{$this->mayorista->id}?solo=sin_precio");

    $items = collect($r->viewData('page')['props']['items']);

    // Sólo el agregado, que es el que no tiene precio.
    expect($items)->toHaveCount(1)
        ->and($items->first()['tipo'])->toBe('producto')
        ->and($items->first()['precio'])->toBeNull();
});

it('«sólo los que no tienen precio» respeta lo que ya está tecleado en la casilla', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    /*
     * La persona tecleó 21.000 a mano y todavía no guardó. Antes el derivado
     * comparaba contra lo GUARDADO —donde no había nada—, así que la fila
     * contaba como vacía y le pisaba el precio: justo el trabajo hecho a pulso
     * era el que se perdía.
     */
    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'redondeo' => 100, 'solo_vacios' => true,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id, 'actual' => 21000]],
        ]);

    $r->assertOk();
    expect($r->json('precios'))->toBe([]);
});

it('la casilla vacía sí se rellena', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);

    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'redondeo' => 100, 'solo_vacios' => true,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id, 'actual' => 0]],
        ]);

    expect((float) $r->json('precios.0.precio'))->toBe(19800.0);
});

it('sin la casilla en la petición se cae a lo guardado, como antes', function () {
    precioEnLista($this->mayorista, $this->variante, 22500);
    precioEnLista($this->distri, $this->variante, 20000);

    $r = $this->actingAs($this->jefa)
        ->postJson("/app/catalogo/listas-precios/{$this->distri->id}/previsualizar", [
            'base' => 'lista', 'lista_origen_id' => $this->mayorista->id,
            'porcentaje' => -12, 'redondeo' => 100, 'solo_vacios' => true,
            'ids' => [['tipo' => 'variante', 'id' => $this->variante->id]],
        ]);

    expect($r->json('precios'))->toBe([]);
});
