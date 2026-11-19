<?php

/**
 * Los flujos de bodega tienen que funcionar con productos AGREGADOS.
 *
 * Un producto agregado es el que no se desglosa por variante: el Excel del
 * cliente cargó 134 así, con una sola existencia por referencia. El plan de
 * trabajo (docs/desglose-dual.md) todavía lista cinco flujos como «no los
 * soportan», pero el código dice que sí desde la ronda C-F2 R2 — y nadie lo
 * había comprobado: los 19 tests del desglose dual cubren los cimientos
 * (constraints, StockService, reservas), ninguno recorre estos flujos.
 *
 * Esto cierra esa brecha. Si mañana alguien vuelve a asumir «sólo variantes»
 * en uno de estos puntos, se cae un test en vez de caerse el inventario de
 * Aracely sin que nadie se entere.
 *
 *   · traslado entre bodegas de un producto agregado;
 *   · la toma física lo incluye en el conteo;
 *   · recibir mercancía de una orden de compra lo ingresa al kardex.
 */

use App\Modules\Compras\Actions\RecibirMercancia;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\EjecutarTraslado;
use App\Modules\Inventario\Actions\PrepararTomaFisica;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Inventario\Models\TrasladoItem;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    // El traslado deja huella de quién movió la mercancía y exige permiso de
    // inventario: sin eso aborta con 403 antes de tocar el kardex.
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    $rol = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $usuario = App\Models\User::factory()->create();
    $usuario->assignRole($rol);
    $this->actingAs($usuario);

    $this->origen = InventarioUbicacion::create([
        'codigo' => 'ORI', 'nombre' => 'Bodega origen',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->destino = InventarioUbicacion::create([
        'codigo' => 'DES', 'nombre' => 'Bodega destino',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    // Producto AGREGADO: sin desglose por variante.
    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'AGR-001',
        'nombre' => 'Pañal recién nacido x40',
        'desglose_stock' => false,
        'stock_directo' => 0,
        'precio_proveedor' => 9000,
    ]));

    InventarioMovimiento::create([
        'producto_id' => $this->producto->id,
        'ubicacion_id' => $this->origen->id,
        'tipo' => 'entrada_compra',
        'cantidad' => 40,
        'costo_unit' => 9000,
    ]);
});

/** Saldo físico de un producto agregado en una ubicación. */
function saldoAgregado(int $productoId, int $ubicacionId): float
{
    return (float) InventarioMovimiento::where('producto_id', $productoId)
        ->whereNull('variante_id')
        ->where('ubicacion_id', $ubicacionId)
        ->sum('cantidad');
}

it('traslada un producto agregado entre bodegas', function () {
    $traslado = Traslado::create([
        'numero' => 'TRA-AGR-001',
        'origen_id' => $this->origen->id,
        'destino_id' => $this->destino->id,
        'estado' => 'borrador',
        'motivo' => 'reposicion',
        'fecha_solicitud' => now()->toDateString(),
    ]);
    TrasladoItem::create([
        'traslado_id' => $traslado->id,
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'cantidad_solicitada' => 15,
    ]);

    $accion = app(EjecutarTraslado::class);
    $accion->enviar($traslado->fresh());
    $accion->recibir($traslado->fresh());

    expect(saldoAgregado($this->producto->id, $this->origen->id))->toBe(25.0)
        ->and(saldoAgregado($this->producto->id, $this->destino->id))->toBe(15.0);
});

it('la toma física incluye los productos agregados', function () {
    // Si no los incluyera, el conteo mostraría la bodega incompleta y el
    // ajuste posterior daría de baja existencias que sí están.
    $toma = TomaFisica::create([
        'numero' => 'TF-AGR-001',
        'ubicacion_id' => $this->origen->id,
        'estado' => 'borrador',
        'fecha_conteo' => now()->toDateString(),
    ]);

    app(PrepararTomaFisica::class)->handle($toma);

    $items = $toma->fresh()->load('items')->items;
    $delAgregado = $items->firstWhere('producto_id', $this->producto->id);

    expect($delAgregado)->not->toBeNull()
        ->and($delAgregado->variante_id)->toBeNull()
        ->and((float) $delAgregado->saldo_sistema)->toBe(40.0);
});

it('recibir mercancía ingresa al kardex un producto agregado', function () {
    $proveedor = App\Models\Contacto::create([
        'numero_documento' => '900111222',
        'nombre_completo' => 'Importadora de Prueba SAS',
    ]);

    $orden = App\Modules\Compras\Models\OrdenCompra::create([
        'numero' => 'OC-AGR-001',
        'proveedor_id' => $proveedor->id,
        'estado' => 'aprobada',
        'fecha_emision' => now()->toDateString(),
    ]);

    $recepcion = App\Modules\Compras\Models\RecepcionCompra::create([
        'numero' => 'REC-AGR-001',
        'orden_id' => $orden->id,
        'bodega_id' => $this->destino->id,
        'estado' => 'borrador',
        'fecha_recepcion' => now()->toDateString(),
    ]);

    $ordenItem = App\Modules\Compras\Models\OrdenCompraItem::create([
        'orden_id' => $orden->id,
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'descripcion' => $this->producto->nombre,
        'cantidad' => 20,
        'precio_unit' => 9500,
        'subtotal' => 190000,
        'total' => 190000,
    ]);

    App\Modules\Compras\Models\RecepcionCompraItem::create([
        'recepcion_id' => $recepcion->id,
        'orden_item_id' => $ordenItem->id,
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'cantidad_recibida' => 20,
        'costo_unit' => 9500,
    ]);

    app(RecibirMercancia::class)->handle($recepcion->fresh());

    expect(saldoAgregado($this->producto->id, $this->destino->id))->toBe(20.0);

    $mov = InventarioMovimiento::where('producto_id', $this->producto->id)
        ->where('ubicacion_id', $this->destino->id)
        ->whereNull('variante_id')
        ->latest('id')->first();

    expect($mov->variante_id)->toBeNull()
        ->and((float) $mov->costo_unit)->toBe(9500.0);
});

it('la liquidación de importación ingresa al kardex un producto agregado', function () {
    // Es como aterriza un contenedor: se prorratean los gastos sobre las
    // líneas y cada una entra a la bodega de su orden con el costo final.
    $proveedor = App\Models\Contacto::create([
        'numero_documento' => '900333444',
        'nombre_completo' => 'Proveedor China Co.',
    ]);

    $orden = App\Modules\Compras\Models\OrdenCompra::create([
        'numero' => 'OC-IMP-001',
        'proveedor_id' => $proveedor->id,
        'bodega_id' => $this->destino->id,
        'estado' => 'aprobada',
        'fecha_emision' => now()->toDateString(),
        'tipo' => 'importacion',
    ]);

    App\Modules\Compras\Models\OrdenCompraItem::create([
        'orden_id' => $orden->id,
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'descripcion' => $this->producto->nombre,
        'cantidad' => 30,
        'precio_unit' => 8000,
        'subtotal' => 240000,
        'total' => 240000,
    ]);

    $imp = App\Modules\Compras\Models\Importacion::create([
        'numero' => 'IMP-AGR-001',
        'estado' => 'en_transito',
        'moneda_origen' => 'USD',
        'tasa_cambio_liquidacion' => 1,
    ]);
    $imp->ordenes()->attach($orden->id, ['peso_kg' => 100, 'volumen_m3' => 2]);

    App\Modules\Compras\Models\GastoImportacion::create([
        'importacion_id' => $imp->id,
        'concepto' => 'flete',
        'descripcion' => 'Flete marítimo del contenedor',
        'monto' => 60000,
        'monto_base' => 60000,
        'capitalizable' => true,
        'metodo_prorrateo' => 'valor',
        'fecha' => now()->toDateString(),
    ]);

    // El stock entra cuando bodega lo acepta; liquidar sólo le corrige el costo.
    $recepcion = App\Modules\Compras\Models\RecepcionCompra::create([
        'numero' => 'REC-AGR-001', 'orden_id' => $orden->id, 'bodega_id' => $this->destino->id,
        'estado' => 'borrador', 'fecha_recepcion' => now(),
    ]);
    $recepcion->items()->create([
        'orden_item_id' => $orden->items()->first()->id, 'producto_id' => $this->producto->id,
        'cantidad_recibida' => 30, 'costo_unit' => 8000, 'clasificacion' => 'apto',
    ]);
    App\Modules\Compras\Actions\RecibirMercancia::run($recepcion);

    app(App\Modules\Compras\Actions\LiquidarImportacion::class)->handle($imp->fresh());

    expect(saldoAgregado($this->producto->id, $this->destino->id))->toBe(30.0);

    $mov = InventarioMovimiento::where('producto_id', $this->producto->id)
        ->where('ubicacion_id', $this->destino->id)
        ->whereNull('variante_id')->latest('id')->first();

    // Costo final = FOB 8.000 + los 60.000 de flete repartidos en 30 unidades.
    expect($mov->variante_id)->toBeNull()
        ->and((float) $mov->costo_unit)->toBe(10000.0);
});

// ── Por la RUTA, no por el Action ────────────────────────────────────────────
//
// Las pruebas de arriba llaman a los Actions directamente, y por eso el
// defecto sobrevivió: los Actions aceptaban el producto agregado desde
// INV-A7, el formulario nunca lo mandó. El bodeguero escribía la referencia,
// el desplegable le mostraba «todas las presentaciones», la clickeaba… y el
// botón «Agregar» se quedaba gris para siempre, sin un solo mensaje.

it('agrega un producto agregado al traslado por la ruta que usa la pantalla', function () {
    $traslado = Traslado::create([
        'numero' => 'TRA-AGR-RUTA',
        'origen_id' => $this->origen->id,
        'destino_id' => $this->destino->id,
        'estado' => 'borrador',
        'motivo' => 'reposicion',
        'fecha_solicitud' => now()->toDateString(),
    ]);

    $this->post("/app/inventario/traslados/{$traslado->id}/items", [
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'cantidad' => 12,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $item = TrasladoItem::where('traslado_id', $traslado->id)->firstOrFail();

    expect($item->producto_id)->toBe($this->producto->id)
        ->and($item->variante_id)->toBeNull()
        ->and((float) $item->cantidad_solicitada)->toBe(12.0);
});

it('no deja mandar variante Y producto al mismo tiempo', function () {
    /*
     * El servidor valida XOR. La pantalla manda uno de los dos en null porque
     * el buscador devuelve uno u otro, pero si algún día se cruzan, acá se
     * nota.
     */
    $traslado = Traslado::create([
        'numero' => 'TRA-AGR-XOR',
        'origen_id' => $this->origen->id,
        'destino_id' => $this->destino->id,
        'estado' => 'borrador',
        'motivo' => 'reposicion',
        'fecha_solicitud' => now()->toDateString(),
    ]);

    $this->post("/app/inventario/traslados/{$traslado->id}/items", [
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'cantidad' => 0,
    ])->assertSessionHasErrors('cantidad');

    expect(TrasladoItem::where('traslado_id', $traslado->id)->count())->toBe(0);
});

it('configura una alerta de stock sobre un producto agregado, y la tabla no la deja en blanco', function () {
    /*
     * El backend lo acepta desde INV-A5 y el panel de Filament lo rotula
     * «AGREGADO». La pantalla de Vue mandaba sólo la variante, y además
     * resolvía el nombre y el SKU únicamente por ella: una alerta de agregado
     * —las que entran por el Excel masivo— salía con las dos columnas vacías.
     */
    $this->post('/app/inventario/alertas', [
        'producto_id' => $this->producto->id,
        'variante_id' => null,
        'stock_minimo' => 5,
        'notificar_email' => true,
        'notificar_whatsapp' => false,
        'activa' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get('/app/inventario/alertas')
        ->assertOk()
        ->assertInertia(function ($p) {
            $fila = collect($p->toArray()['props']['alertas']['data'])->first();

            expect($fila['producto_id'])->toBe($this->producto->id)
                ->and($fila['es_agregado'])->toBeTrue()
                // Lo que antes salía vacío.
                ->and($fila['producto'])->toBe('Pañal recién nacido x40')
                ->and($fila['sku'])->toBe('AGR-001');
        });
});
