<?php

/**
 * MKT-6 · CRM: leads con fuente.
 *
 * Lo que preguntó el cliente: «¿Se puede mirar la fuente del lead? …tiene
 * fuente de WhatsApp, de página». No se podía: el contacto no guardaba de
 * dónde llegó, y por lo tanto tampoco había cómo saber qué canal vende.
 *
 * Ahora la fuente vive en el contacto, cada pedido la copia al nacer, y el
 * CRM tiene una pestaña con clientes, conversión y ventas por fuente.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Portal\Models\PedidoCliente;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
});

function clienteDe(string $nombre, ?string $fuente, ?string $detalle = null): Contacto
{
    return Contacto::create([
        'numero_documento' => (string) fake()->unique()->numberBetween(10000000, 99999999),
        'nombre_completo' => $nombre,
        'es_cliente' => true,
        'fuente' => $fuente,
        'fuente_detalle' => $detalle,
    ]);
}

function pedidoDe(Contacto $c, string $estado, int $total): PedidoCliente
{
    return PedidoCliente::create([
        'numero' => 'PB-'.fake()->unique()->numerify('######'),
        'contacto_id' => $c->id,
        'estado' => $estado,
        'subtotal' => $total, 'total' => $total,
    ]);
}

it('la ficha del contacto guarda de dónde llegó', function () {
    $this->actingAs($this->aracely)->post('/app/contactos', [
        'tipo_documento' => 'NIT',
        'numero_documento' => '901234567',
        'nombre_completo' => 'Pañalera La Cigüeña',
        'es_cliente' => true,
        'activo' => true,
        'fuente' => 'referido',
        'fuente_detalle' => 'Lo refirió Bebés del Norte',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $c = Contacto::where('numero_documento', '901234567')->firstOrFail();
    expect($c->fuente)->toBe('referido')
        ->and($c->fuente_detalle)->toBe('Lo refirió Bebés del Norte');
});

it('no acepta una fuente que no está en la lista', function () {
    $this->actingAs($this->aracely)->post('/app/contactos', [
        'tipo_documento' => 'CC',
        'numero_documento' => '1012345678',
        'nombre_completo' => 'Ana Pérez',
        'fuente' => 'tiktok_inventado',
    ])->assertSessionHasErrors('fuente');
});

it('el pedido nace con la fuente de su contacto y no cambia si se corrige la ficha', function () {
    $c = clienteDe('Tienda Mi Bebé', 'whatsapp');
    $pedido = pedidoDe($c, 'enviado', 50000);

    expect($pedido->fresh()->fuente)->toBe('whatsapp');

    $c->update(['fuente' => 'pagina_web']);

    expect($pedido->fresh()->fuente)->toBe('whatsapp');
});

it('el CRM muestra clientes, conversión y ventas por fuente', function () {
    $wa1 = clienteDe('WA uno', 'whatsapp');
    clienteDe('WA dos', 'whatsapp');
    $web = clienteDe('Web uno', 'pagina_web');
    clienteDe('Sin fuente', null);

    pedidoDe($wa1, 'facturado', 100000);
    pedidoDe($wa1, 'enviado', 40000);   // pipeline: cuenta como pedido, no como venta
    pedidoDe($web, 'despachado', 70000);

    $fuentes = collect(
        $this->actingAs($this->aracely)->get('/app/crm')->assertOk()->viewData('page')['props']['fuentes']
    )->keyBy(fn ($f) => $f['fuente'] ?? 'sin');

    expect($fuentes['whatsapp'])->toMatchArray([
        'contactos' => 2, 'clientes_con_pedido' => 1, 'pedidos' => 2, 'conversion' => 50.0,
    ])
        ->and($fuentes['whatsapp']['ventas'])->toEqual(100000)
        ->and($fuentes['pagina_web']['ventas'])->toEqual(70000)
        ->and($fuentes['sin']['contactos'])->toBe(1)
        ->and($fuentes['sin']['label'])->toBe('Sin registrar');
});
