<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDeclaracion;
use App\Modules\Compras\Models\ImportacionInspeccion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SeguimientoContenedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Armar y seguir el contenedor: sus órdenes, sus datos, sus etapas, las
 * declaraciones y las inspecciones. Sólo acciones: la pantalla es la de la
 * importación, con la misma casilla que sus gastos y su liquidación.
 */
class ImportacionSeguimientoController extends Controller implements HasMiddleware
{
    public function __construct(private SeguimientoContenedor $seguimiento) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(Permisos::puede($r->user(), 'compras_importacion'), 403, 'Esta parte de Compras necesita su propio acceso.');

                return $next($r);
            }),
        ];
    }

    public function actualizar(Request $r, Importacion $importacion): RedirectResponse
    {
        if (in_array($importacion->estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true)) {
            return back()->with('error', 'El contenedor ya está liquidado: sus datos no se pueden cambiar.');
        }

        $importacion->update($r->validate([
            'contenedor' => ['nullable', 'string', 'max:30'],
            'bl_awb' => ['nullable', 'string', 'max:60'],
            'proveedor_pais' => ['nullable', 'string', 'max:60'],
            'puerto_origen' => ['nullable', 'string', 'max:60'],
            'puerto_destino' => ['nullable', 'string', 'max:60'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'tasa_cambio_liquidacion' => ['nullable', 'numeric', 'min:0'],
            'eta' => ['nullable', 'date'],
        ]));

        return back()->with('success', 'Datos del contenedor actualizados.');
    }

    public function ligarOrden(Request $r, Importacion $importacion): RedirectResponse
    {
        $data = $r->validate(['orden_id' => ['required', 'integer', 'exists:compras_ordenes,id']]);
        $orden = OrdenCompra::findOrFail($data['orden_id']);

        return $this->intentar(
            fn () => $this->seguimiento->ligarOrden($importacion, $orden),
            "Orden {$orden->numero} agregada al contenedor.",
        );
    }

    public function soltarOrden(Importacion $importacion, OrdenCompra $orden): RedirectResponse
    {
        return $this->intentar(
            fn () => $this->seguimiento->soltarOrden($importacion, $orden),
            "Orden {$orden->numero} quitada del contenedor.",
        );
    }

    public function marcarEtapa(Request $r, Importacion $importacion): RedirectResponse
    {
        $data = $r->validate([
            'etapa' => ['required', Rule::enum(EtapaContenedor::class)],
            'fecha' => ['required', 'date'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);
        $etapa = EtapaContenedor::from($data['etapa']);

        return $this->intentar(
            fn () => $this->seguimiento->marcarEtapa($importacion, $etapa, Carbon::parse($data['fecha']), $data['notas'] ?? null, $r->user()?->id),
            "Etapa «{$etapa->label()}» marcada.",
        );
    }

    public function quitarEtapa(Importacion $importacion, string $etapa): RedirectResponse
    {
        $caso = EtapaContenedor::tryFrom($etapa);
        abort_unless($caso, 404);

        return $this->intentar(
            fn () => $this->seguimiento->quitarEtapa($importacion, $caso),
            "Etapa «{$caso->label()}» quitada.",
        );
    }

    public function guardarDeclaracion(Request $r, Importacion $importacion, ?ImportacionDeclaracion $declaracion = null): RedirectResponse
    {
        abort_if($declaracion && $declaracion->importacion_id !== $importacion->id, 404);

        $data = $r->validate([
            'numero' => ['nullable', 'string', 'max:40'],
            'selectividad' => ['required', Rule::in(array_keys(ImportacionDeclaracion::SELECTIVIDAD))],
            'fecha_cita' => ['nullable', 'date'],
            'fecha_levante' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        // «Automática» es justamente que la DIAN da el levante sin más trámite.
        if ($data['selectividad'] === 'automatica' && empty($data['fecha_levante'])) {
            $data['fecha_levante'] = now('America/Bogota')->toDateString();
        }

        if ($declaracion) {
            $declaracion->update($data);
        } else {
            $importacion->declaraciones()->create([...$data, 'registrado_por' => $r->user()?->id]);
        }

        return back()->with('success', 'Declaración guardada.');
    }

    public function quitarDeclaracion(Importacion $importacion, ImportacionDeclaracion $declaracion): RedirectResponse
    {
        abort_if($declaracion->importacion_id !== $importacion->id, 404);
        $declaracion->delete();

        return back()->with('success', 'Declaración quitada.');
    }

    public function registrarInspeccion(Request $r, Importacion $importacion): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', Rule::in(array_keys(ImportacionInspeccion::TIPOS))],
            'fecha' => ['required', 'date'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $importacion->inspecciones()->create([...$data, 'registrado_por' => $r->user()?->id]);
        $this->seguimiento->avisarInspeccion($importacion, ImportacionInspeccion::TIPOS[$data['tipo']], $data['notas'] ?? null);

        return back()->with('success', 'Novedad registrada. Compras ya recibió el aviso.');
    }

    public function resolverInspeccion(Importacion $importacion, ImportacionInspeccion $inspeccion): RedirectResponse
    {
        abort_if($inspeccion->importacion_id !== $importacion->id, 404);
        $inspeccion->update(['resuelta_en' => now('America/Bogota')->toDateString()]);

        return back()->with('success', 'Novedad resuelta.');
    }

    /** Las reglas del seguimiento hablan en español: se le muestran tal cual a quien las tropieza. */
    private function intentar(callable $accion, string $exito): RedirectResponse
    {
        try {
            $accion();
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $exito);
    }
}
