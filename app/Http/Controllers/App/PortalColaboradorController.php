<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Models\Asignacion;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\ReporteAsignacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F7 · «Mi espacio»: lo que el empleado ve de sí mismo.
 *
 * «La idea es que el empleado pues tenga su parte también de recursos humanos
 * y que tenga también sus avisos» (Elena, 19-oct).
 *
 * Ve SUS datos, SUS documentos y lo que le entregaron. No ve su salario:
 * aparece en el contrato que firmó, no hace falta repetirlo acá, y una
 * pantalla con el sueldo abierta en la bodega es un problema.
 *
 * Todo cuelga de su `user_id`, no de un parámetro de la URL: no hay forma de
 * pedir el espacio de otra persona porque nunca se pregunta de quién es.
 */
class PortalColaboradorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'portal_colaborador'), 403);

            return $next($r);
        }, except: ['mostrarActivacion', 'activar'])];
    }

    public function miEspacio(Request $r): Response
    {
        $e = $this->miFicha($r);

        return Inertia::render('Rrhh/Portal/MiEspacio', [
            'yo' => [
                'nombre' => $e->nombre,
                'documento' => $e->tipo_documento.' '.$e->numero_documento,
                'cargo' => $e->cargo,
                'area' => $e->area,
                'ingreso' => $e->fecha_ingreso?->toDateString(),
                'tipo_contrato' => $e->tipo_contrato,
                'email' => $e->email,
                'telefono' => $e->telefono,
                'induccion' => $e->estado_induccion,
                'induccion_at' => $e->induccion_at?->toDateString(),
                'induccion_temas' => $e->induccion_temas,
                'prueba_hasta' => $e->prueba_hasta?->toDateString(),
                'prueba_dias' => $e->diasDePrueba(),
                'prueba_resultado' => $e->prueba_resultado,
                'afiliaciones' => $e->contratacion ? array_filter([
                    'EPS' => $e->contratacion->eps,
                    'ARL' => $e->contratacion->arl,
                    'Pensión' => $e->contratacion->pension,
                    'Caja' => $e->contratacion->caja,
                ]) : null,
            ],
            'asignaciones' => $e->asignaciones()->with('reportes')->get()->map(fn ($a) => [
                'id' => $a->id,
                'tipo' => $a->tipoLabel(),
                'rotulo' => $a->rotulo,
                'cantidad' => $a->cantidad,
                'entrega' => $a->fecha_entrega?->toDateString(),
                'estado' => $a->estado,
                'estado_label' => $a->estadoLabel(),
                'pendiente' => $a->estaPendiente(),
                'reportes' => $a->reportes->map(fn ($x) => [
                    'novedad' => $x->novedadLabel(),
                    'detalle' => $x->detalle,
                    'estado' => $x->estado,
                    'respuesta' => $x->respuesta,
                    'cuando' => $x->created_at?->format('Y-m-d'),
                ])->values(),
            ])->values(),
            'novedades' => collect(ReporteAsignacion::NOVEDADES)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            // Sólo los documentos de su carpeta: son suyos, y además es lo
            // que la gente viene a buscar (la certificación laboral, el
            // contrato) sin tener que pedírselo a nadie.
            'documentos' => $e->documentos()->with('subidoPor:id,name')->latest('id')->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'tipo' => $d->tipo_label,
                    'nombre' => $d->nombre,
                    'cuando' => $d->created_at?->toDateString(),
                ])->values(),
        ]);
    }

    /** El colaborador avisa que algo que le entregaron tiene una novedad. */
    public function reportarNovedad(Request $r, int $asignacion): RedirectResponse
    {
        $data = $r->validate([
            'novedad' => ['required', 'in:'.implode(',', array_keys(ReporteAsignacion::NOVEDADES))],
            'detalle' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['detalle' => 'qué pasó']);

        $e = $this->miFicha($r);

        // Suya o de nadie: no se pregunta de quién es, se exige que sea suya.
        $a = Asignacion::where('empleado_id', $e->id)->findOrFail($asignacion);

        $reporte = ReporteAsignacion::create($data + [
            'asignacion_id' => $a->id,
            'reportado_por_id' => $r->user()->id,
            'estado' => 'abierto',
        ]);

        $a->anotar('reporte', "{$reporte->novedadLabel()}: {$data['detalle']}");

        $this->avisarDelReporte($e, $a, $reporte);

        return back()->with('success', 'Quedó reportado. Te vamos a responder por acá.');
    }

    /** Descarga uno de SUS documentos. */
    public function miDocumento(Request $r, int $documento)
    {
        $e = $this->miFicha($r);
        $d = $e->documentos()->findOrFail($documento);

        abort_unless(\Illuminate\Support\Facades\Storage::exists($d->archivo_path), 404,
            'Ese archivo ya no está.');

        return \Illuminate\Support\Facades\Storage::response(
            $d->archivo_path,
            str($d->nombre)->slug().'.'.pathinfo($d->archivo_path, PATHINFO_EXTENSION),
            ['Content-Disposition' => 'inline'],
        );
    }

    // ───────────────── Activación de la cuenta (sin sesión) ─────────────────

    /**
     * La pantalla donde el empleado pone su contraseña por primera vez.
     *
     * No pide sesión: justamente todavía no tiene. El enlace se lo manda
     * Gestión Humana por WhatsApp, que es como se comunican acá.
     */
    public function mostrarActivacion(Request $r): Response
    {
        return Inertia::render('Rrhh/Portal/Activar', [
            'token' => (string) $r->query('token'),
        ]);
    }

    public function activar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], ['password' => 'contraseña']);

        $e = Empleado::whereNotNull('activacion_token')
            ->where('activacion_token', hash('sha256', $data['token']))
            ->first();

        // Mismo mensaje para «no existe» y «venció»: decir cuál de las dos es
        // le confirma a quien prueba tokens que acertó uno.
        if (! $e || ! $e->activacion_expira_at || $e->activacion_expira_at->isPast()) {
            return back()->withErrors([
                'token' => 'Este enlace ya no sirve. Pedile a Gestión Humana que te mande uno nuevo.',
            ]);
        }

        DB::transaction(function () use ($e, $data) {
            $e->user->update(['password' => Hash::make($data['password'])]);
            // El token se quema: un enlace que sirve dos veces es un enlace
            // que alguien más puede usar después.
            $e->update(['activacion_token' => null, 'activacion_expira_at' => null]);
            $e->anotar('portal_activado', "{$e->nombre} activó su acceso al portal");
        });

        return redirect()->route('app.login')
            ->with('success', 'Listo. Entrá con tu correo y la contraseña que acabás de poner.');
    }

    // ───────────────────────────── internos ─────────────────────────────

    /**
     * La ficha de quien está mirando.
     *
     * Si alguien tiene el rol pero no está enlazado a un empleado, es un error
     * de configuración y no una pantalla vacía: se dice.
     */
    private function miFicha(Request $r): Empleado
    {
        $e = Empleado::with('contratacion')->where('user_id', $r->user()->id)->first();

        abort_unless($e, 403,
            'Tu usuario no está enlazado a una ficha de empleado. Avisale a Gestión Humana.');

        return $e;
    }

    /**
     * A quién le llega el aviso depende de qué pasó.
     *
     * Elena lo separó: «si es un caso de deterioro y es un tema de
     * mantenimiento, lo maneja el líder del área. Pero si es un tema ya de un
     * hurto o algo, eso pasa a gestión humana porque yo tengo que llamar a
     * procesos disciplinarios».
     */
    private function avisarDelReporte(Empleado $e, Asignacion $a, ReporteAsignacion $reporte): void
    {
        $destinos = collect();

        if ($jefe = $e->jefeDelArea()) {
            $destinos->push($jefe);
        }

        if ($reporte->esDeGestionHumana() || $destinos->isEmpty()) {
            $destinos = $destinos->merge(
                User::permission(Permisos::permiso('rrhh'))->pluck('id')
            );
        }

        $destinos->unique()->each(fn ($id) => NotificacionErp::crear([
            'user_id' => $id,
            'tipo' => 'rrhh_asignacion',
            'titulo' => "{$reporte->novedadLabel()} · {$a->rotulo}",
            'mensaje' => "{$e->nombre} ({$e->cargo}): {$reporte->detalle}",
            'url' => '/app/rrhh/asignaciones',
            'icono' => 'heroicon-o-wrench-screwdriver',
            'color' => $reporte->esDeGestionHumana() ? 'danger' : 'warning',
        ]));
    }

    /**
     * El rol `Colaborador`, con su permiso puesto.
     *
     * Crearlo con `firstOrCreate` a secas lo dejaba SIN permisos, así que la
     * primera persona a la que se le creaba el acceso entraba y veía un
     * Forbidden —el mismo defecto que ya había aparecido con los otros roles
     * nuevos—. Se vio en pantalla: la prueba no lo cazó porque sembraba los
     * permisos a mano antes de llamar a esto.
     *
     * Se asegura el permiso siempre, no sólo al crear el rol: puede existir
     * desde antes de que `portal_colaborador` se agregara a la matriz.
     */
    private static function rolColaborador(): \Spatie\Permission\Models\Role
    {
        $rol = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Colaborador', 'guard_name' => 'web',
        ]);

        $permiso = Permisos::permiso('portal_colaborador');

        if (! $rol->hasPermissionTo($permiso)) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => $permiso, 'guard_name' => 'web',
            ]);
            $rol->givePermissionTo($permiso);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $rol;
    }

    /**
     * Crea el acceso al portal de un empleado y devuelve el enlace de
     * activación. Lo llama Gestión Humana desde la ficha.
     *
     * Devuelve el enlace en vez de sólo mandarlo por correo porque acá el
     * correo del empleado muchas veces no existe o no lo revisa: Gestión
     * Humana lo pega en el WhatsApp y listo.
     *
     * @return array{link: string, creado: bool}
     */
    public static function crearAcceso(Empleado $e, string $email): array
    {
        $token = Str::random(64);

        $user = DB::transaction(function () use ($e, $email, $token) {
            $user = $e->user ?? User::where('email', $email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $e->nombre,
                    'email' => $email,
                    // Una contraseña que nadie conoce: la real la pone la
                    // persona al activar. Dejarla en blanco permitiría entrar.
                    'password' => Hash::make(Str::random(40)),
                ]);
            }

            if (! $user->hasRole('Colaborador')) {
                $user->assignRole(self::rolColaborador());
            }

            $e->update([
                'user_id' => $user->id,
                'email' => $e->email ?: $email,
                'activacion_token' => hash('sha256', $token),
                // Siete días: Gestión Humana manda el enlace por WhatsApp y la
                // persona lo puede abrir al otro día, o el lunes.
                'activacion_expira_at' => now()->addDays(7),
                'acceso_creado_at' => now(),
            ]);

            $e->anotar('portal_acceso', "Se creó el acceso al portal para {$email}");

            return $user;
        });

        return [
            'link' => url('/app/activar-portal?token='.$token),
            'creado' => (bool) $user,
        ];
    }
}
