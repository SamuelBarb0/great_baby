<?php

/**
 * Dónde cae cada persona al entrar al sistema.
 *
 * `rutaInicial()` es un `match`, y en un `match` el orden ES la regla: gana la
 * primera rama que da verdadero. Eso lo vuelve frágil de una forma que no se
 * ve leyendo el código: agregar una rama arriba le cambia el destino a gente
 * que nadie estaba tocando, y no falla nada — simplemente alguien empieza a
 * entrar a otra pantalla.
 *
 * Pasó dos veces en un mismo día (17-nov-2026):
 *
 *   · se agregó la rama del panel de Dropi por encima de la de bodega. No hizo
 *     daño, pero de casualidad: el equipo de bodega no tiene esa casilla;
 *
 *   · y al jefe de logística se le dio el rol de líder de área —que es lo que
 *     es, además de jefe de bodega— y empezó a entrar todas las mañanas a la
 *     pantalla de pedir personal, algo que hace una vez cada tanto, en vez de
 *     a su cola, que es donde trabaja todo el día.
 *
 * La regla que esta prueba defiende: QUIEN TRABAJA EN BODEGA ENTRA A LA
 * BODEGA, tenga los sombreros que tenga.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * El rol con los permisos que le da la MATRIZ.
 *
 * Con `RefreshDatabase` la tabla de roles nace vacía y el sembrador sólo
 * reparte entre los que ya existen: un rol creado DESPUÉS del sembrado nace
 * sin un solo permiso, y entonces la persona cae al `default` del `match` y la
 * prueba acusa a `rutaInicial()` de algo que no hizo. Tiene nombre propio
 * porque las funciones de un archivo de Pest no se ven desde otro.
 */
function rolDeAterrizaje(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(\App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()
        ->flatMap(fn ($seccion) => ["ver.{$seccion}", "editar.{$seccion}", "crear.{$seccion}"])
        ->filter(fn ($p) => Permission::where('name', $p)->exists())
        ->all());

    return $rol;
}

function conRoles(array $roles): User
{
    $u = User::factory()->create();

    foreach ($roles as $r) {
        $u->assignRole(rolDeAterrizaje($r));
    }

    return $u->fresh();
}

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
});

it('el jefe de logística entra a su cola, aunque además sea líder de área', function () {
    /*
     * Es el caso de Jorge: jefe de bodega Y líder de su área. Lleva los dos
     * sombreros a propósito —por eso puede pedir un alistador nuevo— y eso no
     * le puede costar su pantalla de trabajo.
     */
    $jorge = conRoles(['AdminBodega', 'LiderArea']);

    expect($jorge->rutaInicial())->toBe('/app/logistica/cola-jorge');

    // Y sigue pudiendo pedir personal: la pantalla está, sólo no es la primera.
    expect(\App\Auth\Permisos::puede($jorge, 'rrhh_requisiciones'))->toBeTrue();
});

it('el alistador y el despachador también entran a la cola', function () {
    expect(conRoles(['Alistador'])->rutaInicial())->toBe('/app/logistica/cola-jorge')
        ->and(conRoles(['Despachador'])->rutaInicial())->toBe('/app/logistica/cola-jorge');
});

it('a quien NO es de bodega no le cambiamos nada', function () {
    // Gestión Humana y un líder que no pisa la bodega siguen entrando a lo suyo.
    expect(conRoles(['GestionHumana'])->rutaInicial())->toBe('/app/rrhh/requisiciones')
        ->and(conRoles(['LiderArea'])->rutaInicial())->toBe('/app/rrhh/requisiciones');
});

it('pedir personal queda DESPUÉS de la bodega en el orden del match', function () {
    /*
     * Esta lee el código fuente a propósito, porque es la única forma de
     * cuidar un orden: las tres pruebas de arriba sólo ven los casos que se
     * les ocurrieron a quien las escribió, y lo que rompe esto es siempre una
     * rama NUEVA que alguien mete arriba.
     *
     * Se vigila sólo el empate que decidimos: pedir personal es ocasional y va
     * después. El panel de Dropi sí está por encima, y está bien: se revisó
     * usuario por usuario que nadie de bodega tenga esa casilla.
     */
    $fuente = file_get_contents(app_path('Models/User.php'));

    $desde = mb_strpos($fuente, 'public function rutaInicial');
    $hasta = mb_strpos($fuente, 'public function', $desde + 10);
    $match = mb_substr($fuente, $desde, $hasta - $desde);

    $bodega = mb_strpos($match, "'/app/logistica/cola-jorge'");
    $personal = mb_strpos($match, "'/app/rrhh/requisiciones'");

    expect($bodega)->not->toBeFalse('la rama de bodega desapareció de rutaInicial()')
        ->and($personal)->not->toBeFalse('la rama de requisiciones desapareció de rutaInicial()');

    expect($personal)->toBeGreaterThan(
        $bodega,
        'Pedir personal quedó ANTES que la cola de bodega. El jefe de logística lleva '.
        'los dos sombreros, y así entra todas las mañanas a la pantalla que usa una vez '.
        'cada tanto en vez de a su trabajo.'
    );
});
