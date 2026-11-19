<?php

/**
 * Los listados contables entregan sus controles de paginación.
 *
 * Diez pantallas paginaban en el backend y no pintaban los controles: el Libro
 * contable decía «3.482 filas · mostrando 100 de esta página» y no había botón
 * de siguiente. Las otras 3.382 sólo se alcanzaban escribiendo `?page=2` en la
 * barra del navegador. Con 25 asientos manuales al mes, en enero ya no se
 * llegaba a los de diciembre.
 *
 * Lo que se fija acá es el contrato: el controlador manda un paginador
 * COMPLETO —con `links`, `from`, `to` y `total`— y no sólo `data`. Si alguien
 * cambia un `paginate()` por un `limit()->get()`, esto se pone rojo.
 */

use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->contador = User::factory()->create();
    $this->contador->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));
});

it('el libro contable entrega los controles cuando hay mas de una pagina', function () {
    // 150 movimientos contra una pagina de 100: tiene que haber segunda.
    $base = [
        'cuenta_puc' => cta('cta_cxc_default'), 'haber' => 0, 'debe' => 1,
        'descripcion' => 'fila', 'fecha' => now()->toDateString(),
        'linea_negocio' => 'great_baby', 'destino_fiscal' => 'siigo',
        'origen_type' => \App\Modules\Cartera\Models\FacturaVenta::class, 'origen_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('movimientos_contables')->insert(array_fill(0, 150, $base));

    $props = $this->actingAs($this->contador)
        ->get('/app/cartera/movimientos')
        ->viewData('page')['props'];

    $pag = $props['movimientos'];

    expect($pag['total'])->toBe(150)
        ->and($pag['data'])->toHaveCount(100)
        // Lo que faltaba: sin esto la pantalla no puede pintar nada.
        ->and($pag)->toHaveKeys(['links', 'from', 'to'])
        ->and(collect($pag['links'])->pluck('url')->filter())->not->toBeEmpty();
});

it('la segunda pagina trae las filas que faltaban', function () {
    $base = [
        'cuenta_puc' => cta('cta_cxc_default'), 'haber' => 0, 'debe' => 1,
        'descripcion' => 'fila', 'fecha' => now()->toDateString(),
        'linea_negocio' => 'great_baby', 'destino_fiscal' => 'siigo',
        'origen_type' => \App\Modules\Cartera\Models\FacturaVenta::class, 'origen_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('movimientos_contables')->insert(array_fill(0, 150, $base));

    $pag = $this->actingAs($this->contador)
        ->get('/app/cartera/movimientos?page=2')
        ->viewData('page')['props']['movimientos'];

    expect($pag['data'])->toHaveCount(50)
        ->and($pag['from'])->toBe(101);
});

it('los demas listados tambien entregan paginador completo', function () {
    foreach ([
        '/app/gastos' => 'gastos',
        '/app/cartera/notas-credito' => 'notas',
        '/app/cartera/notas-debito' => 'notas',
        '/app/cartera/pagos-proveedor' => 'pagos',
        '/app/contabilidad/asientos-manuales' => 'asientos',
    ] as $ruta => $prop) {
        $props = $this->actingAs($this->contador)->get($ruta)->viewData('page')['props'];

        expect($props[$prop] ?? null)->toHaveKeys(['data', 'links', 'total'], "falla en {$ruta}");
    }
});
