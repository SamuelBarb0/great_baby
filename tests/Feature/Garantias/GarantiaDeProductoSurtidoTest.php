<?php

/**
 * Una garantía también se le abre a un producto de «colores surtidos».
 *
 * `garantia_tickets.variante_id` era NOT NULL, así que todo ticket exigía una
 * variante. Los productos AGREGADOS —`productos.desglose_stock = false`, los
 * que entraron por el Excel del cliente— no tienen filas en
 * `producto_variantes`: el stock lo llevan en `stock_directo` y el precio en
 * `precios_producto`. A esos productos, que son la mayor parte del catálogo
 * real, no se les podía abrir garantía: el insert lo tumbaba la base.
 *
 * Es el mismo patrón que ya apareció varias veces en este repo: el camino
 * granular/Dropi tiene la función y el agregado/B2B no — las reservas, el
 * seguimiento de guías, la garantía de venta B2B y ahora el sujeto del ticket.
 *
 * Lo que se protege acá:
 *   · que el ticket de un surtido se guarde con `producto_id` y sin variante;
 *   · que se valide contra la factura que lo vendió, buscando el renglón
 *     agregado y no uno por variante que nunca va a existir;
 *   · que un producto granular siga exigiendo cuál variante falló, porque
 *     reponer «el coche» sin saber si es el gris deja el descargue de
 *     inventario a la adivinanza;
 *   · que elegir la variante guarde TAMBIÉN su producto: `producto_id` no se
 *     escribía nunca, y por eso el informe «cuál referencia nos está costando
 *     la posventa» salía vacío para todo ticket creado por el formulario;
 *   · que el surtido llegue entero hasta el final del flujo — decisión,
 *     reposición, costo e informe por referencia.
 */

use App\Models\Contacto;
use App\Models\User;
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
    $this->usuario = User::factory()->create();
    $this->usuario->assignRole($rol);
    $this->actingAs($this->usuario);

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900777888',
        'nombre_completo' => 'Pañalera del Norte', 'es_cliente' => true,
    ]);

    // El producto de «colores surtidos»: sin variantes, stock directo.
    $this->surtido = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'SUR-MEDIAS', 'nombre' => 'Medias surtidas x12',
        'desglose_stock' => false, 'stock_directo' => 60,
        'activo' => true, 'precio_proveedor' => 7500,
    ]));

    // Y uno granular, para comprobar que a ese sí se le sigue pidiendo la
    // variante exacta.
    $this->coche = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'SUR-COCHE', 'nombre' => 'Coche de paseo',
        'desglose_stock' => true, 'activo' => true, 'precio_proveedor' => 180000,
    ]));
    $this->varianteCoche = ProductoVariante::create([
        'producto_id' => $this->coche->id, 'color_nombre' => 'Gris',
        'talla' => 'U', 'combo_key' => 'sur-coche-gris',
    ]);

    $this->factura = FacturaVenta::create([
        'numero' => 'FV-SUR-1', 'contacto_id' => $this->cliente->id,
        'fecha_emision' => now()->subDays(4)->toDateString(),
        'fecha_vencimiento' => now()->addDays(26)->toDateString(),
        'estado' => 'pendiente', 'tipo' => 'credito',
        'subtotal' => 36000, 'descuento' => 0, 'impuestos' => 0,
        'total' => 36000, 'saldo' => 36000,
    ]);

    // El renglón agregado, como lo guarda la venta B2B de un surtido:
    // `producto_id` puesto y `variante_id` nulo.
    $this->factura->items()->create([
        'producto_id' => $this->surtido->id,
        'variante_id' => null,
        'descripcion' => 'Medias surtidas x12',
        'cantidad' => 4, 'precio_unit' => 9000,
        'descuento_pct' => 0, 'impuesto_pct' => 0, 'subtotal' => 36000,
    ]);
});

/** El alta por el formulario, que es el único camino que crea tickets. */
function abrirGarantiaSurtida(array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/app/garantias', array_merge([
        'cliente_nombre' => 'Pañalera del Norte',
        'cliente_telefono' => '3009998888',
        'pedido_guia' => 'FV-SUR-1',
        'producto_id' => test()->surtido->id,
        'cantidad' => 1,
        'descripcion_falla' => 'Vinieron descosidas del puño, el lote entero.',
    ], $extra));
}

/** Un ticket ya abierto, para las pruebas del tramo final del flujo. */
function ticketSurtido(string $estado = 'cerrada', int $cantidad = 1): GarantiaTicket
{
    static $n = 0;
    $n++;

    return GarantiaTicket::create([
        'numero' => 'GAR-SUR-'.$n,
        'producto_id' => test()->surtido->id,
        'variante_id' => null,
        'contacto_id' => test()->cliente->id,
        'cantidad' => $cantidad,
        'cliente_nombre' => 'Pañalera del Norte',
        'descripcion_falla' => 'Vinieron descosidas del puño, el lote entero.',
        'estado' => $estado,
        'creado_por' => test()->usuario->id,
        'plazo_concepto_at' => now()->addDays((int) setting('garantias.dias_concepto')),
    ]);
}

it('se puede abrir una garantía a un producto de colores surtidos', function () {
    abrirGarantiaSurtida();

    $t = GarantiaTicket::latest('id')->first();

    expect($t)->not->toBeNull()
        ->and($t->producto_id)->toBe($this->surtido->id)
        // Sin variante, porque ese producto no tiene ninguna: era justo lo que
        // la columna NOT NULL impedía.
        ->and($t->variante_id)->toBeNull()
        ->and($t->origen_id)->toBe($this->factura->id);
});

it('la garantía del surtido se valida contra el renglón agregado de la factura', function () {
    // En la factura van 4.
    abrirGarantiaSurtida(['cantidad' => 6]);

    expect(GarantiaTicket::count())->toBe(0);

    abrirGarantiaSurtida(['cantidad' => 4]);

    expect(GarantiaTicket::count())->toBe(1);
});

it('no deja reclamar un surtido que esa factura no vendió', function () {
    $otro = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'SUR-BABEROS', 'nombre' => 'Baberos surtidos x6',
        'desglose_stock' => false, 'stock_directo' => 30, 'activo' => true,
    ]));

    abrirGarantiaSurtida(['producto_id' => $otro->id]);

    expect(GarantiaTicket::count())->toBe(0);
});

it('un producto granular sigue exigiendo cuál variante falló', function () {
    // Reponer «el coche» sin saber si es el gris o el azul deja la reposición
    // y el descargue de inventario a la adivinanza.
    abrirGarantiaSurtida(['producto_id' => $this->coche->id, 'pedido_guia' => '']);

    expect(GarantiaTicket::count())->toBe(0);
});

it('elegir la variante guarda también su producto', function () {
    /*
     * `producto_id` se agregó el 6-nov y el alta no lo escribía nunca, así que
     * el informe por referencia salía vacío para todo ticket del formulario
     * aunque el producto estuviera colgando de la variante.
     */
    abrirGarantiaSurtida([
        'producto_id' => null,
        'variante_id' => $this->varianteCoche->id,
        'pedido_guia' => '',
    ]);

    $t = GarantiaTicket::latest('id')->first();

    expect($t)->not->toBeNull()
        ->and($t->variante_id)->toBe($this->varianteCoche->id)
        ->and($t->producto_id)->toBe($this->coche->id);
});

it('sin producto ni variante no se abre el ticket', function () {
    // La base ya no lo exige; el seguro quedó en la validación del alta. Un
    // ticket sin producto no se puede reponer ni costear.
    abrirGarantiaSurtida(['producto_id' => null, 'pedido_guia' => ''])
        ->assertSessionHasErrors(['producto_id', 'variante_id']);

    expect(GarantiaTicket::count())->toBe(0);
});

it('la pantalla dice de qué producto es, y que es surtido', function () {
    $t = ticketSurtido('abierto');

    $producto = $this->get("/app/garantias/{$t->id}")
        ->viewData('page')['props']['ticket']['producto'];

    expect($producto['nombre'])->toBe('Medias surtidas x12')
        ->and($producto['sku'])->toBe('SUR-MEDIAS')
        ->and($producto['detalle'])->toBe('colores surtidos')
        ->and($producto['es_agregado'])->toBeTrue();
});

it('la garantía de un surtido se decide y se repone como cualquier otra', function () {
    $t = ticketSurtido('abierto');

    $this->post("/app/garantias/{$t->id}/decidir", [
        'decision' => 'aprobar',
        'notas_decision' => 'Lote con defecto de confección, se repone completo.',
    ])->assertRedirect();

    expect($t->fresh()->estado)->toBe('aprobada');

    $this->post("/app/garantias/{$t->id}/reposicion")->assertRedirect();

    expect($t->fresh()->estado)->toBe('en_reposicion')
        // El cliente sigue pagando cero: eso no cambia con el agregado.
        ->and((float) $t->fresh()->valor_reposicion)->toBe(0.0);
});

it('al surtido se le carga el costo y aparece en el informe por referencia', function () {
    $t = ticketSurtido('cerrada');

    $this->post("/app/garantias/{$t->id}/costo", [
        'costo_producto' => 7500,
        'costo_flete_recoger' => 9000,
        'costo_flete_enviar' => 9000,
    ])->assertRedirect();

    expect($t->fresh()->costoTotal())->toBe(25500.0);

    $costos = $this->get('/app/garantias')->viewData('page')['props']['costos'];

    // Antes esto era imposible: sin ticket no había costo, y sin `producto_id`
    // el informe no sabía de qué referencia hablar.
    expect($costos['total'])->toBe(25500.0)
        ->and($costos['por_producto'][0]['referencia'])->toBe('SUR-MEDIAS')
        ->and($costos['por_producto'][0]['costo'])->toBe(25500.0);
});

it('el costo sugerido del surtido sale de su precio de proveedor', function () {
    // `costoSugerido()` ya contemplaba `producto ?? variante?->producto`, pero
    // nunca había un ticket de agregado con el que llegar hasta acá.
    $t = ticketSurtido('en_reposicion', 3);

    $ticket = $this->get("/app/garantias/{$t->id}")
        ->viewData('page')['props']['ticket'];

    expect($ticket['costo_sugerido'])->toBe(22500.0);
});

it('el buscador del formulario trae surtidos y variantes en la misma lista', function () {
    // Quien atiende el reclamo no tiene por qué saber cómo se maneja el stock
    // de ese producto. El endpoint de inventario no sirve: está cerrado a
    // bodega y ServicioCliente recibía 403.
    $lista = $this->getJson('/app/garantias/buscar-producto?q=sur-')->json();

    $surtido = collect($lista)->firstWhere('sku', 'SUR-MEDIAS');

    expect($surtido['producto_id'])->toBe($this->surtido->id)
        ->and($surtido['variante_id'])->toBeNull()
        ->and($surtido['detalle'])->toBe('colores surtidos')
        // Y el granular entra por su variante, no por el producto.
        ->and(collect($lista)->firstWhere('sku', 'SUR-COCHE'))->toBeNull()
        ->and(collect($lista)->pluck('variante_id')->filter()->values()->all())
        ->toBe([$this->varianteCoche->id]);
});
