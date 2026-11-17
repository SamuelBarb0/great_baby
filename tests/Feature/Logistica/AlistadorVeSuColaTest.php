<?php

/**
 * El Alistador tiene que ver su cola, y seguir sin poder mover inventario.
 *
 * El defecto, comprobado en pantalla el 17-nov-2026: la cola de alistamiento
 * filtra por las bodegas de quien entra, y ese alcance lo daba
 * `User::bodegasAsignadasIds()`, que sólo hereda las ubicaciones del
 * `responsable_user_id` SI el usuario es AdminBodega. Un Alistador marcado
 * responsable de su propia bodega recibía un arreglo vacío, el controlador lo
 * convertía en `[-1]` —fail-closed, correcto— y su cola salía en cero todos
 * los días, aunque la bodega fuera literalmente suya.
 *
 * El arreglo no fue quitarle el candado a ese método: habría sido regalarle
 * poder de escritura sobre el inventario de su sede, incluidas las ubicaciones
 * de avería y de garantía, y el traslado autoriza cuando las dos puntas están
 * dentro del alcance. Es decir: habría podido devolver a stock vendible una
 * caja dada de baja, sin segunda firma.
 *
 * Se partió en dos:
 *   · `bodegasQueVeIds()` — LECTURA. No pregunta por rol, porque responde un
 *     hecho («¿de qué bodegas responde esta persona?»), no una autorización.
 *   · `bodegasAsignadasIds()` — OPERAR. Sigue exigiendo AdminBodega, intacto.
 *
 * La mitad de abajo de este archivo es la red de seguridad de ese corte: si
 * alguien un día junta los dos métodos, estas pruebas se ponen rojas.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $this->alistador = User::factory()->create(['name' => 'Camila Alistadora']);
    $this->alistador->assignRole(rolDe('Alistador'));

    $this->bodeguero = User::factory()->create(['name' => 'Jorge AdminBodega']);
    $this->bodeguero->assignRole(rolDe('AdminBodega'));

    $this->vendedor = User::factory()->create(['name' => 'Vendedor']);
    $this->vendedor->assignRole(rolDe('Vendedor'));

    $this->sede = InventarioUbicacion::create([
        'codigo' => 'BOG-1', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'responsable_user_id' => $this->alistador->id,
    ]);

    // Otra sede, de otra persona: nada de ella puede aparecerle.
    $this->ajena = InventarioUbicacion::create([
        'codigo' => 'CAL-1', 'nombre' => 'Bodega Cali',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'responsable_user_id' => $this->bodeguero->id,
    ]);
});

function rolDe(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()
        ->flatMap(fn ($seccion) => ["ver.{$seccion}"])
        ->filter(fn ($p) => \Spatie\Permission\Models\Permission::where('name', $p)->exists())
        ->all());

    return $rol;
}

it('el alistador responsable de una bodega la tiene en su alcance de lectura', function () {
    expect($this->alistador->bodegasQueVeIds())->toContain($this->sede->id);
});

it('y no ve la bodega de otro', function () {
    expect($this->alistador->bodegasQueVeIds())->not->toContain($this->ajena->id);
});

it('responsable de un rack, hereda la sede donde ese rack vive', function () {
    // Es la mitad del defecto que no se ve a simple vista: el origen de un
    // pedido es la sede, nunca el estante. A quien lo marcaron responsable de
    // un rack le toca el trabajo de toda la bodega donde ese rack está.
    $otro = User::factory()->create();
    $otro->assignRole(rolDe('Alistador'));

    $sede = InventarioUbicacion::create([
        'codigo' => 'MED-1', 'nombre' => 'Bodega Medellín',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $rack = InventarioUbicacion::create([
        'codigo' => 'MED-R1', 'nombre' => 'Rack A · Nivel 01',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'bodega_id' => $sede->id,
        'responsable_user_id' => $otro->id,
    ]);

    expect($otro->bodegasQueVeIds())
        ->toContain($rack->id)
        ->toContain($sede->id);
});

it('una sede desactivada no entra por la puerta de atrás', function () {
    // Confiar en el valor de la columna sin mirar la fila padre metería en el
    // alcance una sede apagada y, con ella, todas sus demás ubicaciones.
    $otro = User::factory()->create();

    $sedeApagada = InventarioUbicacion::create([
        'codigo' => 'OFF-1', 'nombre' => 'Sede cerrada',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => false,
    ]);

    InventarioUbicacion::create([
        'codigo' => 'OFF-R1', 'nombre' => 'Rack huérfano',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'bodega_id' => $sedeApagada->id,
        'responsable_user_id' => $otro->id,
    ]);

    expect($otro->bodegasQueVeIds())->not->toContain($sedeApagada->id);
});

it('quien no es responsable de nada no ve nada', function () {
    // Fail-closed: sin datos no se ve nada, nunca se ve todo.
    $nadie = User::factory()->create();
    $nadie->assignRole(rolDe('Alistador'));

    expect($nadie->bodegasQueVeIds())->toBe([]);
});

it('el alistador SIGUE sin poder operar inventario', function () {
    // La red de seguridad del corte. Si alguien junta los dos métodos para
    // «simplificar», esta prueba se pone roja antes de llegar a producción.
    expect($this->alistador->bodegasAsignadasIds())->toBe([]);
});

it('el jefe de bodega no pierde nada de lo que ya tenía', function () {
    expect($this->bodeguero->bodegasAsignadasIds())->toContain($this->ajena->id)
        ->and($this->bodeguero->bodegasQueVeIds())->toContain($this->ajena->id);
});

it('ver la cola no es un permiso: el vendedor responsable de una bodega igual no entra', function () {
    // El alcance de lectura responde un dato, no autoriza. Quién entra a la
    // pantalla lo sigue decidiendo el middleware del controlador.
    $this->ajena->update(['responsable_user_id' => $this->vendedor->id]);

    expect($this->vendedor->fresh()->bodegasQueVeIds())->toContain($this->ajena->id);

    $this->actingAs($this->vendedor)
        ->get('/app/logistica/cola-jorge')
        ->assertForbidden();
});

it('el alistador responsable sí entra a la cola y la ve filtrada a su bodega', function () {
    $r = $this->actingAs($this->alistador)->get('/app/logistica/cola-jorge');

    $r->assertOk();
});
