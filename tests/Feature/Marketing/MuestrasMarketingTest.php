<?php

/**
 * MKT-5 · Muestras a Marketing, de punta a punta.
 *
 * Lo que pidió el cliente: «donde tú la solicitas, se lista y queda el
 * responsable, quién la recogió, quién la entregó y en qué estado», y que a
 * Marketing le avisen cuando esté lista.
 *
 * El préstamo ya existía (LOG-J9), pero sin aprobación, sin saber qué persona
 * se llevó la mercancía, sin el estado en que salió o volvió y sin un solo
 * aviso: Marketing tenía que ir a preguntar a bodega.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Enums\EstadoTraslado;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Inventario\Models\TrasladoItem;
use App\Modules\Inventario\Services\StockService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Sin PermisosSeeder a propósito: `Permisos::permisosSembrados()` guarda la
    // respuesta en un `static` para todo el proceso, y sembrar acá dejaba a
    // los demás tests de Marketing (que usan la matriz por rol) en 403.
    // Aracely es root y no lo necesita.
    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->laura = User::factory()->create(['name' => 'Laura Marketing']);
    $this->laura->assignRole(Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web']));

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOG-01', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->almacen = InventarioUbicacion::create([
        'codigo' => 'MKT-BOG', 'nombre' => 'Almacén Marketing',
        'categoria' => CategoriaUbicacion::ReservaProveedor, 'activa' => true,
    ]);

    $producto = Producto::create([
        'referencia' => 'MKT-01', 'nombre' => 'Body verano', 'precio_proveedor' => 3000, 'activo' => true,
    ]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $producto->id, 'talla' => '0-3', 'color_codigo' => 'BL', 'color_nombre' => 'Blanco',
    ]);
    InventarioMovimiento::create([
        'variante_id' => $this->variante->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'ingreso', 'cantidad' => 20, 'created_at' => now(),
    ]);
});

/** Marketing pide desde su panel; devuelve el traslado creado. */
function pedirMuestras($test): Traslado
{
    $test->actingAs($test->laura)->post('/app/marketing/prestamo', [
        'origen_id' => $test->bodega->id,
        'destino_id' => $test->almacen->id,
        'observaciones' => 'Bodies de verano para las fotos del viernes',
    ])->assertSessionHas('success');

    return Traslado::latest('id')->firstOrFail();
}

function conItems(Traslado $t, $variante, int $cantidad = 3): Traslado
{
    TrasladoItem::create(['traslado_id' => $t->id, 'variante_id' => $variante->id, 'cantidad_solicitada' => $cantidad]);

    return $t;
}

it('la solicitud nueva le avisa a quien aprueba', function () {
    $t = pedirMuestras($this);

    $aviso = NotificacionErp::where('user_id', $this->aracely->id)->sole();
    expect($aviso->tipo)->toBe('marketing_muestras')
        ->and($aviso->titulo)->toContain($t->numero)
        ->and($aviso->mensaje)->toContain('Bodies de verano')
        ->and($aviso->url)->toBe("/app/inventario/traslados/{$t->id}");
});

it('bodega no puede marcarla lista sin aprobación', function () {
    $t = conItems(pedirMuestras($this), $this->variante);

    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/enviar")
        ->assertSessionHas('error', fn ($e) => str_contains($e, 'no está aprobada'));

    expect($t->fresh()->estado)->toBe(EstadoTraslado::Borrador);
});

it('recorre el flujo completo y deja cada responsable', function () {
    $t = conItems(pedirMuestras($this), $this->variante, 3);

    // Aprueba
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/aprobar")->assertSessionHas('success');
    expect($t->fresh()->aprobado_por)->toBe($this->aracely->id)
        ->and(NotificacionErp::where('user_id', $this->laura->id)->where('tipo', 'marketing_muestras_aprobada')->exists())->toBeTrue();

    // Bodega alista: «lista para recoger» y aviso a Marketing
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/enviar")->assertSessionHas('success');
    expect($t->fresh()->estado)->toBe(EstadoTraslado::EnTransito)
        ->and(NotificacionErp::where('user_id', $this->laura->id)->where('tipo', 'marketing_muestras_lista')->value('titulo'))
        ->toContain('listas para recoger');

    // Entrega firmada: quién la recogió y en qué estado
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/recibir", [
        'recogido_por' => $this->laura->id,
        'estado_mercancia' => 'completo',
    ])->assertSessionHas('success');

    $t->refresh();
    expect($t->estado)->toBe(EstadoTraslado::Recibido)
        ->and($t->recogido_por)->toBe($this->laura->id)
        ->and($t->ejecutado_por)->toBe($this->aracely->id)
        ->and($t->estado_mercancia)->toBe('completo')
        ->and(app(StockService::class)->saldoFisico($this->variante->id, $this->almacen->id))->toBe(3);

    // La ficha del traslado abre y muestra las firmas. Antes de MKT-5 daba
    // 500 para TODO traslado: filtraba el kardex por `referencia_type`, y la
    // columna se llama `referencia_tipo`.
    $m = $this->actingAs($this->aracely)->get("/app/inventario/traslados/{$t->id}")->assertOk()
        ->viewData('page')['props']['traslado']['muestra'];
    expect($m['aprobado_por'])->toBe('Aracely')
        ->and($m['recogido_por'])->toBe('Laura Marketing');

    // El panel de Marketing lo lista con el paso y las firmas
    $p = $this->actingAs($this->laura)->get('/app/marketing')->assertOk()
        ->viewData('page')['props']['prestamos']['abiertos'][0];
    expect($p['paso'])->toBe('Entregada')
        ->and($p['aprobado_por'])->toBe('Aracely')
        ->and($p['recogido_por'])->toBe('Laura Marketing')
        ->and($p['estado_mercancia_label'])->toBe('Completa y en buen estado');
});

it('si no sale completa exige decir qué pasó', function () {
    $t = conItems(pedirMuestras($this), $this->variante);
    $t->update(['aprobado_por' => $this->aracely->id, 'aprobado_at' => now()]);
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/enviar");

    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/recibir", [
        'recogido_por' => $this->laura->id,
        'estado_mercancia' => 'con_novedad',
    ])->assertSessionHasErrors('observaciones_entrega');

    expect($t->fresh()->estado)->toBe(EstadoTraslado::EnTransito);
});

it('la devolución cierra su préstamo y anota cómo volvió', function () {
    $t = conItems(pedirMuestras($this), $this->variante, 3);
    $t->update(['aprobado_por' => $this->aracely->id, 'aprobado_at' => now()]);
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/enviar");
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/recibir", [
        'recogido_por' => $this->laura->id, 'estado_mercancia' => 'completo',
    ]);

    $this->actingAs($this->laura)->post('/app/marketing/prestamo', [
        'origen_id' => $this->almacen->id,
        'destino_id' => $this->bodega->id,
        'prestamo_id' => $t->id,
    ])->assertSessionHas('success');
    $dev = conItems(Traslado::latest('id')->firstOrFail(), $this->variante, 3);
    expect($dev->prestamo_id)->toBe($t->id);

    // La devolución no pasa por aprobación: bodega la despacha y la recibe.
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$dev->id}/enviar")->assertSessionHas('success');
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$dev->id}/recibir", [
        'recogido_por' => $this->laura->id,
        'estado_mercancia' => 'con_novedad',
        'observaciones_entrega' => 'Un body volvió manchado',
    ])->assertSessionHas('success');

    expect($dev->fresh()->observaciones_entrega)->toBe('Un body volvió manchado')
        ->and(app(StockService::class)->saldoFisico($this->variante->id, $this->almacen->id))->toBe(0)
        ->and(NotificacionErp::where('user_id', $this->laura->id)->where('tipo', 'marketing_muestras_devuelta')->value('mensaje'))
        ->toContain('Un body volvió manchado');
});

it('no deja colgar una devolución de un préstamo ajeno o sin entregar', function () {
    $t = pedirMuestras($this); // sigue en borrador

    $this->actingAs($this->laura)->post('/app/marketing/prestamo', [
        'origen_id' => $this->almacen->id,
        'destino_id' => $this->bodega->id,
        'prestamo_id' => $t->id,
    ])->assertStatus(422);
});

it('rechazarla le avisa a quien la pidió con el motivo', function () {
    $t = pedirMuestras($this);

    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/anular", [
        'motivo' => 'No hay stock de verano todavía',
    ])->assertSessionHas('success');

    expect(NotificacionErp::where('user_id', $this->laura->id)->where('tipo', 'marketing_muestras_anulada')->value('mensaje'))
        ->toContain('No hay stock de verano');
});

it('un traslado normal entre bodegas no cambia', function () {
    $otra = InventarioUbicacion::create([
        'codigo' => 'MED-01', 'nombre' => 'Bodega Medellín',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $t = conItems(Traslado::create([
        'numero' => Traslado::siguienteNumero(), 'origen_id' => $this->bodega->id, 'destino_id' => $otra->id,
        'motivo' => 'reposicion', 'fecha_solicitud' => now(), 'estado' => EstadoTraslado::Borrador,
    ]), $this->variante);

    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/enviar")->assertSessionHas('success');
    $this->actingAs($this->aracely)->post("/app/inventario/traslados/{$t->id}/recibir")->assertSessionHas('success');

    expect($t->fresh()->estado)->toBe(EstadoTraslado::Recibido)
        ->and(NotificacionErp::count())->toBe(0);
});
