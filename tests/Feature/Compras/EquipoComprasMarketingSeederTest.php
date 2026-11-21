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

    // La clave se memoiza en una propiedad estática, que sobrevive de una
    // prueba a la siguiente dentro del mismo proceso. Sin esto, la prueba que
    // configura `CLAVE_INICIAL_USUARIOS` recibiría la clave aleatoria que
    // inventó la prueba anterior y quedaría verde comprobando otra cosa.
    EquipoComprasMarketingSeeder::olvidarClave();
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
            ->and($u->email_verified_at)->not->toBeNull()
            // La clave ya no está escrita en el código (repo público): el
            // seeder la toma del `.env` o la inventa, y `claveInicial()`
            // devuelve la misma de esta corrida.
            ->and(Hash::check(EquipoComprasMarketingSeeder::claveInicial(), $u->password))->toBeTrue();
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

it('respeta la contraseña que se configuró en el entorno', function () {
    /*
     * Ésta es la prueba que faltaba, y es la que justifica todo el cambio.
     *
     * La aserción de arriba compara el hash contra `claveInicial()`, o sea
     * contra la misma función que lo generó: caza un seeder que hashee otra
     * cosa, pero NO caza que la rama del entorno deje de dispararse. Si esa
     * rama se rompiera, los dos lados cambiarían juntos y la prueba seguiría
     * verde mientras producción crea cuentas con claves aleatorias que nadie
     * anotó.
     *
     * Acá se fija un valor conocido y se comprueba contra ESE literal.
     *
     * Se lee con `config()` y no con `env()` a propósito: `config:cache` —que
     * es parte normal de un despliegue— deja `env()` en NULL para siempre.
     */
    config(['seguridad.clave_inicial_usuarios' => 'la-que-puso-el-que-despliega']);
    EquipoComprasMarketingSeeder::olvidarClave();

    $this->seed(EquipoComprasMarketingSeeder::class);

    $u = User::where('email', EquipoComprasMarketingSeeder::USUARIOS[0][0])->firstOrFail();

    expect(Hash::check('la-que-puso-el-que-despliega', $u->password))->toBeTrue()
        ->and(EquipoComprasMarketingSeeder::claveFueGenerada())->toBeFalse();
});

it('sin nada configurado inventa una clave y avisa que la inventó', function () {
    config(['seguridad.clave_inicial_usuarios' => '']);
    EquipoComprasMarketingSeeder::olvidarClave();

    $this->seed(EquipoComprasMarketingSeeder::class);

    $clave = EquipoComprasMarketingSeeder::claveInicial();
    $u = User::where('email', EquipoComprasMarketingSeeder::USUARIOS[0][0])->firstOrFail();

    expect(EquipoComprasMarketingSeeder::claveFueGenerada())->toBeTrue()
        ->and(strlen($clave))->toBe(16)
        // La que se filtró en el repositorio público. Que no vuelva nunca.
        ->and($clave)->not->toBe('greatbaby2026')
        ->and(Hash::check($clave, $u->password))->toBeTrue();
});
