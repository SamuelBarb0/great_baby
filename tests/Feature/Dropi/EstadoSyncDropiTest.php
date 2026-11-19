<?php

/**
 * El botón de «Pedidos Dropi» muestra si el sync automático está al día
 * (última vuelta buena / último fallo) y deja actualizar a mano.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages\ListDropiPedidos;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('dropi.web', ['email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'bff_url' => 'https://bff.test', 'timeout' => 5, 'sync_activo' => true]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
});

function conectarDropiVacio(): void
{
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => 'tok', 'expira_at' => now()->addHour()]);
    Http::fake(fn () => Http::response(['is_succesfull' => true, 'data' => ['count' => null, 'objects' => []]]));
}

it('una vuelta buena guarda la hora y borra el fallo anterior', function () {
    Cache::forever(SincronizarDropiWeb::CACHE_ERROR, ['at' => now()->subMinute()->toIso8601String(), 'msg' => 'viejo']);
    $this->travelTo(now()->setTime(10, 42, 5));
    conectarDropiVacio();
    app(SincronizarDropiWeb::class)->handle('recientes');

    $estado = SincronizarDropiWeb::estado();
    expect($estado['ultima']->format('H:i:s'))->toBe('10:42:05')
        ->and($estado['error'])->toBeNull()
        ->and($estado['activo'])->toBeTrue();
});

it('sin sesión con Dropi guarda el fallo, no la hora, y sigue lanzando', function () {
    expect(fn () => app(SincronizarDropiWeb::class)->handle('recientes'))
        ->toThrow(DropiSesionNoDisponible::class);

    $estado = SincronizarDropiWeb::estado();
    expect($estado['ultima'])->toBeNull()
        ->and($estado['error']['msg'])->not->toBe('');
});

it('el botón dice si está al día, atrasado o apagado', function () {
    expect(ListDropiPedidos::estadoSync()['label'])->toBe('Sin sincronizar todavía');

    Cache::forever(SincronizarDropiWeb::CACHE_ULTIMA, now()->subSeconds(12)->toIso8601String());
    expect(ListDropiPedidos::estadoSync())
        ->label->toBe('Sincronizado hace 12 segundos')
        ->color->toBe('gray');

    Cache::forever(SincronizarDropiWeb::CACHE_ULTIMA, now()->setTime(8, 5)->toIso8601String());
    Cache::forever(SincronizarDropiWeb::CACHE_ERROR, ['at' => now()->toIso8601String(), 'msg' => 'Token rechazado']);
    $this->travelTo(now()->setTime(9, 0));
    expect(ListDropiPedidos::estadoSync())
        ->label->toBe('Sin sincronizar desde 08:05')
        ->color->toBe('danger')
        ->tooltip->toContain('Token rechazado');

    config()->set('dropi.web.sync_activo', false);
    expect(ListDropiPedidos::estadoSync()['label'])->toBe('Sincronización automática apagada');
});

function entrarAlPanel(): void
{
    test()->seed(\Database\Seeders\PermisosSeeder::class);
    $u = User::factory()->create();
    $u->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    Filament::setCurrentPanel('admin');
    test()->actingAs($u);
}

it('«Actualizar ahora» sin conexión con Dropi avisa el error', function () {
    entrarAlPanel();

    Livewire::test(ListDropiPedidos::class)
        ->callAction('sincronizar')
        ->assertNotified('Sin conexión con Dropi');
});

it('«Actualizar ahora» corre el sync, avisa y deja el botón al día', function () {
    entrarAlPanel();
    conectarDropiVacio();

    Livewire::test(ListDropiPedidos::class)
        ->callAction('sincronizar')
        ->assertNotified('Pedidos al día')
        ->assertSee('Sincronizado hace');
});
