<?php

/**
 * La mercancía entra a su estante, no al montón.
 *
 * El procedimiento del área lo pide con todas las letras: «el registro deberá
 * contener también la nomenclatura de la ubicación asignada», y su flujograma
 * pone el ALMACENAMIENTO en racks ANTES del ingreso al sistema. O sea que
 * cuando Contabilidad registra la entrada, la caja ya está en su posición y
 * hay que poder decirlo.
 *
 * Lo que se cuida acá es que ese dato llegue de verdad al kardex, y que no se
 * pueda mandar mercancía a un estante que no es de esa bodega — el valor viene
 * del navegador, y un id de otra sede metido a mano mandaría el contenedor a
 * otra ciudad.
 *
 * Y la cuadrícula de la zona de alistamiento: las 18 casillas del plano de don
 * Jorge, que son el tope de una oleada porque no se pueden armar más pedidos
 * de los que caben en el piso.
 */

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Logistica\Support\ZonaDeAlistamiento;
use App\Support\Reglas;

// ─────────────────────────────────────────────────────────────────────────
// La cuadrícula del piso
// ─────────────────────────────────────────────────────────────────────────

it('arma las 18 casillas del plano, en el orden en que se recorren', function () {
    Reglas::set(ZonaDeAlistamiento::REGLA, json_encode(['columnas' => 6, 'filas' => 3]));
    Reglas::limpiarRequestCache();

    $casillas = ZonaDeAlistamiento::casillas();

    expect($casillas)->toHaveCount(18)
        ->and($casillas[0])->toBe('C1FA')
        ->and($casillas[1])->toBe('C1FB')
        ->and($casillas[2])->toBe('C1FC')
        // La columna 2 empieza después de agotar la 1.
        ->and($casillas[3])->toBe('C2FA')
        ->and(end($casillas))->toBe('C6FC');
});

it('si el área agranda la zona, caben más pedidos sin tocar código', function () {
    Reglas::set(ZonaDeAlistamiento::REGLA, json_encode(['columnas' => 8, 'filas' => 4]));
    Reglas::limpiarRequestCache();

    expect(ZonaDeAlistamiento::cuantasCaben())->toBe(32)
        ->and(ZonaDeAlistamiento::existe('C8FD'))->toBeTrue();
});

it('una cuadrícula imposible cae al plano del área en vez de dejar la bodega sin casillas', function () {
    foreach ([
        ['columnas' => 0, 'filas' => 3],
        ['columnas' => 6, 'filas' => 0],
        ['columnas' => -2, 'filas' => 3],
        // Más filas que letras no se pueden nombrar.
        ['columnas' => 6, 'filas' => 99],
        ['nada' => 'que ver'],
    ] as $basura) {
        Reglas::set(ZonaDeAlistamiento::REGLA, json_encode($basura));
        Reglas::limpiarRequestCache();

        expect(ZonaDeAlistamiento::cuantasCaben())->toBe(18);
    }
});

it('reparte las casillas libres sin pisar las que están en uso', function () {
    Reglas::set(ZonaDeAlistamiento::REGLA, json_encode(['columnas' => 6, 'filas' => 3]));
    Reglas::limpiarRequestCache();

    $libres = ZonaDeAlistamiento::libres(['C1FA', 'c1fc', ' C2FA ']);

    // Se compara sin mayúsculas ni espacios: lo que está ocupado, ocupado está.
    expect($libres)->not->toContain('C1FA')
        ->and($libres)->not->toContain('C1FC')
        ->and($libres)->not->toContain('C2FA')
        ->and($libres[0])->toBe('C1FB')
        ->and($libres)->toHaveCount(15);
});

// ─────────────────────────────────────────────────────────────────────────
// La posición en el ingreso
// ─────────────────────────────────────────────────────────────────────────

/** Resuelve los destinos como lo hace la recepción, sin pasar por HTTP. */
function resolverDestinoDeRecepcion(array $item): array
{
    $c = new \App\Http\Controllers\App\ComprasGestionController();

    $metodo = new ReflectionMethod($c, 'resolverDestinos');
    $metodo->setAccessible(true);

    return $metodo->invoke($c, [$item], null)[0];
}

it('el ingreso queda en la posición exacta cuando quien recibe la dice', function () {
    $oficina = \App\Modules\Inventario\Models\Oficina::create([
        'codigo' => '10', 'nombre' => 'GREAT BABY', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
    ]);
    $almacen = \App\Modules\Inventario\Models\Almacen::create([
        'codigo' => '01', 'nombre' => 'PRINCIPAL', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
        'categoria_equivalente' => CategoriaUbicacion::Venta->value,
        'estado_fisico' => 'BUENO', 'comercial' => true,
    ]);

    $bodega = app(\App\Modules\Inventario\Support\UbicacionDelPar::class)->resolver($oficina, $almacen);

    $estante = InventarioUbicacion::create([
        'codigo' => 'R5-S2-N3-D', 'nombre' => 'Rack 5 · Sección 2 · Nivel 3 · Derecha',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'bodega_id' => $bodega->id, 'pasillo' => '5',
    ]);

    $resuelto = resolverDestinoDeRecepcion([
        'destino_ingreso' => "{$oficina->id}:{$almacen->id}",
        'posicion_ingreso' => $estante->id,
    ]);

    // El movimiento del kardex va a llevar el estante, no la bodega.
    expect($resuelto['ubicacion_id'])->toBe($estante->id);
});

it('sin decir posición, la mercancía entra a la bodega como siempre', function () {
    $oficina = \App\Modules\Inventario\Models\Oficina::create([
        'codigo' => '10', 'nombre' => 'GREAT BABY', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
    ]);
    $almacen = \App\Modules\Inventario\Models\Almacen::create([
        'codigo' => '01', 'nombre' => 'PRINCIPAL', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
        'categoria_equivalente' => CategoriaUbicacion::Venta->value,
        'estado_fisico' => 'BUENO', 'comercial' => true,
    ]);

    $bodega = app(\App\Modules\Inventario\Support\UbicacionDelPar::class)->resolver($oficina, $almacen);

    $resuelto = resolverDestinoDeRecepcion([
        'destino_ingreso' => "{$oficina->id}:{$almacen->id}",
        'posicion_ingreso' => null,
    ]);

    expect($resuelto['ubicacion_id'])->toBe($bodega->id);
});

it('no deja mandar el contenedor a un estante de otra bodega', function () {
    $oficina = \App\Modules\Inventario\Models\Oficina::create([
        'codigo' => '10', 'nombre' => 'GREAT BABY', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
    ]);
    $almacen = \App\Modules\Inventario\Models\Almacen::create([
        'codigo' => '01', 'nombre' => 'PRINCIPAL', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
        'categoria_equivalente' => CategoriaUbicacion::Venta->value,
        'estado_fisico' => 'BUENO', 'comercial' => true,
    ]);

    app(\App\Modules\Inventario\Support\UbicacionDelPar::class)->resolver($oficina, $almacen);

    // Un estante de OTRA sede. El id llega del navegador: metido a mano,
    // mandaría la mercancía a otra ciudad.
    $otraSede = InventarioUbicacion::create([
        'codigo' => 'BOG', 'nombre' => 'Bodega Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $ajeno = InventarioUbicacion::create([
        'codigo' => 'BOG-R1', 'nombre' => 'Rack 1 de Bogotá',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'bodega_id' => $otraSede->id, 'pasillo' => '1',
    ]);

    expect(fn () => resolverDestinoDeRecepcion([
        'destino_ingreso' => "{$oficina->id}:{$almacen->id}",
        'posicion_ingreso' => $ajeno->id,
    ]))->toThrow(InvalidArgumentException::class, 'no pertenece a la bodega del destino');
});

it('no deja recibir en una posición apagada', function () {
    $oficina = \App\Modules\Inventario\Models\Oficina::create([
        'codigo' => '10', 'nombre' => 'GREAT BABY', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
    ]);
    $almacen = \App\Modules\Inventario\Models\Almacen::create([
        'codigo' => '01', 'nombre' => 'PRINCIPAL', 'orden' => 1,
        'activa' => true, 'permite_ingreso_directo' => true,
        'categoria_equivalente' => CategoriaUbicacion::Venta->value,
        'estado_fisico' => 'BUENO', 'comercial' => true,
    ]);

    $bodega = app(\App\Modules\Inventario\Support\UbicacionDelPar::class)->resolver($oficina, $almacen);

    $apagada = InventarioUbicacion::create([
        'codigo' => 'R9-S1-N1-D', 'nombre' => 'Rack 9 fuera de servicio',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => false,
        'bodega_id' => $bodega->id, 'pasillo' => '9',
    ]);

    // Una posición apagada está fuera de servicio: dejar mercancía ahí es
    // dejarla donde nadie la busca.
    expect(fn () => resolverDestinoDeRecepcion([
        'destino_ingreso' => "{$oficina->id}:{$almacen->id}",
        'posicion_ingreso' => $apagada->id,
    ]))->toThrow(InvalidArgumentException::class);
});
