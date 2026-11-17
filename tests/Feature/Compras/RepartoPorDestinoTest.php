<?php

/**
 * Un mismo contenedor se reparte entre varios destinos, con cantidades.
 *
 * Es el caso que describió el área: «de estas 50, 30 son de Great Baby y 20
 * son de Dropi». Hasta hoy el destino se decidía una sola vez, al armar la
 * orden, y la recepción lo copiaba a su encabezado: todo entraba al mismo
 * sitio. Ahora el destino baja a la línea, y una misma línea puede abrir
 * varios renglones.
 *
 * Lo que se verifica acá, y por qué cada cosa:
 *   · el kardex queda partido de verdad, un movimiento por bodega, y la suma
 *     sigue siendo la que se contó;
 *   · una recepción sin repartir no cambia en nada —es el 99% de los días—;
 *   · elegir el mismo destino de la orden se guarda como «sin repartir», o
 *     quedarían dos filas para un solo sitio;
 *   · `fusionar()` separa por destino: con la clave vieja los dos renglones
 *     se sumaban en uno solo y el reparto se perdía en silencio;
 *   · lo que no llegó apto ignora el destino y va a la zona no vendible, y la
 *     fila NO guarda el destino, para que no diga que la mercancía está donde
 *     no está;
 *   · repartir de más se rechaza mirando la línea completa, no renglón por
 *     renglón, y el mensaje dice la suma;
 *   · un par que el área no marcó como de ingreso directo se rechaza aunque
 *     llegue por POST.
 */

use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Inventario\Support\UbicacionDelPar;
use Database\Seeders\CatalogoOficinasAlmacenesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);

    $this->bodegaOrden = InventarioUbicacion::create([
        'codigo' => 'ORD', 'nombre' => 'Bodega de la orden', 'categoria' => 'venta',
        'disponible_para_venta' => true, 'activa' => true,
    ]);

    $this->par = app(UbicacionDelPar::class);
    $this->gbPrincipal = $this->par->resolver(oficinaDe('10'), almacenDe('01'));
    $this->dropiPrincipal = $this->par->resolver(oficinaDe('11'), almacenDe('01'));
});

function oficinaDe(string $c): Oficina
{
    return Oficina::where('codigo', $c)->firstOrFail();
}

function almacenDe(string $c): Almacen
{
    return Almacen::where('codigo', $c)->firstOrFail();
}

/** Una OC nacional aprobada con una línea de 50 unidades de un producto real. */
function ordenDe50(InventarioUbicacion $bodega): OrdenCompra
{
    $producto = \App\Modules\Dropi\Models\Producto::create([
        'nombre' => 'Body león manga larga', 'referencia' => 'TEST-REPARTO',
        'activo' => true, 'desglose_stock' => false,
    ]);

    $orden = OrdenCompra::create([
        'numero' => 'OC-REPARTO-1',
        'proveedor_id' => \App\Models\Contacto::create([
            'nombre_completo' => 'Textiles Andinos', 'numero_documento' => '901111222',
            'es_proveedor' => true, 'activo' => true,
        ])->id,
        'bodega_id' => $bodega->id,
        'tipo' => 'nacional',
        'moneda' => 'COP',
        'tasa_cambio' => 1,
        'estado' => 'aprobada',
        'fecha_emision' => now()->toDateString(),
        'subtotal' => 600000, 'iva' => 0, 'total' => 600000,
        'creado_por' => \App\Models\User::factory()->create()->id,
    ]);

    $orden->items()->create([
        'descripcion' => 'Body león manga larga',
        'cantidad' => 50,
        'cantidad_recibida' => 0,
        'precio_unit' => 12000,
        'producto_id' => $producto->id,
    ]);

    return $orden->load('items');
}

/** Lo que entró a cada bodega por esta recepción. */
function kardexDe(RecepcionCompra $rc): array
{
    return InventarioMovimiento::where('referencia_id', $rc->id)
        ->where('referencia_tipo', RecepcionCompra::class)
        ->get()
        ->mapWithKeys(fn ($m) => [(int) $m->ubicacion_id => (float) $m->cantidad])
        ->all();
}

it('parte una línea de 50 en 30 para Great Baby y 20 para Dropi', function () {
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();

    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 30, 'ubicacion_id' => $this->gbPrincipal->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 20, 'ubicacion_id' => $this->dropiPrincipal->id],
        ],
    ]);

    $kardex = kardexDe($rc);

    expect($kardex[$this->gbPrincipal->id] ?? null)->toBe(30.0)
        ->and($kardex[$this->dropiPrincipal->id] ?? null)->toBe(20.0)
        ->and(array_sum($kardex))->toBe(50.0)
        // Y la orden queda recibida completa: repartir no es recibir a medias.
        ->and((float) $linea->fresh()->cantidad_recibida)->toBe(50.0);
});

it('sin repartir, todo entra a la bodega de la orden y la fila queda en null', function () {
    // Es el caso de todos los días: una recepción normal no puede cambiar.
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();

    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [['orden_item_id' => $linea->id, 'cantidad_recibida' => 50]],
    ]);

    expect(kardexDe($rc))->toBe([$this->bodegaOrden->id => 50.0])
        ->and($rc->items)->toHaveCount(1)
        ->and($rc->items->first()->ubicacion_id)->toBeNull();
});

it('elegir la misma bodega de la orden no abre una segunda fila', function () {
    // Guardar el id en vez de null haría ver repartida una recepción que no lo
    // está, y partiría en dos lo que es un solo destino.
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();

    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 20, 'ubicacion_id' => $this->bodegaOrden->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 30],
        ],
    ]);

    expect($rc->items)->toHaveCount(1)
        ->and((float) $rc->items->first()->cantidad_recibida)->toBe(50.0)
        ->and($rc->items->first()->ubicacion_id)->toBeNull()
        ->and(kardexDe($rc))->toBe([$this->bodegaOrden->id => 50.0]);
});

it('dos renglones al mismo destino se suman y a destinos distintos se separan', function () {
    // El fallo que esto ataja: la clave de fusionar() no incluía el destino,
    // así que «30 aquí y 20 allá» se guardaba como una sola fila de 50 que
    // entraba entera a la bodega de la orden.
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();

    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 10, 'ubicacion_id' => $this->gbPrincipal->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 20, 'ubicacion_id' => $this->gbPrincipal->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 20, 'ubicacion_id' => $this->dropiPrincipal->id],
        ],
    ]);

    expect($rc->items)->toHaveCount(2)
        ->and(kardexDe($rc))->toBe([
            $this->gbPrincipal->id => 30.0,
            $this->dropiPrincipal->id => 20.0,
        ]);
});

it('lo que no llegó apto ignora el destino y no lo guarda', function () {
    // Una fila con bodega escrita y la mercancía en otro sitio es una trampa
    // para quien la lea el mes que viene.
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();

    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [[
            'orden_item_id' => $linea->id,
            'cantidad_recibida' => 50,
            'ubicacion_id' => $this->dropiPrincipal->id,
            'clasificacion' => 'averia',
            'motivo_clasificacion' => 'Cajas mojadas',
        ]],
    ]);

    $noVendible = app(\App\Modules\Compras\Services\TrasRecepcion::class)->ubicacionNoVendible();

    expect($rc->items->first()->ubicacion_id)->toBeNull()
        ->and(kardexDe($rc))->toBe([$noVendible->id => 50.0]);
});

it('repartir más de lo pendiente se rechaza diciendo la suma', function () {
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();

    expect(fn () => \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 30, 'ubicacion_id' => $this->gbPrincipal->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 30, 'ubicacion_id' => $this->dropiPrincipal->id],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'repartiendo 60');

    // Y no deja nada a medias: ni recepción ni una sola unidad de stock.
    expect(RecepcionCompra::count())->toBe(0)
        ->and(InventarioMovimiento::count())->toBe(0)
        ->and((float) $linea->fresh()->cantidad_recibida)->toBe(0.0);
});

it('no se puede recibir contra un par que el área marcó de sólo traslado', function () {
    // El outlet y la exhibición existen, pero se alcanzan por traslado. Esta
    // es la puerta por la que entra el destino, así que acá se cierra.
    $par = app(UbicacionDelPar::class);

    expect(fn () => $par->resolver(oficinaDe('10'), almacenDe('04'), esIngreso: true))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(fn () => $par->resolver(oficinaDe('14'), almacenDe('01'), esIngreso: true))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('repartir en tres destinos reparte el kardex en tres', function () {
    $orden = ordenDe50($this->bodegaOrden);
    $linea = $orden->items->first();
    $garantia = $this->par->resolver(oficinaDe('10'), almacenDe('07'));

    $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run([
        'orden_id' => $orden->id,
        'items' => [
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 25, 'ubicacion_id' => $this->gbPrincipal->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 20, 'ubicacion_id' => $this->dropiPrincipal->id],
            ['orden_item_id' => $linea->id, 'cantidad_recibida' => 5, 'ubicacion_id' => $garantia->id],
        ],
    ]);

    expect(kardexDe($rc))->toBe([
        $this->gbPrincipal->id => 25.0,
        $this->dropiPrincipal->id => 20.0,
        $garantia->id => 5.0,
    ]);
});
