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

    /**
     * Un usuario demo por rol del sistema.
     *
     * El tercer campo admite UN rol o VARIOS. Una persona de verdad lleva más
     * de un sombrero —el jefe de logística es jefe de bodega y además líder de
     * su área, por eso puede pedir un alistador nuevo— y el sistema está hecho
     * para eso: «una persona puede tener más de un rol y suma los accesos de
     * todos», como dice la propia pantalla de Roles. Mientras esta lista
     * admitía uno solo, los dos sombreros había que ponerlos a mano y se
     * perdían en la siguiente resiembra.
     */
    public const USUARIOS = [
        // [email, name, rol o [roles], ciudad para AdminBodega]
        ['aracely@greatbaby.com',               'Aracely (demo)',             'Aracely'],
        ['gerencia.demo@greatbaby.com',         'Gerencia Demo',              'Gerencia'],
        ['gerente.demo@greatbaby.com',          'Gerente Demo',               'Gerente'],
        ['contador.demo@greatbaby.com',         'Contador Demo',              'Contador'],
        ['vendedor.demo@greatbaby.com',         'Carlos Vendedor Demo',       'Vendedor'],
        // Jorge lleva los dos: manda la bodega Y es líder de su área.
        //
        // El nombre dice el CARGO, no el rol técnico. Decía «Jorge AdminBodega
        // BOG», que es cómo lo llama el código, y así salía en cada pantalla
        // que muestra quién hizo qué — en la bitácora, en el pie de un
        // traslado, en la cola de alistamiento. Nadie en la bodega sabe qué es
        // un «AdminBodega».
        ['admin.bodega.bog@greatbaby.com',      'Jorge · Jefe de Logística',  ['AdminBodega', 'LiderArea']],
        ['alistador.bog@greatbaby.com',         'Alistador · Bodega principal', 'Alistador'],
        ['despachador.bog@greatbaby.com',       'Despachador · Bodega principal', 'Despachador'],
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

        // 1. Asegurar que todos los roles existen (fail-closed si falta uno).
        $todos = collect(self::USUARIOS)
            ->flatMap(fn ($fila) => (array) $fila[2])
            ->unique();

        foreach ($todos as $rol) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        }

        // 2. Crear/actualizar cada usuario demo.
        $creados = 0; $actualizados = 0;
        foreach (self::USUARIOS as [$email, $name, $roles]) {
            $roles = (array) $roles;
            $u = User::firstOrNew(['email' => $email]);
            $exista = $u->exists;

            $u->fill([
                'name' => $name,
                'password' => Hash::make(self::PASSWORD_DEMO),
                'email_verified_at' => now(),
            ])->save();

            $u->syncRoles($roles);

            $exista ? $actualizados++ : $creados++;
            $this->command?->line("  <fg=green>✓</> {$email} · <fg=cyan>".implode(' + ', $roles).'</>');
        }

        // 3. Y a cargo de una ubicación, que es lo que hace que vean datos.
        $this->repartirBodegas();

        $this->command?->newLine();
        $this->command?->info("Usuarios demo listos · {$creados} creados · {$actualizados} actualizados");
        $this->command?->comment("Password única para todos: '" . self::PASSWORD_DEMO . "'");
        $this->command?->warn("En producción este seeder NO corre (early return).");
    }

    /**
     * Quién responde por qué ubicación.
     *
     * ───────────────────────────────────────────────────────────────────────
     * POR QUÉ ESTO TIENE QUE ESTAR ACÁ
     *
     * El rol decide qué PANTALLAS abre una persona. Lo que decide de qué
     * bodega salen los DATOS es otra cosa: ser `responsable_user_id` de alguna
     * ubicación. `bodegasQueVeIds()` arranca de ahí, sube del estante a su
     * sede, y devuelve todo lo que cuelga.
     *
     * Hasta el 20-nov-2026 este seeder creaba los usuarios y no repartía
     * ninguna responsabilidad. El resultado no era un error visible: era peor.
     * El alistador entraba a su cola de alistamiento y la veía VACÍA —
     * `bodegasQueVeIds()` devolvía `[]`, el controlador lo convertía en `[-1]`
     * y ningún pedido casaba— sin un solo mensaje que dijera por qué.
     *
     * Costó siete tomas perdidas de un video de capacitación antes de que
     * alguien mirara la columna en vez de la pantalla.
     *
     * Jorge responde por la SEDE entera, porque es el jefe del área. El
     * alistador responde por la zona de alistamiento, que le basta para ver
     * toda la bodega sin quitarle a él la jefatura. El despachador no responde
     * por ninguna, a propósito: firma salidas, no mueve inventario.
     */
    private function repartirBodegas(): void
    {
        /** correo => código de la ubicación de la que responde */
        $reparto = [
            'admin.bodega.bog@greatbaby.com' => 'PPAL',
            'alistador.bog@greatbaby.com' => 'Z-ALISTAMIENTO',
        ];

        if (! \Illuminate\Support\Facades\Schema::hasTable('inventario_ubicaciones')) {
            $this->command?->warn('  No hay ubicaciones todavía: nadie queda a cargo de nada.');

            return;
        }

        foreach ($reparto as $correo => $codigo) {
            $u = User::where('email', $correo)->first();

            $sitio = \App\Modules\Dropi\Models\InventarioUbicacion::query()
                ->where('codigo', $codigo)
                ->where('activa', true)
                ->first();

            if (! $u || ! $sitio) {
                $this->command?->warn("  <fg=yellow>—</> {$correo}: no existe «{$codigo}», queda sin bodega a cargo");

                continue;
            }

            if ((int) $sitio->responsable_user_id === (int) $u->id) {
                continue;
            }

            /*
             * Si la ubicación ya tiene otro responsable NO se le quita: puede
             * ser una persona de verdad a la que alguien se la asignó desde la
             * pantalla, y un seeder de demo no tiene por qué pisarla.
             */
            if ($sitio->responsable_user_id !== null) {
                $this->command?->warn(
                    "  <fg=yellow>—</> {$codigo} ya tiene responsable (id {$sitio->responsable_user_id}): no se toca"
                );

                continue;
            }

            $sitio->responsable_user_id = $u->id;
            $sitio->save();

            $u->refresh();

            $this->command?->line(
                "  <fg=green>✓</> {$correo} a cargo de <fg=cyan>{$codigo}</> · ve "
                .count($u->bodegasQueVeIds()).' ubicaciones'
            );
        }
    }
}
