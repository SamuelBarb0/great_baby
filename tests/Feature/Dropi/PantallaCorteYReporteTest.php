<?php

/**
 * 26-nov-2026: «Cortes Dropi» sin remisiones sueltas, editar ni crear (eso se
 * hace desde «Generar corte» y desde Dropi); clic en un corte abre su pantalla
 * con cada pedido y su vista previa. Y el reporte de guías de «Pedidos Dropi»
 * en tarjetas: primero lo que pide algo de Aracely, con el motivo.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages\ListDropiCortes;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages\ViewDropiCorte;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource\RelationManagers\PedidosRelationManager;
use App\Modules\Dropi\Filament\Widgets\ReporteGuiasWidget;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\Producto;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    Filament::setCurrentPanel('admin');
    $rol = Role::firstOrCreate(['name' => 'Operador Dropi Pantalla', 'guard_name' => 'web']);
    $rol->givePermissionTo([Permisos::permiso('dropi_cortes'), Permisos::permiso('dropi_pedidos')]);
    $this->usuario = User::factory()->create();
    $this->usuario->assignRole($rol);
    $this->actingAs($this->usuario);

    $this->corte = DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 2]);
});

function pcrPedido(DropiCorte $corte, string $orden, EstadoPedidoDropi $estado = EstadoPedidoDropi::Pending, array $items = [['TP', 2]]): DropiPedido
{
    $id = DB::table('dropi_pedidos')->insertGetId([
        'guia' => "SIN-GUIA-{$orden}", 'dropi_orden_id' => $orden, 'estado' => $estado->value, 'corte_id' => $corte->id,
        'cliente_nombre' => "Cliente {$orden}", 'cliente_ciudad' => 'Cali', 'cliente_direccion' => 'Calle 1 # 2-3',
        'monto_esperado_proveedor' => 30000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($items as [$sku, $cant]) {
        DB::table('dropi_pedido_items')->insert([
            'pedido_id' => $id, 'sku_dropi' => $sku, 'producto_nombre' => "Producto {$sku}", 'cantidad' => $cant,
            'precio_proveedor_unit' => 10000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return DropiPedido::findOrFail($id);
}

it('los cortes ya no se crean, editan ni sacan remisiones sueltas; la fila abre la pantalla del corte', function () {
    expect(array_keys(DropiCorteResource::getPages()))->toBe(['index', 'view'])
        ->and(collect(DropiCorteResource::accionesDelCorte())->map->getName()->all())
        ->not->toContain('generar_remisiones')->not->toContain('edit');

    Livewire::test(ListDropiCortes::class)
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('generar_remisiones')->table($this->corte))
        ->assertActionDoesNotExist(TestAction::make('edit')->table($this->corte))
        ->assertSee(DropiCorteResource::getUrl('view', ['record' => $this->corte]), false);
});

it('la pantalla del corte cuenta sus pedidos por etapa y los lista con nuestras referencias y su guía en el reporte', function () {
    $p = Producto::create(['referencia' => '2504-17', 'nombre' => 'Tapete', 'desglose_stock' => false, 'activo' => true]);
    $pendiente = pcrPedido($this->corte, '700');
    DB::table('dropi_pedido_items')->where('pedido_id', $pendiente->id)->update(['producto_id' => $p->id]);
    $empacado = pcrPedido($this->corte, '701', EstadoPedidoDropi::Empacado);
    $cancelado = pcrPedido($this->corte, '702', EstadoPedidoDropi::CanceladoDropi);
    pcrPedido(DropiCorte::create(['fecha' => now()->toDateString(), 'numero' => 1]), '799');
    DropiLoteImpresion::create([
        'corte_id' => $this->corte->id, 'pedidos' => [], 'total' => 3, 'guias_pendientes' => 0, 'estado' => DropiLoteImpresion::LISTO,
        'resultados' => [
            ['pedido_id' => $pendiente->id, 'orden' => '700', 'cliente' => 'Cliente 700', 'tipo' => 'sin_cobertura', 'detalle' => 'Dropi: «no hay cobertura».', 'guia' => null],
            ['pedido_id' => $cancelado->id, 'orden' => '702', 'cliente' => 'Cliente 702', 'tipo' => 'reemplazado', 'detalle' => 'Dropi ya no tiene la orden.', 'guia' => null],
        ],
    ]);

    Livewire::test(ViewDropiCorte::class, ['record' => $this->corte->getRouteKey()])
        ->assertOk()
        ->assertSee('Por empacar')->assertSee('Empacados')->assertSee('Cancelados')
        ->assertActionExists('pedidos_pdf')
        ->assertActionDoesNotExist('generar_remisiones');

    Livewire::test(PedidosRelationManager::class, ['ownerRecord' => $this->corte, 'pageClass' => ViewDropiCorte::class])
        ->assertCanSeeTableRecords([$pendiente, $empacado, $cancelado])
        ->assertCountTableRecords(3)
        ->assertSee('2504-17 ×2')
        ->assertSee('La transportadora no llega a esa dirección')
        ->assertSee('Dropi ya no tiene esta orden');
});

it('clic en un pedido abre su vista previa: cliente, productos, qué pasó con su guía y qué hacer', function () {
    $p = pcrPedido($this->corte, '710', items: [['TP', 3], ['XL', 1]]);
    DropiLoteImpresion::create([
        'corte_id' => $this->corte->id, 'pedidos' => [], 'total' => 1, 'guias_pendientes' => 0, 'estado' => DropiLoteImpresion::LISTO,
        'resultados' => [['pedido_id' => $p->id, 'orden' => '710', 'cliente' => 'Cliente 710', 'tipo' => 'error', 'detalle' => 'Dropi: «La ciudad no existe».', 'guia' => null]],
    ]);

    Livewire::test(PedidosRelationManager::class, ['ownerRecord' => $this->corte, 'pageClass' => ViewDropiCorte::class])
        ->mountAction(TestAction::make('vista_previa')->table($p))
        ->assertMountedActionModalSee(['Cliente 710', 'Calle 1 # 2-3', 'Producto TP', '×3', 'Producto XL', 'Dropi respondió con un error', 'La ciudad no existe', 'Qué hacer']);
});

it('el detalle del reporte va primero con lo que pide acción, luego lo resuelto solo y plegado lo que salió bien', function () {
    $lote = DropiLoteImpresion::create([
        'corte_id' => $this->corte->id, 'pedidos' => [], 'total' => 4, 'guias_pendientes' => 0, 'estado' => DropiLoteImpresion::LISTO,
        'resultados' => [
            ['pedido_id' => 1, 'orden' => '95944823', 'cliente' => 'Yulitza Cuervo', 'tipo' => 'cancelado', 'detalle' => 'En Dropi está «RECHAZADO».', 'guia' => null],
            ['pedido_id' => 2, 'orden' => '98200001', 'cliente' => 'Luz', 'tipo' => 'generada', 'detalle' => null, 'guia' => 'G-1'],
            ['pedido_id' => 3, 'orden' => '98213142', 'cliente' => 'Manuel Burbano', 'tipo' => 'sin_cobertura', 'detalle' => 'Dropi: «no hay cobertura».', 'guia' => null],
            ['pedido_id' => 4, 'orden' => '98200002', 'cliente' => 'Ana', 'tipo' => 'error', 'detalle' => 'Dropi: «Timeout».', 'guia' => null],
        ],
    ]);

    $html = view('dropi.pages.partials.resultados-guias', ['lote' => $lote])->render();

    expect($lote->conAccion())->toBe(2)
        ->and(strpos($html, 'Necesitan tu acción'))->toBeLessThan(strpos($html, 'Se resolvieron solos'))
        ->and(strpos($html, 'Se resolvieron solos'))->toBeLessThan(strpos($html, 'Salieron bien'))
        ->and($html)->toContain('<details')->toContain('Manuel Burbano')->toContain('no hay cobertura')->toContain('Qué hacer');
});

it('«Pedidos Dropi» muestra la tarjeta del reporte de hoy, con su avance y cuántos piden acción', function () {
    expect(ReporteGuiasWidget::canView())->toBeFalse();

    $lote = DropiLoteImpresion::create([
        'corte_id' => $this->corte->id, 'pedidos' => [], 'total' => 10, 'guias_pendientes' => 6, 'estado' => DropiLoteImpresion::GENERANDO_GUIAS,
        'resultados' => [
            ['pedido_id' => 1, 'orden' => '1', 'cliente' => 'A', 'tipo' => 'generada', 'detalle' => null, 'guia' => 'G'],
            ['pedido_id' => 2, 'orden' => '2', 'cliente' => 'B', 'tipo' => 'generada', 'detalle' => null, 'guia' => 'G'],
            ['pedido_id' => 3, 'orden' => '3', 'cliente' => 'C', 'tipo' => 'ya_tenia', 'detalle' => null, 'guia' => 'G'],
            ['pedido_id' => 4, 'orden' => '4', 'cliente' => 'D', 'tipo' => 'error', 'detalle' => 'Dropi: «Timeout».', 'guia' => null],
        ],
    ]);

    expect(ReporteGuiasWidget::canView())->toBeTrue();
    Livewire::test(ReporteGuiasWidget::class)
        ->assertSee('Generando guías en Dropi · 4 de 10')
        ->assertSee('width:40%', false)
        ->assertSee('3 con guía')
        ->assertSee('1 necesitan tu acción')
        ->assertSee('Ver qué pasó')
        ->assertSee('wire:poll.5s', false)
        // El detalle es una acción: el refresco de cada 5 s no lo cierra.
        ->mountAction('verDetalle', ['lote' => $lote->id])
        ->assertMountedActionModalSee(['Necesitan tu acción', 'Timeout', 'Salieron bien']);
});
