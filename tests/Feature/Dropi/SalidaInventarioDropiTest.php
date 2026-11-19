<?php

/**
 * Tarjeta Dropi · 4. Empaque / Escaneo / Despacho.
 *
 *   4.3 · el empaque Dropi descuenta de donde está la mercancía y frena sin
 *         saldo (mismo hueco que tenía el despacho B2B);
 *   4.4 · y causa el costo de ventas en ese momento (6135 contra 1435);
 *   4.5 · cada cambio hecho por una persona queda con su GPS, IP y equipo,
 *         sólo confirma el empaque quien lo empezó, y la línea de tiempo
 *         del envío lo junta todo.
 */

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Actions\ConfirmarEmpaqueYSiguiente;
use App\Modules\Dropi\Actions\RegistrarDevolucion;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiEstadoBitacora;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use App\Modules\Dropi\Support\Trazabilidad;
use App\Support\Reglas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requiere MySQL.');
    }
    Illuminate\Support\Facades\Queue::fake();

    $this->operario = App\Models\User::factory()->create();
    $this->otro = App\Models\User::factory()->create();

    $this->venta = InventarioUbicacion::create([
        'codigo' => 'BOD-VENTA', 'nombre' => 'Bodega venta', 'activa' => true, 'categoria' => 'venta',
    ]);

    $producto = Producto::create(['referencia' => 'COCHE-01', 'nombre' => 'Coche paseador', 'desglose_stock' => true]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $producto->id, 'color_codigo' => 'AZU', 'color_nombre' => 'Azul',
        'codigo_barras' => '7700000000999',
    ]);
    VincularProductoDropi::run('DV-555', $this->variante->id);

    // 10 a 10.000 + 10 a 20.000 → costo promedio 15.000.
    foreach ([10000, 20000] as $costo) {
        InventarioMovimiento::create([
            'variante_id' => $this->variante->id, 'ubicacion_id' => $this->venta->id,
            'tipo' => 'entrada_compra', 'cantidad' => 10, 'costo_unit' => $costo,
        ]);
    }
});

function pedidoDropiConItem(string $guia, int $cantidad): DropiPedido
{
    app(SincronizarPedidosDropi::class)->importarPedidos([new PedidoDropiDTO(
        guia: $guia, dropiOrdenId: 'O-'.$guia, transportadora: 'X', tienda: 'T', vendedorNombre: 'V',
        vendedorIdentificacion: null, clienteNombre: 'Ana', clienteDoc: null, clienteTelefono: null,
        clienteDireccion: null, clienteCiudad: null, clienteDepto: null, estadoDropi: 'sin_estado',
        montoEsperadoProveedor: 90000, montoClienteFinal: null, gananciaVendedor: null, fleteTransportadora: null,
        items: [new ItemDropiDTO('DV-555', $cantidad, 30000, 'Coche paseador', 'Azul')],
        creadoAt: CarbonImmutable::now(),
    )]);

    return DropiPedido::where('guia', $guia)->firstOrFail();
}

function saldoVariante(int $varianteId, int $ubicacionId): int
{
    return (int) InventarioMovimiento::where('variante_id', $varianteId)
        ->where('ubicacion_id', $ubicacionId)->sum('cantidad');
}

function costoCausado(DropiPedido $p): array
{
    $m = MovimientoContable::where('origen_type', DropiPedido::class)->where('origen_id', $p->id)->get();

    return [
        '6135' => round((float) $m->where('cuenta_puc', '6135')->sum('debe') - (float) $m->where('cuenta_puc', '6135')->sum('haber'), 2),
        '1435' => round((float) $m->where('cuenta_puc', '1435')->sum('haber') - (float) $m->where('cuenta_puc', '1435')->sum('debe'), 2),
    ];
}

it('al empacar descuenta de la bodega de venta y causa el costo al promedio', function () {
    $p = pedidoDropiConItem('G-1', 3);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);

    $egreso = InventarioMovimiento::where('referencia_tipo', SalidaInventarioDropi::EGRESO)->sole();
    expect($egreso->cantidad)->toBe(-3)
        ->and((float) $egreso->costo_unit)->toBe(15000.0)
        ->and($egreso->ubicacion_id)->toBe($this->venta->id)
        ->and(saldoVariante($this->variante->id, $this->venta->id))->toBe(17)
        ->and(costoCausado($p))->toBe(['6135' => 45000.0, '1435' => 45000.0]);
});

it('no deja empacar si la bodega no alcanza, y el pedido no cambia de estado', function () {
    $p = pedidoDropiConItem('G-2', 25);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);

    expect(fn () => $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id))
        ->toThrow(InvalidArgumentException::class, 'pide 25, hay 20');

    expect($p->fresh()->estado)->toBe(EstadoPedidoDropi::Alistando)
        ->and(InventarioMovimiento::where('referencia_tipo', SalidaInventarioDropi::EGRESO)->exists())->toBeFalse()
        ->and(MovimientoContable::where('origen_type', DropiPedido::class)->exists())->toBeFalse();
});

it('ignora las bodegas que no son de venta aunque tengan más existencias', function () {
    $averia = InventarioUbicacion::create([
        'codigo' => 'BOD-AVERIA', 'nombre' => 'Averías', 'activa' => true, 'categoria' => 'averia_reparar',
    ]);
    InventarioMovimiento::create([
        'variante_id' => $this->variante->id, 'ubicacion_id' => $averia->id,
        'tipo' => 'entrada_compra', 'cantidad' => 500, 'costo_unit' => 1,
    ]);

    $p = pedidoDropiConItem('G-3', 2);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);

    expect(InventarioMovimiento::where('referencia_tipo', SalidaInventarioDropi::EGRESO)->sole()->ubicacion_id)
        ->toBe($this->venta->id);
});

it('si Dropi lo despacha sin pasar por la estación, igual descuenta (sin frenar)', function () {
    $p = pedidoDropiConItem('G-4', 2);
    $p->transicionar(EstadoPedidoDropi::Despachado, 'api');

    expect(saldoVariante($this->variante->id, $this->venta->id))->toBe(18)
        ->and(costoCausado($p)['6135'])->toBe(30000.0);
});

it('empacado → despachado no descuenta dos veces', function () {
    $p = pedidoDropiConItem('G-5', 2);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Despachado, 'manual', $this->operario->id);

    expect(saldoVariante($this->variante->id, $this->venta->id))->toBe(18)
        ->and(costoCausado($p)['6135'])->toBe(30000.0);
});

it('el rework devuelve a la misma bodega con el mismo costo, y re-empacar vuelve a descontar', function () {
    $p = pedidoDropiConItem('G-6', 4);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);

    $reverso = InventarioMovimiento::where('referencia_tipo', SalidaInventarioDropi::REVERSO)->sole();
    expect($reverso->cantidad)->toBe(4)
        ->and((float) $reverso->costo_unit)->toBe(15000.0)
        ->and(saldoVariante($this->variante->id, $this->venta->id))->toBe(20)
        ->and(costoCausado($p))->toBe(['6135' => 0.0, '1435' => 0.0]);

    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);
    expect(saldoVariante($this->variante->id, $this->venta->id))->toBe(16)
        ->and(costoCausado($p)['6135'])->toBe(60000.0);
});

it('cancelar un pedido ya empacado devuelve la mercancía a la bodega', function () {
    $p = pedidoDropiConItem('G-7', 3);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::CanceladoDropi, 'api');

    expect(saldoVariante($this->variante->id, $this->venta->id))->toBe(20)
        ->and(costoCausado($p)['6135'])->toBe(0.0);
});

it('la devolución reingresa al costo de salida y reversa ese costo, no el precio de venta', function () {
    $p = pedidoDropiConItem('G-8', 2);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Despachado, 'manual', $this->operario->id);

    // La bodega de reingreso de una devolución es la de venta.
    RegistrarDevolucion::run('G-8', DestinoDevolucion::Reingreso, $this->operario->id);

    $ingreso = InventarioMovimiento::where('referencia_tipo', 'devolucion')->sole();
    expect((float) $ingreso->costo_unit)->toBe(15000.0);

    $dev = MovimientoContable::where('descripcion', 'like', 'Reverso costo%')->get();
    expect((float) $dev->sum('haber'))->toBe(30000.0);
});

it('la bitácora guarda GPS, IP y equipo en columnas, no en el payload', function () {
    $p = pedidoDropiConItem('G-9', 1);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(
        EstadoPedidoDropi::Empacado, 'manual', $this->operario->id,
        ['origen' => 'estacion_empaque'] + Trazabilidad::payload(['lat' => 4.6097, 'lng' => -74.0817, 'precision' => 12.4]),
    );

    $b = DropiEstadoBitacora::where('estado_hasta', 'empacado')->sole();
    expect((float) $b->lat)->toBe(4.6097)
        ->and((float) $b->lng)->toBe(-74.0817)
        ->and($b->gps_precision_m)->toBe(12)
        ->and($b->gps_estado)->toBe('ok')
        ->and($b->payload)->toBe(['origen' => 'estacion_empaque']);

    $sinGps = Trazabilidad::normalizar(['estado' => 'denegado']);
    expect($sinGps['estado'])->toBe('denegado')->and($sinGps['lat'])->toBeNull();
});

it('sólo confirma el empaque quien lo empezó', function () {
    $p = pedidoDropiConItem('G-10', 1);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->items()->update(['cantidad_pickeada' => 1]);
    EmpaqueRegistro::create([
        'pedido_id' => $p->id, 'operario_id' => $this->operario->id, 'inicio_at' => now(),
        'items_totales' => 1, 'items_escaneados' => 1, 'estado' => 'en_curso',
    ]);

    expect(fn () => ConfirmarEmpaqueYSiguiente::run($p->id, $this->otro->id))
        ->toThrow(InvalidArgumentException::class, 'No hay empaque en curso tuyo');

    $r = ConfirmarEmpaqueYSiguiente::run($p->id, $this->operario->id, ['lat' => 4.6, 'lng' => -74.08]);
    expect($r['msg'])->toContain('empacado')
        ->and($p->fresh()->estado)->toBe(EstadoPedidoDropi::Empacado);
});

it('con la regla de GPS obligatorio prendida no deja confirmar sin ubicación', function () {
    Reglas::set('empaque.gps_obligatorio', true);

    expect(fn () => Trazabilidad::exigir(['estado' => 'denegado']))
        ->toThrow(InvalidArgumentException::class, 'negó el permiso de ubicación');
    expect(fn () => Trazabilidad::exigir(null))->toThrow(InvalidArgumentException::class);

    Trazabilidad::exigir(['lat' => 4.6, 'lng' => -74.08]);
    expect(true)->toBeTrue();
});

it('la línea de tiempo junta estados, empaque e inventario en orden', function () {
    $p = pedidoDropiConItem('G-11', 1);
    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $this->operario->id);
    $p->transicionar(EstadoPedidoDropi::Empacado, 'manual', $this->operario->id, Trazabilidad::payload(['estado' => 'no_disponible']));

    $eventos = Trazabilidad::lineaDeTiempo($p->fresh());
    $titulos = array_column($eventos, 'titulo');

    expect($titulos)->toContain('Empacado')
        ->and($titulos)->toContain('Salió del inventario');

    $empacado = collect($eventos)->firstWhere('titulo', 'Empacado');
    expect($empacado['quien'])->toBe($this->operario->name)
        ->and($empacado['gps']['estado'])->toBe('no_disponible');
});
