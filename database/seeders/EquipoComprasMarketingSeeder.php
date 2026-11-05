<?php

namespace Database\Seeders;

use App\Auth\Permisos;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Las cuentas del equipo de Compras (Alejandro y Liz) y de Marketing (Juan Felipe),
 * con sus roles.
 *
 * Se puede correr las veces que haga falta, también en producción:
 * - El rol sólo recibe sus casillas la primera vez. Si ya tiene permisos es porque
 *   alguien lo configuró en /app/roles, y eso no se pisa.
 * - El usuario se busca por correo. Si no existe se crea con una contraseña al azar
 *   que se muestra una sola vez; si ya existe sólo se le asegura el rol y su
 *   contraseña no se toca.
 *
 * Los correos son provisionales: cambiarlos en USUARIOS antes de correrlo en producción.
 *
 *   php artisan db:seed --class=EquipoComprasMarketingSeeder
 */
class EquipoComprasMarketingSeeder extends Seeder
{
    public const ROLES = ['Compras', 'Marketing'];

    /** [correo, nombre, rol] */
    public const USUARIOS = [
        ['alejandro@greatbaby.com',  'Alejandro',   'Compras'],
        ['liz@greatbaby.com',        'Liz',         'Compras'],
        ['juanfelipe@greatbaby.com', 'Juan Felipe', 'Marketing'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $nombre) {
            $this->asegurarRol($nombre);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $nuevos = 0;
        foreach (self::USUARIOS as [$correo, $nombre, $rol]) {
            $usuario = User::where('email', $correo)->first();

            if ($usuario) {
                $usuario->assignRole($rol);
                $this->command?->line("  {$correo}: ya existía, se le asegura el rol {$rol} (contraseña sin cambios)");
                continue;
            }

            $clave = Str::password(14, symbols: false);
            $usuario = User::create([
                'name' => $nombre,
                'email' => $correo,
                'password' => Hash::make($clave),
            ]);
            $usuario->forceFill(['email_verified_at' => now()])->save();
            $usuario->assignRole($rol);
            $nuevos++;

            $this->command?->line("  {$correo}: creado con rol {$rol} · contraseña: {$clave}");
        }

        if ($nuevos > 0) {
            $this->command?->warn('  Las contraseñas nuevas se muestran sólo esta vez: entréguelas y pida cambiarlas.');
        }
    }

    private function asegurarRol(string $nombre): void
    {
        $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

        if ($rol->permissions()->exists()) {
            $this->command?->line("  Rol {$nombre}: ya configurado, no se toca");

            return;
        }

        // Sin permisos sembrados sólo queda el rol: PermisosSeeder le da sus casillas.
        if (! Permission::where('name', 'like', 'ver.%')->exists()) {
            $this->command?->line("  Rol {$nombre}: creado; los permisos aún no están sembrados");

            return;
        }

        $suyos = [];
        foreach (Permisos::MATRIZ as $seccion => $roles) {
            if (! in_array($nombre, $roles, true)) {
                continue;
            }
            foreach (Permisos::permisosDe($seccion) as $permiso) {
                Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
                $suyos[] = $permiso;
            }
        }

        $rol->syncPermissions($suyos);
        $this->command?->line("  Rol {$nombre}: ".count($suyos).' permiso(s)');
    }
}
