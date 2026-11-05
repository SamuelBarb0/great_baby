<?php

use App\Auth\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * La parrilla pasó a ser de Marketing por defecto en la matriz, pero la matriz sólo
 * manda donde no se han sembrado los permisos. Sin esto, cada instalación ya sembrada
 * seguiría respondiéndole 403 hasta que alguien marcara las casillas a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->sembrados()) {
            return;
        }

        $permisos = Permisos::permisosDe('marketing_parrilla');
        foreach ($permisos as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        Role::where('name', 'Marketing')->where('guard_name', 'web')->get()
            ->each(fn (Role $rol) => $rol->givePermissionTo($permisos));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->sembrados()) {
            return;
        }

        Role::where('name', 'Marketing')->where('guard_name', 'web')->get()
            ->each(fn (Role $rol) => $rol->revokePermissionTo(Permisos::permisosDe('marketing_parrilla')));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function sembrados(): bool
    {
        return Schema::hasTable('permissions') && Permission::where('name', 'like', 'ver.%')->exists();
    }
};
