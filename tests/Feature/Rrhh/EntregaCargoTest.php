<?php

/**
 * Fase F2 · La entrega de cargo y el paz y salvo.
 *
 * El formato existe por una razón concreta: cuando alguien se va, lo que sabía
 * se va con esa persona. El ERP sabía qué equipos le habían entregado —y nada
 * más—; no qué hacía, ni qué dejó a medias, ni dónde están sus archivos.
 *
 * Lo que estas pruebas cuidan no es que se guarden seis listas: es el control
 * de las TRES FIRMAS. Cada área da conformidad sobre lo suyo y sólo sobre lo
 * suyo, y guardar una corrección no borra lo que otra ya firmó. Sin esas dos
 * cosas el formato se firma al final, de memoria, y no controla nada.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Asignacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntregaCargo;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    // Elena, Gestión Humana: firma la dotación.
    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    // Jorge, jefe del área: firma funciones, pendientes y archivo.
    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    // Silvia, contabilidad: firma el paz y salvo.
    $this->silvia = User::factory()->create(['name' => 'Silvia']);
    $this->silvia->assignRole(rolConSusPermisos('Contador'));

    // Camila entra al ERP pero no abre ninguno de los tres bloques.
    $this->nadie = User::factory()->create(['name' => 'Camila Alistadora']);
    $this->nadie->assignRole(rolConSusPermisos('Alistador'));
});

/**
 * Crea el rol con los permisos que le da la MATRIZ.
 *
 * Hay que repartirlos a mano: `PermisosSeeder` los reparte entre los roles QUE
 * YA EXISTEN, y con `RefreshDatabase` la tabla nace vacía. En la base de
 * verdad el rol ya está desde antes.
 */
function rolConSusPermisos(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    return $rol;
}

function quienEntrega(array $extra = []): Empleado
{
    static $n = 0;
    $n++;

    return Empleado::create(array_merge([
        'nombre' => "Quien entrega {$n}", 'tipo_documento' => 'CC', 'numero_documento' => "ent{$n}",
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'retirado', 'estado_induccion' => 'completada',
        'fecha_retiro' => '2026-06-30', 'tipo_retiro' => 'renuncia',
    ], $extra));
}

/**
 * Guarda una entrega como el JEFE: funciones, pendientes, archivo y contactos.
 *
 * El jefe ya NO puede escribir el paz y salvo: cada área escribe sus bloques
 * y nada más. Para el paz y salvo está `guardarPazYSalvo()`.
 */
function guardarEntrega(Empleado $e, array $items = [], array $extra = [])
{
    return test()->actingAs(test()->jorge)
        ->post("/app/rrhh/empleados/{$e->id}/entrega-cargo", array_merge([
            'fecha' => '2026-06-30',
            'recibe_nombre' => 'Marcela Ruiz',
            'items' => $items,
        ], $extra));
}

/** El bloque de tesorería lo escribe tesorería. */
function guardarPazYSalvo(Empleado $e, array $items = [], array $extra = [])
{
    return test()->actingAs(test()->silvia)
        ->post("/app/rrhh/empleados/{$e->id}/entrega-cargo", array_merge([
            'fecha' => '2026-06-30',
            'items' => $items,
        ], $extra));
}

function renglonDeEntrega(string $bloque, string $nombre, ?string $detalle = null): array
{
    return ['bloque' => $bloque, 'nombre' => $nombre, 'detalle' => $detalle];
}

function laEntregaDe(Empleado $e): EntregaCargo
{
    return EntregaCargo::where('empleado_id', $e->id)->firstOrFail();
}

// ── Las seis listas ──────────────────────────────────────────────────────────

it('guarda los renglones separados por bloque', function () {
    $e = quienEntrega();

    guardarEntrega($e, [
        renglonDeEntrega('funcion', 'Alistar pedidos B2B', 'Diaria'),
        renglonDeEntrega('funcion', 'Cuadrar inventario de bodega', 'Semanal'),
        renglonDeEntrega('pendiente', 'Devolución pendiente de Dropi', 'Queda con Marcela'),
        renglonDeEntrega('archivo_digital', 'Carpeta Remisiones 2026', 'Drive / Logística'),
        renglonDeEntrega('archivo_fisico', 'AZ de guías', 'Archivador 3'),
        renglonDeEntrega('contacto', 'Transportadora Envía', 'Diego · 310 000 0000'),
    ])->assertRedirect();

    // El paz y salvo lo escribe tesorería, no el jefe.
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Anticipo de nómina', 'Sin saldo')]);

    $x = laEntregaDe($e);

    expect($x->items)->toHaveCount(7)
        ->and($x->de('funcion'))->toHaveCount(2)
        ->and($x->de('funcion')->first()->detalle)->toBe('Diaria')
        ->and($x->de('paz_salvo')->first()->nombre)->toBe('Anticipo de nómina')
        ->and($x->quienRecibe())->toBe('Marcela Ruiz');
});

it('reemplaza los renglones en vez de duplicarlos al volver a guardar', function () {
    /*
     * La pantalla manda la lista entera. Si se acumularan, corregir una falta
     * de ortografía dejaría las dos versiones en el impreso.
     */
    $e = quienEntrega();

    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos'), renglonDeEntrega('funcion', 'Cuadrar inventario')]);
    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos B2B y B2C')]);

    $x = laEntregaDe($e);

    expect($x->items)->toHaveCount(1)
        ->and($x->de('funcion')->first()->nombre)->toBe('Alistar pedidos B2B y B2C')
        ->and(EntregaCargo::where('empleado_id', $e->id)->count())->toBe(1);
});

it('no acepta un renglón sin nombre', function () {
    $e = quienEntrega();

    guardarEntrega($e, [
        renglonDeEntrega('funcion', 'Alistar pedidos'),
        ['bloque' => 'funcion', 'nombre' => '', 'detalle' => 'Diaria'],
    ])->assertSessionHasErrors('items.1.nombre');

    expect(EntregaCargo::where('empleado_id', $e->id)->exists())->toBeFalse();
});

it('rechaza un bloque que no existe en el formato', function () {
    $e = quienEntrega();

    guardarEntrega($e, [renglonDeEntrega('clave_inventada', 'Lo que sea')])
        ->assertSessionHasErrors('items.0.bloque');
});

// ── Las tres firmas ──────────────────────────────────────────────────────────

it('deja que cada área firme solamente su bloque', function () {
    $e = quienEntrega();
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Anticipo de nómina', 'Sin saldo')]);

    $url = fn (string $a) => "/app/rrhh/empleados/{$e->id}/entrega-cargo/{$a}/confirmar";

    // El jefe firma lo suyo y nada más.
    $this->actingAs($this->jorge)->post($url('jefe'))->assertRedirect();
    $this->actingAs($this->jorge)->post($url('gh'))->assertForbidden();
    $this->actingAs($this->jorge)->post($url('tesoreria'))->assertForbidden();

    // Tesorería tampoco se mete en el bloque de Gestión Humana.
    $this->actingAs($this->silvia)->post($url('tesoreria'))->assertRedirect();
    $this->actingAs($this->silvia)->post($url('gh'))->assertForbidden();
    $this->actingAs($this->silvia)->post($url('jefe'))->assertForbidden();

    $x = laEntregaDe($e);

    expect($x->jefe_confirmo_por_id)->toBe($this->jorge->id)
        ->and($x->tesoreria_confirmo_por_id)->toBe($this->silvia->id)
        ->and($x->gh_confirmo_at)->toBeNull()
        ->and($x->completa())->toBeFalse()
        ->and($x->faltanFirmas())->toBe(['Gestión Humana']);
});

it('sólo da la entrega por completa cuando firmaron las tres', function () {
    $e = quienEntrega();
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Nada pendiente')]);

    $url = fn (string $a) => "/app/rrhh/empleados/{$e->id}/entrega-cargo/{$a}/confirmar";

    $this->actingAs($this->jorge)->post($url('jefe'));
    expect(laEntregaDe($e)->completa())->toBeFalse();

    $this->actingAs($this->elena)->post($url('gh'));
    expect(laEntregaDe($e)->completa())->toBeFalse()
        ->and(laEntregaDe($e)->faltanFirmas())->toBe(['Tesorería']);

    $this->actingAs($this->silvia)->post($url('tesoreria'))
        ->assertSessionHas('success', 'Entrega de cargo completa: las tres áreas dieron conformidad.');

    expect(laEntregaDe($e)->completa())->toBeTrue()
        ->and(laEntregaDe($e)->faltanFirmas())->toBe([]);
});

it('corregir un bloque sólo tumba la conformidad de ese bloque', function () {
    /*
     * Dos riesgos opuestos, y el equilibrio entre los dos.
     *
     * Si corregir una función borrara TODAS las conformidades, nadie volvería
     * a tocar el documento después de la primera firma y se firmaría al final
     * de memoria. Pero si no borrara NINGUNA, la conformidad de quien firmó
     * quedaría puesta sobre un texto que cambió después y que nunca vio.
     *
     * La regla que cumple las dos cosas: cae la del bloque que cambió, y sólo
     * ésa.
     */
    $e = quienEntrega();
    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos')]);
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Anticipo de nómina', 'Sin saldo')]);

    foreach ([[$this->jorge, 'jefe'], [$this->elena, 'gh'], [$this->silvia, 'tesoreria']] as [$u, $a]) {
        $this->actingAs($u)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/{$a}/confirmar");
    }

    // El jefe corrige SU bloque. Se cae SU conformidad y ninguna otra.
    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos B2B')]);

    $x = laEntregaDe($e);

    expect($x->de('funcion')->first()->nombre)->toBe('Alistar pedidos B2B')
        ->and($x->jefe_confirmo_at)->toBeNull()
        // Las otras dos siguen puestas: su contenido no cambió.
        ->and($x->gh_confirmo_at)->not->toBeNull()
        ->and($x->tesoreria_confirmo_at)->not->toBeNull()
        ->and($x->de('paz_salvo')->first()->nombre)->toBe('Anticipo de nómina');
});

it('no deja firmar un paz y salvo que no dice nada', function () {
    /*
     * Las otras dos áreas sí pueden firmar sin renglones —puede no haber
     * pendientes ni dotación—, pero un paz y salvo vacío no afirma nada.
     */
    $e = quienEntrega();
    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos')]);

    $url = "/app/rrhh/empleados/{$e->id}/entrega-cargo/tesoreria/confirmar";

    $this->actingAs($this->silvia)->post($url)->assertSessionHasErrors('tesoreria');
    expect(laEntregaDe($e)->tesoreria_confirmo_at)->toBeNull();

    // Decir «no queda nada pendiente» en las observaciones también vale.
    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos')], [
        'observaciones' => 'No queda saldo a favor ni en contra.',
    ]);

    $this->actingAs($this->silvia)->post($url)->assertSessionHasNoErrors();
    expect(laEntregaDe($e)->tesoreria_confirmo_at)->not->toBeNull();
});

it('no inventa un área de firma', function () {
    $e = quienEntrega();
    guardarEntrega($e);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/gerencia/confirmar")
        ->assertNotFound();
});

it('cierra el formato a quien no abre ninguno de los tres bloques', function () {
    $e = quienEntrega();

    $this->actingAs($this->nadie)
        ->post("/app/rrhh/empleados/{$e->id}/entrega-cargo", ['fecha' => '2026-06-30'])
        ->assertForbidden();
});

it('dice en el impreso cuando una misma persona dio dos conformidades', function () {
    /*
     * El hueco que los permisos no tapan: Gestión Humana también lleva
     * requisiciones, así que Elena puede firmar el bloque del jefe además del
     * suyo. No se bloquea —en una empresa así una persona lleva dos sombreros
     * de verdad— pero el papel no puede aparentar tres revisiones cuando hubo
     * dos.
     */
    $e = quienEntrega();
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Nada pendiente')]);

    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/jefe/confirmar")
        ->assertRedirect();
    $this->actingAs($this->elena)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/gh/confirmar");
    $this->actingAs($this->silvia)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/tesoreria/confirmar");

    $x = laEntregaDe($e);

    expect($x->completa())->toBeTrue()
        ->and($x->firmoVariosBloques())->toBe(['Elena']);

    expect(impresoDeEntrega($e, $x))->toContain('Elena dio más de una de las tres');
});

it('no avisa nada cuando cada área firmó la suya', function () {
    $e = quienEntrega();
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Nada pendiente')]);

    foreach ([[$this->jorge, 'jefe'], [$this->elena, 'gh'], [$this->silvia, 'tesoreria']] as [$u, $a]) {
        $this->actingAs($u)->post("/app/rrhh/empleados/{$e->id}/entrega-cargo/{$a}/confirmar");
    }

    $x = laEntregaDe($e);

    expect($x->firmoVariosBloques())->toBe([])
        ->and(impresoDeEntrega($e, $x))->not->toContain('Conformidades de una misma persona');
});

// ── Cada área escribe lo suyo ────────────────────────────────────────────────

it('el jefe no puede escribir el paz y salvo de tesorería', function () {
    /*
     * Firmar lo propio no sirve de nada si otro puede cambiar lo que firmaste.
     * Antes la validación aceptaba cualquier bloque: el jefe reescribía el paz
     * y salvo y tesorería las funciones del jefe.
     */
    $e = quienEntrega();

    guardarEntrega($e, [renglonDeEntrega('paz_salvo', 'Me pongo yo mismo a paz y salvo')])
        ->assertSessionHasErrors('items.0.bloque');
});

it('tesorería tampoco puede escribir las funciones del jefe', function () {
    $e = quienEntrega();

    guardarPazYSalvo($e, [renglonDeEntrega('funcion', 'Lo que a mí me parezca')])
        ->assertSessionHasErrors('items.0.bloque');
});

it('guardar lo mío no borra los renglones de las otras áreas', function () {
    $e = quienEntrega();

    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos')]);
    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Anticipo de nómina')]);

    // El jefe vuelve a guardar lo suyo: el paz y salvo tiene que seguir ahí.
    guardarEntrega($e, [renglonDeEntrega('funcion', 'Alistar pedidos B2B')]);

    $x = laEntregaDe($e);

    expect($x->de('funcion'))->toHaveCount(1)
        ->and($x->de('paz_salvo'))->toHaveCount(1)
        ->and($x->de('paz_salvo')->first()->nombre)->toBe('Anticipo de nómina');
});

it('cierra el formato a quien no tiene ningún bloque a su cargo', function () {
    $e = quienEntrega();

    $this->actingAs($this->nadie)
        ->post("/app/rrhh/empleados/{$e->id}/entrega-cargo", ['fecha' => '2026-06-30'])
        ->assertForbidden();
});

// ── El impreso ───────────────────────────────────────────────────────────────

/** Arma el formato impreso igual que el controlador. */
function impresoDeEntrega(Empleado $e, EntregaCargo $x): string
{
    $e->load('asignaciones');

    return view('rrhh.pdf.entrega-cargo', [
        'e' => $e, 'entrega' => $x, 'bloques' => EntregaCargo::BLOQUES,
        'equipos' => $e->asignaciones->whereIn('tipo', ['equipo', 'herramienta', 'otro']),
        'dotacion' => $e->asignaciones->where('tipo', 'dotacion'),
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();
}

it('saca los equipos y la dotación de las asignaciones, no del formato', function () {
    /*
     * Lo que ya está registrado no se vuelve a escribir: el formato parte los
     * activos en dos bloques con firmas distintas —equipo y herramienta los
     * recibe el jefe, la dotación Gestión Humana— y eso sale de
     * `rrhh_asignaciones` con su serial y su estado de devolución.
     */
    $e = quienEntrega();

    Asignacion::create([
        'empleado_id' => $e->id, 'tipo' => 'equipo', 'descripcion' => 'Portátil Lenovo',
        'identificador' => 'SN-99812', 'cantidad' => 1, 'fecha_entrega' => '2026-01-15',
        'estado' => 'devuelta', 'fecha_devolucion' => '2026-06-30', 'estado_devolucion' => 'Buen estado',
    ]);
    Asignacion::create([
        'empleado_id' => $e->id, 'tipo' => 'herramienta', 'descripcion' => 'Pistola lectora',
        'cantidad' => 1, 'fecha_entrega' => '2026-01-15', 'estado' => 'entregada',
    ]);
    Asignacion::create([
        'empleado_id' => $e->id, 'tipo' => 'dotacion', 'descripcion' => 'Uniforme talla M',
        'cantidad' => 2, 'fecha_entrega' => '2026-01-15', 'estado' => 'entregada',
    ]);

    guardarPazYSalvo($e, [renglonDeEntrega('paz_salvo', 'Anticipo de nómina', 'Sin saldo')]);

    $this->actingAs($this->elena)
        ->get("/app/rrhh/empleados/{$e->id}/entrega-cargo/pdf")
        ->assertOk();

    // Nada de esto se guardó en el formato: vino de las asignaciones.
    $x = laEntregaDe($e);
    expect($x->items->pluck('nombre'))->not->toContain('Portátil Lenovo');

    expect(impresoDeEntrega($e, $x))
        ->toContain('SN-99812')
        ->toContain('Devuelto · Buen estado')  // el portátil, que ya volvió
        ->toContain('PENDIENTE')               // la pistola, que no
        ->toContain('Uniforme talla M')
        ->toContain('Falta la conformidad de: Jefe inmediato, Gestión Humana, Tesorería');
});

// ── Quién recibe ─────────────────────────────────────────────────────────────

it('prefiere al usuario del sistema sobre el nombre escrito a mano', function () {
    $e = quienEntrega();

    guardarEntrega($e, [], ['recibe_id' => $this->elena->id, 'recibe_nombre' => 'Mal escrito']);

    expect(laEntregaDe($e)->quienRecibe())->toBe('Elena');
});
