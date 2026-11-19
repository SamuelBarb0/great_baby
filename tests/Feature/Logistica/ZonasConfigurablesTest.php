<?php

/**
 * Las zonas de la bodega se configuran, no se programan.
 *
 * El área entregó el 17-nov-2026 el reparto de racks en tres zonas con un
 * responsable cada una, y su lista de picking pide tres casillas: «ZONA»,
 * «RESPONSABLE DE LA ZONA» y «COMPARTIDO CON ZONA».
 *
 * Lo que se prueba acá no es el reparto —ése cambia— sino que cambiarlo NO
 * requiera tocar código: el día que el área mueva un rack de zona, abra una
 * cuarta o renombre las letras, la hoja de picking tiene que seguirlo desde
 * /app/reglas. Si esto se rompe, la hoja manda a caminar por donde ya no es y
 * nadie se entera hasta que el pedido sale mal.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Logistica\Support\ZonaDeLaBodega as Zona;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->jefa = User::factory()->create();
    $this->jefa->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->cliente = Contacto::create([
        'numero_documento' => '901222333', 'nombre_completo' => 'Pañalera Zonas SAS',
        'es_cliente' => true, 'ciudad' => 'Bucaramanga',
    ]);
});

/** Una posición en un rack concreto, que es lo que define su zona. */
function posicionEnRack(string $codigo, ?string $rack, ?int $responsable = null): InventarioUbicacion
{
    return InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $codigo,
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'pasillo' => $rack, 'responsable_user_id' => $responsable,
    ]);
}

/** Un producto con stock repartido: [ubicacion_id => unidades]. */
function productoRepartido(array $saldos): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-ZONAS-'.fake()->unique()->numerify('####'),
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

/** La hoja de picking de un pedido que pide `$cantidad` de ese producto. */
function hojaDelPedido($test, Producto $producto, int $cantidad): array
{
    $pedido = PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######-####'),
        'contacto_id' => $test->cliente->id, 'estado' => 'facturado',
        'subtotal' => 10000, 'total' => 10000,
    ]);

    PedidoClienteItem::create([
        'pedido_id' => $pedido->id, 'producto_id' => $producto->id,
        'cantidad' => $cantidad, 'precio_unitario' => 10000,
        'subtotal' => 10000, 'total' => 10000,
        'sku_snapshot' => $producto->referencia, 'descripcion_snapshot' => $producto->nombre,
    ]);

    return $test->actingAs($test->jefa)
        ->get("/app/logistica/cola-jorge/{$pedido->id}/picking")
        ->assertOk()->viewData('page')['props'];
}

/** Guardar la regla por la misma puerta que usa la pantalla de Reglas. */
function reparto(array $mapa): void
{
    Reglas::set('logistica.zonas_bodega', json_encode($mapa));
    Reglas::limpiarRequestCache();
}

it('reparte los racks como dice la regla, no como dice el código', function () {
    reparto(['A' => ['1', '2'], 'B' => ['12']]);

    expect(Zona::deRack('1'))->toBe('A')
        ->and(Zona::deRack('12'))->toBe('B')
        ->and(Zona::numero('B'))->toBe(2);

    // El área mueve el 12 a la zona A y abre una zona nueva.
    reparto(['A' => ['1', '2', '12'], 'B' => [], 'Z' => ['PARQUEADERO']]);

    expect(Zona::deRack('12'))->toBe('A')
        ->and(Zona::deRack('PARQUEADERO'))->toBe('Z')
        ->and(Zona::letras())->toBe(['A', 'Z']);   // una zona sin racks no cuenta
});

it('no le inventa zona a un rack que nadie repartió', function () {
    reparto(['A' => ['1']]);

    // Antes caía por defecto en la zona 3: un rack nuevo quedaba a nombre de
    // alguien que no sabe que lo tiene.
    expect(Zona::deRack('99'))->toBeNull()
        ->and(Zona::deRack(''))->toBeNull()
        ->and(Zona::deRack(null))->toBeNull();
});

it('si alguien deja la regla en basura, la bodega no se queda sin zonas', function () {
    reparto(['A' => [], '  ' => ['9'], 'B' => 'esto no es una lista']);

    expect(Zona::mapa())->toBe(Zona::POR_DEFECTO)
        ->and(Zona::deRack('6.5'))->toBe('B');
});

it('lee el rack con espacios y en minúscula, que es como se escribe a mano', function () {
    reparto(['A' => [' 6.5 '], 'C' => ['arsutex']]);

    expect(Zona::deRack('6.5'))->toBe('A')
        ->and(Zona::deRack('ARSUTEX'))->toBe('C')
        ->and(Zona::deRack('Arsutex'))->toBe('C');
});

it('la hoja dice por qué zona se camina y quién responde', function () {
    reparto(['A' => ['1'], 'C' => ['12']]);

    $responsable = User::factory()->create(['name' => 'Marta Rangel']);
    $rack1 = posicionEnRack('R1-S1-N1-D', '1', $responsable->id);

    $props = hojaDelPedido($this, productoRepartido([$rack1->id => 40]), 5);

    expect($props['pedido']['zona'])->toBe('A')
        // El nombre real de quien responde, no «Responsable 1»: esas cuentas se
        // renombran desde la pantalla de usuarios y la hoja tiene que seguirlas.
        ->and($props['pedido']['responsable_zona'])->toBe('Marta Rangel')
        ->and($props['pedido']['compartido_con'])->toBe([])
        ->and($props['pedido']['numero_picking'])->toStartWith('PCK-');
});

it('cae al número de la zona sólo si nadie responde por la ubicación', function () {
    reparto(['A' => ['1'], 'B' => ['7']]);

    $rack7 = posicionEnRack('R7-S1-N1-D', '7');   // sin responsable

    $props = hojaDelPedido($this, productoRepartido([$rack7->id => 10]), 2);

    expect($props['pedido']['zona'])->toBe('B')
        ->and($props['pedido']['responsable_zona'])->toBe('Responsable 2');
});

it('avisa cuando el pedido no se alista de una sola zona', function () {
    reparto(['A' => ['1'], 'C' => ['12']]);

    $rack1 = posicionEnRack('R1-S2-N1-D', '1');
    $rack12 = posicionEnRack('R12-S1-N1', '12');

    // 15 en la C y 12 en la A: pedir 20 obliga a pisar las dos.
    $props = hojaDelPedido($this, productoRepartido([$rack12->id => 15, $rack1->id => 12]), 20);

    expect($props['pedido']['zona'])->toBe('C')          // la que más aporta
        ->and($props['pedido']['compartido_con'])->toBe(['A']);
});

it('no avisa de una zona que el alistador no va a pisar', function () {
    reparto(['A' => ['1'], 'C' => ['12']]);

    $rack12 = posicionEnRack('R12-S2-N1', '12');
    $rack1 = posicionEnRack('R1-S3-N1-D', '1');

    // La primera ubicación sola cubre el pedido: no hay por qué mandar a nadie
    // a la otra punta de la bodega ni pedir una segunda persona.
    $props = hojaDelPedido($this, productoRepartido([$rack12->id => 50, $rack1->id => 12]), 20);

    expect($props['pedido']['zona'])->toBe('C')
        ->and($props['pedido']['compartido_con'])->toBe([]);
});

it('manda a la posición antes que a la bodega entera', function () {
    reparto(['A' => ['1']]);

    $bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL-TEST', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $rack1 = posicionEnRack('R1-S4-N1-D', '1');

    // La bodega tiene mucho más stock, pero «PPAL» no le dice a nadie dónde
    // pararse. Si ganara por cantidad, las 435 posiciones no servirían.
    $props = hojaDelPedido($this, productoRepartido([$bodega->id => 500, $rack1->id => 12]), 5);

    $ubicaciones = collect($props['items'])->first()['ubicaciones'];

    expect($ubicaciones[0]['codigo'])->toBe('R1-S4-N1-D')
        ->and($ubicaciones[0]['zona'])->toBe('A')
        ->and($ubicaciones[1]['codigo'])->toBe('PPAL-TEST')
        ->and($ubicaciones[1]['zona'])->toBeNull()
        ->and($props['pedido']['zona'])->toBe('A');
});

it('el aviso de llegada del contenedor se mueve desde las reglas', function () {
    $contenedor = new Importacion(['eta' => now()->addDays(10)]);

    Reglas::set('logistica.dias_aviso_contenedor', 7);
    Reglas::limpiarRequestCache();
    expect($contenedor->cuantoFaltaParaLlegar()['estado'])->toBe('en_camino');

    // Conseguir gente para descargar se demoró más de lo previsto: el área
    // sube el aviso a 12 días y el mismo contenedor pasa a estar encima.
    Reglas::set('logistica.dias_aviso_contenedor', 12);
    Reglas::limpiarRequestCache();

    $f = $contenedor->cuantoFaltaParaLlegar();

    expect($f['estado'])->toBe('cerca')
        ->and($f['dias'])->toBe(10)
        // Los umbrales viajan con la respuesta para que la pantalla no tenga
        // su propia copia de los números.
        ->and($f['dias_aviso'])->toBe(12);
});

it('no deja que hacer espacio se configure después de pedir la gente', function () {
    Reglas::set('logistica.dias_aviso_contenedor', 10);
    Reglas::set('logistica.dias_espacio_contenedor', 2);
    Reglas::limpiarRequestCache();

    $f = (new Importacion(['eta' => now()->addDays(30)]))->cuantoFaltaParaLlegar();

    // Configurado al revés el consejo sería absurdo —«pida la gente» antes que
    // «vaya haciendo espacio»—, así que el de espacio nunca queda por debajo.
    expect($f['dias_espacio'])->toBe(10);
});
