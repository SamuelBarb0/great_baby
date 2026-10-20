<?php

/**
 * F7 + F9 · El espacio del empleado y lo que la empresa le entrega.
 *
 * «La idea es que el empleado pues tenga su parte también de recursos humanos
 * y que tenga también sus avisos» (Elena, 19-oct).
 *
 * Y el caso que lo hizo urgente, contado por ella: «la persona se fue y se
 * llevó la tablet. Y no nos ha entregado la tablet». El problema no fue la
 * tablet: fue que nadie sabía que la tenía.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Models\Asignacion;
use App\Modules\Rrhh\Models\DocumentoEmpleado;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\ReporteAsignacion;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    /*
     * El rol `Colaborador` NO se siembra acá a propósito.
     *
     * La primera versión de esta prueba le ponía los permisos a mano antes de
     * llamar a `crearAcceso`, y así tapó el defecto: en el sistema de verdad
     * el rol nacía vacío y la persona entraba a un Forbidden. Se vio en
     * pantalla, no acá. Ahora la prueba recorre el camino real.
     */

    $this->lider = User::factory()->create(['name' => 'Jorge Rojas']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $req = Requisicion::create([
        'numero' => Requisicion::siguienteNumero(), 'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística', 'numero_vacantes' => 1,
        'solicitante_id' => $this->lider->id, 'estado' => EstadoRequisicion::Aprobada->value,
    ]);
    $this->vacante = Vacante::create([
        'requisicion_id' => $req->id, 'titulo' => 'Auxiliar de bodega',
        'area' => 'Logística', 'estado' => 'abierta',
    ]);

    $this->ana = Empleado::create([
        'vacante_id' => $this->vacante->id,
        'nombre' => 'Ana Pérez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432',
        'email' => 'ana@correo.test', 'telefono' => '3001234567',
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1500000, 'fecha_ingreso' => now()->subMonth()->toDateString(),
        'estado' => 'activo', 'estado_induccion' => 'completada',
    ]);
});

/** Le crea el acceso a Ana y devuelve su usuario, ya activado. */
function anaConPortal(): User
{
    $r = App\Http\Controllers\App\PortalColaboradorController::crearAcceso(
        test()->ana, 'ana@correo.test'
    );
    test()->ana->refresh();
    // Se quema el token como si ya hubiera activado.
    test()->ana->update(['activacion_token' => null, 'activacion_expira_at' => null]);
    test()->enlace = $r['link'];

    return test()->ana->user;
}

// ───────────────────────── F7 · El acceso ─────────────────────────

it('Gestión Humana le crea el acceso y sale un enlace para mandarle', function () {
    /*
     * El enlace se devuelve en pantalla y no sólo por correo porque acá el
     * correo del empleado muchas veces no existe o no lo revisa: Gestión
     * Humana lo pega en el WhatsApp, que es como se comunican.
     */
    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$this->ana->id}/acceso", ['email' => 'ana@correo.test'])
        ->assertSessionHas('enlace_activacion');

    $this->ana->refresh();

    expect($this->ana->user_id)->not->toBeNull()
        ->and($this->ana->user->hasRole('Colaborador'))->toBeTrue()
        // El token se guarda HASHEADO: quien lea la base no puede activar la
        // cuenta de otro.
        ->and($this->ana->activacion_token)->toHaveLength(64)
        ->and($this->ana->activacion_expira_at->isFuture())->toBeTrue();
});

it('la contraseña nace inservible: sólo la pone la persona al activar', function () {
    // Si naciera en blanco o con algo adivinable, cualquiera entraría antes
    // que ella.
    App\Http\Controllers\App\PortalColaboradorController::crearAcceso($this->ana, 'ana@correo.test');

    $this->ana->refresh();

    expect(Illuminate\Support\Facades\Hash::check('', $this->ana->user->password))->toBeFalse()
        ->and(Illuminate\Support\Facades\Hash::check('password', $this->ana->user->password))->toBeFalse();
});

it('con el enlace la persona pone su contraseña y entra', function () {
    $r = App\Http\Controllers\App\PortalColaboradorController::crearAcceso($this->ana, 'ana@correo.test');
    parse_str(parse_url($r['link'], PHP_URL_QUERY), $q);

    $this->post('/app/activar-portal', [
        'token' => $q['token'],
        'password' => 'unaClaveLarga1',
        'password_confirmation' => 'unaClaveLarga1',
    ])->assertRedirect('/app/login');

    $this->ana->refresh();

    expect(Illuminate\Support\Facades\Hash::check('unaClaveLarga1', $this->ana->user->password))->toBeTrue()
        // El token se quema: un enlace que sirve dos veces es un enlace que
        // alguien más puede usar después.
        ->and($this->ana->activacion_token)->toBeNull();
});

it('el enlace no sirve dos veces', function () {
    $r = App\Http\Controllers\App\PortalColaboradorController::crearAcceso($this->ana, 'ana@correo.test');
    parse_str(parse_url($r['link'], PHP_URL_QUERY), $q);

    $datos = ['token' => $q['token'], 'password' => 'unaClaveLarga1', 'password_confirmation' => 'unaClaveLarga1'];
    $this->post('/app/activar-portal', $datos);
    $this->post('/app/activar-portal', $datos)->assertSessionHasErrors('token');
});

it('un enlace vencido no activa nada', function () {
    $r = App\Http\Controllers\App\PortalColaboradorController::crearAcceso($this->ana, 'ana@correo.test');
    parse_str(parse_url($r['link'], PHP_URL_QUERY), $q);
    $this->ana->update(['activacion_expira_at' => now()->subDay()]);

    $this->post('/app/activar-portal', [
        'token' => $q['token'], 'password' => 'unaClaveLarga1', 'password_confirmation' => 'unaClaveLarga1',
    ])->assertSessionHasErrors('token');
});

it('un correo que ya es de otro empleado no se reutiliza', function () {
    // Enlazarlo le daría a esta persona el espacio de aquella.
    $otro = Empleado::create([
        'nombre' => 'Otro', 'tipo_documento' => 'CC', 'numero_documento' => '999',
        'cargo' => 'Auxiliar', 'area' => 'Bodega', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now()->subYear(), 'estado' => 'activo',
    ]);
    App\Http\Controllers\App\PortalColaboradorController::crearAcceso($otro, 'ocupado@correo.test');

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$this->ana->id}/acceso", ['email' => 'ocupado@correo.test'])
        ->assertSessionHasErrors('email');
});

it('a quien ya no trabaja acá no se le crea acceso', function () {
    $this->ana->update(['estado' => 'retirado', 'fecha_retiro' => now()->toDateString()]);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/empleados/{$this->ana->id}/acceso", ['email' => 'ana@correo.test'])
        ->assertStatus(422);
});

// ───────────────────────── F7 · Mi espacio ─────────────────────────

it('el colaborador ve lo suyo y NO ve su salario', function () {
    /*
     * El sueldo está en el contrato que firmó. Una pantalla con el salario
     * abierta en medio de la bodega es un problema que nadie pidió.
     */
    $user = anaConPortal();

    $this->actingAs($user)->get('/app/mi-espacio')
        ->assertInertia(fn ($p) => $p
            ->where('yo.nombre', 'Ana Pérez')
            ->where('yo.cargo', 'Auxiliar de bodega')
            ->missing('yo.salario'));
});

it('el colaborador entra directo a su espacio, no a un 403', function () {
    /*
     * Pasó de verdad: `crearAcceso` creaba el rol con `firstOrCreate` a secas
     * y lo dejaba SIN permisos, así que la primera persona a la que se le
     * creaba el acceso entraba y veía un Forbidden. Es el mismo defecto que
     * ya había aparecido con `LiderArea` y `GestionHumana`.
     */
    $user = anaConPortal();

    expect($user->hasRole('Colaborador'))->toBeTrue()
        // El rol tiene que nacer USABLE, no sólo existir.
        ->and($user->can(App\Auth\Permisos::permiso('portal_colaborador')))->toBeTrue()
        ->and($user->rutaInicial())->toBe('/app/mi-espacio');

    $this->actingAs($user)->get($user->rutaInicial())->assertOk();
});

it('el colaborador no entra a Gestión Humana ni a lo de su jefe', function () {
    $user = anaConPortal();

    $this->actingAs($user)->get('/app/rrhh/empleados')->assertStatus(403);
    $this->actingAs($user)->get('/app/rrhh/indicadores')->assertStatus(403);
    $this->actingAs($user)->get('/app/rrhh/requisiciones')->assertStatus(403);
});

it('quien tiene el rol pero no ficha ve un error claro, no una pantalla vacía', function () {
    // Es un error de configuración, y decirlo ahorra media hora de buscar.
    $suelto = User::factory()->create();
    $suelto->assignRole('Colaborador');

    $this->actingAs($suelto)->get('/app/mi-espacio')->assertStatus(403);
});

it('sólo ve sus propios documentos', function () {
    Illuminate\Support\Facades\Storage::fake();
    $user = anaConPortal();

    $mio = DocumentoEmpleado::create([
        'empleado_id' => $this->ana->id, 'tipo' => 'contrato', 'nombre' => 'Mi contrato',
        'archivo_path' => 'rrhh/mio.pdf',
    ]);
    $otro = Empleado::create([
        'nombre' => 'Otro', 'tipo_documento' => 'CC', 'numero_documento' => '888',
        'cargo' => 'Auxiliar', 'area' => 'Bodega', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now(), 'estado' => 'activo',
    ]);
    $ajeno = DocumentoEmpleado::create([
        'empleado_id' => $otro->id, 'tipo' => 'contrato', 'nombre' => 'Contrato ajeno',
        'archivo_path' => 'rrhh/ajeno.pdf',
    ]);
    Illuminate\Support\Facades\Storage::put('rrhh/mio.pdf', 'x');
    Illuminate\Support\Facades\Storage::put('rrhh/ajeno.pdf', 'x');

    $this->actingAs($user)->get('/app/mi-espacio')
        ->assertInertia(fn ($p) => $p->has('documentos', 1));

    $this->actingAs($user)->get("/app/mi-espacio/documentos/{$mio->id}")->assertOk();
    $this->actingAs($user)->get("/app/mi-espacio/documentos/{$ajeno->id}")->assertStatus(404);
});

// ───────────────────── F9 · Dotación y herramientas ─────────────────────

function entregada(array $extra = []): Asignacion
{
    return Asignacion::create(array_merge([
        'empleado_id' => test()->ana->id,
        'tipo' => 'herramienta',
        'descripcion' => 'Tablet Samsung Tab A8',
        'identificador' => 'SN-00123',
        'cantidad' => 1,
        'valor' => 800000,
        'fecha_entrega' => now()->subWeek()->toDateString(),
        'estado' => 'asignada',
    ], $extra));
}

it('el líder registra lo que le entrega a su gente', function () {
    // «Es el que está ahí en el área y es el que sabe qué recursos necesita».
    $this->actingAs($this->lider)->post('/app/rrhh/asignaciones', [
        'empleado_id' => $this->ana->id,
        'tipo' => 'herramienta',
        'descripcion' => 'Tablet Samsung Tab A8',
        'identificador' => 'SN-00123',
        'cantidad' => 1,
        'fecha_entrega' => now()->toDateString(),
    ])->assertRedirect();

    $a = Asignacion::first();

    expect($a->empleado_id)->toBe($this->ana->id)
        ->and($a->rotulo)->toBe('Tablet Samsung Tab A8 · SN-00123')
        ->and($a->estaPendiente())->toBeTrue();
});

it('un jefe no le entrega nada a la gente de otra área', function () {
    $otroJefe = User::factory()->create();
    $otroJefe->assignRole('LiderArea');

    $this->actingAs($otroJefe)->post('/app/rrhh/asignaciones', [
        'empleado_id' => $this->ana->id, 'tipo' => 'herramienta',
        'descripcion' => 'Tablet', 'cantidad' => 1, 'fecha_entrega' => now()->toDateString(),
    ])->assertStatus(403);
});

it('se puede responder qué tiene una persona, que era la pregunta sin respuesta', function () {
    entregada();
    entregada(['descripcion' => 'Camisa dotación', 'tipo' => 'dotacion', 'identificador' => null]);
    entregada(['descripcion' => 'Teclado viejo', 'estado' => 'devuelta', 'fecha_devolucion' => now()]);

    expect($this->ana->pendientesDeDevolver()->count())->toBe(2);
});

it('quien se fue sin devolver queda marcado, que es el caso de la tablet', function () {
    entregada();
    $this->ana->update(['estado' => 'retirado', 'fecha_retiro' => now()->toDateString()]);

    $this->actingAs($this->elena)->get('/app/rrhh/asignaciones')
        ->assertInertia(fn ($p) => $p
            ->where('asignaciones.0.persona_retirada', true)
            ->where('asignaciones.0.pendiente', true));
});

it('«no devuelta» sin explicar no se guarda', function () {
    // Es lo que después sustenta el reclamo.
    $a = entregada();

    $this->actingAs($this->elena)->post("/app/rrhh/asignaciones/{$a->id}/devolver", [
        'estado' => 'perdida', 'fecha_devolucion' => now()->toDateString(),
    ])->assertSessionHasErrors('estado_devolucion');

    expect($a->fresh()->estaPendiente())->toBeTrue();
});

it('devolver cierra el ciclo y deja en qué estado llegó', function () {
    $a = entregada();

    $this->actingAs($this->elena)->post("/app/rrhh/asignaciones/{$a->id}/devolver", [
        'estado' => 'devuelta',
        'fecha_devolucion' => now()->toDateString(),
        'estado_devolucion' => 'Completa y funcionando',
    ]);

    expect($a->fresh()->estado)->toBe('devuelta')
        ->and($a->fresh()->recibida_por_id)->toBe($this->elena->id)
        ->and($a->bitacora()->first()->descripcion)->toContain('Completa y funcionando');
});

it('lo mismo no se cierra dos veces', function () {
    $a = entregada(['estado' => 'devuelta', 'fecha_devolucion' => now()]);

    $this->actingAs($this->elena)->post("/app/rrhh/asignaciones/{$a->id}/devolver", [
        'estado' => 'perdida', 'fecha_devolucion' => now()->toDateString(),
        'estado_devolucion' => 'me arrepentí',
    ])->assertStatus(422);
});

// ───────────────────── F9 · El colaborador avisa ─────────────────────

it('el colaborador avisa que algo le está fallando y queda escrito', function () {
    /*
     * «Las chicas se acercan y me dicen que les está fallando el teclado… y
     * pues no quedó el registro.»
     */
    $user = anaConPortal();
    $a = entregada();

    $this->actingAs($user)->post("/app/mi-espacio/asignaciones/{$a->id}/reportar", [
        'novedad' => 'falla',
        'detalle' => 'La pantalla se queda congelada desde el lunes',
    ])->assertRedirect();

    $reporte = ReporteAsignacion::first();

    expect($reporte->estado)->toBe('abierto')
        ->and($reporte->reportado_por_id)->toBe($user->id)
        // Una falla es mantenimiento: la maneja el líder del área.
        ->and(NotificacionErp::where('user_id', $this->lider->id)->where('tipo', 'rrhh_asignacion')->exists())
            ->toBeTrue();
});

it('un hurto sube a Gestión Humana, no se queda con el jefe', function () {
    /*
     * «Si es un caso de deterioro y es un tema de mantenimiento, lo maneja el
     * líder del área. Pero si es un tema ya de un hurto o algo, eso pasa a
     * gestión humana porque yo tengo que llamar a procesos disciplinarios.»
     */
    $user = anaConPortal();
    $a = entregada();

    $this->actingAs($user)->post("/app/mi-espacio/asignaciones/{$a->id}/reportar", [
        'novedad' => 'hurto', 'detalle' => 'Me la robaron en el bus el viernes',
    ]);

    expect(NotificacionErp::where('user_id', $this->elena->id)->where('tipo', 'rrhh_asignacion')->exists())
        ->toBeTrue();
});

it('nadie reporta sobre lo que le entregaron a otro', function () {
    $user = anaConPortal();
    $otro = Empleado::create([
        'nombre' => 'Otro', 'tipo_documento' => 'CC', 'numero_documento' => '777',
        'cargo' => 'Auxiliar', 'area' => 'Bodega', 'tipo_contrato' => 'indefinido',
        'fecha_ingreso' => now(), 'estado' => 'activo',
    ]);
    $ajena = Asignacion::create([
        'empleado_id' => $otro->id, 'tipo' => 'herramienta', 'descripcion' => 'Tablet ajena',
        'cantidad' => 1, 'fecha_entrega' => now()->toDateString(), 'estado' => 'asignada',
    ]);

    $this->actingAs($user)->post("/app/mi-espacio/asignaciones/{$ajena->id}/reportar", [
        'novedad' => 'falla', 'detalle' => 'cualquier cosa',
    ])->assertStatus(404);
});

it('responder el aviso le llega de vuelta a quien lo puso', function () {
    // Cerrar el círculo: la persona reportó y merece saber en qué quedó.
    $user = anaConPortal();
    $a = entregada();
    $this->actingAs($user)->post("/app/mi-espacio/asignaciones/{$a->id}/reportar", [
        'novedad' => 'falla', 'detalle' => 'La pantalla se congela',
    ]);
    NotificacionErp::query()->delete();

    $reporte = ReporteAsignacion::first();
    $this->actingAs($this->lider)->post("/app/rrhh/reportes/{$reporte->id}/atender", [
        'respuesta' => 'Ya se pidió el repuesto, llega el jueves',
    ]);

    expect($reporte->fresh()->estado)->toBe('atendido')
        ->and(NotificacionErp::where('user_id', $user->id)->first()?->mensaje)
            ->toContain('repuesto');
});

it('un aviso ya atendido no se responde de nuevo', function () {
    $user = anaConPortal();
    $a = entregada();
    $this->actingAs($user)->post("/app/mi-espacio/asignaciones/{$a->id}/reportar", [
        'novedad' => 'falla', 'detalle' => 'La pantalla se congela',
    ]);
    $reporte = ReporteAsignacion::first();
    $this->actingAs($this->lider)->post("/app/rrhh/reportes/{$reporte->id}/atender", ['respuesta' => 'listo ya']);

    $this->actingAs($this->lider)
        ->post("/app/rrhh/reportes/{$reporte->id}/atender", ['respuesta' => 'otra vez'])
        ->assertStatus(422);
});
