<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\RetencionConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 4 · B.3 · CRUD Vue de reglas de retención (Retefuente/Reteica/Reteiva).
 * Ruta: /app/cartera/retenciones
 */
class RetencionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);
                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Cartera/Retenciones', [
            'reglas' => RetencionConfig::orderBy('tipo')->orderBy('concepto')->get(),
        ]);
    }

    public function guardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'id' => ['nullable', 'integer'],
            'tipo' => ['required', 'in:retefuente,reteica,reteiva,autorrenta,autorreteica'],
            'ambito' => ['required', 'in:compras,ventas'],
            // La otra pata del asiento. En autorretención es la 13 de activo:
            // sin ella el asiento queda cojo y el balance no lo delata, porque
            // le faltan las dos patas a la vez.
            'cuenta_puc_contrapartida' => ['nullable', 'string', 'max:30'],
            // 255 como la columna: los conceptos de la DIAN traen la condición
            // que decide si aplican («…superiores a 204 UVT») y recortarla deja
            // una regla que parece completa y se aplica donde no debe.
            'concepto' => ['required', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:60'],
            'base_minima' => ['nullable', 'numeric', 'min:0'],
            'tarifa_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            // Opcional mientras se revisa: una regla cargada desde la tabla del
            // contador puede no tener cuenta todavía. El seguro está abajo.
            'cuenta_puc' => ['nullable', 'string', 'max:30'],
            'siigo_tax_id' => ['nullable', 'integer', 'min:1'],
            'activa' => ['boolean'],
            'notas' => ['nullable', 'string'],
        ]);
        $data['activa'] ??= true;
        $data['base_minima'] ??= 0;

        $falta = $data['activa'] ? $this->faltaParaActivar($data) : null;
        if ($falta) {
            return back()->withErrors($falta)->withInput();
        }

        if (! empty($data['id'])) {
            RetencionConfig::where('id', $data['id'])->update($data);
        } else {
            RetencionConfig::create($data);
        }
        return back()->with('flash', ['type' => 'success', 'message' => 'Regla de retención guardada.']);
    }

    /**
     * Lo que le falta a una regla para poder quedar activa, o null si está lista.
     *
     * Lo miran las dos puertas —el formulario y el interruptor de la lista—
     * porque el seguro tiene que ser el mismo. Una regla activa calcula la
     * retención en cada factura y arma su asiento:
     *
     *   · Sin `cuenta_puc` el asiento sale mal y no lo nota nadie hasta el
     *     cierre.
     *   · Una autorretención sin contrapartida no es un asiento, es media:
     *     mueve la 23 (lo que se le debe a la DIAN) contra la 13 (el anticipo
     *     que queda a favor), así que con una sola cuenta produce asientos
     *     descuadrados en cada factura emitida.
     *
     * Devuelve [campo => motivo] para que el formulario lo pinte junto al campo
     * y el interruptor lo mande como aviso.
     */
    protected function faltaParaActivar(array $d): ?array
    {
        if (empty($d['cuenta_puc'])) {
            return ['cuenta_puc' => 'Para activar la regla hay que decirle a qué cuenta del PUC va la retención.'];
        }

        $esAutorretencion = in_array($d['tipo'] ?? '', ['autorrenta', 'autorreteica'], true);
        if ($esAutorretencion && empty($d['cuenta_puc_contrapartida'])) {
            return ['cuenta_puc_contrapartida' => 'Una autorretención mueve dos cuentas: falta la de activo (13…), '
                . 'que es el anticipo que queda a favor.'];
        }

        return null;
    }

    /**
     * Prende y apaga una regla con un clic desde la lista.
     *
     * Antes el único camino era abrir el lápiz, buscar la casilla «Activa»,
     * cambiarla y guardar — por cada una de las 33 reglas del catálogo. Prender
     * y apagar es lo que se hace a diario (cambia la ley, cambia el proveedor);
     * editar la tarifa, casi nunca.
     *
     * El estado nuevo se calcula acá y no se recibe: si la pantalla quedó
     * abierta un rato y alguien más cambió la regla, un `activa` que viajara
     * desde el navegador pisaría ese cambio con lo que el navegador recordaba.
     *
     * Apagar nunca se bloquea: una regla incompleta que quedó prendida tiene
     * que poder apagarse, que es justo cuando más urge.
     */
    public function alternar(RetencionConfig $regla): RedirectResponse
    {
        if (! $regla->activa) {
            $falta = $this->faltaParaActivar([
                'tipo' => $regla->tipo,
                'cuenta_puc' => $regla->cuenta_puc,
                'cuenta_puc_contrapartida' => $regla->cuenta_puc_contrapartida,
            ]);

            if ($falta) {
                return back()->with('flash', ['type' => 'error', 'message' => reset($falta)]);
            }
        }

        $regla->activa = ! $regla->activa;
        $regla->save();

        return back()->with('flash', [
            'type' => 'success',
            'message' => $regla->activa
                ? "«{$regla->concepto}» quedó activa: desde ahora se aplica."
                : "«{$regla->concepto}» quedó apagada: deja de aplicarse.",
        ]);
    }

    public function eliminar(RetencionConfig $regla): RedirectResponse
    {
        $regla->delete();
        return back()->with('flash', ['type' => 'success', 'message' => 'Regla eliminada.']);
    }
}
