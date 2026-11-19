<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Support\OrigenContable;
use App\Modules\Inventario\Models\CargaInventario;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El documento de una carga masiva: qué entró, por qué y cómo quedó en el
 * libro. Es la respuesta a «subí 200 millones de inventario, ¿dónde lo veo en
 * contabilidad?» — acá están las referencias y el asiento, lado a lado.
 */
class CargasInventarioController extends Controller
{
    public function show(Request $request, CargaInventario $carga): Response
    {
        $u = $request->user();
        abort_unless($u?->esEquipoBodega() || $u?->esContable(), 403);

        $carga->load(['tercero:id,nombre_completo,razon_social,numero_documento', 'ubicacion:id,codigo,nombre', 'user:id,name']);

        $kardex = $carga->movimientosKardex()
            ->with(['producto:id,referencia,nombre', 'ubicacion:id,codigo,nombre'])
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'referencia' => $m->producto?->referencia,
                'producto' => $m->producto?->nombre,
                'ubicacion' => trim(($m->ubicacion?->codigo ?? '').' · '.($m->ubicacion?->nombre ?? ''), ' ·'),
                'cantidad' => (float) $m->cantidad,
                'costo_unit' => (float) ($m->costo_unit ?? 0),
                'valor' => round(abs((float) $m->cantidad) * (float) ($m->costo_unit ?? 0), 2),
            ]);

        $asiento = $carga->asiento()
            ->orderBy('id')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'fecha' => $l->fecha?->toDateString(),
                'cuenta' => $l->cuenta_puc,
                'descripcion' => $l->descripcion,
                'debe' => (float) $l->debe,
                'haber' => (float) $l->haber,
            ]);

        return Inertia::render('Inventario/Cargas/Show', [
            'carga' => [
                'id' => $carga->id,
                'numero' => $carga->numero,
                'fecha' => $carga->created_at?->timezone('America/Bogota')->format('Y-m-d H:i'),
                'motivo' => $carga->motivo->label(),
                'observacion' => $carga->observacion,
                'soporte' => $carga->soporte,
                'tercero' => $carga->tercero
                    ? trim(($carga->tercero->razon_social ?: $carga->tercero->nombre_completo).' · '.($carga->tercero->numero_documento ?? ''), ' ·')
                    : null,
                'ubicacion' => trim(($carga->ubicacion?->codigo ?? '').' · '.($carga->ubicacion?->nombre ?? ''), ' ·'),
                'archivo' => $carga->archivo_nombre,
                'canal' => $carga->canal,
                'usuario' => $carga->user?->name,
                'cuenta_contrapartida' => $carga->cuenta_contrapartida,
                'total_unidades' => (float) $carga->total_unidades,
                'total_valor' => (float) $carga->total_valor,
                'referencias' => $carga->referencias,
                'referencias_sin_costo' => $carga->referencias_sin_costo,
            ],
            'kardex' => $kardex,
            'asiento' => $asiento,
            'asiento_url' => OrigenContable::urlAsiento(CargaInventario::class, $carga->id),
        ]);
    }
}
