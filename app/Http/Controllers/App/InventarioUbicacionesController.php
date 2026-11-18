<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Siigo\Models\SiigoCatalogo;
use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UBIC-2 · CRUD completo de Ubicaciones (bodegas / puntos de venta).
 *
 * Diseño:
 *   - Solo Aracely/Gerencia puede modificar.
 *   - No se puede eliminar una ubicación con stock físico o movimientos
 *     históricos; en su lugar se desactiva (`activa = false`). Esto preserva
 *     la integridad del kardex y los asientos locales ya emitidos.
 *   - Cada ubicación puede mapear una resolución DIAN de SIIGO (UBIC-4/5)
 *     y cuentas PUC propias (UBIC-3 habilita BUG-CTA).
 */
class InventarioUbicacionesController extends Controller
{
    public function index(Request $r): Response
    {
        // LOG · El index es READ-ONLY para AdminBodega (solo mira sus racks).
        //   Crear/editar/eliminar sigue siendo root (los otros métodos del
        //   controller mantienen el esRoot original).
        // Casilla: Ubicaciones y bodegas. Ver la pantalla; administrar sigue
        //   siendo de gerencia en cada accion de escritura.
        abort_unless(
            \App\Auth\Permisos::puede($r->user(), 'ubicaciones'),
            403,
            'Esta pantalla es de quien administra las bodegas.'
        );

        // UBIC-8 · Mapa user_id → nombre para pintar el admin de bodega sin N+1.
        $responsables = User::query()
            ->whereIn('id', InventarioUbicacion::whereNotNull('responsable_user_id')->pluck('responsable_user_id'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        // El orden agrupa cada bodega con las posiciones que viven adentro
        // (COALESCE pone a la hija junto a su madre) y deja la bodega arriba de
        // su grupo. Antes se ordenaba por activa+codigo, que mezclaba racks de
        // sedes distintas y hacía imposible leer dónde está cada cosa; las
        // inactivas se siguen distinguiendo porque la fila va atenuada.
        $filas = InventarioUbicacion::query()
            ->withCount(['movimientos'])
            // Primero las de Great Baby. Hoy el alfabeto ya las deja arriba
            // («RES-» < «SIIGO-»), pero eso es casualidad del prefijo y se
            // rompe con el primer código nuevo que empiece por T.
            ->orderByRaw("codigo LIKE 'SIIGO-%'")
            ->orderByRaw('COALESCE(bodega_id, id)')
            ->orderByRaw('bodega_id IS NOT NULL')
            ->orderBy('codigo')
            ->get();

        $nombresBodega = $filas->pluck('nombre', 'id');

        $ubicaciones = $filas
            ->map(function ($u) use ($responsables, $nombresBodega) {
                $resp = $u->responsable_user_id ? $responsables->get($u->responsable_user_id) : null;
                return [
                    'id' => $u->id,
                    'codigo' => $u->codigo,
                    'nombre' => $u->nombre,
                    // Las trajo el importador del catálogo de SIIGO, no las
                    // creó nadie acá. Son 43 contra 21 propias, y como el
                    // sandbox es compartido muchas son de otras empresas
                    // («Tiendanube», «ISLA I0001», «Locacion Jikko MP»).
                    // Sin distinguirlas, la lista parece un desastre operativo
                    // que en realidad no lo es.
                    'importada_de_siigo' => str_starts_with((string) $u->codigo, 'SIIGO-'),
                    'categoria' => $u->categoria?->value,
                    'categoria_label' => $u->categoria?->name,
                    'activa' => (bool) $u->activa,
                    'disponible_para_venta' => (bool) $u->disponible_para_venta,
                    'direccion' => $u->direccion,
                    'ciudad' => $u->ciudad,
                    'bodega_id' => $u->bodega_id,
                    'bodega_nombre' => $u->bodega_id ? $nombresBodega->get($u->bodega_id) : null,
                    'pasillo' => $u->pasillo,
                    'estante' => $u->estante,
                    'nivel' => $u->nivel,
                    'posicion' => $u->posicion(),
                    'es_bodega' => $u->esBodega(),
                    // Una ubicación suelta —que no es bodega ni pertenece a
                    // una— no la puede operar ningún responsable de bodega.
                    'sin_sede' => $u->bodega_id === null && $u->responsable_user_id === null,
                    'responsable_user_id' => $u->responsable_user_id,
                    'responsable_nombre' => $resp?->name,
                    'responsable_email' => $resp?->email,
                    'notas' => $u->notas,
                    'siigo_id' => $u->siigo_id,
                    'siigo_resolution_id' => $u->siigo_resolution_id,
                    'siigo_resolution_name' => $u->siigo_resolution_name,
                    'siigo_resolution_prefix' => $u->siigo_resolution_prefix,
                    'cta_inventario' => $u->cta_inventario,
                    'cta_costo' => $u->cta_costo,
                    'movimientos_count' => (int) $u->movimientos_count,
                ];
            })->all();

        // UBIC-8 · Candidatos a admin de bodega: todo user con rol AdminBodega o
        // Alistador (su admin "senior" natural) + los ya asignados aunque no tengan
        // rol todavía. Al guardar la ubicación auto-promovemos a AdminBodega.
        $candidatos = User::query()
            ->where(function ($q) {
                // El despachador también responde por una zona: entrega a la
                // transportadora y es quien sabe qué salió de ahí.
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['AdminBodega', 'Alistador', 'Despachador']))
                    ->orWhereIn('id', InventarioUbicacion::whereNotNull('responsable_user_id')->pluck('responsable_user_id'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->getRoleNames()->all(),
            ])->all();

        return Inertia::render('Inventario/Ubicaciones/Index', [
            'ubicaciones' => $ubicaciones,
            'categorias' => collect(CategoriaUbicacion::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->name])->all(),
            'resoluciones' => SiigoCatalogo::where('tipo', 'resolutions-fv')->get()
                ->map(fn ($c) => [
                    'id' => (int) $c->codigo,
                    'nombre' => $c->nombre,
                    'prefix' => $c->payload['prefix'] ?? null,
                ])->all(),
            // UBIC-6 · default actual (si la hay) · el front marca con ★ y usa
            // como valor pre-seleccionado al abrir "Nueva ubicación".
            'resolucion_default_id' => (int) setting('siigo.resolucion_fv_default_id', 0) ?: null,
            'warehouses_siigo' => SiigoCatalogo::where('tipo', 'warehouses')->get()
                ->map(fn ($c) => ['id' => (int) $c->codigo, 'nombre' => $c->nombre])->all(),
            // UBIC-8 · usuarios candidatos y endpoint para crear nuevos.
            'candidatos_admin' => $candidatos,
            /*
             * El jefe de bodega puede decir quién responde por cada estante
             * SUYO, sin pasar por Gerencia. Es trabajo de todos los días y
             * mandárselo a Gerencia dejaba estantes sin dueño por semanas —que
             * es, exactamente, por lo que los alistadores tenían la cola vacía.
             */
            'puede_asignar_responsable' => \App\Auth\Permisos::puedeEditar($r->user(), 'ubicaciones'),
            /*
             * Crear y editar la ubicación, activarla, borrarla, empujarla a
             * SIIGO y traer las resoluciones DIAN son todas de Gerencia: tocan
             * el enlace con SIIGO, la resolución y las cuentas del PUC.
             *
             * La pantalla las mostraba a cualquiera que pudiera MIRARLA, o sea
             * también al jefe de bodega, y el servidor las cerraba con 403.
             * Dos de ellas —activar y borrar— ni siquiera avisaban: el click
             * no hacía nada y la fila se quedaba igual.
             */
            'puede_editar_la_ubicacion' => \App\Auth\Permisos::esRoot($r->user()),
            // El mismo alcance que revisa `asignarResponsable`: el de
            // escribir. Mandar el de leer dejaba el select a la vista en
            // ubicaciones que el servidor después rechazaba con 403.
            'mis_ubicaciones' => \App\Auth\Permisos::esRoot($r->user())
                ? null
                : $r->user()->bodegasAsignadasIds(),
            // Ubicaciones activas que no pertenecen a ninguna bodega y tampoco
            // son una: nadie las puede contar ni mover. Se muestra arriba para
            // que no quede invisible — era la causa de que los conteos en los
            // racks le dieran 403 al responsable de la bodega.
            //
            // No cuenta las `SIIGO-*`. Son las bodegas que el importador trajo
            // del catálogo de SIIGO —43 hoy, muchas de otras empresas porque
            // el sandbox es compartido—: existen sólo como destino contable,
            // nadie las opera y nunca van a tener responsable. Contándolas, el
            // aviso decía 63 cuando los casos reales eran 20, y un aviso que
            // grita sin motivo se vuelve invisible.
            'sin_sede' => InventarioUbicacion::where('activa', true)
                ->whereNull('bodega_id')->whereNull('responsable_user_id')
                ->where('codigo', 'not like', 'SIIGO-%')
                ->count(),
            // Las bodegas disponibles para colgar una ubicación · las sedes de
            // Great Baby primero. El resto son warehouses de otras empresas que
            // llegan del ambiente compartido de SIIGO, y mezclados por nombre
            // empujaban las dos sedes propias al fondo de una lista de 60.
            'bodegas' => InventarioUbicacion::whereNull('bodega_id')
                ->orderByRaw('responsable_user_id IS NULL')
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'responsable_user_id'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'nombre' => $b->nombre,
                    'codigo' => $b->codigo,
                    'es_sede' => $b->responsable_user_id !== null,
                ]),
        ]);
    }

    /**
     * UBIC-8 · Crear un nuevo usuario Admin Bodega sin salir del CRUD.
     * Útil cuando Aracely está creando la ubicación y aún no existe el usuario
     * del encargado. Lo deja listo para pre-seleccionarlo como responsable.
     */
    /**
     * Crear la gente de bodega sin salir de la pantalla de ubicaciones.
     *
     * Lo hace quien administra bodegas —no sólo Gerencia— porque es él quien
     * sabe que entró un alistador nuevo el lunes, y porque el camino viejo
     * —pedirle a Gerencia que cree el usuario y volver a asignarlo— terminaba
     * en estantes sin responsable durante semanas.
     *
     * Los roles que puede crear están cerrados a los tres de bodega. Dejar el
     * rol abierto habría convertido esta casilla en una fábrica de Gerencias.
     */
    public function crearAdminBodega(Request $r): JsonResponse
    {
        abort_unless(
            \App\Auth\Permisos::puedeEditar($r->user(), 'ubicaciones'),
            403,
            'Crear gente de bodega es de quien administra las bodegas.'
        );

        $datos = $r->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8', 'max:120'],
            // Sólo los tres papeles de bodega, y nada más.
            'rol' => ['nullable', \Illuminate\Validation\Rule::in(['AdminBodega', 'Alistador', 'Despachador'])],
        ]);

        $rol = $datos['rol'] ?? 'AdminBodega';

        /*
         * El rol tiene que existir como fila.
         *
         * Sin esto, `syncRoles` revienta con una excepción de Spatie y la
         * pantalla devuelve un 500 pelado. No es hipotético: si el sembrador
         * de permisos no se ha corrido en ese ambiente, el rol puede
         * sencillamente no estar, y quien queda mirando el error es el jefe de
         * bodega tratando de dar de alta a alguien que ya está trabajando.
         */
        abort_unless(
            \Spatie\Permission\Models\Role::where('name', $rol)->where('guard_name', 'web')->exists(),
            422,
            "El rol «{$rol}» todavía no existe en este sistema. Pedile a Gerencia que corra el sembrador de permisos."
        );
        $pwd = $datos['password'] ?? Str::password(12, symbols: false);
        // FIX-S0 · User::create + syncRoles son dos escrituras separadas; si
        // Spatie falla, el user queda sin rol. Encerrar en transacción.
        $u = DB::transaction(function () use ($datos, $pwd, $rol) {
            $user = User::create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'password' => Hash::make($pwd),
            ]);
            $user->syncRoles([$rol]);
            return $user;
        });

        $comoSeLlama = match ($rol) {
            'Alistador' => 'Alistador',
            'Despachador' => 'Despachador',
            default => 'Admin Bodega',
        };

        return response()->json([
            'ok' => true,
            'mensaje' => "Usuario {$u->name} creado como {$comoSeLlama}.",
            'password_generado' => empty($datos['password']) ? $pwd : null,
            'usuario' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'roles' => [$rol]],
        ]);
    }

    /**
     * Poner o quitar el responsable de una ubicación.
     *
     * Existe aparte de `guardar()` por una razón concreta: guardar una
     * ubicación toca también el enlace con SIIGO, la resolución DIAN y las
     * cuentas del PUC, y eso es de Gerencia. Decir quién responde por un
     * estante es trabajo de todos los días del jefe de bodega, y mandárselo a
     * Gerencia terminaba en estantes sin dueño durante semanas —que es,
     * exactamente, por qué los alistadores tenían la cola vacía—.
     *
     * Dos candados, y los dos importan:
     *   · sólo sobre SUS ubicaciones, para que no reparta las de otra sede;
     *   · sólo gente con papel de bodega, para que esto no se convierta en la
     *     puerta por la que un vendedor termina viendo el inventario.
     */
    public function asignarResponsable(Request $r): JsonResponse
    {
        abort_unless(
            \App\Auth\Permisos::puedeEditar($r->user(), 'ubicaciones'),
            403,
            'Asignar responsables es de quien administra las bodegas.'
        );

        $datos = $r->validate([
            'ubicacion_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'responsable_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $u = $r->user();
        $ubicacion = InventarioUbicacion::findOrFail($datos['ubicacion_id']);

        /*
         * El alcance que se pide acá es el de ESCRIBIR, no el de ver.
         *
         * `bodegasQueVeIds()` sube del estante a la sede y de ahí baja a todo
         * lo que vive en ella, a propósito: un alistador que responde por un
         * rack tiene que poder ver la cola de su bodega. Usarlo acá era una
         * escalación: ese mismo alistador podía repartir los responsables de
         * TODA la sede —incluida la bodega de averías— y el responsable es
         * justamente la columna de la que cuelga el permiso de operar. O sea
         * que con un rack se nombraba dueño de la sede entera.
         *
         * `bodegasAsignadasIds()` sólo baja, y pide AdminBodega.
         */
        if (! \App\Auth\Permisos::esRoot($u)) {
            abort_unless(
                in_array((int) $ubicacion->id, $u->bodegasAsignadasIds(), true),
                403,
                "«{$ubicacion->nombre}» no es una de sus bodegas."
            );
        }

        $responsable = $datos['responsable_user_id'] ? User::find($datos['responsable_user_id']) : null;

        if ($responsable) {
            abort_unless(
                $responsable->hasAnyRole(['AdminBodega', 'Alistador', 'Despachador', 'Aracely', 'Gerencia']),
                422,
                "{$responsable->name} no tiene ningún papel en bodega. Creálo como alistador o bodeguero primero."
            );
        }

        /*
         * NOTA, 18-nov-2026 · quedarse sin bodega.
         *
         * La revisión señaló que un jefe de bodega puede quitarse a sí mismo de
         * su única ubicación y en el mismo clic se queda sin cola, sin kardex y
         * sin esta pantalla, sin forma de devolvérselo. Es un riesgo real.
         *
         * Pero PODER quitar el responsable es una decisión tomada y probada
         * —«quitar el responsable se puede, y deja la ubicación sin dueño», en
         * JorgeArmaSuEquipoTest—, y cerrarla cambia el comportamiento que el
         * área pidió. Queda anotado para decidirlo con ellos, no acá.
         */
        $ubicacion->update(['responsable_user_id' => $responsable?->id]);

        return response()->json([
            'ok' => true,
            'mensaje' => $responsable
                ? "{$responsable->name} responde por {$ubicacion->nombre}."
                : "{$ubicacion->nombre} quedó sin responsable.",
            'responsable' => $responsable ? ['id' => $responsable->id, 'name' => $responsable->name] : null,
        ]);
    }

    public function guardar(Request $r): JsonResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403);

        $datos = $r->validate([
            'id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
            'codigo' => ['required', 'string', 'max:30'],
            'nombre' => ['required', 'string', 'max:120'],
            'categoria' => ['required', 'string'],
            // A qué bodega pertenece. Vacío = esto ES una bodega (sede).
            'bodega_id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
            'pasillo' => ['nullable', 'string', 'max:30'],
            'estante' => ['nullable', 'string', 'max:30'],
            'nivel' => ['nullable', 'string', 'max:30'],
            'activa' => ['boolean'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'responsable_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
            // Tiene que ser una bodega que SIIGO conozca de verdad. Era un
            // entero suelto: cualquier número entraba y el chip verde de
            // «sincronizado» salía igual, apuntando a una bodega inexistente.
            // Filtra por `tipo` porque `siigo_catalogos` guarda juntos
            // warehouses, taxes, price-lists y account-groups, y sin eso un
            // impuesto con el mismo código pasaría por bodega.
            'siigo_id' => ['nullable', 'integer',
                Rule::exists('siigo_catalogos', 'codigo')->where('tipo', 'warehouses')],
            'siigo_resolution_id' => ['nullable', 'integer'],
            'siigo_resolution_name' => ['nullable', 'string', 'max:120'],
            'siigo_resolution_prefix' => ['nullable', 'string', 'max:10'],
            'cta_inventario' => ['nullable', 'string', 'max:10'],
            'cta_costo' => ['nullable', 'string', 'max:10'],
        ]);

        // Unicidad del código entre activos.
        $query = InventarioUbicacion::where('codigo', $datos['codigo']);
        if (! empty($datos['id'])) $query->where('id', '!=', $datos['id']);
        if ($query->exists()) {
            return response()->json(['ok' => false, 'mensaje' => "Ya existe otra ubicación con código {$datos['codigo']}."], 422);
        }

        // Una ubicación no puede colgar de sí misma ni de otra que ya cuelga
        // de alguien: la jerarquía es de dos niveles, bodega → ubicación. Si se
        // permitieran cadenas, el permiso del responsable dejaría de ser
        // predecible y habría que recorrer el árbol en cada verificación.
        if (! empty($datos['bodega_id'])) {
            if (! empty($datos['id']) && (int) $datos['bodega_id'] === (int) $datos['id']) {
                return response()->json(['ok' => false, 'mensaje' => 'Una ubicación no puede pertenecerse a sí misma.'], 422);
            }

            $padre = InventarioUbicacion::find($datos['bodega_id']);
            if ($padre && $padre->bodega_id !== null) {
                return response()->json([
                    'ok' => false,
                    'mensaje' => "«{$padre->nombre}» no es una bodega: ya pertenece a otra. Elegí una bodega principal.",
                ], 422);
            }

            // Si esta ubicación ya tiene posiciones adentro, no puede pasar a
            // colgar de otra: dejaría de ser bodega y sus hijas quedarían
            // huérfanas de permiso.
            if (! empty($datos['id'])) {
                $hijas = InventarioUbicacion::where('bodega_id', $datos['id'])->count();
                if ($hijas > 0) {
                    return response()->json([
                        'ok' => false,
                        'mensaje' => "Esta bodega tiene {$hijas} ubicación(es) adentro: no puede pasar a depender de otra.",
                    ], 422);
                }
            }
        }

        // UBIC-6 · Auto-asignación de resolución SIIGO · si la ubicación es de
        // categoría "venta" (puede facturar) y el usuario no eligió resolución
        // explícitamente, intentamos auto-asignar la default. Esto evita que
        // Aracely tenga que mapearlas una por una.
        $autoAsignada = false;
        $categoriaVenta = \App\Modules\Dropi\Enums\CategoriaUbicacion::from($datos['categoria'])->disponibleParaVenta();
        if (empty($datos['siigo_resolution_id']) && $categoriaVenta) {
            $defaultId = (int) setting('siigo.resolucion_fv_default_id', 0);
            if ($defaultId > 0) {
                $resol = SiigoCatalogo::where('tipo', 'resolutions-fv')->where('codigo', (string) $defaultId)->first();
            } else {
                // Sin default marcada: si solo hay 1 resolución FV, usar esa.
                $resoluciones = SiigoCatalogo::where('tipo', 'resolutions-fv')->get();
                $resol = $resoluciones->count() === 1 ? $resoluciones->first() : null;
            }
            if ($resol) {
                $datos['siigo_resolution_id'] = (int) $resol->codigo;
                $datos['siigo_resolution_name'] = $resol->payload['name'] ?? $resol->nombre;
                $datos['siigo_resolution_prefix'] = $resol->payload['prefix'] ?? null;
                $autoAsignada = true;
            }
        }

        $u = ! empty($datos['id'])
            ? InventarioUbicacion::findOrFail($datos['id'])
            : new InventarioUbicacion();

        $wasNew = empty($datos['id']);
        $siigoDataChanged = ! $wasNew && (
            ($u->getOriginal('codigo') !== ($datos['codigo'] ?? $u->codigo))
            || ($u->getOriginal('nombre') !== ($datos['nombre'] ?? $u->nombre))
        );

        $u->fill($datos);
        $u->save();

        /*
         * Acá iba un push automático que creaba la bodega en SIIGO.
         *
         * No puede funcionar: `/v1/warehouses` es un catálogo de **sólo
         * lectura**. El GET responde 200 —de ahí salieron las 43 bodegas que
         * tenemos cacheadas— y el POST responde `404 Resource not found`. No
         * es la credencial: un 401 se ve distinto. Y la prueba es que ninguna
         * ubicación propia del ERP tiene `siigo_id`; las únicas que lo tienen
         * son las `SIIGO-xx` que creó el importador trayéndolas de allá.
         *
         * Encima no era deseable aunque funcionara: se disparaba para toda
         * ubicación activa, y 20 de las 21 sin mapear son racks, niveles,
         * zonas de avería y reservas —granularidad interna de bodega— que no
         * tienen nada que hacer como bodegas en la contabilidad.
         *
         * El camino que sí existe está en el formulario: el selector «Bodega
         * SIIGO» enlaza la ubicación con una de las que ya trajimos. Se crean
         * en SIIGO y acá se eligen.
         */

        /*
         * UBIC-8 · a quien se nombra responsable y no tiene NINGÚN papel en
         * bodega se le da el de AdminBodega, para que la ubicación no quede a
         * cargo de alguien que no puede ni abrirla.
         *
         * Pero a un Alistador o a un Despachador NO se los promueve, y eso
         * cambió el 17-nov: desde que ver la cola, el kardex y el stock usa
         * `bodegasQueVeIds()` —que hereda por responsable sin pedir rol—, ya no
         * hace falta promoverlos para que vean su trabajo. Y promoverlos sí
         * tenía precio: AdminBodega puede mover inventario entre las
         * ubicaciones de su sede, incluidas las de avería y bajas, así que un
         * alistador promovido podía devolver a stock vendible una caja dada de
         * baja sin segunda firma. Nombrar a alguien responsable de un estante
         * no puede ser la puerta por la que se entrega ese poder.
         */
        $promovido = null;
        if (! empty($datos['responsable_user_id'])) {
            $resp = User::find($datos['responsable_user_id']);
            $yaEsDeBodega = $resp?->hasAnyRole(['AdminBodega', 'Alistador', 'Despachador', 'Aracely', 'Gerencia']);
            if ($resp && ! $yaEsDeBodega) {
                $resp->assignRole('AdminBodega');
                $promovido = $resp->name;
            }
        }

        $msg = $datos['id'] ? "Ubicación {$u->codigo} actualizada." : "Ubicación {$u->codigo} creada.";
        if ($autoAsignada) {
            $msg .= " Resolución SIIGO auto-asignada: [{$u->siigo_resolution_prefix}] {$u->siigo_resolution_name}.";
        }
        if ($promovido) {
            $msg .= " {$promovido} fue promovido a Admin Bodega.";
        }
        return response()->json([
            'ok' => true,
            'id' => $u->id,
            'auto_asignada' => $autoAsignada,
            'promovido' => $promovido,
            'mensaje' => $msg,
        ]);
    }

    /**
     * El botón "Enviar a SIIGO" ya no manda nada: explica qué hacer.
     *
     * Creaba la bodega con `POST /v1/warehouses`, que responde
     * `404 Resource not found` — ese catálogo es de sólo lectura, el GET sí
     * funciona y de ahí salieron las 43 bodegas cacheadas. El mensaje de
     * antes decía «Creación encolada · el ID aparecerá al terminar», y ese id
     * no iba a aparecer nunca: ninguna ubicación propia del ERP tiene
     * `siigo_id`, sólo las que vinieron importadas.
     *
     * Las bodegas se crean en SIIGO y acá se eligen con el selector «Bodega
     * SIIGO» del formulario.
     */
    public function enviarASiigo(Request $r, int $id): JsonResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403);
        $u = InventarioUbicacion::findOrFail($id);

        if ($u->siigo_id) {
            return response()->json([
                'ok' => true,
                'mensaje' => "«{$u->codigo}» ya está enlazada con la bodega SIIGO #{$u->siigo_id}.",
            ]);
        }

        return response()->json([
            'ok' => false,
            'mensaje' => 'SIIGO no deja crear bodegas desde fuera: hay que crearla en SIIGO, '
                .'correr "Sincronizar catálogos" y después elegirla en el campo «Bodega SIIGO» de esta ubicación. '
                .'Si es un rack o una zona de avería, no lleva bodega de SIIGO.',
        ], 422);
    }

    /**
     * UBIC-6 · Marca una resolución SIIGO como DEFAULT para auto-asignación.
     * Se guarda en settings y aplica a ubicaciones de categoría venta nuevas.
     */
    public function marcarResolucionDefault(Request $r): JsonResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403);
        $datos = $r->validate(['siigo_resolution_id' => ['nullable', 'integer']]);
        $id = (int) ($datos['siigo_resolution_id'] ?? 0);
        \App\Support\Reglas::set('siigo.resolucion_fv_default_id', $id > 0 ? $id : null);
        return response()->json([
            'ok' => true,
            'mensaje' => $id > 0
                ? "Resolución #{$id} marcada como default para auto-asignar a nuevas ubicaciones de venta."
                : "Default limpiado · nuevas ubicaciones no tendrán resolución auto-asignada.",
        ]);
    }

    public function eliminar(Request $r, int $id): JsonResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403);

        $u = InventarioUbicacion::findOrFail($id);
        $mov = $u->movimientos()->exists();
        // En vez de romper el kardex, desactivamos y damos feedback claro.
        if ($mov) {
            $u->activa = false;
            $u->save();
            return response()->json([
                'ok' => true,
                'desactivada' => true,
                'mensaje' => "La ubicación {$u->codigo} tiene movimientos históricos · se marcó como INACTIVA en lugar de borrarse.",
            ]);
        }
        $codigo = $u->codigo;
        $u->delete();
        return response()->json(['ok' => true, 'mensaje' => "Ubicación {$codigo} eliminada."]);
    }

    public function toggleActiva(Request $r, int $id): JsonResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403);
        $u = InventarioUbicacion::findOrFail($id);
        $u->activa = ! $u->activa;
        $u->save();
        return response()->json(['ok' => true, 'activa' => (bool) $u->activa]);
    }

    /**
     * UBIC-4 · Trae de SIIGO las resoluciones DIAN activas de facturas venta.
     * Primero sincroniza document-types (para refrescar la base) y luego
     * extrae las FV al catálogo propio. Devuelve el nuevo listado para que
     * el front refresque el dropdown sin recargar.
     */
    public function sincronizarResoluciones(Request $r): JsonResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403);
        try {
            $svc = app(\App\Modules\Siigo\Services\SiigoService::class);
            $resumen = $svc->sincronizarCatalogos();
            $resoluciones = \App\Modules\Siigo\Models\SiigoCatalogo::where('tipo', 'resolutions-fv')
                ->get()
                ->map(fn ($c) => [
                    'id' => (int) $c->codigo,
                    'nombre' => $c->nombre,
                    'prefix' => $c->payload['prefix'] ?? null,
                ])->values()->all();
            return response()->json([
                'ok' => true,
                'mensaje' => "Trajimos {$resumen['resolutions-fv']} resoluciones de factura venta desde SIIGO.",
                'resoluciones' => $resoluciones,
                'resumen' => $resumen,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Error consultando SIIGO: '.$e->getMessage(),
            ], 500);
        }
    }
}
