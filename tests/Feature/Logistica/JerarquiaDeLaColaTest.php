<?php

/**
 * En qué orden se alista, según el área.
 *
 * Don Jorge entregó la jerarquía por escrito el 17-nov-2026 y son cinco
 * escalones. Lo que había puesto antes lo habíamos decidido nosotros —contado,
 * luego Bucaramanga, luego la fecha de facturación— y el valor del pedido no
 * entraba en la cuenta: uno de ochenta mil le ganaba a uno de tres millones
 * por haberse facturado diez minutos antes.
 *
 * Estas pruebas amarran el orden tal como lo pidió, porque es de esas cosas
 * que se rompen sin hacer ruido: la cola sigue apareciendo llena y nadie nota
 * que está ordenada al revés hasta que el cliente grande reclama.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Logistica\Support\PrioridadDeLaCola;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'disponible_para_venta' => true, 'activa' => true,
    ]);
});

/** Un cliente de una ciudad. */
function clienteDe(string $nombre, string $ciudad): \App\Models\Contacto
{
    return \App\Models\Contacto::create([
        'nombre_completo' => $nombre,
        'numero_documento' => (string) random_int(900000000, 999999999),
        'es_cliente' => true, 'activo' => true, 'ciudad' => $ciudad,
    ]);
}

/**
 * Un pedido ya facturado y esperando alistamiento, que es el estado en que la
 * cola los muestra.
 */
function pedidoEnCola(array $attrs, bool $contado = true): PedidoCliente
{
    static $n = 0;
    $n++;

    $facturaId = \DB::table('facturas_venta')->insertGetId([
        'numero' => 'FV-COLA-'.$n,
        'contacto_id' => $attrs['contacto_id'],
        'tipo' => $contado ? 'contado' : 'credito',
        'estado' => 'emitida',
        'fecha_emision' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'saldo' => 0,
        'subtotal' => $attrs['total'] ?? 1000,
        'total' => $attrs['total'] ?? 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return PedidoCliente::create(array_merge([
        'numero' => 'PB-COLA-'.$n,
        'estado' => 'facturado',
        'ubicacion_origen_id' => test()->bodega->id,
        'factura_id' => $facturaId,
        'facturado_at' => now()->subMinutes(100 - $n),
        'subtotal' => $attrs['total'] ?? 1000,
        'iva' => 0,
    ], $attrs));
}

/** La cola, en el orden en que la pinta la pantalla. */
function colaOrdenada(): array
{
    $consulta = PedidoCliente::query()
        ->where('estado', 'facturado')
        ->whereNull('despachado_at');

    return app(PrioridadDeLaCola::class)->aplicar($consulta)
        ->get()->pluck('numero')->all();
}

it('el contado local va antes que el contado nacional, y el crédito de último', function () {
    $bogota = clienteDe('Pañalera Bogotá', 'Bogotá');
    $bucara = clienteDe('Pañalera Bucaramanga', 'Bucaramanga');

    $credito  = pedidoEnCola(['numero' => 'PB-CREDITO', 'contacto_id' => $bogota->id, 'total' => 9_000_000], false);
    $nacional = pedidoEnCola(['numero' => 'PB-NACIONAL', 'contacto_id' => $bogota->id, 'total' => 100_000]);
    $local    = pedidoEnCola(['numero' => 'PB-LOCAL', 'contacto_id' => $bucara->id, 'total' => 50_000]);

    /*
     * El de crédito es noventa veces más grande y aun así va de último: el
     * escalón manda sobre el valor. El valor sólo ordena DENTRO del escalón.
     */
    expect(colaOrdenada())->toBe(['PB-LOCAL', 'PB-NACIONAL', 'PB-CREDITO']);
});

it('dentro del mismo escalón manda el valor, de mayor a menor', function () {
    $bucara = clienteDe('Pañalera Bucaramanga', 'Bucaramanga');

    // El chico se facturó PRIMERO: con el orden viejo habría ido adelante.
    pedidoEnCola(['numero' => 'PB-CHICO', 'contacto_id' => $bucara->id, 'total' => 80_000]);
    pedidoEnCola(['numero' => 'PB-GRANDE', 'contacto_id' => $bucara->id, 'total' => 3_000_000]);

    expect(colaOrdenada())->toBe(['PB-GRANDE', 'PB-CHICO']);
});

it('a igual escalón y a igual valor, primero el más viejo', function () {
    $bucara = clienteDe('Pañalera Bucaramanga', 'Bucaramanga');

    $viejo = pedidoEnCola(['numero' => 'PB-VIEJO', 'contacto_id' => $bucara->id, 'total' => 500_000]);
    $nuevo = pedidoEnCola(['numero' => 'PB-NUEVO', 'contacto_id' => $bucara->id, 'total' => 500_000]);

    $viejo->update(['facturado_at' => now()->subDays(2)]);
    $nuevo->update(['facturado_at' => now()]);

    expect(colaOrdenada())->toBe(['PB-VIEJO', 'PB-NUEVO']);
});

it('los pedidos de la señora Tania van primeros, cuando se dice cuál contacto es', function () {
    $tania  = clienteDe('Sra. Tania', 'Bogotá');
    $bucara = clienteDe('Pañalera Bucaramanga', 'Bucaramanga');

    pedidoEnCola(['numero' => 'PB-LOCAL-GRANDE', 'contacto_id' => $bucara->id, 'total' => 5_000_000]);
    pedidoEnCola(['numero' => 'PB-TANIA', 'contacto_id' => $tania->id, 'total' => 10_000]);

    // Sin configurar, el escalón no aparta a nadie: manda el resto de la regla.
    expect(colaOrdenada())->toBe(['PB-LOCAL-GRANDE', 'PB-TANIA']);

    // Diciendo cuál contacto es ella, sus pedidos pasan al frente — aunque el
    // suyo sea quinientas veces más chico.
    \App\Support\Reglas::set('logistica.contacto_sra_tania', $tania->id);

    expect(colaOrdenada())->toBe(['PB-TANIA', 'PB-LOCAL-GRANDE']);
});

it('los de Dropi van después de Tania y antes que todo lo demás', function () {
    $dropi = \App\Models\EmpresaConfig::where('codigo', 'ecommerce')->first()
        ?? \App\Models\EmpresaConfig::create([
            'codigo' => 'ecommerce', 'nombre_comercial' => 'E-commerce · Dropi',
            'razon_social' => 'E-commerce Dropi SAS', 'nit' => '900111222', 'activa' => true,
        ]);

    $bucara = clienteDe('Pañalera Bucaramanga', 'Bucaramanga');
    $cli = clienteDe('Cliente Dropi', 'Bogotá');

    pedidoEnCola(['numero' => 'PB-LOCAL', 'contacto_id' => $bucara->id, 'total' => 4_000_000]);
    pedidoEnCola(['numero' => 'PB-DROPI', 'contacto_id' => $cli->id, 'total' => 20_000, 'empresa_id' => $dropi->id]);

    expect(colaOrdenada())->toBe(['PB-DROPI', 'PB-LOCAL']);
});

it('el área metropolitana se puede cambiar sin tocar código', function () {
    $neiva = clienteDe('Pañalera Neiva', 'Neiva');
    $bucara = clienteDe('Pañalera Bucaramanga', 'Bucaramanga');

    pedidoEnCola(['numero' => 'PB-NEIVA', 'contacto_id' => $neiva->id, 'total' => 10_000]);
    pedidoEnCola(['numero' => 'PB-BUCARA', 'contacto_id' => $bucara->id, 'total' => 10_000]);

    expect(colaOrdenada())->toBe(['PB-BUCARA', 'PB-NEIVA']);

    // Si mañana abren sede en Neiva, se agrega a la regla y la cola la respeta.
    \App\Support\Reglas::set('logistica.area_metropolitana', ['neiva']);

    expect(colaOrdenada())->toBe(['PB-NEIVA', 'PB-BUCARA']);
});
