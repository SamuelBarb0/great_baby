<?php

/**
 * F1 · La solicitud de personal recorre el camino completo.
 *
 * Hoy esto es un papel y por eso se pierde. Elena lo describió textual en la
 * reunión del 19-oct: «a veces sí me dan la hojita, a veces no me dan la
 * hojita, se me pierde el rastro». Y del otro lado, el líder que pidió termina
 * llamando para saber en qué va.
 *
 * Lo que estas pruebas cuidan es justamente eso: que nadie tenga que preguntar
 * —cada movimiento avisa y queda escrito—, y que el control que hoy se pierde
 * con la hojita no se pueda saltar: sin requisición aprobada, no hay vacante.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\EstadoRequisicion;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);

    $this->lider = User::factory()->create(['name' => 'Jorge Rojas']);
    $this->lider->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));
});

/** Crea una solicitud ya enviada, que es el punto donde empieza a decidirse. */
function solicitudEnviada(User $lider, array $extra = []): Requisicion
{
    return Requisicion::create(array_merge([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'area' => 'Logística',
        'numero_vacantes' => 1,
        'funciones' => 'Alistar y despachar pedidos',
        'competencias' => 'Orden y manejo de inventario',
        'salario_min' => 1500000,
        'tipo_contrato' => 'indefinido',
        'solicitante_id' => $lider->id,
        'estado' => EstadoRequisicion::Enviada->value,
        'enviada_at' => now(),
    ], $extra));
}

it('el líder crea su solicitud y nace en borrador', function () {
    $this->actingAs($this->lider)
        ->post('/app/rrhh/requisiciones', [
            'cargo' => 'Auxiliar de bodega',
            'area' => 'Logística',
            'numero_vacantes' => 2,
            'motivo' => 'Aumentó el volumen de despachos',
        ])->assertRedirect();

    $x = Requisicion::first();

    expect($x->estado)->toBe(EstadoRequisicion::Borrador)
        ->and($x->numero)->toBe('RQ-'.now()->year.'-0001')
        // El solicitante no se elige en el formulario: es quien la crea, y es
        // el dato que después decide quién puede verla.
        ->and($x->solicitante_id)->toBe($this->lider->id)
        ->and($x->bitacora()->count())->toBe(1);
});

it('un borrador no le llega a nadie todavía', function () {
    // Es la diferencia con el papel: mientras lo estás llenando, no existe.
    $this->actingAs($this->lider)
        ->post('/app/rrhh/requisiciones', ['cargo' => 'Auxiliar', 'numero_vacantes' => 1]);

    expect(NotificacionErp::count())->toBe(0);
});

it('al enviarla, Gestión Humana se entera sin que nadie la llame', function () {
    $x = Requisicion::create([
        'numero' => Requisicion::siguienteNumero(),
        'cargo' => 'Auxiliar de bodega',
        'numero_vacantes' => 1,
        'solicitante_id' => $this->lider->id,
        'estado' => EstadoRequisicion::Borrador->value,
    ]);

    $this->actingAs($this->lider)->post("/app/rrhh/requisiciones/{$x->id}/enviar");

    $aviso = NotificacionErp::where('user_id', $this->elena->id)->first();

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Enviada)
        ->and($x->fresh()->enviada_at)->not->toBeNull()
        ->and($aviso)->not->toBeNull()
        ->and($aviso->mensaje)->toContain('Jorge Rojas')
        ->and($aviso->url)->toBe("/app/rrhh/requisiciones/{$x->id}");
});

it('el segundo clic en Enviar no manda un aviso repetido', function () {
    // El botón tarda y la gente lo vuelve a apretar. Si cada clic notificara,
    // Elena abriría la campana con cinco avisos de la misma solicitud.
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->lider)->post("/app/rrhh/requisiciones/{$x->id}/enviar")
        ->assertStatus(422);

    expect(NotificacionErp::count())->toBe(0);
});

it('el líder no aprueba sus propias solicitudes', function () {
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->lider)->post("/app/rrhh/requisiciones/{$x->id}/aprobar")
        ->assertStatus(403);

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Enviada);
});

it('el líder no ve lo que pidió otra área', function () {
    // Ahí va el salario que se le ofrece a un cargo que no es suyo.
    $otro = User::factory()->create();
    $ajena = solicitudEnviada($otro, ['cargo' => 'Contador']);

    $this->actingAs($this->lider)->get("/app/rrhh/requisiciones/{$ajena->id}")
        ->assertStatus(403);
});

it('aprobar avisa a quien la pidió', function () {
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/aprobar");

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Aprobada)
        ->and($x->fresh()->aprobada_por_id)->toBe($this->elena->id)
        ->and(NotificacionErp::where('user_id', $this->lider->id)->exists())->toBeTrue();
});

it('rechazar sin decir por qué no se puede', function () {
    // Un «no» a secas deja al líder sin saber si insistir o corregir algo.
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->elena)
        ->post("/app/rrhh/requisiciones/{$x->id}/rechazar", ['motivo' => 'no'])
        ->assertSessionHasErrors('motivo');

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Enviada);
});

it('el motivo del rechazo le llega al líder y queda escrito', function () {
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/rechazar", [
        'motivo' => 'El presupuesto del área no da para un cargo más este trimestre',
    ]);

    $aviso = NotificacionErp::where('user_id', $this->lider->id)->first();

    expect($x->fresh()->estado)->toBe(EstadoRequisicion::Rechazada)
        ->and($x->fresh()->motivo_rechazo)->toContain('presupuesto')
        ->and($aviso->mensaje)->toContain('presupuesto')
        ->and($x->bitacora()->first()->descripcion)->toContain('presupuesto');
});

it('una solicitud ya enviada no se edita por detrás', function () {
    // Si se pudiera, Elena aprobaría un cargo de $1.500.000 y terminaría
    // contratando uno de $4.000.000.
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->lider)
        ->put("/app/rrhh/requisiciones/{$x->id}", ['cargo' => 'Gerente', 'numero_vacantes' => 1, 'salario_min' => 4000000])
        ->assertStatus(422);

    expect($x->fresh()->cargo)->toBe('Auxiliar de bodega');
});

it('sin solicitud aprobada no se abre la vacante', function () {
    // Éste es el control que hoy se pierde con la hojita.
    $x = solicitudEnviada($this->lider);

    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/vacante")
        ->assertStatus(422);

    expect(Vacante::count())->toBe(0);
});

it('la vacante nace con los datos de la solicitud y queda amarrada a ella', function () {
    $x = solicitudEnviada($this->lider, ['estado' => EstadoRequisicion::Aprobada->value]);

    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/vacante");

    $v = Vacante::first();

    expect($v->requisicion_id)->toBe($x->id)
        ->and($v->titulo)->toBe('Auxiliar de bodega')
        ->and($v->area)->toBe('Logística')
        // Lo que escribió el líder es lo que se publica: nadie lo retranscribe.
        ->and($v->descripcion)->toBe('Alistar y despachar pedidos')
        ->and((float) $v->salario_min)->toBe(1500000.0)
        ->and($v->estado)->toBe('abierta')
        // El rastro queda de los dos lados: en la solicitud y en la vacante.
        ->and($x->bitacora()->where('evento', 'vacante_abierta')->exists())->toBeTrue()
        ->and($v->bitacora()->count())->toBe(1);
});

it('no se abren más vacantes que las que se pidieron', function () {
    // Se pidieron 2 auxiliares; no pueden terminar contratándose 3.
    $x = solicitudEnviada($this->lider, [
        'estado' => EstadoRequisicion::Aprobada->value,
        'numero_vacantes' => 2,
    ]);

    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/vacante");
    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/vacante");
    $this->actingAs($this->elena)->post("/app/rrhh/requisiciones/{$x->id}/vacante")
        ->assertStatus(422);

    expect(Vacante::count())->toBe(2);
});

it('un salario máximo menor que el mínimo se frena al crear', function () {
    $this->actingAs($this->lider)
        ->post('/app/rrhh/requisiciones', [
            'cargo' => 'Auxiliar', 'numero_vacantes' => 1,
            'salario_min' => 3000000, 'salario_max' => 1500000,
        ])->assertSessionHasErrors('salario_max');
});

it('quien no tiene nada que ver con Gestión Humana no entra', function () {
    $vendedor = User::factory()->create();
    $vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->actingAs($vendedor)->get('/app/rrhh/requisiciones')->assertStatus(403);
});

it('el listado del líder sólo trae lo suyo, el de Gestión Humana lo trae todo', function () {
    solicitudEnviada($this->lider, ['cargo' => 'La mía']);
    solicitudEnviada(User::factory()->create(), ['cargo' => 'La de otra área']);

    $this->actingAs($this->lider)->get('/app/rrhh/requisiciones')
        ->assertInertia(fn ($p) => $p
            ->where('esGestionHumana', false)
            ->has('requisiciones.data', 1)
            ->where('requisiciones.data.0.cargo', 'La mía'));

    $this->actingAs($this->elena)->get('/app/rrhh/requisiciones')
        ->assertInertia(fn ($p) => $p
            ->where('esGestionHumana', true)
            ->has('requisiciones.data', 2)
            ->where('kpis.por_aprobar', 2));
});

it('los roles nuevos no entran al sistema directo a un 403', function () {
    /*
     * El `default` de `rutaInicial()` es «Cosas del día», que pide perfil de
     * bodega. Sin una línea propia, tanto el líder de área como Gestión Humana
     * iniciaban sesión y lo primero que veían era un Forbidden — que es
     * exactamente lo que ese método existe para evitar, según su propio
     * comentario.
     */
    foreach ([$this->lider, $this->elena] as $u) {
        expect($u->rutaInicial())->toBe('/app/rrhh/requisiciones');
        $this->actingAs($u)->get($u->rutaInicial())->assertOk();
    }
});
