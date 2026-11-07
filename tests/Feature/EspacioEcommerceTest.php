<?php

/**
 * Espacio e-commerce · el selector «¿en qué empresa estoy?» de `/app`.
 *
 * Lo pidió quien opera Dropi: estar sólo en el e-commerce y ver ahí sus
 * ventas, sus productos y su contabilidad. Estas pruebas cuidan tres cosas:
 * que sólo cambie quien opera Dropi, que la sesión vieja de `/admin` no deje
 * a nadie en un menú vacío, y que «Ventas e-commerce» cuente sólo lo del
 * e-commerce (eje `linea_negocio = dropi`), igual que el libro y el balance.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Support\EspacioTrabajo;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function espacioUsuario(string $rol): User
{
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']));

    return $u;
}

function espacioFactura(string $linea, string $fecha, float $total, EstadoFactura $estado = EstadoFactura::Pendiente): FacturaVenta
{
    static $n = 0;
    $n++;
    $cliente = Contacto::firstOrCreate(['numero_documento' => '222222222222'], ['nombre_completo' => 'CONSUMIDOR FINAL']);

    return FacturaVenta::create([
        'numero' => 'EC-TEST-'.$n,
        'contacto_id' => $cliente->id,
        'fecha_emision' => $fecha, 'fecha_vencimiento' => $fecha,
        'subtotal' => round($total / 1.19, 2), 'descuento' => 0, 'impuestos' => round($total - $total / 1.19, 2),
        'total' => $total, 'saldo' => 0,
        'estado' => $estado, 'es_electronica' => false,
        'linea_negocio' => $linea,
    ]);
}

beforeEach(function () {
    $this->aracely = espacioUsuario('Aracely');
    $this->alistador = espacioUsuario('Alistador');
    $this->contador = espacioUsuario('Contador');
    // Después de crear los roles: el sembrador reparte casillas a los que existen.
    $this->seed(\Database\Seeders\PermisosSeeder::class);
});

/** `estado` está protegido en el modelo: se asigna explícito, como el sync. */
function espacioPedidoDropi(int $corteId, string $orden, float $monto, EstadoPedidoDropi $estado, array $fechas): DropiPedido
{
    $p = new DropiPedido();
    $p->forceFill(['corte_id' => $corteId, 'guia' => 'G'.$orden, 'dropi_orden_id' => $orden,
        'cliente_nombre' => 'Cliente '.$orden, 'monto_esperado_proveedor' => $monto, 'estado' => $estado] + $fechas)->save();

    return $p;
}

it('Aracely ve el selector y al pasar al e-commerce aterriza en sus ventas', function () {
    $this->actingAs($this->aracely)->get('/app/ecommerce/ventas')
        ->assertInertia(fn (Assert $p) => $p->where('auth.espacio.actual', EspacioTrabajo::GENERAL));

    $this->actingAs($this->aracely)->post('/app/espacio', ['espacio' => 'dropi'])
        ->assertRedirect('/app/ecommerce/ventas');

    expect(session('gb_canal'))->toBe('dropi');

    $this->actingAs($this->aracely)->get('/app/ecommerce/ventas')
        ->assertInertia(fn (Assert $p) => $p->where('auth.espacio.actual', EspacioTrabajo::ECOMMERCE)
            ->where('auth.menu', fn ($m) => collect($m)->contains('dropi_admin')));

    $this->actingAs($this->aracely)->post('/app/espacio', ['espacio' => 'empresa'])
        ->assertRedirect('/app');
    expect(session('gb_canal'))->toBe('empresa');
});

it('el alistador cambia, pero aterriza en su vista y no en las cifras', function () {
    $this->actingAs($this->alistador)->post('/app/espacio', ['espacio' => 'dropi'])
        ->assertRedirect('/app/dropi/alistador');

    $this->actingAs($this->alistador)->get('/app/ecommerce/ventas')->assertForbidden();
});

it('quien no opera Dropi no ve el selector ni puede cambiar', function () {
    $this->actingAs($this->contador)->post('/app/espacio', ['espacio' => 'dropi'])->assertForbidden();

    // La ruta vieja del /admin escribe la sesión para cualquiera: aun así,
    // para el contador el espacio sigue siendo el general.
    $this->actingAs($this->contador)->withSession(['gb_canal' => 'dropi']);
    expect(EspacioTrabajo::actual($this->contador))->toBe(EspacioTrabajo::GENERAL)
        ->and(EspacioTrabajo::paraPantalla($this->contador))->toBeNull();
});

it('rechaza un espacio que no existe', function () {
    $this->actingAs($this->aracely)->post('/app/espacio', ['espacio' => 'arsutex'])
        ->assertSessionHasErrors('espacio');
});

it('Ventas e-commerce cuenta sólo lo facturado por Dropi en el mes, sin anuladas', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'America/Bogota'));

    espacioFactura('dropi', '2026-11-03', 119000);
    espacioFactura('dropi', '2026-11-03', 59500);
    espacioFactura('dropi', '2026-11-10', 238000);
    espacioFactura('dropi', '2026-11-10', 99999, EstadoFactura::Anulada);   // anulada: fuera
    espacioFactura('dropi', '2026-10-31', 50000);                           // otro mes: fuera
    espacioFactura('great_baby', '2026-11-03', 1000000);                    // Great Baby: fuera

    $corte = AsignarCorteACarga::run(CarbonImmutable::now());
    espacioPedidoDropi($corte->id, '1', 80000, EstadoPedidoDropi::Pagado, [
        'dropi_creado_at' => '2026-11-02 09:00', 'despachado_at' => '2026-11-03 09:00', 'pagado_at' => '2026-11-12 09:00',
    ]);
    espacioPedidoDropi($corte->id, '2', 30000, EstadoPedidoDropi::Devuelto, [
        'dropi_creado_at' => '2026-11-04 09:00', 'devuelto_at' => '2026-11-09 09:00',
    ]);

    $this->actingAs($this->aracely)->get('/app/ecommerce/ventas')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Ecommerce/Ventas')
            ->where('periodo.mes', '2026-11')
            ->where('facturado.cantidad', 3)
            ->where('facturado.total', 416500)
            ->where('por_dia.0.dia', '2026-11-03')
            ->where('por_dia.0.cantidad', 2)
            ->where('por_dia.1.total', 238000)
            ->where('pedidos.recibidos', 2)
            ->where('pedidos.pagados', 1)
            ->where('pedidos.pagado_monto', 80000)
            ->where('pedidos.devueltos', 1)
            ->where('pedidos.devuelto_monto', 30000)
            ->has('ultimas', 3));

    // Otro mes por parámetro: sólo la del 31-oct.
    $this->actingAs($this->aracely)->get('/app/ecommerce/ventas?mes=2026-10')
        ->assertInertia(fn (Assert $p) => $p->where('facturado.cantidad', 1)->where('facturado.total', 50000));
});
