<?php

/**
 * Una hoja por zona, y que la suma cuadre.
 *
 * El procedimiento del área dice «picking por zonas» y la hoja lo decía nada
 * más en una casilla: se imprimía UNA lista con los renglones de las tres
 * zonas mezclados, rotulada con la zona que más aportaba. Un pedido compartido
 * terminaba alistado de una de dos maneras, las dos malas: una persona lo
 * caminaba entero —justo lo que el reparto por zonas existe para evitar— o se
 * fotocopiaba la hoja y cada quien tachaba a ojo lo que creía suyo.
 *
 * Lo que de verdad hay que cuidar al partir es la ARITMÉTICA. Son dos errores
 * opuestos y los dos terminan en el cliente:
 *
 *   • duplicar: si el renglón se parte por «dónde hay stock» en vez de por
 *     «cuánto sale de acá», la misma línea de 50 sale completa en dos
 *     secciones y entre las dos personas recogen 100;
 *   • perder: si las unidades que ningún rack cubre —exhibición, racks sin
 *     zona, mercancía que no está— se caen de las secciones, la hoja partida
 *     suma menos que el pedido y nadie lo nota hasta que falta en la caja.
 *
 * Por eso casi todos estos casos terminan comparando la suma de las secciones
 * contra el total del pedido.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->jefa = User::factory()->create();
    $this->jefa->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->cliente = Contacto::create([
        'numero_documento' => '901555444', 'nombre_completo' => 'Pañalera Partida SAS',
        'es_cliente' => true, 'ciudad' => 'Bucaramanga',
    ]);

    // Dos zonas con un rack cada una, y el rack 9 fuera del reparto.
    Reglas::set('logistica.zonas_bodega', json_encode(['A' => ['1'], 'B' => ['2']]));
    Reglas::limpiarRequestCache();
});

function posicionDeZona(string $codigo, ?string $rack, ?int $responsable = null, bool $exhibicion = false): InventarioUbicacion
{
    /*
     * «Respaldo» no es una categoría: es pertenecer a la oficina que el área
     * marcó como respaldo de surtido, que se mueve desde la pantalla. Por eso
     * la vitrina del test se arma así y no con un enum.
     */
    $oficina = $exhibicion
        ? \App\Modules\Inventario\Models\Oficina::firstOrCreate(
            ['codigo' => 'EXH'],
            ['nombre' => 'Exhibición', 'activa' => true, 'es_respaldo_surtido' => true, 'orden' => 90]
        )
        : null;

    return InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $codigo,
        'categoria' => CategoriaUbicacion::Venta,
        'activa' => true, 'pasillo' => $rack, 'responsable_user_id' => $responsable,
        'oficina_id' => $oficina?->id,
    ]);
}

/** Un producto con stock repartido: [ubicacion_id => unidades]. */
function productoEnVariasZonas(array $saldos): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-PART-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => 9000,
    ]));

    foreach ($saldos as $ubicacionId => $cantidad) {
        InventarioMovimiento::create([
            'producto_id' => $p->id, 'ubicacion_id' => $ubicacionId,
            'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 9000,
        ]);
    }

    return $p;
}

/** Un pedido con una línea por producto: [producto => cantidad]. */
function pedidoParaPartir($test, array $lineas): PedidoCliente
{
    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $test->cliente->id, 'estado' => 'facturado',
        'subtotal' => 10000, 'total' => 10000,
    ]);

    foreach ($lineas as [$producto, $cantidad]) {
        PedidoClienteItem::create([
            'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
            'cantidad' => $cantidad, 'precio_unitario' => 10000,
            'subtotal' => 10000, 'total' => 10000,
            'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
        ]);
    }

    return $pedido;
}

function hojaPartida($test, PedidoCliente $pedido, array $query = []): array
{
    return $test->actingAs($test->jefa)
        ->get("/app/logistica/cola-jorge/{$pedido->id}/picking".($query ? '?'.http_build_query($query) : ''))
        ->assertOk()->viewData('page')['props'];
}

/** Lo que de verdad importa: que las secciones sumen el pedido. */
function unidadesDeLasSecciones(array $props): int
{
    return array_sum(array_column($props['secciones'], 'unidades'));
}

it('parte el pedido en una sección por zona', function () {
    $zonaA = posicionDeZona('R1-S1-N1', '1');
    $zonaB = posicionDeZona('R2-S1-N1', '2');

    $deA = productoEnVariasZonas([$zonaA->id => 50]);
    $deB = productoEnVariasZonas([$zonaB->id => 30]);

    $pedido = pedidoParaPartir($this, [[$deA, 10], [$deB, 4]]);
    $props = hojaPartida($this, $pedido);

    expect($props['secciones'])->toHaveCount(2)
        ->and(array_column($props['secciones'], 'zona'))->toBe(['A', 'B'])
        // La que más aporta va primero: es la misma regla con la que ya se
        // rotulaba la hoja entera.
        ->and($props['secciones'][0]['unidades'])->toBe(10)
        ->and($props['secciones'][1]['unidades'])->toBe(4)
        // Y la lista plana se queda intacta: es el respaldo y lo que miran los
        // tests de siempre.
        ->and($props['items'])->toHaveCount(2)
        ->and(unidadesDeLasSecciones($props))->toBe(14);
});

it('un renglón que sale de dos zonas se parte, no se duplica', function () {
    /*
     * El error caro. La línea pide 50, el rack de la zona B tiene 38 y el de la
     * A tiene 12: hay que pasar por los dos. Partir por «dónde hay stock» en
     * vez de por «cuánto sale de acá» pondría 50 en cada sección, y entre las
     * dos personas recogerían 100 unidades de un pedido de 50.
     */
    $zonaA = posicionDeZona('R1-S2-N1', '1');
    $zonaB = posicionDeZona('R2-S2-N1', '2');

    $repartido = productoEnVariasZonas([$zonaA->id => 12, $zonaB->id => 38]);

    $pedido = pedidoParaPartir($this, [[$repartido, 50]]);
    $props = hojaPartida($this, $pedido);

    $porZona = collect($props['secciones'])->keyBy('zona');

    // La zona B va primero porque aporta 38 contra 12. Lo que no puede pasar es
    // que las dos digan 50.
    expect($porZona->get('B')['items'][0]['cantidad'])->toBe(38)
        ->and($porZona->get('A')['items'][0]['cantidad'])->toBe(12)
        ->and(unidadesDeLasSecciones($props))->toBe(50);
});

it('cada sección lleva el responsable de SU zona', function () {
    $deA = User::factory()->create(['name' => 'Marcela de la A']);
    $deB = User::factory()->create(['name' => 'Rubén de la B']);

    $zonaA = posicionDeZona('R1-S3-N1', '1', $deA->id);
    $zonaB = posicionDeZona('R2-S3-N1', '2', $deB->id);

    $pedido = pedidoParaPartir($this, [
        [productoEnVariasZonas([$zonaA->id => 20]), 5],
        [productoEnVariasZonas([$zonaB->id => 20]), 5],
    ]);

    $props = hojaPartida($this, $pedido);
    $porZona = collect($props['secciones'])->keyBy('zona');

    // Imprimir el responsable de la zona principal en las dos bandas sería peor
    // que no imprimir ninguno: manda a buscar a quien no tiene esa mercancía.
    expect($porZona->get('A')['responsable'])->toBe('Marcela de la A')
        ->and($porZona->get('B')['responsable'])->toBe('Rubén de la B');
});

it('lo que ningún rack cubre no se cae de la hoja', function () {
    /*
     * Pide 20 y en los racks con zona sólo hay 6. Las otras 14 tienen que
     * aparecer en alguna parte: si se pierden, la hoja partida suma 6 sobre un
     * pedido de 20 y nadie lo nota hasta que falta mercancía en la caja.
     */
    $zonaA = posicionDeZona('R1-S4-N1', '1');
    $vitrina = posicionDeZona('EXH-01', null, exhibicion: true);

    $escaso = productoEnVariasZonas([$zonaA->id => 6, $vitrina->id => 40]);

    $pedido = pedidoParaPartir($this, [[$escaso, 20]]);

    /*
     * La vitrina sólo cuenta como respaldo cuando el pedido tiene bodega de
     * origen: sin ella la hoja no restringe el alcance —prefiere imprimir
     * dónde está la mercancía— y Exhibición entra como una ubicación más.
     */
    $pedido->forceFill(['ubicacion_origen_id' => $zonaA->id])->save();

    $props = hojaPartida($this, $pedido);

    $sinZona = collect($props['secciones'])->firstWhere('zona', null);

    expect($sinZona)->not->toBeNull()
        ->and($sinZona['unidades'])->toBe(14)
        // Y dice POR QUÉ: no es lo mismo que esté en la vitrina —hay que
        // traerlo con traslado— a que no esté en la bodega.
        ->and($sinZona['items'][0]['motivo'])->toBe('exhibicion')
        ->and(unidadesDeLasSecciones($props))->toBe(20);
});

it('un rack que nadie repartió va al final, no a una zona inventada', function () {
    // El rack 9 no está en la regla de zonas.
    $huerfano = posicionDeZona('R9-S1-N1', '9');

    $pedido = pedidoParaPartir($this, [[productoEnVariasZonas([$huerfano->id => 30]), 8]]);
    $props = hojaPartida($this, $pedido);

    $sinZona = collect($props['secciones'])->firstWhere('zona', null);

    expect($props['secciones'])->toHaveCount(1)
        ->and($sinZona['unidades'])->toBe(8)
        ->and($sinZona['items'][0]['motivo'])->toBe('sin_zona')
        ->and(unidadesDeLasSecciones($props))->toBe(8);
});

it('un pedido de una sola zona no se parte', function () {
    $zonaA = posicionDeZona('R1-S5-N1', '1');

    $pedido = pedidoParaPartir($this, [
        [productoEnVariasZonas([$zonaA->id => 50]), 6],
        [productoEnVariasZonas([$zonaA->id => 50]), 4],
    ]);

    $props = hojaPartida($this, $pedido);

    // Una sola sección: la hoja del pedido normal no cambia en nada.
    expect($props['secciones'])->toHaveCount(1)
        ->and($props['secciones'][0]['zona'])->toBe('A')
        ->and($props['secciones'][0]['unidades'])->toBe(10)
        ->and($props['zona_filtrada'])->toBeNull();
});

it('reimprime una sola zona, y no deja pedir una que el pedido no toca', function () {
    $zonaA = posicionDeZona('R1-S6-N1', '1');
    $zonaB = posicionDeZona('R2-S6-N1', '2');

    $pedido = pedidoParaPartir($this, [
        [productoEnVariasZonas([$zonaA->id => 50]), 9],
        [productoEnVariasZonas([$zonaB->id => 50]), 3],
    ]);

    $props = hojaPartida($this, $pedido, ['zona' => 'B']);

    expect($props['secciones'])->toHaveCount(1)
        ->and($props['secciones'][0]['zona'])->toBe('B')
        ->and($props['zona_filtrada'])->toBe('B')
        // La lista plana no se filtra: sigue siendo la del pedido entero, para
        // que la hoja pueda imprimir «3 de 12 unidades del pedido».
        ->and($props['items'])->toHaveCount(2);

    /*
     * Y una zona que este pedido no toca se corta con 422, no con una hoja
     * vacía: una hoja con el membrete completo y cero renglones hace creer que
     * no hay nada que recoger.
     */
    $this->actingAs($this->jefa)
        ->get("/app/logistica/cola-jorge/{$pedido->id}/picking?zona=Z")
        ->assertStatus(422);
});

it('en un segundo viaje las secciones suman lo que FALTA, no el pedido entero', function () {
    /*
     * La hoja del segundo viaje ya pedía sólo lo pendiente. Al partirla hay que
     * cuidar que el reparto se haga sobre ese número y no sobre la cantidad
     * original: si no, las secciones mandan a recoger las 10 de la factura
     * cuando ya salieron 6.
     */
    $zonaA = posicionDeZona('R1-S7-N1', '1');

    $producto = productoEnVariasZonas([$zonaA->id => 50]);
    $pedido = pedidoParaPartir($this, [[$producto, 10]]);

    $pedido->items()->update(['cantidad_despachada' => 6]);
    $pedido->forceFill(['despacho_parcial' => true, 'resto_pendiente' => true])->save();

    $props = hojaPartida($this, $pedido);

    expect(unidadesDeLasSecciones($props))->toBe(4)
        ->and($props['secciones'][0]['items'][0]['cantidad'])->toBe(4)
        // Y el renglón sigue explicando de dónde sale ese 4.
        ->and($props['secciones'][0]['items'][0]['cantidad_pedida'])->toBe(10)
        ->and($props['secciones'][0]['items'][0]['ya_salio'])->toBe(6);
});

it('imprime la casilla donde se consolida', function () {
    /*
     * El ERP la asignaba desde la primera oleada y no se imprimía en ninguna
     * parte: quien terminaba su zona se quedaba con la mercancía en la mano
     * preguntando dónde dejarla. Con la hoja partida son dos o tres personas
     * llegando por separado.
     */
    $zonaA = posicionDeZona('R1-S8-N1', '1');

    $pedido = pedidoParaPartir($this, [[productoEnVariasZonas([$zonaA->id => 50]), 5]]);
    $pedido->forceFill(['casilla_consolidacion' => 'C2FB'])->save();

    expect(hojaPartida($this, $pedido)['pedido']['casilla'])->toBe('C2FB');
});

it('la hoja de un pedido ya despachado no manda a recoger nada', function () {
    /*
     * Reimprimir la hoja de un pedido que ya salió completo es común: alguien
     * quiere ver qué se recogió. Salía con el membrete entero, el aviso de
     * «segundo viaje… esta hoja es sólo de lo que falta» sobre un pedido al que
     * no le falta nada, y debajo una tabla VACÍA bajo el rótulo «sin ubicación
     * asignada» — que suena a problema grave y no lo es. Quien la recibe se va
     * a buscar mercancía que ya está en un camión.
     */
    $zonaA = posicionDeZona('R1-S9-N1', '1');

    $pedido = pedidoParaPartir($this, [[productoEnVariasZonas([$zonaA->id => 50]), 6]]);
    $pedido->items()->update(['cantidad_despachada' => 6]);
    $pedido->forceFill(['despacho_parcial' => true, 'resto_pendiente' => false])->save();

    $props = hojaPartida($this, $pedido);

    // Ni renglones ni secciones: no hay nada que recoger y la hoja no puede
    // fingir que sí.
    expect($props['items'])->toBeEmpty()
        ->and($props['secciones'])->toBeEmpty();
});
