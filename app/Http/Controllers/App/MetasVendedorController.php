<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\MetaVendedor;
use App\Models\User;
use App\Modules\Crm\Actions\CumplimientoDeMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las metas del equipo comercial, mes a mes.
 *
 * El panel del vendedor mostraba lo vendido y nada contra qué compararlo, así
 * que «$4.120.000 este mes» no decía si iba bien o mal. El vendedor se
 * enteraba en la reunión de fin de mes, que es cuando ya no se puede hacer
 * nada: si faltaban dos millones, el día 12 había tiempo de salir a buscarlos.
 *
 * Ponerlas es de quien manda, no del vendedor: una meta que se pone uno mismo
 * no es una meta. Verlas, en cambio, las ve cada quien en su panel.
 */
class MetasVendedorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // Una meta que se pone el propio vendedor no es una meta.
                abort_unless(Permisos::esRoot($r->user()), 403,
                    'Las metas las define Gerencia.');

                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        $anio = (int) $r->input('anio', now()->year);
        $mes = (int) $r->input('mes', now()->month);

        /*
         * Sin el permiso sembrado, `User::permission()` revienta con 500.
         *
         * Spatie tira `PermissionDoesNotExist` cuando el permiso no está en la
         * tabla, y ese es justo el estado que el resto de la autorización sí
         * tolera: `Permisos::puede()` se cae a los roles de la matriz cuando no
         * hay permisos sembrados. Acá no, y la pantalla entera se caía en una
         * instalación recién montada —o en producción, donde el sembrador
         * todavía no corrió—. Se usa la misma salida que usa todo lo demás.
         */
        $vendedores = Permisos::usuariosQuePueden('vendedor')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (User $u) use ($anio, $mes) {
                $meta = MetaVendedor::para($u->id, $anio, $mes);
                $c = CumplimientoDeMeta::run($u->id, $anio, $mes);

                return [
                    'id' => $u->id,
                    'nombre' => $u->name,
                    'meta' => (float) ($meta?->monto ?? 0),
                    'meta_pedidos' => $meta?->pedidos,
                    'notas' => $meta?->notas,
                    'vendido' => $c['vendido'],
                    'cumplimiento' => $c['cumplimiento'],
                    'va_en_ritmo' => $c['va_en_ritmo'],
                ];
            })
            ->values();

        return Inertia::render('Vendedor/Metas', [
            'anio' => $anio,
            'mes' => $mes,
            'vendedores' => $vendedores,
            'totales' => [
                'meta' => round($vendedores->sum('meta'), 2),
                'vendido' => round($vendedores->sum('vendido'), 2),
                'sin_meta' => $vendedores->where('meta', 0)->count(),
            ],
            // La línea de referencia: cuánto del mes ya pasó, en días hábiles.
            'avance_del_mes' => CumplimientoDeMeta::avanceDelMesDe($anio, $mes),
        ]);
    }

    public function guardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'metas' => ['present', 'array'],
            'metas.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'metas.*.monto' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'metas.*.pedidos' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'metas.*.notas' => ['nullable', 'string', 'max:255'],
        ]);

        $puestas = 0;
        $quitadas = 0;

        DB::transaction(function () use ($data, $r, &$puestas, &$quitadas) {
            foreach ($data['metas'] as $m) {
                $monto = (float) ($m['monto'] ?? 0);

                /*
                 * Meta en cero = sin meta. Se borra la fila en vez de guardar
                 * un cero, porque «meta de $0» cumpliría siempre al 100% y el
                 * tablero mostraría a todo el mundo en verde.
                 */
                if ($monto <= 0) {
                    $quitadas += MetaVendedor::where('user_id', $m['user_id'])
                        ->delMes($data['anio'], $data['mes'])->delete();

                    continue;
                }

                MetaVendedor::updateOrCreate(
                    ['user_id' => $m['user_id'], 'anio' => $data['anio'], 'mes' => $data['mes']],
                    [
                        'monto' => $monto,
                        'pedidos' => $m['pedidos'] ?? null,
                        'notas' => $m['notas'] ?? null,
                        'creado_por' => $r->user()?->id,
                    ],
                );
                $puestas++;
            }
        });

        $msg = $puestas > 0 ? "{$puestas} meta(s) guardada(s)." : '';
        if ($quitadas > 0) {
            $msg .= " {$quitadas} quedaron sin meta.";
        }

        return back()->with('success', trim($msg) ?: 'No hubo cambios.');
    }

    /**
     * Copia las metas de un mes al siguiente.
     *
     * Sin esto, poner las metas de ocho vendedores todos los meses es teclear
     * ocho números cada treinta días, y lo que se hace a mano todos los meses
     * termina sin hacerse.
     */
    public function copiarDelMesAnterior(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $origen = \Illuminate\Support\Carbon::create($data['anio'], $data['mes'], 1)->subMonth();

        $previas = MetaVendedor::delMes((int) $origen->year, (int) $origen->month)->get();

        if ($previas->isEmpty()) {
            return back()->with('warning', 'El mes anterior no tiene metas que copiar.');
        }

        $copiadas = 0;

        DB::transaction(function () use ($previas, $data, $r, &$copiadas) {
            foreach ($previas as $p) {
                // No pisa lo que ya se definió para este mes: quien ya puso una
                // meta distinta tenía una razón.
                $existe = MetaVendedor::where('user_id', $p->user_id)
                    ->delMes($data['anio'], $data['mes'])->exists();

                if ($existe) {
                    continue;
                }

                MetaVendedor::create([
                    'user_id' => $p->user_id,
                    'anio' => $data['anio'], 'mes' => $data['mes'],
                    'monto' => $p->monto, 'pedidos' => $p->pedidos,
                    'notas' => $p->notas,
                    'creado_por' => $r->user()?->id,
                ]);
                $copiadas++;
            }
        });

        return back()->with('success', $copiadas > 0
            ? "{$copiadas} meta(s) copiadas del mes anterior. Revisalas antes de dejarlas."
            : 'Todos los vendedores ya tenían meta este mes.');
    }
}
