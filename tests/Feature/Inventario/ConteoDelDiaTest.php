<?php

/**
 * Contar un pedacito cada día, en vez de no contar nunca.
 *
 * Una toma física de la bodega entera son 436 posiciones: nadie la termina, así
 * que en la práctica el inventario se revisaba cuando ya había un problema — y
 * para entonces la diferencia ya no se podía explicar.
 *
 * `inventario:conteo-del-dia` arma la tanda del día: unas pocas posiciones de
 * una zona, sin cerrar la bodega. Lo que estos casos cuidan es que la vuelta
 * sea COMPLETA y JUSTA: que ninguna zona se quede sin turno, que no se repita
 * el mismo estante dos días seguidos, y que nunca salgan dos conteos abiertos
 * sobre el mismo sitio —eso ajusta dos veces el mismo faltante—.
 *
 * Crea UNA TOMA POR POSICIÓN y no una de la zona entera. Es deliberado:
 * `tomas_fisicas_items` no tiene columna de ubicación, así que una toma que
 * abarcara varios estantes no sabría a cuál devolverle el ajuste.
 */

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use App\Modules\Inventario\Models\ConteoCiclicoZona;
use App\Modules\Inventario\Models\TomaFisica;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->aracely = User::factory()->create();
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));

    // Dos zonas con un rack cada una.
    Reglas::set('logistica.zonas_bodega', json_encode(['A' => ['1'], 'B' => ['2']]));
    // El conteo automático nace apagado: estos casos prueban la operación con
    // el área ya decidida. Que apagado no haga nada tiene su propio caso.
    Reglas::set('inventario.conteo_ciclico_activo', '1');
    Reglas::limpiarRequestCache();

    $this->producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'GB-CIC-'.fake()->unique()->numerify('####'),
        'nombre' => 'Body manga larga', 'activo' => true,
        'desglose_stock' => false, 'precio_proveedor' => 9000,
    ]));
});

/** Una posición del rack dado, con o sin saldo. */
function estante(string $codigo, string $rack, ?Producto $con = null, int $cantidad = 5): InventarioUbicacion
{
    $u = InventarioUbicacion::create([
        'codigo' => $codigo, 'nombre' => $codigo,
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
        'disponible_para_venta' => true, 'pasillo' => $rack,
    ]);

    if ($con) {
        InventarioMovimiento::create([
            'producto_id' => $con->id, 'ubicacion_id' => $u->id,
            'tipo' => 'entrada_compra', 'cantidad' => $cantidad, 'costo_unit' => 9000,
        ]);
    }

    return $u;
}

function tomasDe(InventarioUbicacion $u): int
{
    return TomaFisica::where('ubicacion_id', $u->id)->count();
}

it('manda a contar sólo los estantes que tienen algo', function () {
    $conSaldo = estante('Z-A-01', '1', $this->producto);
    $vacio = estante('Z-A-02', '1');

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])->assertSuccessful();

    // Contar un estante vacío no dice nada y gastaría la tanda del día.
    expect(tomasDe($conSaldo))->toBe(1)
        ->and(tomasDe($vacio))->toBe(0);

    $toma = TomaFisica::where('ubicacion_id', $conSaldo->id)->first();

    expect($toma->tipo)->toBe('ciclico')
        ->and($toma->estado)->toBe(EstadoTomaFisica::Borrador);
});

it('el simulacro no crea nada', function () {
    $estante = estante('Z-A-03', '1', $this->producto);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--dry-run' => true])
        ->assertSuccessful();

    expect(tomasDe($estante))->toBe(0)
        ->and(ConteoCiclicoZona::count())->toBe(0);
});

it('la tanda siguiente no repite el estante de la anterior', function () {
    $primero = estante('Z-A-10', '1', $this->producto);
    $segundo = estante('Z-A-11', '1', $this->producto);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--posiciones' => 1])->assertSuccessful();
    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--posiciones' => 1])->assertSuccessful();

    // Una cada una: sin cursor, el comando contaría el mismo estante todos los
    // días y la bodega no se recorrería nunca.
    expect(tomasDe($primero))->toBe(1)
        ->and(tomasDe($segundo))->toBe(1);
});

it('una zona que nunca se ha contado va antes que la que se contó ayer', function () {
    estante('Z-A-20', '1', $this->producto);
    estante('Z-B-20', '2', $this->producto);

    // La A corre primero y se crea su fila.
    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])->assertSuccessful();

    /*
     * Sin --zona, el turno tiene que pasar a la B. Ordenar sólo las zonas que
     * YA tienen fila dejaba fuera justo a las que nunca se habían contado: la
     * primera que corría salía elegida todos los días y a las otras no les
     * llegaba el turno nunca.
     */
    $this->artisan('inventario:conteo-del-dia')->assertSuccessful();

    expect(ConteoCiclicoZona::where('zona', 'B')->exists())->toBeTrue()
        ->and(TomaFisica::whereIn('ubicacion_id',
            InventarioUbicacion::where('pasillo', '2')->pluck('id'))->count())->toBe(1);
});

it('al terminar la zona vuelve a empezar y suma una vuelta', function () {
    $unico = estante('Z-A-30', '1', $this->producto);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])->assertSuccessful();

    // Se cierra la toma para que el estante vuelva a estar libre. «Ajustada»
    // es el estado de una toma ya cerrada con sus movimientos escritos.
    TomaFisica::where('ubicacion_id', $unico->id)
        ->update(['estado' => EstadoTomaFisica::Ajustada]);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])->assertSuccessful();

    $estado = ConteoCiclicoZona::where('zona', 'A')->first();

    expect($estado->vueltas)->toBe(1)
        ->and($estado->ultima_vuelta_at)->not->toBeNull()
        ->and(tomasDe($unico))->toBe(2);
});

it('no abre un segundo conteo sobre un estante que ya tiene uno', function () {
    $estante = estante('Z-A-40', '1', $this->producto);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])->assertSuccessful();

    // La toma queda abierta; la tanda siguiente tiene que saltársela. Dos
    // conteos sobre el mismo sitio congelan el mismo saldo y, al cerrar, los
    // dos ajustan el mismo faltante.
    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])->assertSuccessful();

    expect(tomasDe($estante))->toBe(1);
});

it('la pantalla tampoco deja abrir dos conteos sobre el mismo sitio', function () {
    $estante = estante('Z-A-50', '1', $this->producto);

    $crear = fn () => $this->actingAs($this->aracely)->post('/app/inventario/conteos', [
        'ubicacion_id' => $estante->id, 'tipo' => 'ciclico',
    ]);

    $crear()->assertSessionHas('success');
    $crear()->assertSessionHasErrors('ubicacion_id');

    expect(tomasDe($estante))->toBe(1);
});

it('una zona que no existe se rechaza diciendo cuáles hay', function () {
    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'Z'])
        ->expectsOutputToContain('A, B')
        ->assertFailed();
});

it('apagado no crea nada, aunque corra todos los días', function () {
    /*
     * El comando queda agendado desde el primer despliegue, pero crear conteos
     * solos es algo que el área tiene que querer: cada toma que nadie trabaja
     * bloquea su estante para cualquier otro conteo. Nace apagado y se
     * enciende desde Reglas.
     */
    Reglas::set('inventario.conteo_ciclico_activo', '0');
    Reglas::limpiarRequestCache();

    $estante = estante('Z-A-60', '1', $this->producto);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A'])
        ->expectsOutputToContain('apagado')
        ->assertSuccessful();

    expect(tomasDe($estante))->toBe(0)
        ->and(ConteoCiclicoZona::count())->toBe(0);

    // Pero a mano sí corre: es como se prueba antes de encenderlo.
    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--forzar' => true])
        ->assertSuccessful();

    expect(tomasDe($estante))->toBe(1);
});

it('un estante que se vacía no hace que la zona vuelva a empezar', function () {
    /*
     * El cursor se buscaba por id dentro de las candidatas, y las candidatas
     * son sólo las posiciones CON SALDO. Un estante se vacía todos los días:
     * en cuanto el del cursor quedaba en cero desaparecía de la lista, la
     * búsqueda fallaba y la zona volvía a empezar desde el principio,
     * mandando a contar otra vez lo recién contado.
     */
    $primero = estante('Z-A-70', '1', $this->producto);
    $segundo = estante('Z-A-71', '1', $this->producto);
    $tercero = estante('Z-A-72', '1', $this->producto);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--posiciones' => 1])->assertSuccessful();
    expect(tomasDe($primero))->toBe(1);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--posiciones' => 1])->assertSuccessful();
    expect(tomasDe($segundo))->toBe(1);

    // El del cursor se vacía: sale de las candidatas.
    InventarioMovimiento::create([
        'producto_id' => $this->producto->id, 'ubicacion_id' => $segundo->id,
        'tipo' => 'salida_venta', 'cantidad' => -5, 'costo_unit' => 9000,
    ]);

    $this->artisan('inventario:conteo-del-dia', ['--zona' => 'A', '--posiciones' => 1])->assertSuccessful();

    // Sigue por el tercero, no vuelve al primero.
    expect(tomasDe($tercero))->toBe(1)
        ->and(tomasDe($primero))->toBe(1);
});
