<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Administración de roles · el admin crea el rol, marca qué puede ver y elige
 * quién lo tiene, sin que un programador toque código.
 *
 * Hasta acá la autorización vivía en `Permisos::MATRIZ`: cambiar el alcance de
 * un rol era editar una clase y volver a desplegar. Ahora la matriz sólo fue la
 * semilla y la autoridad es la tabla `permissions`.
 */
class RolesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            // Repartir accesos es lo más sensible del sistema: sólo root.
            // Casilla: Usuarios. Es la pantalla donde se reparten los accesos,
                //   asi que tambien se reparte desde una casilla.
                abort_unless(Permisos::puede($r->user(), 'usuarios'), 403,
                'Sólo Aracely o Gerencia pueden administrar roles.');

            return $next($r);
        })];
    }

    public function index(): Response
    {
        /*
         * El contador cuenta SECCIONES, no permisos.
         *
         * Desde que cada sección tiene cuatro acciones, contar permisos daría
         * «112 accesos» donde antes decía 28 y nadie sabría qué significa.
         * Lo que importa es a cuántas pantallas entra el rol; el detalle de
         * qué puede hacer en cada una está adentro.
         */
        $roles = Role::withCount([
            'users',
            'permissions as secciones_count' => fn ($q) => $q->where('name', 'like', 'ver.%'),
            'permissions as acciones_count' => fn ($q) => $q->where('name', 'not like', 'ver.%'),
        ])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'nombre' => $r->name,
                'usuarios' => $r->users_count,
                'permisos' => $r->secciones_count,
                'acciones' => $r->acciones_count,
                'protegido' => in_array($r->name, Permisos::ROLES_PROTEGIDOS, true),
                'root' => in_array($r->name, ['Aracely', 'Gerencia'], true),
            ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'total_permisos' => count(Permisos::MATRIZ),
        ]);
    }

    /** Formulario de un rol · `$rol` nulo significa "rol nuevo". */
    public function form(?int $rol = null): Response
    {
        $role = $rol ? Role::with('permissions')->findOrFail($rol) : null;

        return Inertia::render('Roles/Form', [
            'rol' => $role ? [
                'id' => $role->id,
                'nombre' => $role->name,
                'protegido' => in_array($role->name, Permisos::ROLES_PROTEGIDOS, true),
                'root' => in_array($role->name, ['Aracely', 'Gerencia'], true),
                'permisos' => $role->permissions->pluck('name')->all(),
                'usuarios' => $role->users()->pluck('users.id')->all(),
            ] : null,
            'catalogo' => Permisos::catalogo(),
            'acciones' => collect(Permisos::ACCIONES)
                ->map(fn ($texto, $accion) => ['accion' => $accion, 'texto' => $texto])
                ->values(),
            'usuarios' => User::orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'nombre' => $u->name,
                    'email' => $u->email,
                ]),
        ]);
    }

    public function guardar(Request $r, ?int $rol = null): RedirectResponse
    {
        $role = $rol ? Role::findOrFail($rol) : null;
        $esProtegido = $role && in_array($role->name, Permisos::ROLES_PROTEGIDOS, true);

        $datos = $r->validate([
            'nombre' => [
                'required', 'string', 'max:60', 'regex:/^[\pL\pN _-]+$/u',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'permisos' => ['array'],
            'permisos.*' => ['string', Rule::in(
                array_map(fn ($s) => Permisos::permiso($s), array_keys(Permisos::MATRIZ))
            )],
            'usuarios' => ['array'],
            'usuarios.*' => ['integer', 'exists:users,id'],
        ], [
            'nombre.regex' => 'El nombre del rol sólo admite letras, números, espacios, guiones y guiones bajos.',
        ]);

        // Los roles protegidos conservan su nombre: medio sistema los referencia
        // por texto (helpers del modelo User, seeders, la propia lista de root).
        $nombre = $esProtegido ? $role->name : $datos['nombre'];
        $usuarios = $datos['usuarios'] ?? [];

        // Si quien edita se quitara a sí mismo de un rol root se quedaría afuera
        // de esta misma pantalla, así que no lo permitimos.
        //
        // Esta guarda va ANTES de cualquier escritura. Estaba después del
        // `syncPermissions`, así que al rebotar la pantalla decía "no se guardó"
        // pero los permisos ya habían cambiado en la base.
        if (in_array($nombre, ['Aracely', 'Gerencia'], true)
            && $r->user()->hasRole($nombre)
            && ! in_array($r->user()->id, $usuarios, true)) {
            return back()->with('error',
                'No podés quitarte a vos mismo del rol '.$nombre.': perderías el acceso a esta pantalla.');
        }

        // Todo o nada: si falla la asignación de usuarios no queremos quedarnos
        // con el rol renombrado y los permisos a medio aplicar.
        $role = \Illuminate\Support\Facades\DB::transaction(function () use ($role, $nombre, $datos, $usuarios) {
            $role = $role
                ? tap($role)->update(['name' => $nombre])
                : Role::create(['name' => $nombre, 'guard_name' => config('auth.defaults.guard', 'web')]);

            $role->syncPermissions($datos['permisos'] ?? []);
            $this->sincronizarUsuarios($role, $usuarios);

            return $role;
        });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect('/app/roles')->with('success', "Rol «{$role->name}» guardado.");
    }

    public function eliminar(Request $r, int $rol): RedirectResponse
    {
        $role = Role::withCount('users')->findOrFail($rol);

        if (in_array($role->name, Permisos::ROLES_PROTEGIDOS, true)) {
            return back()->with('error', "El rol «{$role->name}» es del sistema y no se puede eliminar.");
        }

        if ($role->users_count > 0) {
            return back()->with('error',
                "«{$role->name}» tiene {$role->users_count} usuario(s). Reasignalos antes de eliminarlo.");
        }

        $nombre = $role->name;
        $role->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect('/app/roles')->with('success', "Rol «{$nombre}» eliminado.");
    }

    /**
     * Deja en el rol exactamente los usuarios elegidos.
     *
     * @param  list<int>  $usuarios
     */
    private function sincronizarUsuarios(Role $role, array $usuarios): void
    {
        $actuales = $role->users()->pluck('users.id')->all();

        foreach (array_diff($usuarios, $actuales) as $id) {
            User::find($id)?->assignRole($role);
        }
        foreach (array_diff($actuales, $usuarios) as $id) {
            User::find($id)?->removeRole($role);
        }
    }
}
