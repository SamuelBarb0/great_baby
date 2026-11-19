<?php

/**
 * La oleada: varios pedidos recogidos de una sola pasada.
 *
 * El procedimiento del área pone tres pasos, no dos: picking «por operarios de
 * zona», CONSOLIDACIÓN —«trasladar al punto de consolidación y agrupar la
 * totalidad de ítems por cada orden», con entrada «artículos de distintas
 * zonas»— y packing.
 *
 * Lo que estas pruebas cuidan es lo que hace que la oleada valga la pena:
 *
 *   · Que una referencia pedida por varios pedidos sea UNA parada, no una por
 *     pedido. Ése es todo el ahorro: con cien pedidos en cola, una referencia
 *     que esté en la mitad de las facturas son cincuenta viajes al mismo
 *     estante.
 *   · Que el recorrido salga en el orden en que se camina la bodega. El plano
 *     que mandó don Jorge tiene la entrada junto al rack 1, así que se va del
 *     1 al 15 sin devolverse.
 *   · Que lo que falta se diga ANTES de salir a caminar, no a mitad de
 *     camino.
 *   · Que cada pedido tenga su casilla en el piso y no se la muevan al
 *     reimprimir la hoja.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Logistica\Support\ZonaDeAlistamiento;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->jefa = User::factory()->create();
    $this->jefa->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->sede = InventarioUbicacion::create([
        'codigo' => 'PPAL-OLEADA', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901888999', 'nombre_completo' => 'Pañalera Oleada SAS',
        'es_cliente' => true, 'ciudad' => 'Bucaramanga',
    ]);

    // El reparto de zonas del plano, explícito: estas pruebas hablan de
    // recorrido y el recorrido depende de él.
    Reglas::set('logistica.zonas_bodega', json_encode([
        'A' => ['1', '2'],
        'B' => ['7'],
        'C' => ['12'],
    ]));
    Reglas::limpiarRequestCache();
});

/** Una posición de un rack, colgada de la sede. */
function estanteDeOleada(string $codigo, string $rack): InventarioUbicacion
{
    return InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $codigo,
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'bodega_id' => test()->sede->id, 'pasillo' => $rack,
    ]);
}

/** Un producto agregado con stock repartido: [ubicacion_id => unidades]. */
function productoDeOleada(string $sku, array $saldos): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => $sku, 'nombre' => "Producto {$sku}",
        'activo' => true, 'desglose_stock' => false, 'precio_proveedor' => 9000,
    ]));

    foreach ($saldos as $ubicacionId => $cantidad) {
        InventarioMovimiento::create([
            'producto_id' => $p->id, 'ubicacion_id' => $ubicacionId,
            'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 9000,
        ]);
    }

    return $p;
}

/** Un pedido facturado que sale de la sede. @param array<int,int> $items [producto_id => cantidad] */
function pedidoDeOleada(array $items): PedidoCliente
{
    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => test()->cliente->id, 'estado' => 'facturado',
        'ubicacion_origen_id' => test()->sede->id,
        'subtotal' => 10000, 'total' => 10000,
    ]);

    foreach ($items as $productoId => $cantidad) {
        $p = Producto::find($productoId);

        PedidoClienteItem::create([
            'pedido_id' => $pedido->id, 'producto_id' => $productoId,
            'cantidad' => $cantidad, 'precio_unitario' => 10000,
            'subtotal' => 10000, 'total' => 10000,
            'sku_snapshot' => $p->referencia, 'descripcion_snapshot' => $p->nombre,
        ]);
    }

    return $pedido;
}

/** La hoja de oleada de esos pedidos. */
function hojaDeOleada($pedidos): array
{
    $ids = collect($pedidos)->map(fn ($p) => $p->id)->implode(',');

    return test()->actingAs(test()->jefa)
        ->get("/app/logistica/cola-jorge/oleada?pedidos={$ids}")
        ->assertOk()->viewData('page')['props']['oleada'];
}

it('junta en UNA parada lo que piden varios pedidos del mismo estante', function () {
    $estante = estanteDeOleada('R1-S1-N1-D', '1');
    $prod = productoDeOleada('SKU-UNO', [$estante->id => 100]);

    $oleada = hojaDeOleada([
        pedidoDeOleada([$prod->id => 4]),
        pedidoDeOleada([$prod->id => 8]),
        pedidoDeOleada([$prod->id => 12]),
    ]);

    // Tres pedidos, una sola parada: ése es todo el ahorro.
    expect($oleada['recorrido'])->toHaveCount(1)
        ->and($oleada['recorrido'][0]['codigo'])->toBe('R1-S1-N1-D')
        ->and($oleada['recorrido'][0]['lineas'][0]['total'])->toBe(24)
        // Y dice a qué casilla va cada cuánto, que es lo que se reparte después.
        ->and($oleada['recorrido'][0]['lineas'][0]['reparto'])->toHaveCount(3)
        ->and($oleada['resumen']['pedidos'])->toBe(3)
        ->and($oleada['resumen']['unidades'])->toBe(24);
});

it('camina del rack 1 al 15, que es como se entra a la bodega', function () {
    // Se crean al revés a propósito: el orden no puede venir de cómo se
    // insertaron, tiene que venir del plano.
    $r12 = estanteDeOleada('R12-S1-N1', '12');
    $r7 = estanteDeOleada('R7-S1-N1-D', '7');
    $r1 = estanteDeOleada('R1-S2-N1-D', '1');

    $oleada = hojaDeOleada([pedidoDeOleada([
        productoDeOleada('SKU-C', [$r12->id => 50])->id => 5,
        productoDeOleada('SKU-B', [$r7->id => 50])->id => 5,
        productoDeOleada('SKU-A', [$r1->id => 50])->id => 5,
    ])]);

    expect(array_column($oleada['recorrido'], 'codigo'))
        ->toBe(['R1-S2-N1-D', 'R7-S1-N1-D', 'R12-S1-N1'])
        // Y el encabezado dice las zonas en el mismo orden que la tabla.
        ->and($oleada['resumen']['zonas'])->toBe(['A', 'B', 'C']);
});

it('avisa de lo que falta antes de salir a caminar', function () {
    $estante = estanteDeOleada('R1-S3-N1-D', '1');
    $prod = productoDeOleada('SKU-CORTO', [$estante->id => 10]);

    $oleada = hojaDeOleada([pedidoDeOleada([$prod->id => 25])]);

    $ultima = end($oleada['recorrido']);

    expect($oleada['resumen']['hay_faltantes'])->toBeTrue()
        // Lo que no se encontró va al final y dice cuánto: 25 pedidas, 10 hay.
        ->and($ultima['codigo'])->toBe('SIN UBICAR')
        ->and($ultima['lineas'][0]['total'])->toBe(15);
});

it('a cada pedido le da su casilla, y no se la mueve al reimprimir', function () {
    $estante = estanteDeOleada('R1-S4-N1-D', '1');
    $prod = productoDeOleada('SKU-CASILLA', [$estante->id => 100]);

    $uno = pedidoDeOleada([$prod->id => 3]);
    $dos = pedidoDeOleada([$prod->id => 3]);

    $primera = hojaDeOleada([$uno, $dos]);
    $casillas = array_column($primera['casillas'], 'casilla', 'numero');

    expect(array_values($casillas))->toBe(['C1FA', 'C1FB']);

    // La misma hoja otra vez: un pedido a medio armar en el piso no se puede
    // mandar a otra casilla.
    $segunda = hojaDeOleada([$uno, $dos]);

    expect(array_column($segunda['casillas'], 'casilla', 'numero'))->toBe($casillas);
});

it('no arma una oleada más grande que la zona de alistamiento', function () {
    $estante = estanteDeOleada('R1-S5-N1-D', '1');
    $prod = productoDeOleada('SKU-TOPE', [$estante->id => 500]);

    $caben = ZonaDeAlistamiento::cuantasCaben();

    $pedidos = collect(range(1, $caben + 1))->map(fn () => pedidoDeOleada([$prod->id => 1]));

    // No se pueden armar más pedidos de los que caben en el piso.
    $this->actingAs($this->jefa)
        ->get('/app/logistica/cola-jorge/oleada?pedidos='.$pedidos->pluck('id')->implode(','))
        ->assertStatus(422);
});

it('no mete en la oleada un pedido que no está listo para alistar', function () {
    $estante = estanteDeOleada('R1-S1-N2-D', '1');
    $prod = productoDeOleada('SKU-BORRADOR', [$estante->id => 50]);

    $pedido = pedidoDeOleada([$prod->id => 2]);
    $pedido->update(['estado' => 'borrador']);

    $this->actingAs($this->jefa)
        ->get("/app/logistica/cola-jorge/oleada?pedidos={$pedido->id}")
        ->assertStatus(422);
});

it('sin pedidos no arma nada', function () {
    $this->actingAs($this->jefa)
        ->get('/app/logistica/cola-jorge/oleada')
        ->assertStatus(422);
});

/*
 * El recorrido de la zona 3, tal como lo pidió el área el 19-nov-2026.
 *
 * No es ascendente ni descendente: se arranca por el local, sigue el
 * parqueadero, se va al fondo (15) y se vuelve hacia la zona de alistamiento
 * recogiendo Arsutex al pasar entre el 13 y el 12.
 *
 * Lo dijeron con todas las letras: «si el pedido contiene mercancía ubicada en
 * la zona local, esta aparezca como PRIMER punto de recolección». Ordenar por
 * número de rack —como se hacía— dejaba el local de último.
 */
it('camina la zona 3 en el orden que pidió el área, no por número de rack', function () {
    Reglas::set('logistica.zonas_bodega', json_encode([
        'C' => ['LOCAL', 'PARQUEADERO', '15', '14', '13', 'ARSUTEX', '12', '11'],
    ]));
    Reglas::limpiarRequestCache();

    // Se crean al revés del recorrido, a propósito.
    $r11 = estanteDeOleada('R11-S1-N1-D', '11');
    $arsutex = estanteDeOleada('Z-ARSUTEX', 'ARSUTEX');
    $r15 = estanteDeOleada('R15-S1-N1-D', '15');
    $local = estanteDeOleada('Z-LOCAL', 'LOCAL');

    $oleada = hojaDeOleada([pedidoDeOleada([
        productoDeOleada('SKU-11', [$r11->id => 50])->id => 5,
        productoDeOleada('SKU-ARS', [$arsutex->id => 50])->id => 5,
        productoDeOleada('SKU-15', [$r15->id => 50])->id => 5,
        productoDeOleada('SKU-LOC', [$local->id => 50])->id => 5,
    ])]);

    expect(array_column($oleada['recorrido'], 'codigo'))
        ->toBe(['Z-LOCAL', 'R15-S1-N1-D', 'Z-ARSUTEX', 'R11-S1-N1-D']);
});

it('el local va de primero aunque el pedido también toque el rack 11', function () {
    Reglas::set('logistica.zonas_bodega', json_encode([
        'C' => ['LOCAL', 'PARQUEADERO', '15', '14', '13', 'ARSUTEX', '12', '11'],
    ]));
    Reglas::limpiarRequestCache();

    $r11 = estanteDeOleada('R11-S2-N1-D', '11');
    $local = estanteDeOleada('Z-LOCAL-2', 'LOCAL');

    $oleada = hojaDeOleada([pedidoDeOleada([
        productoDeOleada('SKU-R11', [$r11->id => 30])->id => 3,
        productoDeOleada('SKU-LOCAL', [$local->id => 30])->id => 3,
    ])]);

    // «Si el pedido contiene mercancía ubicada en la zona local, esta aparezca
    // como primer punto de recolección».
    expect($oleada['recorrido'][0]['codigo'])->toBe('Z-LOCAL-2');
});

it('reordenar la regla reordena el recorrido, sin tocar código', function () {
    $r1 = estanteDeOleada('R1-S9-N1-D', '1');
    $r2 = estanteDeOleada('R2-S9-N1-D', '2');

    $pedido = pedidoDeOleada([
        productoDeOleada('SKU-R1', [$r1->id => 30])->id => 3,
        productoDeOleada('SKU-R2', [$r2->id => 30])->id => 3,
    ]);

    Reglas::set('logistica.zonas_bodega', json_encode(['A' => ['1', '2']]));
    Reglas::limpiarRequestCache();
    expect(array_column(hojaDeOleada([$pedido])['recorrido'], 'codigo'))
        ->toBe(['R1-S9-N1-D', 'R2-S9-N1-D']);

    // El área decide caminarla al revés: se reordena la lista y ya.
    Reglas::set('logistica.zonas_bodega', json_encode(['A' => ['2', '1']]));
    Reglas::limpiarRequestCache();
    expect(array_column(hojaDeOleada([$pedido])['recorrido'], 'codigo'))
        ->toBe(['R2-S9-N1-D', 'R1-S9-N1-D']);
});
