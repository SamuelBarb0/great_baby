<?php

/**
 * La bodega clasifica con dos ejes y hay combinaciones que no se pueden recibir.
 *
 * Don Jorge entregó el procedimiento escrito el 17-nov-2026 y ahí está la
 * regla que manda: al descargar un contenedor la mercancía sólo puede entrar a
 * la oficina 10 (Great Baby) u 11 (Dropi), y al almacén 01 (Principal) o 07
 * (Garantía de proveedor). Marketing, la Sra. Tania, Exhibición, las averías
 * leves, el outlet, los saldos, las bajas y los repuestos existen, pero sólo
 * se alcanzan por traslado, cuando la mercancía ya ingresó al sistema.
 *
 * Esa regla vive en una fila y no en un `if`, porque el área avisó que la
 * tabla va a cambiar. Estas pruebas la amarran igual: si alguien edita el
 * catálogo desde la pantalla, el comportamiento tiene que seguir la fila.
 *
 * Lo que se verifica acá:
 *   · el catálogo queda sembrado con los códigos y nombres del área;
 *   · las averías leves, el outlet y los saldos SE VENDEN —el área los
 *     comercializa con descuento— aunque su estado físico sea desperfecto;
 *   · resolver el par (oficina, almacén) devuelve siempre la misma bodega y no
 *     crea una nueva en cada recepción;
 *   · recibir contra un par que no admite ingreso directo se rechaza, y el
 *     mensaje dice por qué en palabras que la bodega entiende;
 *   · la bodega creada hereda del catálogo si es vendible o no.
 */

use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Inventario\Support\UbicacionDelPar;
use Database\Seeders\CatalogoOficinasAlmacenesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);
    $this->par = app(UbicacionDelPar::class);
});

function oficina(string $codigo): Oficina
{
    return Oficina::where('codigo', $codigo)->firstOrFail();
}

function almacen(string $codigo): Almacen
{
    return Almacen::where('codigo', $codigo)->firstOrFail();
}

it('siembra las cinco oficinas y los siete almacenes del área', function () {
    expect(Oficina::pluck('nombre', 'codigo')->all())->toBe([
        '10' => 'GREAT BABY',
        '11' => 'DROPI',
        '12' => 'MARKETING',
        '13' => 'SRA. TANIA',
        '14' => 'EXHIBICIÓN',
    ]);

    expect(Almacen::count())->toBe(7)
        ->and(almacen('01')->nombre)->toBe('PRINCIPAL')
        ->and(almacen('07')->nombre)->toBe('GARANTÍA DE PROVEEDOR');
});

it('sólo admite ingreso directo donde el área lo permitió', function () {
    expect(Oficina::deIngreso()->pluck('codigo')->all())->toBe(['10', '11']);
    expect(Almacen::deIngreso()->pluck('codigo')->all())->toBe(['01', '07']);
});

it('las averías leves, el outlet y los saldos se venden aunque no estén nuevos', function () {
    // Es la diferencia que más cuesta: «con desperfecto» no significa «no se
    // vende». El área las comercializa con descuento y marcarlas como no
    // vendibles las sacaría del inventario disponible.
    expect(almacen('02')->comercial)->toBeTrue()
        ->and(almacen('02')->estado_fisico)->toBe('DESPERFECTO')
        ->and(almacen('04')->comercial)->toBeTrue()
        ->and(almacen('06')->comercial)->toBeTrue();

    // Y las que de verdad no se venden, no se venden.
    expect(almacen('03')->comercial)->toBeFalse()
        ->and(almacen('05')->comercial)->toBeFalse()
        ->and(almacen('07')->comercial)->toBeFalse();
});

it('resolver el mismo par dos veces devuelve la misma bodega', function () {
    // El fallo real que esto ataja: `oficina_id` y `almacen_id` no estaban en
    // el fillable de la ubicación, así que `create()` los descartaba en
    // silencio, la búsqueda nunca encontraba lo recién creado y cada recepción
    // dejaba una bodega nueva —10-01, 10-01-2, 10-01-3— partiendo el stock.
    $primera = $this->par->resolver(oficina('10'), almacen('01'), esIngreso: true);
    $segunda = $this->par->resolver(oficina('10'), almacen('01'), esIngreso: true);

    expect($segunda->id)->toBe($primera->id)
        ->and($primera->codigo)->toBe('10-01')
        ->and($primera->oficina_id)->toBe(oficina('10')->id)
        ->and($primera->almacen_id)->toBe(almacen('01')->id)
        ->and(InventarioUbicacion::where('codigo', 'like', '10-01%')->count())->toBe(1);
});

it('separa la mercancía de Great Baby de la de Dropi', function () {
    $gb = $this->par->resolver(oficina('10'), almacen('01'), esIngreso: true);
    $dropi = $this->par->resolver(oficina('11'), almacen('01'), esIngreso: true);

    expect($dropi->id)->not->toBe($gb->id)
        ->and($gb->codigo)->toBe('10-01')
        ->and($dropi->codigo)->toBe('11-01');
});

it('no deja recibir en un almacén que sólo admite traslado', function () {
    $motivo = $this->par->motivoParaNoRecibir(oficina('10'), almacen('04'));

    expect($motivo)->toContain('no se ingresa directo')
        ->and($motivo)->toContain('OUTLET');

    $this->par->resolver(oficina('10'), almacen('04'), esIngreso: true);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('no deja recibir en una oficina que sólo recibe traslados', function () {
    $motivo = $this->par->motivoParaNoRecibir(oficina('14'), almacen('01'));

    expect($motivo)->toContain('EXHIBICIÓN')
        ->and($motivo)->toContain('traslado');
});

it('deja mover a cualquier par cuando no es un ingreso', function () {
    // El outlet no se recibe, pero sí se alcanza por traslado: resolver sin
    // `esIngreso` tiene que darle su bodega sin pelear.
    $outlet = $this->par->resolver(oficina('10'), almacen('04'));

    expect($outlet->codigo)->toBe('10-04')
        ->and($outlet->disponible_para_venta)->toBeTrue();
});

it('la bodega nueva hereda del catálogo si se puede vender o no', function () {
    $garantia = $this->par->resolver(oficina('10'), almacen('07'), esIngreso: true);
    $bajas = $this->par->resolver(oficina('10'), almacen('03'));

    expect($garantia->disponible_para_venta)->toBeFalse()
        ->and($bajas->disponible_para_venta)->toBeFalse();
});

it('Exhibición es la que surte lo que falta en picking', function () {
    // El alistador no puede cerrar con faltante sin antes mirar Exhibición.
    // Se busca por la marca y no por el código 14, para que mover el catálogo
    // no obligue a tocar código.
    expect(Oficina::respaldoDeSurtido()?->codigo)->toBe('14');
});

it('correr el seeder otra vez no duplica ni pisa lo que el área editó', function () {
    $almacen = almacen('02');
    $almacen->update(['nombre' => 'AVERÍAS LEVES (revisado)', 'permite_ingreso_directo' => true]);

    $this->seed(CatalogoOficinasAlmacenesSeeder::class);

    expect(Almacen::count())->toBe(7)
        ->and(Oficina::count())->toBe(5)
        ->and(almacen('02')->nombre)->toBe('AVERÍAS LEVES (revisado)')
        ->and(almacen('02')->permite_ingreso_directo)->toBeTrue();
});
