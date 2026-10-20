<?php

namespace Database\Seeders;

use App\Auth\Permisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Los dos roles que el módulo de Gestión Humana necesita para existir.
 *
 * `LiderArea` es el jefe que pide personal. NO es un rol administrativo: entra,
 * llena su requisición y sigue las suyas. No ve salarios de otras áreas ni
 * hojas de vida, porque no le corresponde.
 *
 * `GestionHumana` es quien recibe esas solicitudes, aprueba, publica la
 * vacante y lleva el proceso de selección.
 *
 * `Colaborador` es el empleado mirando su propio espacio. Es el rol más
 * acotado de todos: abre una sola pantalla, la suya.
 *
 * Es idempotente: se puede correr las veces que haga falta. No toca los
 * permisos que el administrador haya repartido después a mano desde la
 * pantalla de Roles, que es la que manda.
 */
class RolesGestionHumanaSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (['LiderArea', 'GestionHumana', 'Colaborador'] as $nombre) {
            $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => $guard]);

            // Sólo se reparten permisos la primera vez. Si el rol ya tiene
            // alguno es porque alguien lo configuró, y eso no se pisa.
            if ($rol->permissions()->exists()) {
                $this->command?->line("  {$nombre}: ya configurado, no se toca");
                continue;
            }

            $suyos = [];
            foreach (Permisos::MATRIZ as $seccion => $roles) {
                if (in_array($nombre, $roles, true)) {
                    $suyos[] = Permisos::permiso($seccion);
                }
            }

            $rol->syncPermissions($suyos);
            $this->command?->line("  {$nombre}: ".count($suyos).' permiso(s)');
        }
    }
}
