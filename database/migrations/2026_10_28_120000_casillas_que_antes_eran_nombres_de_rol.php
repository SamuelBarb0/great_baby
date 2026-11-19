<?php

/*
 * Las casillas de /app/roles que no gobernaban nada, ahora gobiernan.
 *
 * Hasta hoy, 22 de las 60 secciones declaradas en `Permisos::MATRIZ` estaban
 * pintadas en la pantalla de Roles, el `RolesController` las validaba al
 * guardar… y ningún control del sistema las consultaba. El admin creía estar
 * repartiendo accesos y en realidad sólo movía lo que se ve en el menú: la
 * verdad vivía en ~80 `abort_unless` escritos con nombres de rol.
 *
 * Al cablear cada puerta a su casilla, los roles que entraban POR SU NOMBRE
 * perderían el acceso, porque su casilla nunca se les marcó: nadie la
 * necesitaba. Esta migración se la marca una sola vez.
 *
 * No es cosmética. Sin ella, el día del despliegue:
 *   · Jorge pierde recepciones, importaciones, reportes de compras,
 *     ubicaciones y la estación de empaque — o sea, su trabajo entero.
 *   · El Contador pierde recepciones e importaciones.
 *   · El Gerente pierde el monitoreo de SIIGO.
 *
 * `Permisos::MATRIZ` quedó ensanchada en el mismo cambio, así que una
 * instalación nueva nace bien; esto es sólo para las bases que ya existen.
 * El seeder no sirve para esto a propósito: está escrito para no pisar lo que
 * el admin configuró a mano, y por eso no propaga un ensanche posterior.
 */

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Quién entraba por nombre de rol y por lo tanto necesita la casilla.
     * Sale de comparar, rol por rol, la puerta vieja contra la nueva.
     */
    private const OTORGAR = [
        'compras_recepcion' => ['Contador', 'AdminBodega'],
        'compras_importacion' => ['Contador', 'AdminBodega'],
        'compras_reportes' => ['AdminBodega'],
        'ubicaciones' => ['AdminBodega'],
        'vista_alistador' => ['AdminBodega'],
        'siigo' => ['Gerente'],
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (self::OTORGAR as $seccion => $roles) {
            $nombre = 'ver.'.$seccion;

            /*
             * Si el permiso todavía no existe, esta migración no tiene nada que
             * hacer: es una instalación nueva, y ahí `PermisosSeeder` reparte
             * desde la matriz ya ensanchada.
             *
             * Y es importante NO crearlo acá. El seeder decide a quién darle un
             * permiso mirando si acaba de nacer en esa corrida; si la migración
             * lo crea antes, el seeder lo ve viejo, no lo reparte, y como el rol
             * ya tiene otros permisos tampoco entra en el sembrado inicial. El
             * rol queda sin la casilla — que es justo el daño que esto evita.
             */
            $permiso = Permission::where('name', $nombre)
                ->where('guard_name', $guard)
                ->first();

            if (! $permiso) {
                continue;
            }

            foreach ($roles as $nombreRol) {
                $rol = Role::where('name', $nombreRol)->where('guard_name', $guard)->first();

                // Un rol que no existe en esta instalación no es un error:
                // la lista cubre todas, y no todas tienen los mismos roles.
                if ($rol && ! $rol->hasPermissionTo($permiso)) {
                    $rol->givePermissionTo($permiso);
                }
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        /*
         * No se revierte.
         *
         * Quitar estas casillas dejaría a los mismos roles sin acceso que
         * tenían antes de la migración, que es exactamente el daño que vino a
         * evitar. Y como el admin puede haberlas tocado a mano desde entonces,
         * revertirlas pisaría su decisión. Si hay que quitar un acceso, se
         * quita desde /app/roles, que para eso ahora sirve.
         */
    }
};
