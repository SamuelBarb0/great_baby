<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * C-F9 QA · Seeder de usuarios demo (1 por cada rol).
 *
 * Idempotente — se puede correr N veces. Todos los usuarios tienen
 * password `demo` para que QA pueda cambiar de rol en 2s. En producción,
 * NUNCA correr este seeder.
 *
 * Correr:
 *   php artisan db:seed --class=UsuariosDemoSeeder
 *   php artisan gb:usuarios-demo   # alias cómodo
 */
class UsuariosDemoSeeder extends Seeder
{
    public const PASSWORD_DEMO = 'demo';

    /** Un usuario demo por rol del sistema. */
    public const USUARIOS = [
        // [email, name, rol, ciudad para AdminBodega]
        ['aracely@greatbaby.com',               'Aracely (demo)',             'Aracely'],
        ['gerencia.demo@greatbaby.com',         'Gerencia Demo',              'Gerencia'],
        ['gerente.demo@greatbaby.com',          'Gerente Demo',               'Gerente'],
        ['contador.demo@greatbaby.com',         'Contador Demo',              'Contador'],
        ['vendedor.demo@greatbaby.com',         'Carlos Vendedor Demo',       'Vendedor'],
        ['admin.bodega.bog@greatbaby.com',      'Jorge AdminBodega BOG',      'AdminBodega'],
        ['alistador.bog@greatbaby.com',         'Alistador BOG Demo',         'Alistador'],
        ['despachador.bog@greatbaby.com',       'Despachador BOG Demo',       'Despachador'],
        ['sac.demo@greatbaby.com',              'Servicio Cliente Demo',      'ServicioCliente'],
        ['marketing.demo@greatbaby.com',        'Mia Marketing Demo',         'Marketing'],
        ['facturador.demo@greatbaby.com',       'Facturador Demo',            'Facturador'],
        // Gestión Humana y el líder de área no tenían usuario demo, aunque el
        //   módulo entero está construido alrededor de los dos: ella maneja
        //   selección, contratación y el cuadro de novedades; él pide personal
        //   y da el concepto del período de prueba. Sin ellos no había forma
        //   de recorrer el módulo en local sin crear usuarios a mano.
        ['gestionhumana.demo@greatbaby.com',    'Elena Gestión Humana (demo)', 'GestionHumana'],
        ['lider.demo@greatbaby.com',            'Líder de Área Demo',          'LiderArea'],
        // El colaborador tampoco tenía uno, y es el rol más acotado de todos:
        //   abre una sola pantalla. Sin él, la prueba que compara el menú con
        //   las puertas lo saltaba en silencio y nadie cubría ese caso.
        ['colaborador.demo@greatbaby.com',      'Colaborador Demo',            'Colaborador'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('ABORTADO · este seeder NO corre en producción.');
            return;
        }

        // 1. Asegurar que los 10 roles existen (fail-closed si falta uno).
        foreach (array_unique(array_column(self::USUARIOS, 2)) as $rol) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        }

        // 2. Crear/actualizar cada usuario demo.
        $creados = 0; $actualizados = 0;
        foreach (self::USUARIOS as [$email, $name, $rol]) {
            $u = User::firstOrNew(['email' => $email]);
            $exista = $u->exists;

            $u->fill([
                'name' => $name,
                'password' => Hash::make(self::PASSWORD_DEMO),
                'email_verified_at' => now(),
            ])->save();

            $u->syncRoles([$rol]);

            $exista ? $actualizados++ : $creados++;
            $this->command?->line("  <fg=green>✓</> {$email} · <fg=cyan>{$rol}</>");
        }

        $this->command?->newLine();
        $this->command?->info("Usuarios demo listos · {$creados} creados · {$actualizados} actualizados");
        $this->command?->comment("Password única para todos: '" . self::PASSWORD_DEMO . "'");
        $this->command?->warn("En producción este seeder NO corre (early return).");
    }
}
