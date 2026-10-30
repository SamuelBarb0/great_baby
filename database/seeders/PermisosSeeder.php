<?php

namespace Database\Seeders;

use App\Auth\Permisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Convierte la matriz de autorización que vivía en código (`Permisos::MATRIZ`)
 * en permisos reales de la base, para que el admin pueda crear roles y repartir
 * accesos sin que un programador toque nada.
 *
 * Es idempotente y NO cambia el comportamiento: cada rol queda con exactamente
 * los mismos accesos que tenía escritos en la matriz. A partir de acá, lo que
 * manda es lo que se marque en la pantalla de Roles.
 */
class PermisosSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // 1 · Cuatro permisos por sección: ver, crear, editar, eliminar.
        //     Anotamos los que nacen en esta corrida: una sección agregada
        //     después del primer sembrado tiene que llegar igual a sus roles,
        //     aunque esos roles ya estén configurados.
        $nuevos = [];
        $accionesNuevas = [];
        foreach (array_keys(Permisos::MATRIZ) as $seccion) {
            foreach (array_keys(Permisos::ACCIONES) as $accion) {
                $nombre = Permisos::permiso($seccion, $accion);
                if (Permission::where('name', $nombre)->where('guard_name', $guard)->exists()) {
                    continue;
                }

                Permission::create(['name' => $nombre, 'guard_name' => $guard]);

                if ($accion === 'ver') {
                    $nuevos[$nombre] = $seccion;
                } else {
                    // Las acciones de una sección que YA existía se reparten
                    // distinto: ver abajo.
                    $accionesNuevas[$nombre] = $seccion;
                }
            }
        }

        /*
         * 1a · Las acciones nuevas sobre secciones viejas son ADITIVAS.
         *
         * Antes una sección era un solo permiso y quien la veía podía hacerle
         * todo. Al partirla en cuatro, el despliegue no puede quitarle nada a
         * nadie: si el Contador podía editar la cartera ayer, hoy tiene que
         * poder. Así que cada rol que ya tenía el `ver` de una sección recibe
         * sus tres acciones.
         *
         * Restringir es una decisión de Aracely en la pantalla de Roles, no un
         * efecto colateral de actualizar el sistema.
         */
        foreach ($accionesNuevas as $nombre => $seccion) {
            $verDeLaSeccion = Permisos::permiso($seccion);

            foreach (Role::all() as $rol) {
                if ($rol->hasPermissionTo($verDeLaSeccion)
                    || in_array($rol->name, ['Aracely', 'Gerencia'], true)) {
                    $rol->givePermissionTo($nombre);
                }
            }
        }

        // 1b · Los permisos recién creados se reparten según la matriz, sin
        //      tocar nada de lo que el admin ya haya configurado a mano.
        foreach ($nuevos as $nombre => $seccion) {
            foreach (Role::all() as $rol) {
                $leCorresponde = in_array($rol->name, Permisos::MATRIZ[$seccion], true)
                    || in_array($rol->name, ['Aracely', 'Gerencia'], true);
                if ($leCorresponde) {
                    $rol->givePermissionTo($nombre);
                }
            }
        }

        // 2 · Cada rol recibe los permisos que la matriz le daba.
        //     Se usa syncPermissions sólo en el primer sembrado de ese rol para
        //     no pisar lo que el admin haya configurado después a mano.
        foreach (Role::all() as $rol) {
            if ($rol->permissions()->exists()) {
                continue;
            }

            // Un rol que estrena permisos los recibe COMPLETOS sobre sus
            // secciones: es el comportamiento que tenía antes de que las
            // acciones existieran. Recortar es decisión del admin, no del
            // sembrado.
            $suyos = [];
            foreach (Permisos::MATRIZ as $seccion => $roles) {
                if (in_array($rol->name, $roles, true)) {
                    $suyos = array_merge($suyos, Permisos::permisosDe($seccion));
                }
            }

            // Root ve todo: su acceso no depende de las casillas, pero le
            // dejamos los permisos marcados para que la pantalla no muestre
            // a la dueña del sistema con todo en blanco.
            //
            // OJO: "protegido" (no se puede borrar) no es lo mismo que "root"
            // (ve todo). AdminBodega es protegido porque sin él la bodega se
            // detiene, pero sigue viendo sólo lo suyo.
            if (in_array($rol->name, ['Aracely', 'Gerencia'], true)) {
                $suyos = Permission::query()->pluck('name')->all();
            }

            $rol->syncPermissions($suyos);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Secciones: %d · permisos: %d (ver/crear/editar/eliminar) · roles: %d',
            Permission::where('name', 'like', 'ver.%')->count(),
            Permission::count(),
            Role::count(),
        ));
    }
}
