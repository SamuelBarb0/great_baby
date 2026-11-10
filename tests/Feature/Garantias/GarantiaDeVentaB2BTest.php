<?php

/**
 * Una garantía también puede salir de una venta B2B.
 *
 * El módulo de garantías existía completo —ticket con fotos, concepto con
 * plazo, decisión, reposición y cierre— pero sólo se podía amarrar a una guía
 * de Dropi: el formulario buscaba el pedido por `guia` en `dropi_pedidos`. Un
 * cliente B2B, que es el que compra a crédito y el que más reclama, no tenía
 * por dónde entrar, y su ticket quedaba sin venta de respaldo.
 *
 * Sin venta de respaldo no hay contra qué validar nada, que es justo lo que
 * pide el contrato: «validación de referencia, cliente, pedido/factura y fecha
 * contra la venta original».
 *
 * Es el mismo patrón que apareció tres veces esta semana — las reservas, el
 * seguimiento de guías y ahora las garantías: el canal Dropi tenía la función
 * y la venta B2B no.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Models\GarantiaTicket;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    Reglas::limpiarRequestCache();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $rol = Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->usuario = \App\Models\User::factory()->create();
    $this->usuario->assignRole($rol);
    $this->actingAs($this->usuario);

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900555666',
        'nombre_completo' => 'Pañalera del Sur', 'es_cliente' => true,
    ]);

    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GAR-001', 'nombre' => 'Coche de paseo', 'desglose_stock' => true,
    ]));
    $this->variante = ProductoVariante::create([
        'producto_id' => $producto->id, 'codigo_barras' => 'GAR-001-AZ',
        'color_nombre' => 'azul',
    ]);

    $this->factura = FacturaVenta::create([
        'numero' => 'FV-GAR-1', 'contacto_id' => $this->cliente->id,
        'fecha_emision' => now()->subDays(5)->toDateString(),
        'fecha_vencimiento' => now()->addDays(25)->toDateString(),
        'estado' => 'pendiente', 'tipo' => 'credito',
        'subtotal' => 300000, 'descuento' => 0, 'impuestos' => 0,
        'total' => 300000, 'saldo' => 300000,
    ]);
    $this->factura->items()->create([
        'variante_id' => $this->variante->id,
        'descripcion' => 'Coche de paseo azul',
        'cantidad' => 3, 'precio_unit' => 100000,
        'descuento_pct' => 0, 'impuesto_pct' => 0, 'subtotal' => 300000,
    ]);
});

function crearGarantia(array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/app/garantias', array_merge([
        'cliente_nombre' => 'Pañalera del Sur',
        'cliente_telefono' => '3001234567',
        'pedido_guia' => 'FV-GAR-1',
        'variante_id' => test()->variante->id,
        'cantidad' => 1,
        'descripcion_falla' => 'Llegó con una rueda partida y no gira.',
    ], $extra));
}

it('se puede abrir una garantía contra una factura B2B', function () {
    crearGarantia();

    $t = GarantiaTicket::latest('id')->first();

    expect($t)->not->toBeNull()
        ->and($t->origen_type)->toBe(FacturaVenta::class)
        ->and($t->origen_id)->toBe($this->factura->id)
        // Y queda amarrado al cliente, que antes no se guardaba.
        ->and($t->contacto_id)->toBe($this->cliente->id);
});

it('la pantalla dice de qué venta salió, sin importar el canal', function () {
    crearGarantia();

    expect(GarantiaTicket::latest('id')->first()->referenciaDeOrigen())
        ->toBe('Factura FV-GAR-1');
});

it('no deja reclamar un producto que esa factura no vendió', function () {
    // Otro producto distinto: es el caso real —el cliente manda la factura
    // equivocada, o se equivoca de referencia al reclamar.
    $otroProducto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GAR-002', 'nombre' => 'Silla mecedora', 'desglose_stock' => true,
    ]));
    $otra = ProductoVariante::create([
        'producto_id' => $otroProducto->id,
        'codigo_barras' => 'GAR-002-RO', 'color_nombre' => 'rojo',
    ]);

    crearGarantia(['variante_id' => $otra->id]);

    expect(GarantiaTicket::count())->toBe(0);
});

it('no deja reclamar más unidades de las que se vendieron', function () {
    // En la factura van 3.
    crearGarantia(['cantidad' => 5]);

    expect(GarantiaTicket::count())->toBe(0);
});

it('reclamar exactamente lo vendido sí se puede', function () {
    crearGarantia(['cantidad' => 3]);

    expect(GarantiaTicket::count())->toBe(1);
});

it('avisa de un número que no existe en vez de crear un ticket huérfano', function () {
    crearGarantia(['pedido_guia' => 'NO-EXISTE-123']);

    expect(GarantiaTicket::count())->toBe(0);
});

it('sin número de venta igual se puede abrir: el cliente no siempre lo tiene', function () {
    crearGarantia(['pedido_guia' => '']);

    $t = GarantiaTicket::latest('id')->first();

    expect($t)->not->toBeNull()
        ->and($t->origen_type)->toBeNull();
});

it('el plazo para dar concepto sale de la regla', function () {
    Reglas::set('garantias.dias_concepto', 7);
    Reglas::limpiarRequestCache();

    crearGarantia();

    $t = GarantiaTicket::latest('id')->first();

    expect($t->plazo_concepto_at->startOfDay()->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});

it('una factura vieja avisa pero no bloquea', function () {
    $this->factura->forceFill(['fecha_emision' => now()->subDays(90)])->saveQuietly();

    crearGarantia();

    // Se crea igual: aceptar una garantía fuera de plazo es decisión de
    // Gerencia, no del sistema.
    expect(GarantiaTicket::count())->toBe(1);
});

it('la factura hecha a mano guarda el producto, no la variante, y la garantía igual entra', function () {
    /*
     * `CrearFacturaManual` escribe sus renglones con `producto_id` y deja
     * `variante_id` en nulo: no le pregunta el color ni la talla a nadie. Antes
     * la garantía de un producto granular contra una factura así se rechazaba
     * con «la factura no incluye ese producto», sobre una factura que sí lo
     * incluía. Es el peor tipo de rechazo: tiene razón en la forma y se
     * equivoca en el fondo, y quien atiende no tiene cómo discutirle.
     */
    $this->factura->items()->delete();
    $this->factura->items()->create([
        'producto_id' => $this->variante->producto_id,
        'variante_id' => null,
        'descripcion' => 'Coche de paseo',
        'cantidad' => 3, 'precio_unit' => 100000,
        'descuento_pct' => 0, 'impuesto_pct' => 0, 'subtotal' => 300000,
    ]);

    crearGarantia()->assertRedirect();

    expect(\App\Modules\Dropi\Models\GarantiaTicket::count())->toBe(1);
});

it('suma todos los renglones del mismo producto, no sólo el primero', function () {
    // Dos cajas en la misma factura, con precio distinto. Pasa seguido.
    $this->factura->items()->create([
        'variante_id' => $this->variante->id,
        'descripcion' => 'Coche de paseo azul · segunda caja',
        'cantidad' => 4, 'precio_unit' => 90000,
        'descuento_pct' => 0, 'impuesto_pct' => 0, 'subtotal' => 360000,
    ]);

    // Son 3 + 4 = 7. Leyendo sólo el primer renglón, reclamar 5 se rechazaba.
    crearGarantia(['cantidad' => 5])->assertRedirect();

    expect(\App\Modules\Dropi\Models\GarantiaTicket::count())->toBe(1);
});

it('pasado el total de los renglones sí se rechaza', function () {
    $this->factura->items()->create([
        'variante_id' => $this->variante->id,
        'descripcion' => 'Coche de paseo azul · segunda caja',
        'cantidad' => 4, 'precio_unit' => 90000,
        'descuento_pct' => 0, 'impuesto_pct' => 0, 'subtotal' => 360000,
    ]);

    // 7 vendidas, 8 reclamadas.
    crearGarantia(['cantidad' => 8]);

    expect(\App\Modules\Dropi\Models\GarantiaTicket::count())->toBe(0);
});
