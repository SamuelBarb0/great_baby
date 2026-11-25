<?php

/**
 * Arranque «en cero» (reunión con la señora Lahida, 24-nov-2026): al primer
 * corte del ERP sólo entran los pedidos que en Dropi siguen PENDIENTE. Los que
 * ya tienen guía generada en Dropi (flujo anterior) o que Dropi ya no muestra
 * pasan a «Gestionado en Dropi» y salen de las colas; la sincronización los
 * sigue moviendo después.
 */

use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $corte = AsignarCorteACarga::run(CarbonImmutable::now());

    $this->pedido = function (string $orden, EstadoPedidoDropi $estado, ?string $enDropi) use ($corte) {
        $p = DropiPedido::create([
            'corte_id' => $corte->id, 'guia' => "SIN-GUIA-{$orden}", 'dropi_orden_id' => $orden,
            'cliente_nombre' => 'Ana', 'monto_esperado_proveedor' => 1000,
        ]);
        $p->forceFill(['estado' => $estado])->save();
        if ($enDropi !== null) {
            DB::table('dropi_web_huellas')->insert(['dropi_orden_id' => $orden, 'huella' => 'x', 'estatus_dropi' => $enDropi]);
        }

        return $p;
    };
});

it('sólo deja en las colas lo que en Dropi sigue PENDIENTE', function () {
    $nuevo = ($this->pedido)('101', EstadoPedidoDropi::Pending, 'PENDIENTE');
    $sinStock = ($this->pedido)('102', EstadoPedidoDropi::PendienteInventario, 'PENDIENTE');
    $conGuia = ($this->pedido)('103', EstadoPedidoDropi::Pending, 'GUIA_GENERADA');
    $conGuiaSinStock = ($this->pedido)('104', EstadoPedidoDropi::PendienteInventario, 'GUIA_GENERADA');
    $viejo = ($this->pedido)('105', EstadoPedidoDropi::Pending, null);
    $empacado = ($this->pedido)('106', EstadoPedidoDropi::Empacado, 'GUIA_GENERADA');

    $this->artisan('dropi:arranque', ['--ejecutar' => true])->assertSuccessful();

    expect($nuevo->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
        ->and($sinStock->fresh()->estado)->toBe(EstadoPedidoDropi::PendienteInventario)
        ->and($conGuia->fresh()->estado)->toBe(EstadoPedidoDropi::GestionadoEnDropi)
        ->and($conGuiaSinStock->fresh()->estado)->toBe(EstadoPedidoDropi::GestionadoEnDropi)
        ->and($viejo->fresh()->estado)->toBe(EstadoPedidoDropi::GestionadoEnDropi)
        // Sacar un empacado revertiría su egreso de inventario: no se toca.
        ->and($empacado->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);

    // Queda la constancia de por qué salió.
    $bitacora = DB::table('dropi_estados_bitacora')->where('pedido_id', $conGuia->id)->latest('id')->first();
    expect($bitacora->estado_hasta)->toBe('gestionado_en_dropi')
        ->and($bitacora->fuente)->toBe('sistema')
        ->and(json_decode($bitacora->payload, true))->toMatchArray(['motivo' => 'arranque_erp', 'estatus_dropi' => 'GUIA_GENERADA']);
});

it('lo que sigue PENDIENTE en Dropi queda sin corte, esperando el primer «Generar corte» (25-nov-2026)', function () {
    $viejo = \App\Modules\Dropi\Models\DropiCorte::create(['fecha' => now()->subDays(10)->toDateString(), 'numero' => 1, 'estado' => \App\Modules\Dropi\Enums\EstadoCorte::Abierto]);
    $p = ($this->pedido)('501', EstadoPedidoDropi::Pending, 'PENDIENTE');
    $p->forceFill(['corte_id' => $viejo->id])->save();
    \App\Modules\Dropi\Listeners\RecalcularContadoresCorte::recontar($viejo->id);
    $conGuia = ($this->pedido)('502', EstadoPedidoDropi::Pending, 'GUIA_GENERADA');

    $this->artisan('dropi:arranque', ['--ejecutar' => true])->assertSuccessful();

    expect($p->fresh()->corte_id)->toBeNull()
        ->and($p->fresh()->estado)->toBe(EstadoPedidoDropi::Pending)
        ->and($viejo->fresh()->pedidos_totales)->toBe(0)
        ->and($conGuia->fresh()->estado)->toBe(EstadoPedidoDropi::GestionadoEnDropi);
});

it('sin --ejecutar no cambia nada', function () {
    $conGuia = ($this->pedido)('201', EstadoPedidoDropi::Pending, 'GUIA_GENERADA');

    $this->artisan('dropi:arranque')->assertSuccessful();

    expect($conGuia->fresh()->estado)->toBe(EstadoPedidoDropi::Pending);
});

it('la sincronización lo sigue moviendo, y a mano no vuelve a la cola', function () {
    $p = ($this->pedido)('301', EstadoPedidoDropi::Pending, 'GUIA_GENERADA');
    $this->artisan('dropi:arranque', ['--ejecutar' => true])->assertSuccessful();

    expect(EstadoPedidoDropi::GestionadoEnDropi->puedeIrA(EstadoPedidoDropi::Pending, 'manual'))->toBeFalse()
        ->and(EstadoPedidoDropi::GestionadoEnDropi->puedeIrA(EstadoPedidoDropi::Alistando, 'manual'))->toBeFalse();

    $p->fresh()->transicionar(EstadoPedidoDropi::Despachado, 'api');

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Despachado);
});
