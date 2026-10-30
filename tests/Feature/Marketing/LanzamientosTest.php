<?php

/**
 * Tablero de lanzamiento por contenedor («TABLERO GREAT BABY.pdf», 30-oct-2026).
 *
 *  - Cada contenedor nuevo nace con sus 10 tarjetas y su checklist.
 *  - Solo pasa a Finalizado con el checklist completo.
 *  - La Validación Comercial pasa por aprobación; la aprueba Aracely o la
 *    devuelve a En proceso con el cambio pedido.
 *  - Bloquear exige motivo, responsable y acción.
 *  - Cada área trabaja sus tarjetas.
 *  - Con las diez finalizadas, el lanzamiento termina.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Marketing\Models\Lanzamiento;
use App\Modules\Marketing\Models\LanzamientoTarjeta;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->anny = User::factory()->create(['name' => 'Anny']);
    $this->anny->assignRole(Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web']));
    $this->jorge = User::factory()->create(['name' => 'Jorge']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->compras = User::factory()->create(['name' => 'Alejandro']);
    $this->compras->assignRole(Role::firstOrCreate(['name' => 'Gerente', 'guard_name' => 'web']));
    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSCU1234567',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito,
    ]);
    $this->lanzamiento = Lanzamiento::where('importacion_id', $this->imp->id)->firstOrFail();
    $this->tarjeta = fn (string $clave) => LanzamientoTarjeta::where('lanzamiento_id', $this->lanzamiento->id)->where('clave', $clave)->firstOrFail();
    $this->completar = function (LanzamientoTarjeta $t, User $u) {
        foreach ($t->items as $i) {
            $this->actingAs($u)->post("/app/marketing/lanzamientos/item/{$i->id}", ['hecho' => true]);
        }
    };
    $this->mover = fn (User $u, LanzamientoTarjeta $t, string $lista, array $extra = []) => $this->actingAs($u)
        ->post("/app/marketing/lanzamientos/tarjeta/{$t->id}/mover", ['lista' => $lista, ...$extra]);
});

it('cada contenedor nuevo nace con sus 10 tarjetas y su checklist', function () {
    $tarjetas = $this->lanzamiento->tarjetas()->withCount('items')->get();

    expect($tarjetas)->toHaveCount(10)
        ->and($tarjetas->pluck('clave')->all())->toBe(['recepcion', 'seleccion', 'brief', 'propuesta', 'validacion', 'muestras', 'produccion', 'dropi', 'publicacion', 'seguimiento'])
        ->and($tarjetas->pluck('lista')->unique()->all())->toBe(['por_hacer'])
        ->and($tarjetas->firstWhere('clave', 'recepcion')->items_count)->toBe(7)
        ->and($tarjetas->firstWhere('clave', 'validacion')->requiere_aprobacion)->toBeTrue();

    // Crear de nuevo no duplica.
    $this->actingAs($this->aracely)->post('/app/marketing/lanzamientos', ['importacion_id' => $this->imp->id]);
    expect(Lanzamiento::where('importacion_id', $this->imp->id)->count())->toBe(1);
});

it('quien toma una tarjeta queda como responsable', function () {
    $t = ($this->tarjeta)('propuesta');
    ($this->mover)($this->anny, $t, 'en_proceso')->assertSessionHas('success');

    expect($t->fresh()->lista)->toBe('en_proceso')
        ->and($t->fresh()->responsable_id)->toBe($this->anny->id);
});

it('no finaliza con el checklist incompleto, sí cuando está completo', function () {
    $t = ($this->tarjeta)('recepcion');
    ($this->mover)($this->compras, $t, 'en_proceso');

    ($this->mover)($this->compras, $t, 'finalizado')->assertSessionHas('error');
    expect($t->fresh()->lista)->toBe('en_proceso');

    ($this->completar)($t, $this->compras);
    ($this->mover)($this->compras, $t, 'finalizado')->assertSessionHas('success');
    expect($t->fresh()->lista)->toBe('finalizado')->and($t->fresh()->finalizada_en)->not->toBeNull();
});

it('al confirmar el contenedor avisa a Araceli y a Anny', function () {
    $t = ($this->tarjeta)('recepcion');
    ($this->mover)($this->compras, $t, 'en_proceso');
    ($this->completar)($t, $this->compras);
    ($this->mover)($this->compras, $t, 'finalizado');

    $avisados = NotificacionErp::where('tipo', 'marketing_lanzamiento')->where('titulo', 'Contenedor confirmado')->pluck('user_id')->all();
    expect($avisados)->toContain($this->aracely->id)->toContain($this->anny->id);
});

it('la validación comercial no se salta la aprobación y solo Aracely la aprueba', function () {
    $t = ($this->tarjeta)('validacion');
    ($this->mover)($this->anny, $t, 'en_proceso');
    ($this->completar)($t, $this->anny);

    ($this->mover)($this->anny, $t, 'finalizado')->assertSessionHas('error');
    ($this->mover)($this->anny, $t, 'pendiente_aprobacion')->assertSessionHas('success');
    expect(NotificacionErp::where('user_id', $this->aracely->id)->where('titulo', 'like', 'Por aprobar%')->exists())->toBeTrue();

    $this->actingAs($this->anny)->post("/app/marketing/lanzamientos/tarjeta/{$t->id}/aprobar")->assertForbidden();
    $this->actingAs($this->aracely)->post("/app/marketing/lanzamientos/tarjeta/{$t->id}/aprobar")->assertSessionHas('success');

    $t->refresh();
    expect($t->lista)->toBe('finalizado')->and($t->aprobada_por)->toBe($this->aracely->id);
});

it('si Aracely pide cambios la tarjeta vuelve a En proceso con el cambio escrito', function () {
    $t = ($this->tarjeta)('validacion');
    ($this->mover)($this->anny, $t, 'en_proceso');
    ($this->completar)($t, $this->anny);
    ($this->mover)($this->anny, $t, 'pendiente_aprobacion');

    // Mientras espera, quien la envió no la puede mover.
    ($this->mover)($this->anny, $t, 'en_proceso')->assertSessionHas('error');

    $this->actingAs($this->aracely)->post("/app/marketing/lanzamientos/tarjeta/{$t->id}/devolver", ['cambios' => 'Otro hook para el video'])
        ->assertSessionHas('success');

    expect($t->fresh()->lista)->toBe('en_proceso')
        ->and($t->comentarios()->where('tipo', 'devolucion')->value('cuerpo'))->toContain('Otro hook para el video')
        ->and(NotificacionErp::where('user_id', $this->anny->id)->where('titulo', 'like', 'Cambios pedidos%')->exists())->toBeTrue();
});

it('bloquear exige motivo, responsable y acción, y avisa a quien lo resuelve', function () {
    $t = ($this->tarjeta)('muestras');
    ($this->mover)($this->jorge, $t, 'en_proceso');

    ($this->mover)($this->jorge, $t, 'bloqueado', ['motivo' => 'Falta producto'])->assertSessionHas('error');
    expect($t->fresh()->lista)->toBe('en_proceso');

    ($this->mover)($this->jorge, $t, 'bloqueado', [
        'motivo' => 'Falta disponibilidad de producto en bodega', 'responsable_id' => $this->compras->id, 'accion' => 'Confirmar llegada del saldo',
    ])->assertSessionHas('success');

    $t->refresh();
    expect($t->lista)->toBe('bloqueado')
        ->and($t->bloqueo_responsable_id)->toBe($this->compras->id)
        ->and(NotificacionErp::where('user_id', $this->compras->id)->where('titulo', 'like', 'Bloqueada%')->exists())->toBeTrue();

    // Al desbloquear, el bloqueo sale de la tarjeta pero queda en su historia.
    ($this->mover)($this->jorge, $t, 'en_proceso');
    expect($t->fresh()->bloqueo_motivo)->toBeNull()
        ->and($t->comentarios()->where('tipo', 'bloqueo')->exists())->toBeTrue();
});

it('cada área trabaja sus tarjetas', function () {
    // Bodega no toca la propuesta creativa; Marketing no toca la entrega de muestras.
    ($this->mover)($this->jorge, ($this->tarjeta)('propuesta'), 'en_proceso')->assertForbidden();
    ($this->mover)($this->anny, ($this->tarjeta)('muestras'), 'en_proceso')->assertForbidden();
    $item = ($this->tarjeta)('brief')->items()->first();
    $this->actingAs($this->anny)->post("/app/marketing/lanzamientos/item/{$item->id}", ['hecho' => true])->assertForbidden();

    // Aracely (root) puede con todas.
    ($this->mover)($this->aracely, ($this->tarjeta)('muestras'), 'en_proceso')->assertSessionHas('success');
});

it('quien no está en el tablero no lo ve', function () {
    $this->actingAs($this->vendedor)->get('/app/marketing/lanzamientos')->assertForbidden();
    $this->actingAs($this->vendedor)->get("/app/marketing/lanzamientos/{$this->lanzamiento->id}")->assertForbidden();

    $this->actingAs($this->anny)->get("/app/marketing/lanzamientos/{$this->lanzamiento->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Marketing/Lanzamientos/Show')
            ->has('tarjetas', 10)
            ->where('puede_aprobar', false));
});

it('con las diez tarjetas finalizadas el lanzamiento termina', function () {
    foreach ($this->lanzamiento->tarjetas as $t) {
        ($this->mover)($this->aracely, $t, 'en_proceso');
        ($this->completar)($t->fresh('items'), $this->aracely);
        if ($t->requiere_aprobacion) {
            ($this->mover)($this->aracely, $t, 'pendiente_aprobacion');
            $this->actingAs($this->aracely)->post("/app/marketing/lanzamientos/tarjeta/{$t->id}/aprobar");
        } else {
            ($this->mover)($this->aracely, $t, 'finalizado');
        }
    }

    expect($this->lanzamiento->fresh()->finalizado_en)->not->toBeNull()
        ->and(NotificacionErp::where('titulo', 'Lanzamiento terminado')->where('user_id', $this->anny->id)->exists())->toBeTrue();
});
