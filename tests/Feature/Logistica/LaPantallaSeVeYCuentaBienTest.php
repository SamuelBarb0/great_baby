<?php

/**
 * Que la pantalla se vea, y que lo que cuenta sea todo.
 *
 * Tres defectos del mismo bloque, y los tres del tipo que no da error en
 * ninguna consola:
 *
 *   · El ítem «Seguimiento de guías» pedía una clave de menú que el servidor no
 *     emitía, así que la pantalla no la veía NADIE —ni Gerencia—. Existía, la
 *     ruta respondía, y no había cómo llegar.
 *   · Los contadores de arriba se calculaban sobre las 400 filas cargadas, no
 *     sobre todas, y el orden dejaba justo a las que piden atención fuera de
 *     esa ventana.
 *   · Se podía marcar una entrega con fecha ANTERIOR al despacho: los días en
 *     tránsito salían negativos y se le restaban al promedio de la
 *     transportadora, que es el número con el que se le reclama.
 */

use App\Auth\MenuApp;
use App\Models\Contacto;
use App\Models\User;
use App\Modules\Portal\Models\PedidoCliente;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $rol = Role::firstOrCreate(['name' => 'LogisticaTest', 'guard_name' => 'web']);
    $rol->givePermissionTo('ver.logistica');

    $this->jorge = User::factory()->create(['name' => 'Jorge de Logística']);
    $this->jorge->assignRole($rol);

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900454545',
        'nombre_completo' => 'Pañalera del Norte', 'ciudad' => 'Cali', 'es_cliente' => true,
    ]);
});

function guiaDe(?string $despachado, ?string $entregado = null): PedidoCliente
{
    static $n = 0;
    $n++;

    return PedidoCliente::create([
        'numero' => 'PB-GUIA-'.$n,
        'contacto_id' => test()->cliente->id,
        'estado' => $despachado ? 'despachado' : 'facturado',
        'subtotal' => 100000, 'iva' => 0, 'total' => 100000,
        'enviado_at' => now()->subDays(20),
        'despachado_at' => $despachado,
        'entregado_at' => $entregado,
    ]);
}

it('quien tiene logística ve el ítem del menú', function () {
    /*
     * El menú se arma con las claves que calcula `MenuApp`, y el ítem pide
     * `logistica`. Esa clave no se emitía: ni Gerencia veía la pantalla.
     */
    expect(MenuApp::clavesDe($this->jorge))->toContain('logistica');

    // Y el ítem del menú sigue pidiendo esa misma clave, no otra.
    $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

    expect($layout)->toContain("href: '/app/logistica/guias', icon: Truck, permiso: 'logistica'");
});

it('quien no tiene logística no la ve', function () {
    $ajeno = User::factory()->create();

    expect(MenuApp::clavesDe($ajeno))->not->toContain('logistica');
});

it('los contadores cuentan TODO, no sólo lo que entró en la página', function () {
    // Una entregada y dos sin despachar.
    guiaDe(now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString());
    guiaDe(null);
    guiaDe(null);

    $conteo = $this->actingAs($this->jorge)
        ->get('/app/logistica/guias')->viewData('page')['props']['conteo'];

    // La suma de los contadores es la cantidad real de guías vivas.
    expect(array_sum($conteo))->toBe(3);
});

it('lo que todavía no sale de bodega va primero en la lista', function () {
    guiaDe(now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString());
    $sinDespachar = guiaDe(null);

    $guias = $this->actingAs($this->jorge)
        ->get('/app/logistica/guias?estado=todos')->viewData('page')['props']['guias'];

    /*
     * Con el tope de 400 filas, lo que quede al final es lo que se cae de la
     * ventana. En una pantalla cuyo filtro por defecto es «pide atención», eso
     * no puede ser justo lo que más atención pide.
     */
    expect($guias[0]['numero'])->toBe($sinDespachar->numero);
});

it('no se puede entregar antes de haber despachado', function () {
    $g = guiaDe(now()->subDays(3)->toDateString());

    $this->actingAs($this->jorge)
        ->post("/app/logistica/guias/{$g->id}/entregar", [
            'nota' => 'Recibió la señora de la tienda',
            'fecha' => now()->subDays(10)->toDateString(),   // antes del despacho
        ])
        ->assertStatus(422);

    expect($g->fresh()->entregado_at)->toBeNull();
});

it('entregar el mismo día del despacho sí se puede', function () {
    // Un envío urbano sale y llega el mismo día. No hay por qué estorbarlo.
    $g = guiaDe(now()->subDays(3)->toDateString());

    $this->actingAs($this->jorge)
        ->post("/app/logistica/guias/{$g->id}/entregar", [
            'nota' => 'Entrega en el mismo día',
            'fecha' => now()->subDays(3)->toDateString(),
        ])
        ->assertRedirect();

    expect($g->fresh()->entregado_at)->not->toBeNull();
});
