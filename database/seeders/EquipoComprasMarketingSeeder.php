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
 * - El usuario se busca por correo. Si no existe se crea con la contraseña inicial
 *   (la misma de las demás cuentas del ERP) y hay que pedirle que la cambie; si ya
 *   existe sólo se le asegura el rol y su contraseña no se toca.
 *
 * Los correos son provisionales: cambiarlos en USUARIOS antes de correrlo en producción.
 *
 *   php artisan db:seed --class=EquipoComprasMarketingSeeder
 */
class EquipoComprasMarketingSeeder extends Seeder
{
    public const ROLES = ['Compras', 'Marketing'];

    /**
     * La contraseña con la que nacen las cuentas nuevas.
     *
     * NO es una constante con el valor escrito. Este repositorio es público, y
     * una clave literal al lado de los correos reales del equipo es la cuenta
     * de esa persona servida en bandeja a cualquiera que clone.
     *
     * Orden:
     *   1. `CLAVE_INICIAL_USUARIOS` del `.env` — lo que se usa en producción,
     *      donde quien despliega la elige y no queda en ningún commit;
     *   2. si no está, una aleatoria distinta en cada corrida, que se imprime
     *      en la consola. Así el seeder sigue sirviendo para desarrollo sin
     *      dejar un secreto en el código.
     *
     * Memoizada: en una misma corrida todas las cuentas nacen con la misma, y
     * el mensaje final dice cuál.
     */
    private static ?string $clave = null;

    private static bool $generada = false;

    public static function claveInicial(): string
    {
        if (self::$clave !== null) {
            return self::$clave;
        }

        /*
         * `config()`, no `env()`.
         *
         * `php artisan config:cache` es parte normal de un despliegue, y a
         * partir de ahí `env()` devuelve NULL siempre: Laravel deja de leer el
         * `.env`. Un seeder que leyera `env()` pelado creería que nadie
         * configuró nada y se inventaría una contraseña aleatoria, en
         * silencio, justo en producción — la que quien despliega puso en el
         * `.env` no se usaría nunca y nadie se enteraría hasta que alguien no
         * pudiera entrar.
         */
        $delEntorno = trim((string) config('seguridad.clave_inicial_usuarios', ''));

        if ($delEntorno !== '') {
            return self::$clave = $delEntorno;
        }

        self::$generada = true;

        return self::$clave = Str::password(16, symbols: false);
    }

    /** ¿La inventó el seeder, o la puso quien despliega en el `.env`? */
    public static function claveFueGenerada(): bool
    {
        self::claveInicial();

        return self::$generada;
    }

    /**
     * Olvida la clave memoizada.
     *
     * Existe para las pruebas. La memoización vive en una propiedad estática,
     * que sobrevive de una prueba a la siguiente dentro del mismo proceso: sin
     * esto, una prueba que configure `CLAVE_INICIAL_USUARIOS` recibiría la
     * clave que inventó la prueba anterior, y quedaría verde comprobando otra
     * cosa.
     */
    public static function olvidarClave(): void
    {
        self::$clave = null;
        self::$generada = false;
    }

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

            $usuario = User::create([
                'name' => $nombre,
                'email' => $correo,
                'password' => Hash::make(self::claveInicial()),
            ]);
            $usuario->forceFill(['email_verified_at' => now()])->save();
            $usuario->assignRole($rol);
            $nuevos++;

            $this->command?->line("  {$correo}: creado con rol {$rol}");
        }

        if ($nuevos > 0) {
            /*
             * Si la clave la puso quien despliega en el `.env`, ya la sabe y no
             * hace falta repetirla en pantalla. La que inventa el seeder sí se
             * imprime: es el único momento en que alguien puede leerla.
             */
            $this->command?->warn(self::claveFueGenerada()
                ? '  Cuentas nuevas con esta contraseña: '.self::claveInicial().' — anótela ahora y pida cambiarla al entrar.'
                : '  Cuentas nuevas con la contraseña de CLAVE_INICIAL_USUARIOS: pida cambiarla al entrar.');
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
