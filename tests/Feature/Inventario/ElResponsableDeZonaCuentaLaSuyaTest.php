<?php

/**
 * Quien responde por un estante puede contarlo.
 *
 * El 23-nov-2026 se les entregaron las claves a los tres responsables de zona
 * de Great Baby, y ahí se vio el callejón sin salida: entraban a la pantalla de
 * conteos —el middleware sí los deja—, escribían las cantidades contadas, y al
 * guardar recibían 403.
 *
 * La causa no era el permiso de la pantalla sino el ALCANCE.
 * `User::bodegasAsignadasIds()` gobierna mover mercancía, traslados incluidos,
 * y sólo hereda la responsabilidad de una ubicación cuando el rol es
 * AdminBodega —porque de esa misma casilla cuelgan los traslados entre
 * bodegas—. Los responsables de zona son Alistador, así que ese método les
 * devolvía un array vacío, y `in_array($ubicacion, [])` es siempre falso.
 *
 * Contar un estante del que uno responde no es operar la bodega: no mueve
 * mercancía de sitio, la cuenta donde está. Por eso el conteo pasa a mirar
 * `ubicacionesQuePuedeContar()`, que hereda por `responsable_user_id` sin pedir
 * rol — y los traslados siguen exigiendo lo de siempre, que es lo que estos
 * casos cuidan además.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Models\TomaFisica;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    // Un responsable de zona tal como lo crea el seeder: rol Alistador, sin
    // casilla de bodega.
    $this->responsable = User::factory()->create(['name' => 'Responsable 2']);
    $this->responsable->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    $this->ajeno = User::factory()->create(['name' => 'Alistador de otra bodega']);
    $this->ajeno->assignRole(Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']));

    $this->zona = InventarioUbicacion::create([
        'codigo' => 'ZR-SEDE', 'nombre' => 'Bodega de la zona 2',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true,
        'responsable_user_id' => $this->responsable->id,
    ]);

    // Un rack colgando de esa bodega: es donde ocurre el conteo de verdad.
    $this->rack = InventarioUbicacion::create([
        'codigo' => 'ZR-R7-N1', 'nombre' => 'Rack 7 nivel 1',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true,
        'bodega_id' => $this->zona->id, 'pasillo' => '7',
    ]);

    // Y una bodega de la que no responde nadie de este test.
    $this->otraSede = InventarioUbicacion::create([
        'codigo' => 'ZR-OTRA', 'nombre' => 'Bodega de otro',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true,
    ]);

    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-ZR-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => 9000,
    ]));

    foreach ([$this->rack->id, $this->otraSede->id] as $donde) {
        InventarioMovimiento::create([
            'producto_id' => $producto->id, 'ubicacion_id' => $donde,
            'tipo' => 'entrada_compra', 'cantidad' => 10, 'costo_unit' => 9000,
        ]);
    }
});

function crearConteo(User $quien, InventarioUbicacion $donde)
{
    return test()->actingAs($quien)->post('/app/inventario/conteos', [
        'ubicacion_id' => $donde->id, 'tipo' => 'ciclico',
    ]);
}

it('el responsable de una zona abre el conteo de un rack suyo', function () {
    // El rack cuelga de la bodega que tiene a cargo: quien responde por la
    // bodega responde por sus estantes, que es donde se cuenta.
    crearConteo($this->responsable, $this->rack)->assertSessionHas('success');

    expect(TomaFisica::where('ubicacion_id', $this->rack->id)->count())->toBe(1);
});

it('y también el de la bodega que tiene a cargo', function () {
    crearConteo($this->responsable, $this->zona)->assertSessionHas('success');

    expect(TomaFisica::where('ubicacion_id', $this->zona->id)->count())->toBe(1);
});

it('pero no el de una bodega que no es suya', function () {
    // El alcance se ensanchó por responsabilidad, no de par en par.
    crearConteo($this->responsable, $this->otraSede)->assertForbidden();

    expect(TomaFisica::count())->toBe(0);
});

it('un alistador que no responde por nada sigue sin poder contar', function () {
    crearConteo($this->ajeno, $this->rack)->assertForbidden();

    expect(TomaFisica::count())->toBe(0);
});

it('contar NO le abre los traslados', function () {
    /*
     * Lo que no se podía romper. Mover mercancía entre bodegas lo autoriza el
     * jefe de logística —decisión del área, y por eso `traslados` salió de la
     * matriz del Alistador—. Si el alcance del conteo se hubiera metido en
     * `bodegasAsignadasIds()` en vez de vivir aparte, el responsable de zona
     * habría estrenado también los traslados sin que nadie lo decidiera.
     */
    expect($this->responsable->ubicacionesQuePuedeContar())->toContain($this->rack->id)
        ->and($this->responsable->bodegasAsignadasIds())->not->toContain($this->rack->id);

    $this->actingAs($this->responsable)
        ->post('/app/inventario/traslados', [
            'origen_id' => $this->rack->id,
            'destino_id' => $this->otraSede->id,
            'motivo' => 'No debería poder',
        ])->assertForbidden();
});

it('el responsable de zona aparece en la cola para que le asignen pedidos', function () {
    /*
     * La otra mitad del callejón: la cola de alistamiento excluía estas cuentas
     * del selector de alistadores, porque nacían sin contraseña utilizable y
     * asignarles un pedido lo parqueaba. Desde que son personas que entran a
     * trabajar, dejarlas fuera significa que Don Jorge no puede darles una
     * sola tarea.
     */
    $jorge = User::factory()->create(['name' => 'Jorge · Jefe de Logística']);
    $jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));

    $this->responsable->forceFill(['email' => 'responsable2@greatbaby.com'])->save();

    $this->actingAs($jorge)
        ->get('/app/logistica/cola-jorge')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'alistadores',
            fn ($lista) => collect($lista)->pluck('name')->contains('Responsable 2')
        ));
});
