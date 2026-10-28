<?php

/**
 * El sistema no te muestra algo para después negártelo.
 *
 * Es el mismo defecto que ya habíamos corregido cuatro veces en logística, y
 * volvió a aparecer en contabilidad por tres caminos distintos: el buscador
 * global, el botón de aprobar un asiento y el enlace al PDF de una orden de
 * compra.
 *
 * Duele más de lo que parece porque el ERP no tiene pantalla de 403: cada uno
 * de estos casos saca al usuario del sistema y lo deja en la página de error
 * pelada de Laravel, sin menú y sin manera de volver. Para quien lo vive no
 * es «no tengo permiso», es «se rompió».
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole(rolContable('Contador'));

    $this->vendedor = User::factory()->create(['name' => 'Vendedor']);
    $this->vendedor->assignRole(rolContable('Vendedor'));

    $this->alistador = User::factory()->create(['name' => 'Camila']);
    $this->alistador->assignRole(rolContable('Alistador'));

    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(rolContable('Aracely'));
});

function rolContable(string $nombre): Role
{
    $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

    $rol->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array($nombre, $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    return $rol;
}

function unaVariante(): ProductoVariante
{
    $p = Producto::create([
        'referencia' => 'AND-QA-1', 'nombre' => 'Body de prueba',
        'activo' => true, 'desglose_stock' => true,
    ]);

    return ProductoVariante::create([
        'producto_id' => $p->id, 'codigo_barras' => 'QA-COD-12345',
        'color_nombre' => 'azul', 'talla' => '12M',
    ]);
}

// ── El buscador global ───────────────────────────────────────────────────────

it('no le ofrece el kardex a quien el kardex le cierra la puerta', function () {
    /*
     * El Contador escribe un código para ver de dónde salió un movimiento, el
     * buscador le devuelve el producto, hace clic y recibe 403. El kardex es
     * de Aracely, alistadores y admins de bodega.
     */
    $v = unaVariante();

    $this->actingAs($this->silvia)->get('/app/inventario/kardex?codigo='.$v->codigo_barras)
        ->assertForbidden();

    $resultados = $this->actingAs($this->silvia)->getJson('/app/buscar?q=QA-COD')->json();

    expect(json_encode($resultados))->not->toContain('kardex');
});

it('sí se lo ofrece a quien sí puede abrirlo', function () {
    $v = unaVariante();

    $this->actingAs($this->alistador)->get('/app/inventario/kardex?codigo='.$v->codigo_barras)
        ->assertOk();

    expect(json_encode($this->actingAs($this->alistador)->getJson('/app/buscar?q=QA-COD')->json()))
        ->toContain('kardex');
});

it('no le ofrece facturas al Vendedor, que no puede abrirlas', function () {
    /*
     * `facturas` es una casilla de la pantalla de roles que la MATRIZ le da
     * al Vendedor, pero `/app/facturas/{id}` pide `contabilidad`. El buscador
     * miraba la primera y la pantalla exige la segunda.
     */
    expect(Permisos::puede($this->vendedor, 'facturas'))->toBeTrue()
        ->and(Permisos::puede($this->vendedor, 'contabilidad'))->toBeFalse();

    $resultados = $this->actingAs($this->vendedor)->getJson('/app/buscar?q=FV')->json();

    expect(json_encode($resultados))->not->toContain('/app/facturas/');
});

it('no le ofrece accesos rápidos a quien no puede usarlos', function () {
    /*
     * Salían los seis para todo el mundo: al Colaborador la estación de
     * empaque, al Vendedor la nueva orden de compra.
     */
    $resultados = $this->actingAs($this->vendedor)->getJson('/app/buscar?q=empaque')->json();

    expect(json_encode($resultados))->not->toContain('estacion-empaque');

    // Y al alistador sí.
    expect(json_encode($this->actingAs($this->alistador)->getJson('/app/buscar?q=empaque')->json()))
        ->toContain('estacion-empaque');
});

it('el buscador sigue sirviendo para quien sí tiene permiso', function () {
    // No vaya a ser que lo hayamos cerrado de más.
    $v = unaVariante();

    $resultados = $this->actingAs($this->aracely)->getJson('/app/buscar?q=QA-COD')->json();

    expect(json_encode($resultados))->toContain($v->codigo_barras);
});
