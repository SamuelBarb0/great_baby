<?php

/**
 * La campana tiene que mostrar los avisos que alguien le escribió.
 *
 * Encontrado el 2026-10-19 probando la solicitud de personal en pantalla: Elena
 * abre la campana, dice «Sin novedades», y en `notificaciones_erp` hay dos
 * filas dirigidas a ella. `NotificacionErp::crear()` se llama desde la cola de
 * Jorge, desde pedidos B2B y desde las requisiciones; escribía bien. Lo que
 * faltaba era leerla: seis filas desde el 13-oct, ninguna visible nunca.
 *
 * Es el mismo patrón que ya apareció en despachos y en recepción de mercancía:
 * la tabla está, el backend escribe, la pantalla no lo lee, y nadie se entera
 * porque la operación «termina bien».
 */

use App\Models\NotificacionErp;
use App\Models\User;

beforeEach(function () {
    $this->elena = User::factory()->create();
    $this->aracely = User::factory()->create();
});

it('muestra lo que llegó antes de abrir la pantalla', function () {
    /*
     * El defecto de fondo: la campana sólo traía lo ocurrido DESPUÉS de cargar
     * la pestaña. Quien llega en la mañana nunca se enteraba de lo de anoche.
     */
    NotificacionErp::crear([
        'user_id' => $this->elena->id,
        'tipo' => 'rrhh_requisicion',
        'titulo' => 'Nueva solicitud de personal · RQ-2026-0001',
        'mensaje' => 'Jorge Rojas solicita 2 × Auxiliar de bodega',
        'url' => '/app/rrhh/requisiciones/1',
    ]);

    $r = $this->actingAs($this->elena)
        ->getJson('/app/notificaciones?desde='.now()->addMinute()->toIso8601String());

    expect($r->json('pendientes'))->toHaveCount(1)
        ->and($r->json('pendientes.0.titulo'))->toContain('RQ-2026-0001')
        ->and($r->json('pendientes.0.url'))->toBe('/app/rrhh/requisiciones/1');
});

it('a cada quien le llega lo suyo y nada más', function () {
    NotificacionErp::crear([
        'user_id' => $this->elena->id, 'tipo' => 'x', 'titulo' => 'Para Elena',
    ]);
    NotificacionErp::crear([
        'user_id' => $this->aracely->id, 'tipo' => 'x', 'titulo' => 'Para Aracely',
    ]);

    $titulos = fn (User $u) => collect(
        $this->actingAs($u)->getJson('/app/notificaciones')->json('pendientes')
    )->pluck('titulo');

    expect($titulos($this->elena))->toContain('Para Elena')
        ->and($titulos($this->elena))->not->toContain('Para Aracely');
});

it('abrir la campana baja el contador y no lo vuelve a subir', function () {
    NotificacionErp::crear(['user_id' => $this->elena->id, 'tipo' => 'x', 'titulo' => 'Algo']);

    $this->actingAs($this->elena)->postJson('/app/notificaciones/leidas')
        ->assertOk()->assertJson(['leidas' => 1]);

    expect($this->actingAs($this->elena)->getJson('/app/notificaciones')->json('pendientes'))
        ->toHaveCount(0);
});

it('leer un aviso general no se lo borra a los demás', function () {
    /*
     * Un aviso para todos es UNA fila con UNA sola marca de leído. Marcarla
     * hacía que el primero que abriera la campana lo desapareciera para el
     * resto —se vio en pantalla: Elena abrió y Aracely ya no lo tenía—, que es
     * peor que el problema que se estaba arreglando.
     */
    NotificacionErp::crear([
        'user_id' => null, 'tipo' => 'pedido', 'titulo' => 'Pedido PB-0002 rechazado',
    ]);

    $this->actingAs($this->elena)->postJson('/app/notificaciones/leidas');

    $deAracely = $this->actingAs($this->aracely)->getJson('/app/notificaciones')->json('pendientes');

    expect($deAracely)->toHaveCount(1)
        ->and($deAracely[0]['titulo'])->toBe('Pedido PB-0002 rechazado');
});

it('un aviso general viejo deja de encender la campana', function () {
    // Como no se marcan, sin un límite de tiempo uno de hace meses la dejaría
    // prendida para siempre.
    $viejo = NotificacionErp::crear(['user_id' => null, 'tipo' => 'x', 'titulo' => 'De hace un mes']);
    $viejo->forceFill(['created_at' => now()->subMonth()])->save();

    NotificacionErp::crear(['user_id' => null, 'tipo' => 'x', 'titulo' => 'De hoy']);

    $titulos = collect($this->actingAs($this->elena)->getJson('/app/notificaciones')->json('pendientes'))
        ->pluck('titulo');

    expect($titulos)->toContain('De hoy')
        ->and($titulos)->not->toContain('De hace un mes');
});

it('el color de Filament se traduce al de la campana', function () {
    // La tabla guarda `warning`/`danger`; la campana pinta con otra paleta, y
    // un color que no reconoce se dibuja gris, como si no pasara nada.
    NotificacionErp::crear(['user_id' => $this->elena->id, 'tipo' => 'x', 'titulo' => 'Ojo', 'color' => 'warning']);

    expect($this->actingAs($this->elena)->getJson('/app/notificaciones')->json('pendientes.0.color'))
        ->toBe('amber');
});

it('el chip de SIIGO no se le ofrece a quien no puede abrirlo', function () {
    /*
     * El semáforo de SIIGO vive en la barra superior, fuera del menú, y por
     * eso se le escapó al arreglo que quitó del menú los enlaces que el
     * controller no deja abrir. Resultado: todo usuario sin acceso a /app/siigo
     * pedía el semáforo cada 30 segundos, recibía 403 cada vez —el componente
     * se lo traga en silencio— y el chip quedaba en «cargando…» para siempre.
     */
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $gh = User::factory()->create();
    $gh->assignRole(Spatie\Permission\Models\Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    // La llave que la barra mira para decidir si lo pinta.
    expect(App\Auth\MenuApp::clavesDe($gh))->not->toContain('siigo');

    // Y el endpoint efectivamente lo rechaza, que es lo que lo hacía inútil.
    $this->actingAs($gh)->get('/app/siigo/semaforo')->assertStatus(403);
});
