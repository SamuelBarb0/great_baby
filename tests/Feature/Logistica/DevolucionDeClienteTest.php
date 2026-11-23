<?php

/**
 * Lo que el cliente devuelve entra al almacén 08, y entra bien.
 *
 * Hasta el 23-nov-2026 una devolución de cliente no existía para el sistema:
 * el módulo de garantías lleva tickets pero no mueve inventario, y las
 * devoluciones que había son a proveedor —mercancía que sale—. Así que lo que
 * volvía terminaba de una de dos formas, las dos malas: en el piso sin que el
 * ERP supiera que está, o metido al almacén de venta, donde se le vuelve a
 * ofrecer a otro cliente antes de que nadie lo revise.
 *
 * Lo que estos casos cuidan, además de que la mercancía entre:
 *
 *   · que entre CON COSTO. El promedio de una ubicación se calcula al vuelo
 *     sobre las entradas de su kardex, así que una entrada sin costo deja el
 *     08 en cero — y cuando la mercancía salga de ahí por traslado, ese cero
 *     viaja al almacén bueno y ensucia su promedio.
 *   · que la bodega del 08 nazca CON RESPONSABLE. Sin él queda invisible: el
 *     alcance de bodegas se calcula desde ese campo, así que lo que acaban de
 *     recibir no les sale en el kardex ni lo pueden mover.
 *   · que confirmar sea IRREVERSIBLE. El kardex es append-only: una devolución
 *     confirmada ya contó mercancía, y borrarla dejaría stock sin documento.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Logistica\Actions\RegistrarDevolucionCliente;
use App\Modules\Logistica\Models\DevolucionCliente;
use App\Modules\Logistica\Models\DevolucionClienteItem;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->jorge = User::factory()->create(['name' => 'Jorge · Logística']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));

    $this->oficina = Oficina::firstOrCreate(
        ['codigo' => '10'],
        ['nombre' => 'GREAT BABY', 'activa' => true, 'permite_ingreso_directo' => true, 'orden' => 10]
    );

    $this->almacen08 = Almacen::firstOrCreate(
        ['codigo' => '08'],
        [
            'nombre' => 'DEVOLUCIONES Y GARANTÍAS DE CLIENTES', 'estado_fisico' => 'POR REVISAR',
            'comercial' => false, 'permite_ingreso_directo' => true,
            'categoria_equivalente' => 'garantia', 'orden' => 80, 'activo' => true,
        ]
    );

    // La bodega contra la que se recibe, con Jorge como responsable: de acá
    // hereda la del 08 su responsable, y sin eso nace invisible.
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'DVC-PPAL', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true,
        'oficina_id' => $this->oficina->id,
        'responsable_user_id' => $this->jorge->id,
        'ciudad' => 'Bucaramanga',
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901888777', 'nombre_completo' => 'Pañalera que devuelve SAS',
        'es_cliente' => true, 'ciudad' => 'Bucaramanga',
    ]);
});

function productoDevuelto(InventarioUbicacion $bodega, int $stock = 50, float $costo = 9000): Producto
{
    $p = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-DVC-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => $costo,
    ]));

    InventarioMovimiento::create([
        'producto_id' => $p->id, 'ubicacion_id' => $bodega->id,
        'tipo' => 'entrada_compra', 'cantidad' => $stock, 'costo_unit' => $costo,
    ]);

    return $p;
}

function devolucionEnBorrador($test, Producto $producto, int $cantidad, ?float $costo = null): DevolucionCliente
{
    $d = DevolucionCliente::create([
        'numero' => DevolucionCliente::siguienteNumero(),
        'contacto_id' => $test->cliente->id,
        'ubicacion_origen_id' => $test->bodega->id,
        'fecha' => now()->toDateString(),
        'motivo' => 'Llegó con la costura abierta',
        'estado' => 'borrador',
        'creada_por' => $test->jorge->id,
    ]);

    DevolucionClienteItem::create([
        'devolucion_id' => $d->id, 'producto_id' => $producto->id,
        'cantidad' => $cantidad, 'costo_unit' => $costo,
    ]);

    return $d->fresh('items');
}

/*
 * Nombre propio: en Pest las funciones de un archivo de pruebas son GLOBALES,
 * y `saldoDeLaDevolucion()` ya lo ocupa DespachoBajaDondeEstaTest. Redeclararla revienta
 * la suite entera con un fatal, no con un test en rojo.
 */
function saldoDeLaDevolucion(string $codigoUbicacion, Producto $p): float
{
    $u = InventarioUbicacion::where('codigo', $codigoUbicacion)->first();

    return $u === null ? 0.0 : (float) InventarioMovimiento::where('producto_id', $p->id)
        ->where('ubicacion_id', $u->id)->sum('cantidad');
}

it('al confirmar, la mercancía entra al almacén de devoluciones', function () {
    $producto = productoDevuelto($this->bodega);
    $d = devolucionEnBorrador($this, $producto, 3, costo: 9000);

    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar")
        ->assertRedirect();

    $d->refresh();
    $destino = InventarioUbicacion::find($d->ubicacion_destino_id);

    expect($d->estado)->toBe('confirmada')
        ->and($d->confirmada_at)->not->toBeNull()
        ->and($destino->almacen_id)->toBe($this->almacen08->id)
        // Y no es vendible: esa casilla es la que impide que una devolución
        // sin revisar vuelva a salir en un pedido.
        ->and((bool) $destino->disponible_para_venta)->toBeFalse()
        ->and(saldoDeLaDevolucion($destino->codigo, $producto))->toBe(3.0);

    // La bodega de origen no se tocó: la mercancía vuelve, no se mueve de ahí.
    expect(saldoDeLaDevolucion($this->bodega->codigo, $producto))->toBe(50.0);
});

it('la bodega de devoluciones nace con responsable, no invisible', function () {
    /*
     * El alcance de bodegas se calcula desde `responsable_user_id`. Si el par
     * nace sin él, lo que acaban de recibir no le aparece a nadie en su
     * kardex, ni en su buscador, y tampoco lo pueden mover: una bodega que
     * existe sólo para el sistema.
     */
    $producto = productoDevuelto($this->bodega);
    $d = devolucionEnBorrador($this, $producto, 2, costo: 9000);

    $this->actingAs($this->jorge)->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar");

    $destino = InventarioUbicacion::find($d->fresh()->ubicacion_destino_id);

    expect($destino->responsable_user_id)->toBe($this->jorge->id)
        ->and($destino->ciudad)->toBe('Bucaramanga');
});

it('sin costo escrito, usa el promedio de la bodega de la que salió', function () {
    $producto = productoDevuelto($this->bodega, stock: 40, costo: 7500);

    $d = DevolucionCliente::create([
        'numero' => DevolucionCliente::siguienteNumero(),
        'contacto_id' => $this->cliente->id,
        'ubicacion_origen_id' => $this->bodega->id,
        'fecha' => now()->toDateString(),
        'motivo' => 'Devolución sin costo escrito',
        'estado' => 'borrador',
    ]);

    // Por la pantalla, sin costo: el controlador lo sugiere.
    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/items", [
            'producto_id' => $producto->id,
            'cantidad' => 5,
        ])->assertRedirect();

    expect((float) $d->items()->first()->costo_unit)->toBe(7500.0);
});

it('confirmar dos veces no duplica la mercancía', function () {
    $producto = productoDevuelto($this->bodega);
    $d = devolucionEnBorrador($this, $producto, 4, costo: 9000);

    $this->actingAs($this->jorge)->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar");
    $destino = InventarioUbicacion::find($d->fresh()->ubicacion_destino_id);

    // El segundo intento no pasa del candado: ya no está en borrador.
    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar")
        ->assertSessionHasErrors('confirmar');

    expect(saldoDeLaDevolucion($destino->codigo, $producto))->toBe(4.0);
});

it('una devolución confirmada no se edita ni se borra', function () {
    /*
     * Su mercancía ya está contada, y el kardex es append-only: borrar el
     * documento dejaría stock sin papel que lo explique. Una corrección se
     * hace con otro movimiento.
     */
    $producto = productoDevuelto($this->bodega);
    $d = devolucionEnBorrador($this, $producto, 2, costo: 9000);

    $this->actingAs($this->jorge)->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar");

    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/items", [
            'producto_id' => $producto->id, 'cantidad' => 1,
        ])->assertStatus(409);

    $this->actingAs($this->jorge)
        ->delete("/app/logistica/devoluciones-cliente/{$d->id}")
        ->assertStatus(409);

    expect(DevolucionCliente::whereKey($d->id)->exists())->toBeTrue();
});

it('no se confirma una devolución sin líneas', function () {
    $d = DevolucionCliente::create([
        'numero' => DevolucionCliente::siguienteNumero(),
        'contacto_id' => $this->cliente->id,
        'ubicacion_origen_id' => $this->bodega->id,
        'fecha' => now()->toDateString(),
        'motivo' => 'Vacía',
        'estado' => 'borrador',
    ]);

    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar")
        ->assertSessionHasErrors('confirmar');

    expect($d->fresh()->estado)->toBe('borrador')
        ->and(InventarioMovimiento::where('tipo', RegistrarDevolucionCliente::TIPO)->count())->toBe(0);
});

it('marcar revisada deja constancia, y sólo después de que entró', function () {
    $producto = productoDevuelto($this->bodega);
    $d = devolucionEnBorrador($this, $producto, 2, costo: 9000);

    // En borrador la mercancía todavía no entró: no hay nada que revisar.
    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/revisada")
        ->assertStatus(409);

    $this->actingAs($this->jorge)->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar");

    $this->actingAs($this->jorge)
        ->post("/app/logistica/devoluciones-cliente/{$d->id}/revisada")
        ->assertRedirect();

    $d->refresh();

    expect($d->revisada_at)->not->toBeNull()
        ->and($d->revisada_por)->toBe($this->jorge->id)
        // Revisar NO mueve mercancía: eso lo hace el traslado, que es otra
        // pantalla con sus propios permisos.
        ->and($d->esperaRevision())->toBeFalse();
});

it('cuenta los días que lleva esperando, que es con lo que se mide el plazo', function () {
    $producto = productoDevuelto($this->bodega);
    $d = devolucionEnBorrador($this, $producto, 1, costo: 9000);

    $this->actingAs($this->jorge)->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar");

    // Entró hoy: cero días.
    expect($d->fresh()->diasEsperando())->toBe(0);

    // Tres días después sigue sin revisar: el área puso un día de plazo.
    $d->fresh()->forceFill(['confirmada_at' => now()->subDays(3)])->save();
    expect($d->fresh()->diasEsperando())->toBe(3);

    // Y una vez revisada deja de llevar días de nada.
    $d->fresh()->forceFill(['revisada_at' => now(), 'revisada_por' => $this->jorge->id])->save();
    expect($d->fresh()->diasEsperando())->toBeNull();
});

it('la pantalla abre por lo que falta revisar, y lo más viejo primero', function () {
    $producto = productoDevuelto($this->bodega, stock: 100);

    foreach ([5, 1] as $dias) {
        $d = devolucionEnBorrador($this, $producto, 1, costo: 9000);
        $this->actingAs($this->jorge)->post("/app/logistica/devoluciones-cliente/{$d->id}/confirmar");
        $d->fresh()->forceFill(['confirmada_at' => now()->subDays($dias)])->save();
    }

    $this->actingAs($this->jorge)
        ->get('/app/logistica/devoluciones-cliente')
        ->assertOk()
        ->assertInertia(function ($page) {
            $filas = collect($page->toArray()['props']['devoluciones']);

            // Lo que lleva más días va arriba: es lo que incumple el plazo, y
            // si se cae al final de la lista nadie lo revisa.
            expect($filas->pluck('dias_esperando')->all())->toBe([5, 1])
                ->and($page->toArray()['props']['filtro'])->toBe('por_revisar');
        });
});
