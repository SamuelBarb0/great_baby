<?php

/**
 * Lo que encontró el QA del 17-nov-2026, amarrado para que no vuelva.
 *
 * Son defectos de distinta naturaleza pero todos del mismo trabajo —el reparto
 * del contenedor y las dos pantallas nuevas de logística—, así que viven
 * juntos: si alguien toca ese flujo, acá se entera de cuál de los problemas
 * está reabriendo.
 *
 *   1 · la ciudad del cliente se comparaba con tilde, y «Medellin» no
 *       matcheaba la regla de «Medellín»: el pedido nacía sin bodega;
 *   2 · «Va para» —en pantalla y en el PDF que se firma— decía el destino
 *       elegido en líneas que entraron a averías;
 *   3 · el tope del reparto contaba lo marcado «faltante», que no entra;
 *   4 · una bodega apagada dejaba el par libre y la recepción creaba una
 *       gemela, partiendo el inventario en dos filas;
 *   5 · y la bodega que se crea sola nacía sin responsable, o sea invisible
 *       para quien acaba de recibir en ella.
 */

use App\Models\User;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Models\RecepcionCompraItem;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Inventario\Support\UbicacionDelPar;
use App\Modules\Portal\Models\ReglaRuteoCiudad;
use Database\Seeders\CatalogoOficinasAlmacenesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function bodegaQa(string $codigo, array $extra = []): InventarioUbicacion
{
    return InventarioUbicacion::create(array_merge([
        'codigo' => $codigo,
        'nombre' => "Bodega {$codigo}",
        'categoria' => CategoriaUbicacion::Venta,
        'activa' => true,
    ], $extra));
}

// ───────────────────────── 1 · la ciudad con tilde ─────────────────────────

it('rutea la ciudad sin importar las tildes ni las mayúsculas', function () {
    $medellin = bodegaQa('MED');
    $principal = bodegaQa('PPAL');

    ReglaRuteoCiudad::create(['ciudad' => 'Medellín', 'ubicacion_id' => $medellin->id, 'prioridad' => 10, 'activa' => true]);
    ReglaRuteoCiudad::create(['ciudad' => '*', 'ubicacion_id' => $principal->id, 'prioridad' => 99, 'activa' => true]);

    /*
     * Las formas en que aparece escrita la misma ciudad en la base de
     * clientes. Antes sólo matcheaba la idéntica: «MEDELLÍN» se iba por
     * `strtolower`, que no sabe de la Í, y «Medellin» sin tilde tampoco.
     */
    foreach (['Medellín', 'medellín', 'MEDELLÍN', 'Medellin', 'MEDELLIN', '  medellin  '] as $comoVino) {
        expect(ReglaRuteoCiudad::resolver($comoVino))
            ->toBe($medellin->id, "«{$comoVino}» debería salir de Medellín");
    }

    // Y una ciudad sin regla propia sigue cayendo al comodín.
    expect(ReglaRuteoCiudad::resolver('Neiva'))->toBe($principal->id);
});

// ───────── 2 y 3 · dónde quedó de verdad, y qué cuenta contra la orden ─────

it('dice que lo averiado quedó en averías y no en el destino que eligieron', function () {
    $destinoElegido = bodegaQa('11-01');
    $averias = bodegaQa('AVR', ['categoria' => CategoriaUbicacion::AveriaReparar]);
    $bodegaOrden = bodegaQa('PPAL');

    $orden = \App\Modules\Compras\Models\OrdenCompra::create([
        'numero' => 'OC-QA-1',
        'proveedor_id' => \App\Models\Contacto::create([
            'nombre_completo' => 'Textiles Andinos', 'numero_documento' => '901111222',
            'es_proveedor' => true, 'activo' => true,
        ])->id,
        'bodega_id' => $bodegaOrden->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'estado' => 'aprobada', 'fecha_emision' => now()->toDateString(),
        'subtotal' => 600000, 'iva' => 0, 'total' => 600000,
        'creado_por' => User::factory()->create()->id,
    ]);

    $ocItem = $orden->items()->create([
        'descripcion' => 'Set de prueba', 'cantidad' => 45, 'cantidad_recibida' => 0,
        'precio_unit' => 12000, 'subtotal' => 540000,
    ]);

    $rec = RecepcionCompra::create([
        'numero' => 'REC-QA-1', 'orden_id' => $orden->id, 'bodega_id' => $bodegaOrden->id,
        'estado' => 'borrador', 'fecha_recepcion' => now(),
    ]);

    $hacerItem = function (array $attrs) use ($rec, $ocItem) {
        $it = new RecepcionCompraItem($attrs + ['orden_item_id' => $ocItem->id]);
        $it->recepcion_id = $rec->id;
        $it->save();

        return $it;
    };

    $apta = $hacerItem(['cantidad_recibida' => 30, 'costo_unit' => 1, 'clasificacion' => 'apto', 'ubicacion_id' => $destinoElegido->id]);

    // Con destino elegido Y averiada: el destino NO se respeta, entra a averías.
    $rota = $hacerItem(['cantidad_recibida' => 10, 'costo_unit' => 1, 'clasificacion' => 'averia', 'ubicacion_id' => $destinoElegido->id]);

    $noLlego = $hacerItem(['cantidad_recibida' => 5, 'costo_unit' => 0, 'clasificacion' => 'faltante']);

    expect($apta->bodegaDondeQuedo())->toBe($destinoElegido->id)
        ->and($rota->bodegaDondeQuedo())->toBe($averias->id)
        ->and($noLlego->bodegaDondeQuedo())->toBeNull();

    // Y sin bodega de averías no se inventa una desde una simple lectura: una
    // consulta GET no puede dejar filas nuevas en el inventario.
    $averias->update(['activa' => false]);
    expect($rota->fresh()->bodegaDondeQuedo())->toBeNull()
        ->and(InventarioUbicacion::count())->toBe(3);
});

it('no cuenta lo faltante contra el pendiente de la orden', function () {
    $metodo = new ReflectionMethod(\App\Modules\Compras\Actions\RegistrarRecepcion::class, 'exigirQueElRepartoCuadre');
    $metodo->setAccessible(true);

    $item = new \App\Modules\Compras\Models\OrdenCompraItem([
        'cantidad' => 40, 'cantidad_recibida' => 0, 'descripcion' => 'Set de prueba',
    ]);
    $item->id = 1;

    $orden = new \App\Modules\Compras\Models\OrdenCompra;
    $orden->setRelation('items', collect([$item]));

    $correr = fn (array $items) => $metodo->invoke(new \App\Modules\Compras\Actions\RegistrarRecepcion, $orden, $items);

    /*
     * 30 recibidas + 30 anotadas como faltantes contra un pendiente de 40.
     * Sumando las dos da 60 y antes bloqueaba la recepción diciendo «está
     * repartiendo 60 y sólo quedan 40», mandando a revisar una suma correcta:
     * lo faltante no entra al inventario ni le descuenta nada a la orden.
     */
    $correr([
        ['orden_item_id' => 1, 'cantidad_recibida' => 30, 'clasificacion' => 'apto'],
        ['orden_item_id' => 1, 'cantidad_recibida' => 30, 'clasificacion' => 'faltante'],
    ]);

    // Y repartir de más entre destinos APTOS sigue bloqueado.
    expect(fn () => $correr([
        ['orden_item_id' => 1, 'cantidad_recibida' => 30, 'clasificacion' => 'apto'],
        ['orden_item_id' => 1, 'cantidad_recibida' => 30, 'clasificacion' => 'apto'],
    ]))->toThrow(InvalidArgumentException::class);
});

// ───────── 4 y 5 · una sola bodega por par, y con dueño ─────────

it('no crea una bodega gemela cuando la del par está apagada', function () {
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);
    $par = app(UbicacionDelPar::class);
    $of = Oficina::where('codigo', '11')->firstOrFail();
    $al = Almacen::where('codigo', '01')->firstOrFail();

    $primera = $par->resolver($of, $al);
    $antes = InventarioUbicacion::count();

    $primera->update(['activa' => false]);

    /*
     * Antes `buscar()` filtraba por activa, así que el par quedaba «libre» y
     * la siguiente recepción creaba otra fila: dos bodegas que significan lo
     * mismo con la mitad del stock cada una. Apagar una bodega es una
     * decisión; se respeta, y el mensaje dice qué fila arreglar.
     */
    expect(fn () => $par->resolver($of, $al))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(InventarioUbicacion::count())->toBe($antes);
});

it('la bodega que se crea sola hereda al responsable de la bodega de la orden', function () {
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);
    Role::findOrCreate('AdminBodega');
    $jorge = User::factory()->create(['name' => 'Jorge']);
    $jorge->assignRole('AdminBodega');

    $deLaOrden = bodegaQa('PPAL', ['responsable_user_id' => $jorge->id, 'ciudad' => 'Bogotá']);

    $nueva = app(UbicacionDelPar::class)->resolver(
        Oficina::where('codigo', '11')->firstOrFail(),
        Almacen::where('codigo', '01')->firstOrFail(),
        false,
        $deLaOrden,
    );

    /*
     * Sin responsable la bodega nueva es invisible: el alcance se calcula
     * desde `responsable_user_id`, así que lo que Jorge acababa de recibir no
     * le aparecía en su cola, ni en su kardex, ni lo podía mover.
     */
    expect($nueva->responsable_user_id)->toBe($jorge->id)
        ->and($nueva->ciudad)->toBe('Bogotá')
        ->and($jorge->bodegasQueVeIds())->toContain($nueva->id)
        ->and($jorge->bodegasAsignadasIds())->toContain($nueva->id);
});

// ───────── 6 y 7 · los dos candados que el QA encontró abiertos ─────────

it('el responsable de un estante no se puede nombrar dueño de la sede entera', function () {
    Role::findOrCreate('AdminBodega');
    Role::findOrCreate('Alistador');

    $sede = bodegaQa('PPAL');
    $rack = bodegaQa('PPAL-R1', ['bodega_id' => $sede->id]);

    // Un AdminBodega que sólo responde por un estante.
    $deUnEstante = User::factory()->create(['name' => 'Jefe de un estante']);
    $deUnEstante->assignRole('AdminBodega');
    $rack->update(['responsable_user_id' => $deUnEstante->id]);

    $otro = User::factory()->create(['name' => 'Alistador cualquiera']);
    $otro->assignRole('Alistador');

    /*
     * El alcance de LEER sube del estante a la sede a propósito: tiene que
     * poder ver la cola de su bodega. Pero ésta es una ESCRITURA, y sobre la
     * columna de la que cuelga el permiso de operar: con el alcance de leer,
     * el responsable de un estante podía repartir los responsables de toda la
     * sede —incluida la bodega de averías— y quedarse con ella.
     */
    expect($deUnEstante->bodegasQueVeIds())->toContain($sede->id)
        ->and($deUnEstante->bodegasAsignadasIds())->not->toContain($sede->id);

    $this->actingAs($deUnEstante)
        ->postJson('/app/inventario/ubicaciones/responsable', [
            'ubicacion_id' => $sede->id,
            'responsable_user_id' => $otro->id,
        ])
        ->assertStatus(403);

    expect($sede->fresh()->responsable_user_id)->toBeNull();

    // Sobre SU estante sí puede, que es para lo que existe la pantalla.
    $this->actingAs($deUnEstante)
        ->postJson('/app/inventario/ubicaciones/responsable', [
            'ubicacion_id' => $rack->id,
            'responsable_user_id' => $otro->id,
        ])
        ->assertOk();

    expect($rack->fresh()->responsable_user_id)->toBe($otro->id);
});

it('el catálogo lo mira quien administra bodegas pero lo cambia Gerencia', function () {
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);
    Role::findOrCreate('AdminBodega');
    Role::findOrCreate('Gerencia');

    $jorge = User::factory()->create(['name' => 'Jorge']);
    $jorge->assignRole('AdminBodega');

    $bajas = Almacen::where('codigo', '03')->firstOrFail();

    // Mirar, sí.
    $this->actingAs($jorge)->get('/app/inventario/catalogo-bodega')->assertOk();

    /*
     * Cambiar, no. Estas escrituras fijan reglas para TODAS las sedes: qué par
     * admite mercancía del contenedor y qué almacén es vendible. Con la
     * casilla de mirar alcanzaba, y eso dejaba que un jefe de bodega marcara
     * «se vende» el almacén de BAJAS —y la mercancía dada de baja volvía a
     * ofrecerse al cliente—.
     */
    $this->actingAs($jorge)
        ->post('/app/inventario/catalogo-bodega/almacen', [
            'id' => $bajas->id,
            'codigo' => $bajas->codigo,
            'nombre' => $bajas->nombre,
            'estado_fisico' => $bajas->estado_fisico,
            'comercial' => true,
            'permite_ingreso_directo' => false,
            'categoria_equivalente' => $bajas->categoria_equivalente,
            'activo' => true,
        ])
        ->assertStatus(403);

    expect($bajas->fresh()->comercial)->toBeFalse();
});

it('no deja guardar un almacén que dice que se vende y cuya categoría no vende', function () {
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);
    Role::findOrCreate('Gerencia');
    $gerencia = User::factory()->create();
    $gerencia->assignRole('Gerencia');

    $bajas = Almacen::where('codigo', '03')->firstOrFail();

    /*
     * `disponible_para_venta` lo recalcula el modelo desde `categoria` en su
     * hook de guardado, pase lo que pase. Así que un almacén «comercial» con
     * una categoría que no vende se guardaba sin protestar y la bodega nacía
     * NO vendible: el catálogo decía una cosa y el inventario hacía otra.
     * No se corrige a escondidas, se pide elegir.
     */
    $this->actingAs($gerencia)
        ->post('/app/inventario/catalogo-bodega/almacen', [
            'id' => $bajas->id,
            'codigo' => $bajas->codigo,
            'nombre' => $bajas->nombre,
            'estado_fisico' => $bajas->estado_fisico,
            'comercial' => true,
            'permite_ingreso_directo' => false,
            'categoria_equivalente' => CategoriaUbicacion::AveriaBaja->value,
            'activo' => true,
        ])
        ->assertSessionHasErrors('comercial');

    expect($bajas->fresh()->comercial)->toBeFalse();
});

it('la pantalla de ubicaciones no le ofrece al jefe de bodega lo que es de Gerencia', function () {
    Role::findOrCreate('AdminBodega');
    Role::findOrCreate('Gerencia');

    $sede = bodegaQa('PPAL');

    $jorge = User::factory()->create(['name' => 'Jorge']);
    $jorge->assignRole('AdminBodega');
    $sede->update(['responsable_user_id' => $jorge->id]);

    $gerencia = User::factory()->create();
    $gerencia->assignRole('Gerencia');

    $bandera = function (User $quien): bool {
        $r = test()->actingAs($quien)->get('/app/inventario/ubicaciones');
        $r->assertOk();

        return (bool) ($r->viewData('page')['props']['puede_editar_la_ubicacion'] ?? null);
    };

    /*
     * Crear y editar la ubicación, activarla, borrarla, empujarla a SIIGO y
     * traer las resoluciones DIAN tocan el enlace con SIIGO, la resolución
     * DIAN y las cuentas del PUC: son de Gerencia, y el controlador las cierra
     * con `esRoot`. La pantalla las mostraba a cualquiera que pudiera mirarla,
     * y dos de ellas —activar y borrar— fallaban sin decir nada.
     */
    expect($bandera($jorge))->toBeFalse()
        ->and($bandera($gerencia))->toBeTrue();

    // Y el servidor sigue cerrando la puerta, bandera o no.
    $this->actingAs($jorge)
        ->postJson('/app/inventario/ubicaciones/'.$sede->id.'/toggle')
        ->assertStatus(403);
});
