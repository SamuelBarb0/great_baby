<?php

/**
 * Anular tiene que deshacer, no sólo cambiar la etiqueta.
 *
 * Dos anulaciones de este ERP dejaban vivo lo que la anulación decía borrar:
 *
 *   1 · Anular un PAGO A PROVEEDOR ponía `estado = 'anulado'` y se iba. Si el
 *       pago estaba confirmado —y sólo se anula lo que se confirmó por error—
 *       su asiento seguía en el libro: el pasivo con el proveedor cancelado,
 *       la plata salida del banco y, lo grave, las retenciones practicadas,
 *       que son la base de la declaración mensual ante la DIAN. Quedaba
 *       declarando una retención que nunca se le hizo a nadie.
 *
 *   2 · Anular una FACTURA cuyo pedido ya se despachó borraba el asiento de la
 *       factura pero no el del despacho —que tiene origen en el PEDIDO— ni el
 *       egreso del kardex. Resultado: el costo sin el ingreso, el inventario
 *       abajo, la mercancía en casa del cliente y nada que cobrarle.
 *
 * Y el control que el comentario del pago prometía y el código no tenía:
 * confirmarlo era la misma puerta que crearlo, así que quien registraba
 * aprobaba su propio pago un segundo después.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Actions\RegistrarPagoProveedor;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Models\RetencionConfig;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    /*
     * Los permisos se siembran a propósito, y el rol recibe los suyos.
     *
     * Sin esto la prueba pasaba o fallaba según QUÉ HUBIERA CORRIDO ANTES.
     * `Permisos::puede()` decide por permisos sólo si `permisosSembrados()`
     * dice que sí, y esa función guarda su respuesta en una `static`: una vez
     * por PROCESO, no por prueba. En producción está bien —un request, un
     * proceso— pero la suite entera corre en uno solo, así que en cuanto
     * cualquier prueba siembra permisos la static queda en `true` para todas
     * las demás, aunque RefreshDatabase vacíe la tabla.
     *
     * Con un rol creado a pelo, el resultado era: sola, caía al respaldo
     * `hasAnyRole()` y el Contador entraba; detrás de una prueba que sembraba,
     * se le pedía el permiso que nadie le dio y respondía 403. El orden
     * decidía. Sembrando acá, siempre se ejercita el camino de producción.
     */
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $rolContador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $rolContador->syncPermissions(collect(App\Auth\Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->map(fn ($s) => App\Auth\Permisos::permiso($s))->all());

    $this->contable = User::factory()->create(['name' => 'Contador Demo']);
    $this->contable->assignRole($rolContador);

    // Aracely es root: `puede()` le corta el paso antes de mirar permisos.
    $this->revisora = User::factory()->create(['name' => 'Aracely']);
    $this->revisora->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    $this->proveedor = Contacto::create([
        'numero_documento' => '900123456', 'nombre_completo' => 'Textiles del Norte SAS',
        'es_proveedor' => true, 'ciudad' => 'Bogotá',
    ]);

    RetencionConfig::create([
        'tipo' => 'retefuente', 'concepto' => 'compras_generales', 'ciudad' => null,
        'base_minima' => 1_000_000, 'tarifa_pct' => 2.5, 'cuenta_puc' => '236540', 'activa' => true,
    ]);
});

/** Un pago pendiente, registrado por quien dice el parámetro. */
function unPagoDe(object $caso, User $autor): PagoProveedor
{
    return RegistrarPagoProveedor::run([
        'fecha' => now()->toDateString(),
        'contacto_id' => $caso->proveedor->id,
        'monto_bruto' => 5_000_000,
        'iva' => 0,
        'concepto_retencion' => 'compras_generales',
        'ciudad' => 'Bogotá',
        'metodo' => 'transferencia',
        'user_id' => $autor->id,
    ]);
}

function lineasDelPagoAProveedor(PagoProveedor $p): int
{
    return MovimientoContable::where('origen_type', PagoProveedor::class)
        ->where('origen_id', $p->id)->count();
}

// ───────────────────── 1 · Cuatro ojos sobre el pago ─────────────────────

it('quien registra el pago no lo puede confirmar', function () {
    $pago = unPagoDe($this, $this->contable);

    $this->actingAs($this->contable)
        ->post("/app/cartera/pagos-proveedor/{$pago->id}/confirmar")
        ->assertRedirect();

    // El estado `pendiente` y el botón existían; el control no, porque
    // revisar lo propio no es revisar. Y al confirmar sale la plata.
    expect($pago->fresh()->estado)->toBe('pendiente')
        ->and(lineasDelPagoAProveedor($pago))->toBe(0);
});

it('no hay excepción para Aracely sobre su propio pago', function () {
    $pago = unPagoDe($this, $this->revisora);

    $this->actingAs($this->revisora)
        ->post("/app/cartera/pagos-proveedor/{$pago->id}/confirmar")
        ->assertRedirect();

    // Un control que la jefa puede saltarse es un control que se salta
    // siempre, y justo en el pago que importa.
    expect($pago->fresh()->estado)->toBe('pendiente');
});

it('otra persona del equipo contable sí lo confirma, y ahí se asienta', function () {
    $pago = unPagoDe($this, $this->contable);

    $this->actingAs($this->revisora)
        ->post("/app/cartera/pagos-proveedor/{$pago->id}/confirmar")
        ->assertRedirect();

    $p = $pago->fresh();

    expect($p->estado)->toBe('confirmado')
        ->and($p->confirmado_por)->toBe($this->revisora->id)
        ->and(lineasDelPagoAProveedor($pago))->toBeGreaterThan(0);
});

// ────────────── 2 · Anular el pago revierte lo que escribió ──────────────

it('anular un pago confirmado le quita sus líneas al libro', function () {
    $pago = unPagoDe($this, $this->contable);
    $this->actingAs($this->revisora)->post("/app/cartera/pagos-proveedor/{$pago->id}/confirmar");

    expect(lineasDelPagoAProveedor($pago))->toBeGreaterThan(0);

    $this->actingAs($this->revisora)
        ->post("/app/cartera/pagos-proveedor/{$pago->id}/anular", [
            'motivo' => 'Se tecleó el NIT de otro proveedor.',
        ])->assertRedirect();

    $p = $pago->fresh();

    expect($p->estado)->toBe('anulado')
        // Lo que importa: la retención practicada deja de existir en el libro.
        // Si no, la declaración de la DIAN la sigue contando.
        ->and(lineasDelPagoAProveedor($pago))->toBe(0)
        ->and($p->anulado_por)->toBe($this->revisora->id)
        ->and($p->anulado_at)->not->toBeNull()
        ->and($p->motivo_anulacion)->toContain('NIT de otro proveedor');
});

it('anular sin decir por qué no se acepta', function () {
    $pago = unPagoDe($this, $this->contable);
    $this->actingAs($this->revisora)->post("/app/cartera/pagos-proveedor/{$pago->id}/confirmar");

    $this->actingAs($this->revisora)
        ->post("/app/cartera/pagos-proveedor/{$pago->id}/anular", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    // Una acción que borra líneas del libro sin firma no se puede auditar.
    expect($pago->fresh()->estado)->toBe('confirmado')
        ->and(lineasDelPagoAProveedor($pago))->toBeGreaterThan(0);
});

it('anular dos veces no pisa el motivo del primero', function () {
    $pago = unPagoDe($this, $this->contable);
    $this->actingAs($this->revisora)->post("/app/cartera/pagos-proveedor/{$pago->id}/confirmar");
    $this->actingAs($this->revisora)->post("/app/cartera/pagos-proveedor/{$pago->id}/anular", [
        'motivo' => 'El primero, que es el que vale.',
    ]);

    $this->actingAs($this->revisora)->post("/app/cartera/pagos-proveedor/{$pago->id}/anular", [
        'motivo' => 'El segundo, que no debería entrar.',
    ]);

    expect($pago->fresh()->motivo_anulacion)->toContain('el que vale');
});

// ──────────── 3 · La factura de un pedido que ya se despachó ────────────

it('no se anula la factura de un pedido ya despachado', function () {
    $factura = FacturaVenta::create([
        'numero' => 'FV-000123', 'contacto_id' => $this->proveedor->id,
        'estado' => 'pendiente', 'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 500_000, 'impuestos' => 0, 'total' => 500_000, 'saldo' => 500_000,
    ]);

    PedidoCliente::create([
        'numero' => 'PB-000999', 'contacto_id' => $this->proveedor->id,
        'estado' => 'despachado', 'factura_id' => $factura->id,
        'despachado_at' => now()->subDay(),
        'subtotal' => 500_000, 'total' => 500_000,
    ]);

    // Anular borraría el asiento de la factura y dejaría vivo el del despacho,
    // que tiene origen en el pedido: costo sin ingreso y kardex abajo.
    expect(fn () => app(SiigoEmisionService::class)->anularFacturaEnSiigo($factura))
        ->toThrow(RuntimeException::class, 'nota crédito');

    // `estado` viene casteado a enum: comparar contra el string pasaría
    // siempre, probando nada.
    expect($factura->fresh()->estado->value)->not->toBe('anulada');
});

it('la factura de un pedido que no salió de bodega sí se anula', function () {
    $factura = FacturaVenta::create([
        'numero' => 'FV-000124', 'contacto_id' => $this->proveedor->id,
        'estado' => 'pendiente', 'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'subtotal' => 500_000, 'impuestos' => 0, 'total' => 500_000, 'saldo' => 500_000,
    ]);

    $pedido = PedidoCliente::create([
        'numero' => 'PB-001000', 'contacto_id' => $this->proveedor->id,
        'estado' => 'facturado', 'factura_id' => $factura->id,
        'subtotal' => 500_000, 'total' => 500_000,
    ]);

    app(SiigoEmisionService::class)->anularFacturaEnSiigo($factura, 'Se facturó al cliente equivocado.');

    // El camino que ya funcionaba y que no se quiso romper: sin despacho de
    // por medio, anular libera el pedido para volver a facturarlo.
    expect($factura->fresh()->estado->value)->toBe('anulada')
        ->and($pedido->fresh()->factura_id)->toBeNull()
        ->and($pedido->fresh()->estado)->toBe('aprobado');
});
