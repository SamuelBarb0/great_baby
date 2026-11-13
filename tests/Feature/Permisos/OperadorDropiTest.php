<?php

/**
 * El rol «Operador Dropi» vive en la pantalla de Roles, no en el código.
 *
 * Estas pruebas sujetan las cuatro cosas que se rompían al abrirle
 * Contabilidad y Compras: que aterrice en su panel, que Conexión Dropi deje de
 * depender de `esAracely()`, que la auditoría que se abre desde Reportes lo
 * acepte, y que aprobar gastos sea una casilla propia —no un efecto lateral de
 * ver contabilidad—.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Contabilidad\Actions\Gastos;
use App\Modules\Dropi\Filament\Pages\CambiosFinancierosDropi;
use App\Modules\Dropi\Filament\Pages\ConexionDropi;
use App\Modules\Dropi\Filament\Pages\ImportarPedidos;
use App\Modules\Dropi\Filament\Pages\SiigoDropi;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $this->rol = Role::firstOrCreate(['name' => 'Operador Dropi', 'guard_name' => 'web']);
    $this->rol->syncPermissions([
        'ver.dropi_dashboard', 'ver.dropi_pedidos', 'ver.dropi_cortes', 'ver.dropi_wallet',
        'ver.dropi_devoluciones', 'ver.dropi_discrepancias', 'ver.dropi_reportes',
        'ver.contabilidad', 'ver.libro_diario', 'ver.reportes_contables',
        'ver.cuentas_por_pagar', 'ver.programacion_pagos', 'ver.pagos',
        'ver.compras_oc', 'ver.compras_recepcion', 'ver.compras_importacion', 'ver.compras_reportes',
        'ver.contabilidad_aprobar_gastos', 'ver.dropi_conexion', 'editar.dropi_conexion',
        'ver.dropi_siigo', 'editar.dropi_siigo',
        'ver.dropi_importar_pedidos', 'editar.dropi_importar_pedidos',
    ]);

    $this->operador = User::factory()->create();
    $this->operador->assignRole($this->rol);
});

it('aterriza en el panel de Dropi aunque tenga casillas de compras', function () {
    expect($this->operador->rutaInicial())->toBe('/admin');
});

it('entra a Conexión Dropi y puede cambiarla', function () {
    $this->actingAs($this->operador);

    expect(ConexionDropi::canAccess())->toBeTrue()
        ->and(Permisos::puedeAccion($this->operador, 'dropi_conexion', 'editar'))->toBeTrue();
});

it('completa Configuración: SIIGO de Dropi e Importar pedidos', function () {
    $this->actingAs($this->operador);

    expect(SiigoDropi::canAccess())->toBeTrue()
        ->and(ImportarPedidos::canAccess())->toBeTrue()
        ->and(Permisos::puedeAccion($this->operador, 'dropi_siigo', 'editar'))->toBeTrue()
        ->and(Permisos::puedeAccion($this->operador, 'dropi_importar_pedidos', 'editar'))->toBeTrue();
});

it('un rol con solo ver no puede guardar la resolución de Dropi', function () {
    $this->actingAs($this->operador);
    expect(Permisos::puedeAccion($this->operador, 'dropi_siigo', 'editar'))->toBeTrue();

    $soloMira = User::factory()->create();
    $soloMira->givePermissionTo('ver.dropi_siigo');

    expect(Permisos::puedeAccion($soloMira, 'dropi_siigo', 'editar'))->toBeFalse();
});

it('ve la auditoría financiera porque llega desde Reportes Dropi', function () {
    $this->actingAs($this->operador);

    expect(CambiosFinancierosDropi::canAccess())->toBeTrue();
});

it('aprueba gastos con su casilla, sin abrirle la firma al contador', function () {
    expect(Gastos::puedeAprobar($this->operador))->toBeTrue();

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->givePermissionTo('ver.contabilidad');

    $quien = User::factory()->create();
    $quien->assignRole($contador);

    expect(Gastos::puedeAprobar($quien))->toBeFalse();
});
