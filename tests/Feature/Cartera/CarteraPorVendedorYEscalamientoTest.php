<?php

/**
 * El semáforo de cartera por vendedor, y lo que sube a Gerencia.
 *
 * El contrato lo pide así: «semáforo de cartera por tramos y por vendedor, con
 * escalamiento a Gerencia en 120+». De las tres cosas existía una: los cinco
 * tramos estaban desde hacía meses en `TramoAntiguedad`, pero la antigüedad
 * sólo se podía filtrar por cliente —no por vendedor— y el escalamiento no
 * existía en ninguna parte. Una factura podía cruzar los 120 días sin que
 * nadie se enterara, salvo que alguien se acordara de abrir el reporte.
 *
 * Lo que se protege acá:
 *   · que cada vendedor pueda ver lo suyo;
 *   · que lo que no tiene vendedor salga APARTE y no repartido, porque
 *     esconderlo haría cuadrar el total mintiendo;
 *   · que a Gerencia se le avise UNA vez por factura, no todos los días.
 */

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Cartera\Actions\CalcularAntiguedadCartera;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use App\Modules\Cartera\Models\FacturaVenta;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->cliente = \App\Models\Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900888999',
        'nombre_completo' => 'Cliente moroso', 'es_cliente' => true,
    ]);
    $this->vendedor = User::factory()->create(['name' => 'Carlos Vendedor']);
    $this->otro = User::factory()->create(['name' => 'Marta Vendedora']);
});

/** Una factura con la mora que se quiera. */
function facturaConMora(int $diasMora, float $saldo, ?int $vendedorId = null): FacturaVenta
{
    static $n = 0;
    $n++;

    return FacturaVenta::create([
        'numero' => 'FV-MORA-'.$n,
        'contacto_id' => test()->cliente->id,
        'vendedor_id' => $vendedorId,
        'fecha_emision' => now()->subDays($diasMora + 30)->toDateString(),
        'fecha_vencimiento' => now()->subDays($diasMora)->toDateString(),
        'estado' => 'pendiente', 'tipo' => 'credito',
        'subtotal' => $saldo, 'descuento' => 0, 'impuestos' => 0,
        'total' => $saldo, 'saldo' => $saldo,
    ]);
}

it('la antigüedad se puede pedir de un solo vendedor', function () {
    facturaConMora(10, 100000, $this->vendedor->id);
    facturaConMora(10, 500000, $this->otro->id);

    $suyo = CalcularAntiguedadCartera::run(null, $this->vendedor->id);

    expect($suyo['total'])->toBe(100000.0)
        ->and($suyo['count'])->toBe(1);
});

it('el desglose por vendedor separa lo vencido de lo que está al día', function () {
    facturaConMora(-20, 300000, $this->vendedor->id);   // todavía no vence
    facturaConMora(45, 200000, $this->vendedor->id);    // vencida

    $filas = collect(CalcularAntiguedadCartera::make()->porVendedor())
        ->firstWhere('vendedor_id', $this->vendedor->id);

    expect($filas['total'])->toBe(500000.0)
        ->and($filas['vencido'])->toBe(200000.0);
});

it('lo que no tiene vendedor sale aparte, no repartido', function () {
    facturaConMora(10, 700000, null);
    facturaConMora(10, 100000, $this->vendedor->id);

    $filas = collect(CalcularAntiguedadCartera::make()->porVendedor());
    $huerfanas = $filas->firstWhere('vendedor_id', null);

    expect($huerfanas)->not->toBeNull()
        ->and($huerfanas['vendedor'])->toBe('Sin vendedor asignado')
        ->and($huerfanas['total'])->toBe(700000.0)
        // Y la suma de todas sigue dando el total real.
        ->and($filas->sum('total'))->toBe(800000.0);
});

it('el más vencido va primero: es por donde hay que empezar a llamar', function () {
    facturaConMora(5, 100000, $this->vendedor->id);
    facturaConMora(200, 900000, $this->otro->id);

    $filas = CalcularAntiguedadCartera::make()->porVendedor();

    expect($filas[0]['vendedor_id'])->toBe($this->otro->id);
});

it('escala a Gerencia lo que cruzó los 120 días', function () {
    facturaConMora(130, 500000, $this->vendedor->id);
    facturaConMora(60, 400000, $this->vendedor->id);

    $this->artisan('cartera:escalar-vencidas')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'cartera_escalada')->count())->toBe(1);
});

it('no escala dos veces la misma factura', function () {
    facturaConMora(130, 500000, $this->vendedor->id);

    $this->artisan('cartera:escalar-vencidas')->assertSuccessful();
    $this->artisan('cartera:escalar-vencidas')->assertSuccessful();

    // Avisar todos los días de lo mismo es la forma más rápida de que dejen
    // de leer los avisos.
    expect(NotificacionErp::where('tipo', 'cartera_escalada')->count())->toBe(1);
});

it('deja constancia de cuándo se escaló', function () {
    $f = facturaConMora(130, 500000, $this->vendedor->id);

    $this->artisan('cartera:escalar-vencidas')->assertSuccessful();

    expect($f->fresh()->escalada_gerencia_at)->not->toBeNull();
});

it('una factura ya pagada no escala', function () {
    $f = facturaConMora(200, 500000, $this->vendedor->id);
    $f->forceFill(['estado' => 'pagada', 'saldo' => 0])->saveQuietly();

    $this->artisan('cartera:escalar-vencidas')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'cartera_escalada')->count())->toBe(0);
});

it('el aviso nombra al vendedor, que es a quien hay que preguntarle', function () {
    facturaConMora(130, 500000, $this->vendedor->id);

    $this->artisan('cartera:escalar-vencidas')->assertSuccessful();

    expect(NotificacionErp::where('tipo', 'cartera_escalada')->value('mensaje'))
        ->toContain('Carlos Vendedor');
});

it('el tramo 120+ es el que manda, no una fecha calculada aparte', function () {
    $f = facturaConMora(125, 100000, $this->vendedor->id);

    expect($f->tramo())->toBe(TramoAntiguedad::D120Mas);
});
