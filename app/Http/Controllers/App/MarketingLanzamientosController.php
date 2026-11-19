<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Marketing\Models\Lanzamiento;
use App\Modules\Marketing\Models\LanzamientoComentario;
use App\Modules\Marketing\Models\LanzamientoItem;
use App\Modules\Marketing\Models\LanzamientoTarjeta;
use App\Modules\Marketing\Services\PlantillaLanzamiento;
use App\Modules\Marketing\Services\TableroLanzamiento;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Tablero de lanzamiento por contenedor («TABLERO GREAT BABY.pdf»): la lista de
 * contenedores con su avance y el tablero de 5 listas de cada uno. Las reglas
 * viven en `TableroLanzamiento`; aquí solo se traduce a la pantalla.
 */
class MarketingLanzamientosController extends Controller implements HasMiddleware
{
    public function __construct(private TableroLanzamiento $tablero) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(Permisos::puede($r->user(), 'marketing_lanzamientos'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        $importaciones = Importacion::query()
            ->latest('id')
            ->limit(100)
            ->get(['id', 'numero', 'contenedor', 'estado', 'eta']);

        $lanzamientos = Lanzamiento::whereIn('importacion_id', $importaciones->pluck('id'))
            ->withCount([
                'tarjetas',
                'tarjetas as finalizadas_count' => fn ($q) => $q->where('lista', 'finalizado'),
                'tarjetas as bloqueadas_count' => fn ($q) => $q->where('lista', 'bloqueado'),
                'tarjetas as por_aprobar_count' => fn ($q) => $q->where('lista', 'pendiente_aprobacion'),
            ])
            ->get()->keyBy('importacion_id');

        return Inertia::render('Marketing/Lanzamientos/Index', [
            'contenedores' => $importaciones->map(function (Importacion $i) use ($lanzamientos) {
                $l = $lanzamientos[$i->id] ?? null;

                return [
                    'importacion_id' => $i->id,
                    'numero' => $i->numero,
                    'contenedor' => $i->contenedor,
                    'estado' => $i->estado?->value,
                    'eta' => $i->eta?->toDateString(),
                    'lanzamiento' => $l ? [
                        'id' => $l->id,
                        'total' => $l->tarjetas_count,
                        'finalizadas' => $l->finalizadas_count,
                        'bloqueadas' => $l->bloqueadas_count,
                        'por_aprobar' => $l->por_aprobar_count,
                        'finalizado_en' => $l->finalizado_en?->toDateString(),
                    ] : null,
                ];
            }),
        ]);
    }

    /** Lo que Marketing necesita saber del contenedor, sin entrar a Compras. */
    private function desdeCompras(Lanzamiento $lanzamiento, ?User $u): ?array
    {
        $imp = $lanzamiento->importacion;
        if (! $imp) {
            return null;
        }
        $resumen = app(\App\Modules\Compras\Services\SeguimientoContenedor::class)->resumen($imp);
        $ordenes = $imp->ordenes()->with('items:id,orden_id,cantidad,cantidad_recibida')->get();

        return [
            'importacion_id' => $imp->id,
            'etapa' => $resumen['etapa_label'],
            'llegada_estimada' => $resumen['llegada_estimada'],
            'unidades' => (float) $ordenes->sum(fn ($o) => $o->items->sum('cantidad')),
            'recibidas' => (float) $ordenes->sum(fn ($o) => $o->items->sum('cantidad_recibida')),
            'puede_contenedor' => Permisos::puede($u, 'compras_importacion'),
            'puede_productos' => \App\Modules\Marketing\Models\MarketingCreativo::puedeGestionar($u),
        ];
    }

    /** Para los contenedores que existían antes del tablero. */
    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate(['importacion_id' => ['required', 'integer', 'exists:compras_importaciones,id']]);
        $l = $this->tablero->crearPara(Importacion::findOrFail($data['importacion_id']), $r->user());

        return redirect("/app/marketing/lanzamientos/{$l->id}")->with('success', 'Tablero de lanzamiento creado.');
    }

    public function show(Request $r, Lanzamiento $lanzamiento): Response
    {
        // Los contenedores que ya venían andando también quedan al día.
        if ($lanzamiento->importacion) {
            $this->tablero->alDiaConCompras($lanzamiento->importacion);
        }
        $lanzamiento->load([
            'importacion:id,numero,contenedor,estado,eta',
            'tarjetas.items.autor:id,name',
            'tarjetas.responsable:id,name',
            'tarjetas.bloqueoResponsable:id,name',
            'tarjetas.aprobador:id,name',
            'tarjetas.comentarios.autor:id,name',
        ]);
        $u = $r->user();
        $hoy = now()->startOfDay();

        return Inertia::render('Marketing/Lanzamientos/Show', [
            'lanzamiento' => [
                'id' => $lanzamiento->id,
                'numero' => $lanzamiento->importacion?->numero,
                'contenedor' => $lanzamiento->importacion?->contenedor,
                'eta' => $lanzamiento->importacion?->eta?->toDateString(),
                'finalizado_en' => $lanzamiento->finalizado_en?->toDateString(),
            ],
            'tarjetas' => $lanzamiento->tarjetas->map(function (LanzamientoTarjeta $t) use ($u, $hoy) {
                $plantilla = PlantillaLanzamiento::de($t->clave);

                return [
                    'id' => $t->id,
                    'numero' => $t->numero,
                    'clave' => $t->clave,
                    'titulo' => $t->titulo,
                    'area' => $t->area,
                    'area_label' => LanzamientoTarjeta::AREAS[$t->area] ?? $t->area,
                    'objetivo' => $plantilla['objetivo'] ?? null,
                    'entregable' => $plantilla['entregable'] ?? null,
                    'lista' => $t->lista,
                    'requiere_aprobacion' => $t->requiere_aprobacion,
                    'responsable' => $t->responsable ? ['id' => $t->responsable->id, 'nombre' => $t->responsable->name] : null,
                    'vence_en' => $t->vence_en?->toDateString(),
                    'vencida' => $t->vence_en && $t->lista !== 'finalizado' && $t->vence_en->lt($hoy),
                    'bloqueo' => $t->lista === 'bloqueado' ? [
                        'motivo' => $t->bloqueo_motivo,
                        'responsable' => $t->bloqueoResponsable?->name,
                        'accion' => $t->bloqueo_accion,
                    ] : null,
                    'aprobada' => $t->aprobada_en ? ['por' => $t->aprobador?->name, 'en' => $t->aprobada_en->format('Y-m-d H:i')] : null,
                    'finalizada_en' => $t->finalizada_en?->toDateString(),
                    'items' => $t->items->map(fn (LanzamientoItem $i) => [
                        'id' => $i->id,
                        'texto' => $i->texto,
                        'grupo' => $i->grupo,
                        'hecho' => $i->hecho_en !== null,
                        'por' => $i->autor?->name,
                        'en' => $i->hecho_en?->format('Y-m-d H:i'),
                    ]),
                    'comentarios' => $t->comentarios->map(fn (LanzamientoComentario $c) => [
                        'id' => $c->id,
                        'tipo' => $c->tipo,
                        'cuerpo' => $c->cuerpo,
                        'autor' => $c->autor?->name,
                        'en' => $c->created_at?->format('Y-m-d H:i'),
                    ]),
                    'puede_trabajar' => $this->tablero->puedeTrabajar($u, $t),
                ];
            }),
            'listas' => LanzamientoTarjeta::LISTAS,
            'puede_aprobar' => $this->tablero->puedeAprobar($u),
            'compras' => $this->desdeCompras($lanzamiento, $u),
            'paso' => app(\App\Modules\Marketing\Services\PasoDeMarketing::class)->deLanzamiento($lanzamiento, $u),
            'usuarios' => $this->usuariosDelTablero(),
        ]);
    }

    public function mover(Request $r, LanzamientoTarjeta $tarjeta): RedirectResponse
    {
        $data = $r->validate([
            'lista' => ['required', 'string'],
            'motivo' => ['nullable', 'string', 'max:1000'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'accion' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->intentar(fn () => $this->tablero->mover($tarjeta, $data['lista'], $r->user(), $data),
            'Tarjeta movida a '.(LanzamientoTarjeta::LISTAS[$data['lista']] ?? $data['lista']).'.');
    }

    public function aprobar(Request $r, LanzamientoTarjeta $tarjeta): RedirectResponse
    {
        return $this->intentar(fn () => $this->tablero->aprobar($tarjeta, $r->user()), "«{$tarjeta->titulo}» aprobada.");
    }

    public function devolver(Request $r, LanzamientoTarjeta $tarjeta): RedirectResponse
    {
        $data = $r->validate(['cambios' => ['required', 'string', 'max:1000']]);

        return $this->intentar(fn () => $this->tablero->devolver($tarjeta, $r->user(), $data['cambios']), 'Tarjeta devuelta a En proceso.');
    }

    public function actualizar(Request $r, LanzamientoTarjeta $tarjeta): RedirectResponse
    {
        $data = $r->validate([
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'vence_en' => ['nullable', 'date'],
        ]);

        return $this->intentar(fn () => $this->tablero->actualizar($tarjeta, $r->user(), $data), 'Tarjeta actualizada.');
    }

    public function marcar(Request $r, LanzamientoItem $item): RedirectResponse
    {
        $data = $r->validate(['hecho' => ['required', 'boolean']]);

        return $this->intentar(fn () => $this->tablero->marcarItem($item, (bool) $data['hecho'], $r->user()));
    }

    public function comentar(Request $r, LanzamientoTarjeta $tarjeta): RedirectResponse
    {
        $data = $r->validate(['cuerpo' => ['required', 'string', 'max:2000']]);

        return $this->intentar(fn () => $this->tablero->comentar($tarjeta, $r->user(), $data['cuerpo']), 'Comentario agregado.');
    }

    // ───────────────────────── internos ─────────────────────────

    private function intentar(\Closure $accion, ?string $ok = null): RedirectResponse
    {
        try {
            $accion();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $ok ? back()->with('success', $ok) : back();
    }

    /** Quienes pueden ser responsables de una tarjeta o de resolver un bloqueo. */
    private function usuariosDelTablero(): array
    {
        $roles = \Spatie\Permission\Models\Role::whereIn('name', ['Aracely', 'Gerencia', 'Marketing', 'Gerente', 'AdminBodega'])
            ->where('guard_name', 'web')->pluck('name')->all();

        return $roles
            ? User::role($roles)->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'nombre' => $u->name])->all()
            : [];
    }
}
