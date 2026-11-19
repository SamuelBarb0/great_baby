<?php

/*
 * El contador puede escribir las reglas `contable.*`.
 *
 * Hasta hoy `/app/reglas` era de administración y punto, así que Silvia —que
 * es quien sabe a qué subcuenta va cada cosa— tenía que dictarle los números a
 * Aracely. Y las tres que importan están mal: `cta_costo_default` en 6135,
 * `cta_inventario_default` en 1435 y `cta_cxc_default` en 1305, todas
 * agrupadoras, con 112 de 129 líneas del libro posteadas contra ellas.
 *
 * Esta migración sólo REPARTE el permiso nuevo. La pantalla sigue siendo de
 * administración para los otros diez grupos: el controlador filtra por el
 * prefijo `contable.` tanto al mostrar como al guardar.
 *
 * ── Por qué no se crea el permiso acá ────────────────────────────────────
 *
 * `PermisosSeeder` decide a quién repartirle un permiso mirando si acaba de
 * nacer en ESA corrida. Si esta migración lo crea antes, el seeder lo ve
 * viejo, no lo reparte, y el rol se queda sin la casilla aunque la matriz la
 * declare. Así que acá sólo se busca; crearlo es tarea del seeder.
 */

use App\Auth\Permisos;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permiso = Permission::where('name', Permisos::permiso('reglas_contables'))->first();
        $rol = Role::where('name', 'Contador')->first();

        if (! $permiso || ! $rol) {
            // Instalación nueva: el seeder lo crea y lo reparte él mismo.
            return;
        }

        $rol->givePermissionTo($permiso);
    }

    public function down(): void
    {
        $permiso = Permission::where('name', Permisos::permiso('reglas_contables'))->first();
        $rol = Role::where('name', 'Contador')->first();

        if ($permiso && $rol) {
            $rol->revokePermissionTo($permiso);
        }
    }
};
