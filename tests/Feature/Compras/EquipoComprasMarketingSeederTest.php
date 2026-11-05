<?php

/**
 * Las cuentas de Compras y Marketing se crean con un seeder que también corre en
 * producción, así que correrlo de nuevo no puede cambiar contraseñas ni pisar los
 * permisos que alguien ya ajustó en /app/roles.
 */

use App\Auth\Permisos;
use App\Models\User;
use Database\Seeders\EquipoComprasMarketingSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
});

it('crea los roles con sus permisos y las tres cuentas', function () {
    $this->seed(EquipoComprasMarketingSeeder::class);

    $compras = Role::findByName('Compras', 'web');
    $marketing = Role::findByName('Marketing', 'web');
    expect($compras->hasPermissionTo(Permisos::permiso('compras_oc')))->toBeTrue()
        ->and($compras->hasPermissionTo(Permisos::permiso('marketing')))->toBeFalse()
        ->and($marketing->hasPermissionTo(Permisos::permiso('marketing_parrilla')))->toBeTrue()
        ->and($marketing->hasPermissionTo(Permisos::permiso('compras_oc')))->toBeFalse();

    foreach (EquipoComprasMarketingSeeder::USUARIOS as [$correo, $nombre, $rol]) {
        $u = User::where('email', $correo)->firstOrFail();
        expect($u->name)->toBe($nombre)
            ->and($u->hasRole($rol))->toBeTrue()
            ->and($u->email_verified_at)->not->toBeNull();
    }
});

it('correrlo otra vez no cambia contraseñas ni duplica cuentas', function () {
    $this->seed(EquipoComprasMarketingSeeder::class);
    $antes = User::whereIn('email', array_column(EquipoComprasMarketingSeeder::USUARIOS, 0))->pluck('password', 'email');

    $this->seed(EquipoComprasMarketingSeeder::class);

    $despues = User::whereIn('email', array_column(EquipoComprasMarketingSeeder::USUARIOS, 0))->pluck('password', 'email');
    expect($despues->all())->toBe($antes->all())
        ->and(User::where('email', 'alejandro@greatbaby.com')->count())->toBe(1);
});

it('a una cuenta que ya existía sólo le da el rol', function () {
    $liz = User::factory()->create(['email' => 'liz@greatbaby.com', 'password' => Hash::make('la-de-ella')]);

    $this->seed(EquipoComprasMarketingSeeder::class);

    $liz->refresh();
    expect(Hash::check('la-de-ella', $liz->password))->toBeTrue()
        ->and($liz->hasRole('Compras'))->toBeTrue();
});

it('no pisa los permisos que ya se ajustaron en Roles', function () {
    $marketing = Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web']);
    $marketing->syncPermissions([Permisos::permiso('marketing')]);

    $this->seed(EquipoComprasMarketingSeeder::class);

    expect($marketing->fresh()->permissions->pluck('name')->all())->toBe([Permisos::permiso('marketing')]);
});
