<?php

/**
 * Quién se acuerda de contar la bodega.
 *
 * El módulo de tomas físicas estaba completo desde hacía meses: cuenta,
 * compara contra el sistema, exige cuatro ojos para cerrar y hace el asiento
 * del sobrante o el faltante. Lo único que no tenía era CADENCIA.
 *
 * El contrato pide inventario semestral, y eso dependía de que alguien se
 * acordara: nadie llevaba la cuenta de cuándo fue la última toma de cada
 * bodega, así que la forma de enterarse de que hacía un año que no se contaba
 * era que no cuadrara la plata.
 *
 * Lo que se protege acá:
 *   · sólo se avisa de bodegas que TIENEN mercancía — la tabla trae 55
 *     ubicaciones y la mayoría llegaron del sandbox compartido de SIIGO;
 *   · sólo cuenta la última toma CERRADA, no una en borrador;
 *   · y el plazo sale de la regla, no de un número escrito en el código.
 */

use App\Models\NotificacionErp;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use App\Modules\Inventario\Models\TomaFisica;
use App\Support\Reglas;

beforeEach(function () {
    Reglas::limpiarRequestCache();

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'CNT-1', 'nombre' => 'Bodega con mercancía',
        'categoria' => 'venta', 'activa' => true,
    ]);
    $this->vacia = InventarioUbicacion::create([
        'codigo' => 'CNT-2', 'nombre' => 'Bodega vacía',
        'categoria' => 'venta', 'activa' => true,
    ]);

    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'CNT-001', 'nombre' => 'Algo que contar', 'desglose_stock' => false,
    ]));

    InventarioMovimiento::create([
        'producto_id' => $producto->id, 'ubicacion_id' => $this->bodega->id,
        'tipo' => 'ajuste_toma_fisica', 'cantidad' => 5,
        'motivo' => 'Saldo de la prueba',
    ]);
});

/** Una toma cerrada de esa bodega, con la antigüedad que se quiera. */
function tomaCerradaHace(int $meses, int $ubicacionId): TomaFisica
{
    $t = TomaFisica::create([
        'numero' => 'TF-'.uniqid(),
        'ubicacion_id' => $ubicacionId,
        'estado' => EstadoTomaFisica::Ajustada,
        'fecha_conteo' => now()->subMonths($meses)->toDateString(),
    ]);
    // `updated_at` es lo que mira el comando, y los timestamps lo pisan.
    TomaFisica::whereKey($t->id)->update(['updated_at' => now()->subMonths($meses)]);

    return $t->fresh();
}

it('avisa de una bodega que nunca se ha contado', function () {
    $this->artisan('inventario:recordar-conteo')
        ->expectsOutputToContain('Bodega con mercancía')
        ->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'conteo_fisico_vencido')->count())->toBe(1);
});

it('no avisa de una bodega sin un solo movimiento', function () {
    $this->artisan('inventario:recordar-conteo')->assertSuccessful();

    $avisos = NotificacionErp::where('tipo', 'conteo_fisico_vencido')->pluck('titulo');

    expect($avisos->filter(fn ($t) => str_contains($t, 'Bodega vacía')))->toBeEmpty();
});

it('no avisa si la toma es reciente', function () {
    tomaCerradaHace(1, $this->bodega->id);

    $this->artisan('inventario:recordar-conteo')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'conteo_fisico_vencido')->count())->toBe(0);
});

it('vuelve a avisar cuando la última toma se pasó del plazo', function () {
    tomaCerradaHace(8, $this->bodega->id);

    $this->artisan('inventario:recordar-conteo')
        ->expectsOutputToContain('8 meses')
        ->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'conteo_fisico_vencido')->count())->toBe(1);
});

it('una toma en borrador no cuenta como contada', function () {
    TomaFisica::create([
        'numero' => 'TF-BORRADOR',
        'ubicacion_id' => $this->bodega->id,
        'estado' => EstadoTomaFisica::Borrador,
        'fecha_conteo' => now()->toDateString(),
    ]);

    $this->artisan('inventario:recordar-conteo')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'conteo_fisico_vencido')->count())->toBe(1);
});

it('el plazo sale de la regla', function () {
    tomaCerradaHace(8, $this->bodega->id);
    Reglas::set('inventario.meses_entre_conteos', 12);
    Reglas::limpiarRequestCache();

    $this->artisan('inventario:recordar-conteo')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'conteo_fisico_vencido')->count())->toBe(0);
});

it('con --dry-run dice qué falta pero no avisa a nadie', function () {
    $this->artisan('inventario:recordar-conteo', ['--dry-run' => true])
        ->expectsOutputToContain('Bodega con mercancía')
        ->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'conteo_fisico_vencido')->count())->toBe(0);
});
