<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tesoreria\Models\FlujoConcepto;
use App\Modules\Tesoreria\Models\FlujoProyeccion;
use App\Modules\Tesoreria\Services\FlujoDeCaja;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El flujo de caja, semanal o mensual.
 *
 * Reemplaza `flujo_de_caja_semanal_V8.xlsx`, conservando su estructura para
 * que quien lo llena hoy reconozca la pantalla. Lo que cambia es la columna
 * «Real»: la calcula el ERP en vez de teclearse cada semana.
 *
 * Capturar la proyección NO es de contabilidad: es de cada área, que es lo
 * que la hoja «Responsables» del Excel ya dice. Por eso la pantalla se abre
 * con el acceso de contabilidad para ver todo, pero guardar un renglón sólo
 * lo puede hacer su responsable —o gerencia, que ve y decide sobre todo—.
 */
class FlujoDeCajaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                $u = $r->user();
                // Entra quien lleva contabilidad, y también cualquiera que
                // tenga renglones a su cargo: es su tarea semanal.
                $tieneRenglones = $u && FlujoConcepto::activos()
                    ->where('responsable_id', $u->id)->exists();

                abort_unless(\App\Auth\Permisos::puede($u, 'flujo_caja') || $tieneRenglones, 403,
                    'El flujo de caja es de contabilidad y de quienes informan sus renglones.');

                return $next($r);
            }),
        ];
    }

    public function index(Request $r, FlujoDeCaja $servicio): Response
    {
        $tipo = in_array($r->query('tipo'), ['semana', 'mes'], true) ? $r->query('tipo') : 'semana';
        $cuantos = $tipo === 'mes' ? 6 : 8;

        $desde = $r->date('desde') ?? now('America/Bogota');
        $grilla = $servicio->grilla($tipo, Carbon::parse($desde), $cuantos);

        $u = $r->user();

        return Inertia::render('Tesoreria/FlujoDeCaja', [
            'tipo' => $tipo,
            'desde' => Carbon::parse($desde)->toDateString(),
            'periodos' => $grilla['periodos'],
            'grupos' => $grilla['grupos'],
            'resumen' => $servicio->resumen($grilla),
            // Lo que ESTA persona todavía no ha informado. Es la lista que
            // hoy se pide por WhatsApp cada lunes.
            'misPendientes' => $servicio->pendientesDe($u->id, $tipo, Carbon::parse($desde), $cuantos),
            'puedoTodo' => (bool) ($u->esRoot() || $u->esContable()),
            'misConceptos' => FlujoConcepto::activos()->where('responsable_id', $u->id)->pluck('id'),
            'origenes' => collect(FlujoConcepto::ORIGENES)
                ->map(fn ($t, $k) => ['valor' => $k, 'texto' => $t])->values(),
            'grupos_catalogo' => collect(FlujoConcepto::GRUPOS)
                ->map(fn ($t, $k) => ['valor' => $k, 'texto' => $t])->values(),
            'personas' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Guardar la proyección de un renglón en un período.
     *
     * Sólo su responsable, o gerencia. Si cualquiera pudiera escribir en
     * cualquier renglón, la columna «responsable» del Excel sería decoración
     * y nadie podría responder por su número.
     */
    public function guardarProyeccion(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'concepto_id' => ['required', 'integer', 'exists:flujo_conceptos,id'],
            'periodo_tipo' => ['required', Rule::in(['semana', 'mes'])],
            'periodo' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'min:0'],
            'real_manual' => ['nullable', 'numeric'],
            'notas' => ['nullable', 'string', 'max:300'],
        ]);

        $concepto = FlujoConcepto::findOrFail($data['concepto_id']);
        $u = $r->user();

        abort_unless(
            $u->esRoot() || $u->esContable() || $concepto->responsable_id === $u->id,
            403,
            "«{$concepto->nombre}» lo informa ".($concepto->responsable?->name ?? 'otra área').'.',
        );

        // Un concepto con origen calcula su real: aceptar un real a mano
        // dejaría dos verdades para la misma celda.
        if ($concepto->origen && array_key_exists('real_manual', $data) && $data['real_manual'] !== null) {
            return back()->withErrors([
                'real_manual' => "«{$concepto->nombre}» calcula su real desde el ERP: no se teclea.",
            ]);
        }

        FlujoProyeccion::updateOrCreate(
            [
                'concepto_id' => $concepto->id,
                'periodo_tipo' => $data['periodo_tipo'],
                'periodo' => $data['periodo'],
            ],
            [
                'monto' => $data['monto'],
                'real_manual' => $concepto->origen ? null : ($data['real_manual'] ?? null),
                'notas' => $data['notas'] ?? null,
                'capturado_por' => $u->id,
            ],
        );

        return back()->with('flash', ['type' => 'success',
            'message' => "«{$concepto->nombre}» actualizado."]);
    }

    /** Alta y edición de los renglones del flujo. */
    public function guardarConcepto(Request $r): RedirectResponse
    {
        // Crear un renglón nuevo y corregir uno existente son permisos
        // distintos: agregar un concepto cambia la forma del informe.
        abort_unless(
            \App\Auth\Permisos::puedeAccion($r->user(), 'flujo_caja', $r->filled('id') ? 'editar' : 'crear'),
            403,
            'No tenés permiso para cambiar los renglones del flujo.',
        );

        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:flujo_conceptos,id'],
            'codigo' => ['required', 'string', 'max:40',
                Rule::unique('flujo_conceptos', 'codigo')->ignore($r->input('id'))],
            'nombre' => ['required', 'string', 'max:150'],
            'grupo' => ['required', Rule::in(array_keys(FlujoConcepto::GRUPOS))],
            'tipo' => ['required', Rule::in(['F', 'V'])],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'valor_fijo_mensual' => ['nullable', 'numeric', 'min:0'],
            'origen' => ['nullable', Rule::in(array_keys(FlujoConcepto::ORIGENES))],
            'orden' => ['nullable', 'integer', 'min:0'],
            'activo' => ['boolean'],
        ]);

        $data['activo'] ??= true;
        $data['orden'] ??= 0;

        if (! empty($data['id'])) {
            FlujoConcepto::whereKey($data['id'])->update(collect($data)->except('id')->all());
            $msg = "Renglón «{$data['nombre']}» actualizado.";
        } else {
            FlujoConcepto::create(collect($data)->except('id')->all());
            $msg = "Renglón «{$data['nombre']}» creado.";
        }

        return back()->with('flash', ['type' => 'success', 'message' => $msg]);
    }
}
