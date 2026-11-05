<?php

use App\Auth\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El rol de Alejandro y Liz. Se puede ajustar después en /app/roles. Donde los permisos
 * aún no se han sembrado sólo se crea el rol: el seeder le da sus casillas desde la matriz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $rol = Role::firstOrCreate(['name' => 'Compras', 'guard_name' => 'web']);

        if (Permission::where('name', 'like', 'ver.%')->exists()) {
            $secciones = array_keys(array_filter(Permisos::MATRIZ, fn (array $roles) => in_array('Compras', $roles, true)));
            foreach ($secciones as $seccion) {
                foreach (Permisos::permisosDe($seccion) as $nombre) {
                    Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
                    $rol->givePermissionTo($nombre);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'Compras')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
