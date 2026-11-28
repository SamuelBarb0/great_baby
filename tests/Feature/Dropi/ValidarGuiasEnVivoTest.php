<?php

/**
 * Antes de pedir cada guía se le pregunta a Dropi cómo está el pedido HOY
 * (26-nov-2026): el corte de las 9:19 pidió guías de pedidos ya rechazados o
 * reemplazados, porque el ERP los seguía teniendo pendientes. Y cada pedido
 * deja en el reporte qué pasó y qué hacer, para Aracely.
 */

use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Actions\GenerarGuiaDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Jobs\GenerarGuiasEnBloqueJob;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->corte = AsignarCorteACarga::run(CarbonImmutable::now());
    // Muchas guías por esperar: el reporte no arranca en estas pruebas.
    $this->lote = DropiLoteImpresion::create([
        'corte_id' => $this->corte->id, 'pedidos' => [], 'total' => 0,
        'guias_pendientes' => 99, 'estado' => DropiLoteImpresion::GENERANDO_GUIAS,
    ]);
});

function gevPedido(string $orden, array $cambios = []): DropiPedido
{
    $p = DropiPedido::create($cambios + [
        'corte_id' => test()->corte->id, 'guia' => (string) $orden, 'dropi_orden_id' => $orden,
        'cliente_nombre' => 'Ana Pérez', 'cliente_telefono' => '3001112233',
        'monto_esperado_proveedor' => 1000, 'dropi_creado_at' => now()->subDay(),
    ]);
    $p->items()->create(['sku_dropi' => 'TP', 'producto_nombre' => 'Producto TP', 'cantidad' => 1, 'precio_proveedor_unit' => 1000]);

    return $p;
}

/** El detalle que devuelve Dropi, como el real (con su ítem, o el sync lo dejaría vacío). */
function gevDetalle(string $orden, string $status, ?string $guia = null): array
{
    return [
        'id' => (int) $orden, 'status' => $status, 'shipping_guide' => $guia, 'shipping_company' => $guia ? 'INTERRAPIDISIMO' : null,
        'name' => 'Ana', 'surname' => 'Pérez', 'phone' => '3001112233', 'created_at' => now()->subDay()->toIso8601String(),
        'orderdetails' => [['quantity' => 1, 'supplier_price' => 1000, 'product' => ['sku' => 'TP', 'name' => 'Producto TP']]],
    ];
}

/** Dropi responde así por orden; una Throwable = Dropi respondió con ese error. */
function gevDropiDice(array $porOrden): void
{
    test()->partialMock(DropiWebClient::class, fn ($m) => $m->shouldReceive('pedidoDetalle')->andReturnUsing(function (int $id) use ($porOrden) {
        $r = $porOrden[(string) $id];
        if ($r instanceof Throwable) {
            throw $r;
        }

        return $r;
    }));
}

function gevResultado(DropiPedido $p): ?array
{
    return collect(test()->lote->fresh()->resultados)->firstWhere('pedido_id', $p->id);
}

it('lo que en Dropi sigue PENDIENTE pide su guía y queda «generada» en el reporte', function () {
    $p = gevPedido('900');
    gevDropiDice(['900' => gevDetalle('900', 'PENDIENTE')]);
    $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->once()->andReturn(['guia' => 'G-900']);

    GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

    expect(gevResultado($p))->toMatchArray(['tipo' => 'generada', 'guia' => 'G-900', 'orden' => '900', 'cliente' => 'Ana Pérez']);
});

it('rechazado en Dropi: no pide guía, lo cancela en el ERP y lo explica', function () {
    $p = gevPedido('901');
    gevDropiDice(['901' => gevDetalle('901', 'RECHAZADO')]);
    $this->mock(GenerarGuiaDropi::class)->shouldNotReceive('handle');

    GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::CanceladoDropi)
        ->and(gevResultado($p))->toMatchArray(['tipo' => 'cancelado', 'detalle' => 'En Dropi está «RECHAZADO».']);
});

it('Dropi ya no tiene la orden: la cancela en el ERP y señala la que la reemplazó', function () {
    $viejo = gevPedido('902');
    gevPedido('903', ['guia' => '064109446023', 'estado' => EstadoPedidoDropi::Despachado->value]);
    gevDropiDice(['902' => new RuntimeException('Dropi no dejó leer el pedido 902: Orden no encontrada')]);
    $this->mock(GenerarGuiaDropi::class)->shouldNotReceive('handle');

    GenerarGuiasEnBloqueJob::lanzar([$viejo->id], 1, $this->lote->id);

    $r = gevResultado($viejo);
    expect($viejo->fresh()->estado)->toBe(EstadoPedidoDropi::CanceladoDropi)
        ->and($r['tipo'])->toBe('reemplazado')
        ->and($r['detalle'])->toContain('La nueva es la orden 903, con guía 064109446023');
});

it('si al pedir la guía Dropi dice que fue reemplazada, también se da de baja', function () {
    $p = gevPedido('904');
    gevDropiDice(['904' => gevDetalle('904', 'PENDIENTE')]);
    $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')
        ->andThrow(new RuntimeException('Dropi no cambió el pedido 904: La orden fue reemplazada por una nueva. Actualiza el listado para editarla.'));

    GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::CanceladoDropi)
        ->and(gevResultado($p)['tipo'])->toBe('reemplazado');
});

it('ya tenía guía en Dropi: no genera otra, la trae al ERP y entra al reporte', function () {
    $p = gevPedido('905');
    gevDropiDice(['905' => gevDetalle('905', 'GUIA_GENERADA', '240055550001')]);
    // Sólo para buscar su sticker: el pedido ya trae su guía.
    $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->once()
        ->withArgs(fn (DropiPedido $x) => $x->tieneGuiaReal())->andReturn(['guia' => '240055550001']);

    GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

    expect($p->fresh()->guia)->toBe('240055550001')
        ->and(gevResultado($p))->toMatchArray(['tipo' => 'ya_tenia', 'guia' => '240055550001']);
});

it('sin cobertura de la transportadora queda separado de los demás errores', function () {
    $p = gevPedido('906');
    $q = gevPedido('907');
    gevDropiDice(['906' => gevDetalle('906', 'PENDIENTE'), '907' => gevDetalle('907', 'PENDIENTE')]);
    $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->andReturnUsing(function (DropiPedido $x) {
        throw new RuntimeException($x->dropi_orden_id === '906'
            ? 'Dropi no cambió el pedido 906: Error al actualizar la orden: Lo sentimos, no hay cobertura disponible para el tipo de envío especificado'
            : 'cURL error 28: Operation timed out after 20000 milliseconds');
    });

    GenerarGuiasEnBloqueJob::lanzar([$p->id, $q->id], 1, $this->lote->id);

    expect(gevResultado($p))->toMatchArray(['tipo' => 'sin_cobertura', 'detalle' => 'Dropi: «Lo sentimos, no hay cobertura disponible para el tipo de envío especificado».'])
        ->and(gevResultado($q)['tipo'])->toBe('error')
        ->and($p->fresh()->estado)->not->toBe(EstadoPedidoDropi::CanceladoDropi)
        ->and($this->lote->fresh()->conteoResultados())->toBe(['sin_cobertura' => 1, 'error' => 1]);
});

it('si Dropi no responde la consulta, no pide la guía a ciegas ni cancela nada', function () {
    $p = gevPedido('908');
    gevDropiDice(['908' => new RuntimeException('cURL error 28: Operation timed out')]);
    $this->mock(GenerarGuiaDropi::class)->shouldNotReceive('handle');

    GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

    expect(gevResultado($p)['tipo'])->toBe('error')
        ->and($p->fresh()->estado)->not->toBe(EstadoPedidoDropi::CanceladoDropi);
});

it('las guías y el reporte van en su propia cola, no detrás de SIIGO', function () {
    Queue::fake();
    $p = gevPedido('909');

    GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

    Queue::assertPushedOn('guias', GenerarGuiasEnBloqueJob::class);
    expect((new \App\Modules\Dropi\Jobs\DescargarStickersLoteJob(1, []))->queue)->toBe('guias')
        ->and((new \App\Modules\Dropi\Jobs\ArmarParteLoteJob(1, 1))->queue)->toBe('guias');
});

it('«Pedidos Dropi» resume el reporte y «Ver detalle» explica cada pedido', function () {
    \Filament\Facades\Filament::setCurrentPanel('admin');
    $this->lote->update(['resultados' => [
        ['pedido_id' => 1, 'orden' => '95944823', 'cliente' => 'Yulitza Cuervo', 'tipo' => 'cancelado', 'detalle' => 'En Dropi está «RECHAZADO».', 'guia' => null],
        ['pedido_id' => 2, 'orden' => '98133886', 'cliente' => 'Juan David', 'tipo' => 'reemplazado', 'detalle' => 'Dropi ya no tiene la orden. La nueva es la orden 98133965, con guía 064109446023 (Despachado).', 'guia' => null],
        ['pedido_id' => 3, 'orden' => '98200001', 'cliente' => 'Luz', 'tipo' => 'generada', 'detalle' => null, 'guia' => 'G-1'],
    ]]);

    $html = view('dropi.pages.partials.resultados-guias', ['lote' => $this->lote->fresh()])->render();

    expect($this->lote->fresh()->conteoResultados())->toBe(['generada' => 1, 'cancelado' => 1, 'reemplazado' => 1])
        ->and($html)->toContain('Cancelado o rechazado en Dropi')
        ->and($html)->toContain('Yulitza Cuervo')
        ->and($html)->toContain('La nueva es la orden 98133965')
        ->and($html)->toContain('Qué hacer');
});

describe('cuando Dropi frena con «Too Many Attempts» (27-nov-2026)', function () {
    it('se reintenta solo y, cuando Dropi contesta, queda «generada» y cuenta una sola vez', function () {
        $p = gevPedido('910');
        $llamadas = 0;
        test()->partialMock(DropiWebClient::class, function ($m) use (&$llamadas) { $m->shouldReceive('pedidoDetalle')->andReturnUsing(function () use (&$llamadas) {
            if (++$llamadas <= 2) {
                throw new RuntimeException('Dropi no devolvió el pedido 910 (HTTP 429): Too Many Attempts.');
            }

            return gevDetalle('910', 'PENDIENTE');
        }); });
        $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->once()->andReturn(['guia' => 'G-910']);

        GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

        expect($llamadas)->toBe(3)
            ->and(gevResultado($p))->toMatchArray(['tipo' => 'generada', 'guia' => 'G-910'])
            ->and($this->lote->fresh()->guias_pendientes)->toBe(98);
    });

    it('si el freno llega al pedir la guía, también se reintenta', function () {
        $p = gevPedido('911');
        gevDropiDice(['911' => gevDetalle('911', 'PENDIENTE')]);
        $intentos = 0;
        $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->andReturnUsing(function () use (&$intentos) {
            if (++$intentos === 1) {
                throw new RuntimeException('Dropi no cambió el pedido 911: Too Many Attempts.');
            }

            return ['guia' => 'G-911'];
        });

        GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

        expect($intentos)->toBe(2)->and(gevResultado($p))->toMatchArray(['tipo' => 'generada', 'guia' => 'G-911']);
    });

    it('tras los reintentos, si sigue frenando, queda el error para Aracely', function () {
        $p = gevPedido('912');
        $llamadas = 0;
        test()->partialMock(DropiWebClient::class, function ($m) use (&$llamadas) { $m->shouldReceive('pedidoDetalle')->andReturnUsing(function () use (&$llamadas) {
            $llamadas++;
            throw new RuntimeException('Dropi no devolvió el pedido 912 (HTTP 429): Too Many Attempts.');
        }); });
        $this->mock(GenerarGuiaDropi::class)->shouldNotReceive('handle');

        GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

        expect($llamadas)->toBe(GenerarGuiasEnBloqueJob::REINTENTOS_POR_FRENO + 1)
            ->and(gevResultado($p)['tipo'])->toBe('error')
            ->and(gevResultado($p)['detalle'])->toContain('Too Many Attempts')
            ->and($this->lote->fresh()->guias_pendientes)->toBe(98);
    });

    it('los demás errores no se reintentan: sin cobertura sale de una', function () {
        $p = gevPedido('913');
        gevDropiDice(['913' => gevDetalle('913', 'PENDIENTE')]);
        $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->once()
            ->andThrow(new RuntimeException('Dropi no cambió el pedido 913: Lo sentimos, no hay cobertura disponible'));

        GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

        expect(gevResultado($p)['tipo'])->toBe('sin_cobertura');
    });
});

describe('cuando Dropi no contesta a tiempo (28-nov-2026: «cURL error 28» con la guía ya creada)', function () {
    it('se reintenta y, si la guía sí quedó en Dropi, entra al corte con ella', function () {
        $p = gevPedido('920');
        $consultas = 0;
        test()->partialMock(DropiWebClient::class, function ($m) use (&$consultas) { $m->shouldReceive('pedidoDetalle')->andReturnUsing(function () use (&$consultas) {
            // La primera vez está pendiente; al reintentar, Dropi ya generó la guía.
            return ++$consultas === 1 ? gevDetalle('920', 'PENDIENTE') : gevDetalle('920', 'GUIA_GENERADA', '240066985832');
        }); });
        $intentos = 0;
        $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->andReturnUsing(function () use (&$intentos) {
            if (++$intentos === 1) {
                throw new RuntimeException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
            }

            return ['guia' => '240066985832'];
        });

        GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

        expect($consultas)->toBe(2)
            ->and(gevResultado($p))->toMatchArray(['tipo' => 'ya_tenia', 'guia' => '240066985832'])
            ->and($p->fresh()->guia)->toBe('240066985832')
            ->and($this->lote->fresh()->guias_pendientes)->toBe(98);
    });

    it('si la consulta misma se demora, también se reintenta', function () {
        $p = gevPedido('921');
        $consultas = 0;
        test()->partialMock(DropiWebClient::class, function ($m) use (&$consultas) { $m->shouldReceive('pedidoDetalle')->andReturnUsing(function () use (&$consultas) {
            if (++$consultas === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
            }

            return gevDetalle('921', 'PENDIENTE');
        }); });
        $this->mock(GenerarGuiaDropi::class)->shouldReceive('handle')->once()->andReturn(['guia' => 'G-921']);

        GenerarGuiasEnBloqueJob::lanzar([$p->id], 1, $this->lote->id);

        expect(gevResultado($p))->toMatchArray(['tipo' => 'generada', 'guia' => 'G-921']);
    });

    it('un trabajo cortado por tiempo vuelve a la cola en vez de quedar en error', function () {
        Queue::fake();
        $p = gevPedido('922');

        (new GenerarGuiasEnBloqueJob($p->id, 1, $this->lote->id))
            ->failed(new \Illuminate\Queue\MaxAttemptsExceededException('GenerarGuiasEnBloqueJob has been attempted too many times or run too long.'));

        Queue::assertPushed(GenerarGuiasEnBloqueJob::class, fn ($j) => $j->pedidoId === $p->id && $j->freno === 1);
        expect(gevResultado($p))->toBeNull()
            ->and($this->lote->fresh()->guias_pendientes)->toBe(99);
    });
});
