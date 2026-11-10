<?php

/**
 * Cotizar, que es lo que pasa ANTES de un pedido.
 *
 * El ERP sólo sabía levantar pedidos. Pero un cliente B2B casi nunca compra en
 * la primera conversación —pide precios, los compara, consulta con su socio y
 * vuelve a la semana—, y ese paso no existía: se cotizaba por WhatsApp y
 * después se transcribía a mano al pedido. El precio prometido no quedaba
 * registrado en ninguna parte, y al transcribir se equivocaban.
 *
 * Lo que se protege acá:
 *   · que la cotización NO comprometa inventario, porque reservar por algo que
 *     el cliente no aceptó congelaría mercancía por conversaciones que no van
 *     a ningún lado;
 *   · que VENZA, porque un precio sin fecha es una promesa indefinida;
 *   · que al convertirla mande el precio COTIZADO y no el de la lista de hoy
 *     —si se recalculara, cotizar no serviría de nada—;
 *   · que no se convierta dos veces, porque dos clics seguidos crearían dos
 *     pedidos iguales;
 *   · que un vendedor no vea lo que le está ofreciendo otro a su cliente.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Portal\Actions\ConvertirCotizacionEnPedido;
use App\Modules\Portal\Actions\CrearCotizacion;
use App\Modules\Portal\Models\Cotizacion;
use App\Modules\Portal\Models\PedidoCliente;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    Reglas::limpiarRequestCache();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $this->lista = ListaPrecios::create([
        'codigo' => 'COT-MAY', 'nombre' => 'Mayorista cotiza', 'canal' => 'mayorista', 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900343434',
        'nombre_completo' => 'Pañalera que Compara', 'es_cliente' => true, 'es_cliente_b2b' => true,
        'lista_precios_id' => $this->lista->id, 'ciudad' => 'Cali',
    ]);

    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'COT-BODY', 'nombre' => 'Body manga larga',
        'desglose_stock' => true, 'activo' => true, 'precio_proveedor' => 12000,
    ]));

    $this->variante = ProductoVariante::create([
        'producto_id' => $producto->id, 'color_nombre' => 'Azul',
        'talla' => '6M', 'combo_key' => 'cot-body-azul-6m',
    ]);

    PrecioVariante::create([
        'variante_id' => $this->variante->id, 'lista_id' => $this->lista->id,
        'precio' => 22500, 'vigente_desde' => now()->subDay()->toDateString(),
    ]);

    $rol = Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->jefa = User::factory()->create();
    $this->jefa->assignRole($rol);

    /*
     * Los dos con acceso real de vendedor.
     *
     * Sin el permiso, todas las peticiones HTTP responden 403 y los tests
     * «fallan» por la puerta, no por lo que querian probar.
     */
    $rolVendedor = Role::firstOrCreate(['name' => 'VendedorTest', 'guard_name' => 'web']);
    $rolVendedor->givePermissionTo(['ver.vendedor', 'crear.vendedor', 'editar.vendedor']);

    $this->carlos = User::factory()->create(['name' => 'Carlos Vendedor']);
    $this->carlos->assignRole($rolVendedor);

    $this->marta = User::factory()->create(['name' => 'Marta Vendedora']);
    $this->marta->assignRole($rolVendedor);
});

/** Una cotización por N unidades, al precio que se pida o al de la lista. */
function cotizaDe(int $cantidad = 10, ?float $precio = null, ?int $vendedorId = null, ?string $vence = null): Cotizacion
{
    return app(CrearCotizacion::class)->ejecutar(
        cliente: test()->cliente,
        items: [[
            'variante_id' => test()->variante->id,
            'cantidad' => $cantidad,
            'precio' => $precio,
        ]],
        vendedorId: $vendedorId ?? test()->carlos->id,
        vigenteHasta: $vence,
    );
}

it('toma los precios de la lista del cliente', function () {
    $cot = cotizaDe(10);

    expect((float) $cot->items->first()->precio_unitario)->toBe(22500.0)
        ->and((float) $cot->subtotal)->toBe(225000.0);
});

it('no compromete inventario: no reserva ni descuenta', function () {
    $antes = \App\Modules\Dropi\Models\InventarioMovimiento::count();

    cotizaDe(10);

    // Reservar por algo que el cliente no aceptó congelaría mercancía por
    // conversaciones que no van a ningún lado.
    expect(\App\Modules\Dropi\Models\InventarioMovimiento::count())->toBe($antes);
});

it('vence, y la vigencia sale de la regla', function () {
    Reglas::set('ventas.dias_vigencia_cotizacion', 7);
    Reglas::limpiarRequestCache();

    $cot = cotizaDe(10);

    expect($cot->vigente_hasta->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($cot->estaVigente())->toBeTrue();
});

it('el vendedor puede cotizar por debajo de la lista: negociar es media razón de existir', function () {
    $cot = cotizaDe(10, 19800.0);

    expect((float) $cot->items->first()->precio_unitario)->toBe(19800.0);
});

it('cotizar en cero no es negociar, es regalar', function () {
    $this->actingAs($this->carlos)
        ->post("/app/vendedor/cotizacion-nueva/{$this->cliente->id}", [
            'items' => [['variante_id' => $this->variante->id, 'cantidad' => 5, 'precio' => 0]],
        ])
        ->assertSessionHasErrors('items.0.precio');
});

it('al convertir manda el precio cotizado, no el de la lista de hoy', function () {
    $cot = cotizaDe(10, 19800.0);
    $cot->update(['estado' => 'aceptada']);

    // La lista sube DESPUÉS de cotizar. La cotización vale lo que decía.
    PrecioVariante::where('lista_id', $this->lista->id)->update(['precio' => 30000]);

    $pedido = app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id);

    expect((float) $pedido->items->first()->precio_unitario)->toBe(19800.0)
        ->and((float) $pedido->subtotal)->toBe(198000.0);
});

it('la cotización queda marcada con el pedido que salió de ella', function () {
    $cot = cotizaDe(5);
    $cot->update(['estado' => 'aceptada']);

    $pedido = app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id);

    expect($cot->fresh()->estado)->toBe('convertida')
        ->and($cot->fresh()->pedido_id)->toBe($pedido->id)
        ->and($cot->fresh()->convertida_at)->not->toBeNull();
});

it('no se convierte dos veces', function () {
    $cot = cotizaDe(5);
    $cot->update(['estado' => 'aceptada']);

    app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id);

    // Dos clics seguidos crearían dos pedidos iguales.
    expect(fn () => app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    expect(PedidoCliente::count())->toBe(1);
});

it('no se convierte si el cliente todavía no la aceptó', function () {
    $cot = cotizaDe(5);   // queda en borrador

    expect($cot->sePuedeConvertir())->toBeFalse()
        ->and($cot->porQueNoSeConvierte())->toContain('acepte');
});

it('no se convierte una vencida: hay que recotizar con los precios de hoy', function () {
    $cot = cotizaDe(5, null, null, now()->subDay()->toDateString());
    $cot->update(['estado' => 'aceptada']);

    expect($cot->fresh()->estaVigente())->toBeFalse()
        ->and($cot->fresh()->porQueNoSeConvierte())->toContain('venció');

    expect(fn () => app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('una que vence hoy todavía vale hoy', function () {
    $cot = cotizaDe(5, null, null, now()->toDateString());

    // Vencerla a las 00:00 del día que dice «vigente hasta» sería quitarle un
    // día al cliente sin avisarle.
    expect($cot->estaVigente())->toBeTrue();
});

it('el estado «vencida» se calcula, no se guarda', function () {
    $cot = cotizaDe(5, null, null, now()->subDay()->toDateString());
    $cot->update(['estado' => 'enviada']);

    // Guardarlo obligaría a correr un proceso diario, y una cotización que
    // venció anoche aparecería como vigente hasta que ese proceso corriera.
    expect($cot->fresh()->estado)->toBe('enviada')
        ->and($cot->fresh()->estadoVisible())->toBe('vencida');
});

it('rechazar exige decir por qué', function () {
    $cot = cotizaDe(5);

    $this->actingAs($this->carlos)
        ->post("/app/cotizaciones/{$cot->id}/responder", ['respuesta' => 'rechazada'])
        ->assertSessionHasErrors('motivo_rechazo');

    $this->actingAs($this->carlos)
        ->post("/app/cotizaciones/{$cot->id}/responder",
            ['respuesta' => 'rechazada', 'motivo_rechazo' => 'Nos ganaron por precio'])
        ->assertRedirect();

    expect($cot->fresh()->motivo_rechazo)->toBe('Nos ganaron por precio');
});

it('un vendedor no abre la cotización de un compañero', function () {
    $cot = cotizaDe(5, null, $this->carlos->id);

    $this->actingAs($this->marta)
        ->get("/app/cotizaciones/{$cot->id}")
        ->assertForbidden();

    // Quien manda sí la ve.
    $this->actingAs($this->jefa)
        ->get("/app/cotizaciones/{$cot->id}")
        ->assertOk();
});

it('el listado de un vendedor sólo trae lo suyo', function () {
    cotizaDe(5, null, $this->carlos->id);
    cotizaDe(5, null, $this->marta->id);

    $mias = $this->actingAs($this->carlos)
        ->get('/app/cotizaciones')
        ->viewData('page')['props']['cotizaciones']['data'];

    expect($mias)->toHaveCount(1)
        ->and($mias[0]['vendedor'])->toBe('Carlos Vendedor');
});

it('avisa de lo aceptado que está por vencerse sin convertir', function () {
    Reglas::set('ventas.dias_aviso_cotizacion', 3);
    Reglas::limpiarRequestCache();

    $porVencer = cotizaDe(10, null, $this->carlos->id, now()->addDays(2)->toDateString());
    $porVencer->update(['estado' => 'aceptada']);

    $tranquila = cotizaDe(10, null, $this->carlos->id, now()->addDays(20)->toDateString());
    $tranquila->update(['estado' => 'aceptada']);

    $resumen = $this->actingAs($this->carlos)
        ->get('/app/cotizaciones')
        ->viewData('page')['props']['resumen'];

    // Es plata que el cliente YA dijo que sí y se pierde por no pasarla a pedido.
    expect($resumen['por_vencer'])->toBe(1)
        ->and($resumen['monto_por_vencer'])->toBe((float) $porVencer->total)
        ->and($resumen['aceptadas_sin_convertir'])->toBe(2);
});

it('sin lista de precios no se puede cotizar', function () {
    $this->cliente->update(['lista_precios_id' => null]);

    expect(fn () => cotizaDe(5))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('un vendedor no le cotiza al cliente de un compañero', function () {
    $this->cliente->update(['vendedor_id' => $this->carlos->id]);

    $cuerpo = ['items' => [['variante_id' => $this->variante->id, 'cantidad' => 5]]];
    $url = "/app/vendedor/cotizacion-nueva/{$this->cliente->id}";

    /*
     * El buscador de clientes ya filtra por cartera, pero eso es comodidad: con
     * la URL del cliente de un compañero se colaba una cotización —y después un
     * pedido real— a nombre de quien no atiende esa cuenta.
     */
    $this->actingAs($this->marta)->post($url, $cuerpo)->assertForbidden();

    expect(Cotizacion::count())->toBe(0);

    // El dueño de la cuenta sí.
    $this->actingAs($this->carlos)->post($url, $cuerpo)->assertRedirect();

    // Y gerencia también, que tiene que poder cotizar por teléfono.
    $this->actingAs($this->jefa)->post($url, $cuerpo)->assertRedirect();

    expect(Cotizacion::count())->toBe(2);
});

it('una cuenta sin dueño la cotiza cualquiera: es cuenta libre', function () {
    expect($this->cliente->vendedor_id)->toBeNull();

    // Quitarle las cuentas libres al equipo sería cambiarle el oficio.
    $this->actingAs($this->marta)
        ->post("/app/vendedor/cotizacion-nueva/{$this->cliente->id}",
            ['items' => [['variante_id' => $this->variante->id, 'cantidad' => 5]]])
        ->assertRedirect();

    expect(Cotizacion::first()->vendedor_id)->toBe($this->marta->id);
});

it('la venta es del vendedor que cotizó, no de quien apretó el botón', function () {
    $cot = cotizaDe(10, null, $this->carlos->id);
    $cot->update(['estado' => 'aceptada']);

    /*
     * Gerencia tiene que poder convertir: el vendedor está en la calle y el
     * cliente llamó. Pero la comisión no se muda con el clic.
     */
    $pedido = app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->jefa->id);

    expect((int) $pedido->vendedor_id)->toBe($this->carlos->id)
        ->and((int) $cot->fresh()->convertida_por)->toBe($this->jefa->id);
});

it('la cotización sin vendedor queda de quien la convirtió, no huérfana', function () {
    $cot = cotizaDe(10, null, $this->carlos->id);
    $cot->update(['estado' => 'aceptada', 'vendedor_id' => null]);

    /*
     * Un pedido sin vendedor NO es neutro: `CrearPedidoCliente` lo trata como
     * cliente autogestionado y le aplica el bloqueo por stock del portal.
     */
    $pedido = app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->jefa->id);

    expect((int) $pedido->vendedor_id)->toBe($this->jefa->id);
});

it('la pantalla sólo nombra a quien convirtió cuando no es el vendedor', function () {
    $propia = cotizaDe(5, null, $this->carlos->id);
    $propia->update(['estado' => 'aceptada']);
    app(ConvertirCotizacionEnPedido::class)->ejecutar($propia->fresh(), $this->carlos->id);

    // «Convertida por Carlos» debajo de «Vendedor: Carlos» es ruido.
    $props = $this->actingAs($this->carlos)
        ->get("/app/cotizaciones/{$propia->id}")->viewData('page')['props']['cotizacion'];

    expect($props['convertida_por'])->toBeNull();

    $ajena = cotizaDe(5, null, $this->carlos->id);
    $ajena->update(['estado' => 'aceptada']);
    app(ConvertirCotizacionEnPedido::class)->ejecutar($ajena->fresh(), $this->jefa->id);

    $props = $this->actingAs($this->carlos)
        ->get("/app/cotizaciones/{$ajena->id}")->viewData('page')['props']['cotizacion'];

    expect($props['convertida_por'])->toBe($this->jefa->name)
        ->and($props['vendedor'])->toBe('Carlos Vendedor');
});

it('un renglón cotizado no desaparece porque le quitaron el precio de la lista', function () {
    $cot = cotizaDe(10, 19800.0);
    $cot->update(['estado' => 'aceptada']);

    /*
     * Sacar un producto de la lista es una decisión de catálogo; no anula un
     * precio que ya se le prometió a un cliente. Antes la línea se descartaba
     * ANTES de llegar al pactado y el pedido nacía sin ella, en silencio, con
     * la cotización marcada «convertida».
     */
    PrecioVariante::where('lista_id', $this->lista->id)->delete();

    $pedido = app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id);

    expect($pedido->items)->toHaveCount(1)
        ->and((float) $pedido->items->first()->precio_unitario)->toBe(19800.0)
        ->and((float) $pedido->total)->toBe(198000.0);
});

it('si el producto ya no existe se niega la conversión en vez de recortar el pedido', function () {
    $cot = cotizaDe(10, 19800.0);
    $cot->update(['estado' => 'aceptada']);

    // Desactivarlo lo saca del catálogo vendible: la línea ya no se puede armar.
    $this->variante->producto->update(['activo' => false]);

    expect(fn () => app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    // Ni pedido a medias, ni cotización marcada como convertida.
    expect(PedidoCliente::count())->toBe(0)
        ->and($cot->fresh()->estado)->toBe('aceptada')
        ->and($cot->fresh()->pedido_id)->toBeNull();
});

it('el semáforo de cartera mide con el precio pactado, no con el de la lista', function () {
    // Cupo justo: $200.000. Lo cotizado cabe; la lista de hoy, no.
    $this->cliente->condiciones()->create([
        'cupo' => 200000, 'plazo_dias' => 30, 'activa' => true,
        'vigente_desde' => now()->subDay()->toDateString(),
    ]);

    $cot = cotizaDe(10, 19000.0);   // $190.000 cotizados
    $cot->update(['estado' => 'aceptada']);

    // La lista sube a $30.000 ⇒ $300.000, que se pasa del cupo. El pedido no
    // vale eso: vale lo que se cotizó, y el semáforo tiene que medir eso mismo.
    PrecioVariante::where('lista_id', $this->lista->id)->update(['precio' => 30000]);

    $pedido = app(ConvertirCotizacionEnPedido::class)->ejecutar($cot->fresh(), $this->carlos->id);

    expect((float) $pedido->total)->toBe(190000.0)
        ->and($pedido->estado)->not->toBe('retenido');
});

it('el vendedor le puede poner precio a algo que la lista todavía no tarifa', function () {
    // Pasa a diario: un producto nuevo que nadie tarifó en esa lista, o uno
    // que salió de ella. Antes la línea se caía en silencio.
    PrecioVariante::where('lista_id', $this->lista->id)->delete();

    $cot = cotizaDe(8, 17500.0);

    expect($cot->items)->toHaveCount(1)
        ->and((float) $cot->items->first()->precio_unitario)->toBe(17500.0)
        ->and((float) $cot->total)->toBe(140000.0);
});

it('sin precio de lista y sin precio tecleado no hay línea que cotizar', function () {
    PrecioVariante::where('lista_id', $this->lista->id)->delete();

    // El `precio_proveedor` es el COSTO: caer en él cotizaría a precio de compra.
    expect(fn () => cotizaDe(8))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('si el IVA del producto cambió, el total del pedido también y se avisa', function () {
    $cot = cotizaDe(10, 20000.0);
    $cot->update(['estado' => 'aceptada']);

    /*
     * El precio se congela porque es una promesa entre dos personas. El IVA no
     * se acuerda con nadie: lo pone la ley o lo corrige quien arregla la ficha
     * del producto, y facturar el de hace veinte días sería liquidar mal un
     * impuesto. Lo que no puede pasar es que el total cambie en silencio.
     */
    $iva = \App\Modules\Catalogo\Models\Impuesto::create([
        'codigo' => 'IVA19', 'nombre' => 'IVA 19', 'tipo' => 'IVA',
        'porcentaje' => 19, 'activo' => true,
    ]);
    $this->variante->producto->update(['impuesto_id' => $iva->id]);

    $r = $this->actingAs($this->carlos)->post("/app/cotizaciones/{$cot->id}/convertir");

    $pedido = PedidoCliente::latest('id')->first();

    expect((float) $cot->fresh()->total)->toBe(200000.0)
        ->and((float) $pedido->total)->toBe(238000.0)
        ->and(session('warning'))->toContain('Cambió el IVA');
});

it('sin cambio de impuesto el aviso no aparece', function () {
    $cot = cotizaDe(10, 20000.0);
    $cot->update(['estado' => 'aceptada']);

    $this->actingAs($this->carlos)->post("/app/cotizaciones/{$cot->id}/convertir");

    // Un aviso que sale siempre deja de leerse.
    expect(session('warning'))->toBeNull()
        ->and(session('success'))->toContain('con los precios que se cotizaron');
});

it('el consecutivo sobrevive al renglón 10.000 del día', function () {
    /*
     * `str_pad` rellena pero no recorta: pasado el 9999 el consecutivo crece a
     * cinco dígitos, y leerlo por «los últimos cuatro» devolvía 0000, así que
     * el siguiente volvía a ser 0001 contra un `numero` que es único. La
     * cotización no se creaba, y el mensaje hablaba de una llave duplicada.
     */
    $cot = cotizaDe(1);
    $prefijo = 'COT-'.now()->format('ymd').'-';

    // La del 9999: la siguiente tiene que ser la 10000, no la 0001.
    $cot->update(['numero' => $prefijo.'9999']);

    expect(cotizaDe(1)->numero)->toBe($prefijo.'10000');

    // Y de ahí para arriba sigue contando.
    expect(cotizaDe(1)->numero)->toBe($prefijo.'10001');
});
