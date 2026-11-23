<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Actions\RegistrarAsientoGasto;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use App\Modules\Gerencia\Models\GastoOperativo;
use App\Modules\Contabilidad\Support\FiltroEjes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M10 · Gastos operativos y reembolsos con aprobación
 */
class GastosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('auth')];
    }

    public function index(Request $request): Response
    {
        $tipo = (string) $request->input('tipo', '');
        $estado = (string) $request->input('estado', '');
        // `?linea=dropi` · el panel de Dropi abre los gastos ya filtrados.
        $ejes = FiltroEjes::de($request->input('linea', FiltroEjes::LINEA_ERP_POR_DEFECTO), null);
        $u = $request->user();
        // Re-audit M5 R2 SEG-B2 · esContable() unificado.
        /*
         * Tres banderas y no una.
         *
         * La pantalla usaba `esAdmin` —que es `esContable()`— para mostrar
         * aprobar, rechazar, pagar y reclasificar. Pero aprobar y rechazar
         * exigen Aracely/Gerencia/Gerente, asi que **el Contador veia los
         * botones verde y rojo y recibia un 403 al apretarlos**. Podia pagar
         * pero no aprobar, y la pantalla le ofrecia las tres cosas igual.
         *
         * El molde correcto ya existia en el proyecto: el controlador manda la
         * bandera y el Vue la respeta (`ColaJorgeController::puede_resolver`).
         */
        $esAdmin = $u->esContable();
        $puedeAprobar = $u->esAracely() || $u->hasAnyRole(['Gerente']);

        $q = GastoOperativo::with(['solicita:id,name', 'aprueba:id,name'])
            ->when(! $esAdmin, fn ($qb) => $qb->where('solicita_id', $u->id))
            ->when($tipo, fn ($qb) => $qb->where('tipo', $tipo))
            ->when($estado, fn ($qb) => $qb->where('estado', $estado))
            ->tap(fn ($qb) => $ejes->aplicar($qb))
            ->orderByDesc('id')->paginate(30);

        $conteos = GastoOperativo::selectRaw('estado, COUNT(*) as total, SUM(monto) as suma')
            ->when(! $esAdmin, fn ($qb) => $qb->where('solicita_id', $u->id))
            ->tap(fn ($qb) => $ejes->aplicar($qb))
            ->groupBy('estado')->get()->keyBy('estado');

        return Inertia::render('Gastos/Index', [
            'gastos' => $q->through(fn ($g) => [
                'id' => $g->id, 'numero' => $g->numero,
                'fecha' => $g->fecha?->toDateString(),
                'categoria' => $g->categoria, 'descripcion' => $g->descripcion,
                'monto' => (float) $g->monto,
                'tipo' => $g->tipo, 'estado' => $g->estado,
                'metodo_pago' => $g->metodo_pago,
                'proveedor' => $g->proveedor, 'factura_ref' => $g->factura_ref,
                'solicita' => $g->solicita?->name, 'aprueba' => $g->aprueba?->name,
                'notas' => $g->notas,
                // De qué caja salió, cuando se pagó en efectivo.
                'caja_id' => $g->caja_id,
                // Lo contable, que antes no viajaba a la pantalla porque no
                // existia: contra que cuenta va y si se declara.
                'cuenta_puc' => $g->cuenta_puc,
                'destino' => ($g->destino_fiscal ?? DestinoFiscal::Siigo)->value,
                'destino_label' => ($g->destino_fiscal ?? DestinoFiscal::Siigo)->label(),
                'destino_color' => ($g->destino_fiscal ?? DestinoFiscal::Siigo)->color(),
                'motivo_destino' => $g->motivo_destino,
                'linea' => ($g->linea_negocio ?? LineaNegocio::GreatBaby)->value,
                'linea_label' => ($g->linea_negocio ?? LineaNegocio::GreatBaby)->label(),
                'en_el_libro' => \App\Modules\Cartera\Models\MovimientoContable::query()
                    ->where('origen_type', GastoOperativo::class)->where('origen_id', $g->id)->exists(),
            ]),
            'conteos' => $conteos->map(fn ($c) => ['total' => (int) $c->total, 'suma' => (float) $c->suma]),
            'esAdmin' => $esAdmin,
            // Quien puede aprobar o rechazar · distinto de quien puede pagar.
            'puedeAprobar' => $puedeAprobar,
            'cuentaPorDefecto' => (string) setting('contable.cta_gasto_default'),
            // El listado de tipos de gasto sale de las reglas, con su cuenta:
            // la pantalla ya no lo lleva escrito adentro.
            'catalogoGastos' => collect(self::catalogoGastos())
                ->map(fn ($t, $clave) => ['valor' => $clave] + $t)->values(),
            // De qué caja puede salir la plata, para los que se pagan en
            // efectivo. Sin esto el gasto no aterriza en ningún arqueo.
            'cajas' => \App\Modules\Contabilidad\Models\Caja::activas()
                ->whereIn('tipo', ['menor', 'general'])
                ->orderBy('codigo')->get(['id', 'codigo', 'nombre'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => "{$c->codigo} · {$c->nombre}"]),
            'destinos' => DestinoFiscal::opcionesDeCompra(),  // gasto: fiscal o interno, sin reparto
            'lineas' => LineaNegocio::opciones(),
            'filtros' => ['tipo' => $tipo ?: null, 'estado' => $estado ?: null,
                'linea' => $ejes->linea === 'todas' ? null : $ejes->linea],
        ]);
    }

    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'categoria' => ['required', 'string', 'max:60'],
            'descripcion' => ['required', 'string', 'max:200'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'proveedor' => ['nullable', 'string', 'max:200'],
            'factura_ref' => ['nullable', 'string', 'max:100'],
            'metodo_pago' => ['required', 'in:efectivo,transferencia,tarjeta,nequi,daviplata'],
            'tipo' => ['required', 'in:gasto,reembolso'],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);
        // Número, cuenta de la categoría y destino fiscal: ver Contabilidad\Actions\Gastos.
        $g = \App\Modules\Contabilidad\Actions\Gastos::crear($data, $r->user());
        return back()->with('success', "Solicitud {$g->numero} creada.");
    }

    /**
     * Los tipos de gasto y la cuenta de cada uno: ver CatalogoGastos (7-nov,
     * también lo usa el panel de Dropi).
     *
     * @return array<string,array{etiqueta:string,cuenta:string}>
     */
    public static function catalogoGastos(): array
    {
        return \App\Modules\Contabilidad\Support\CatalogoGastos::todos();
    }

    public function aprobar(Request $r, int $gasto): RedirectResponse
    {
        abort_unless(\App\Modules\Contabilidad\Actions\Gastos::puedeAprobar($r->user()), 403);
        $g = GastoOperativo::findOrFail($gasto);
        // Sólo lo pendiente, y se causa en el libro: ver Contabilidad\Actions\Gastos.
        \App\Modules\Contabilidad\Actions\Gastos::aprobar($g, $r->user());
        return back()->with('success', "Gasto {$g->numero} aprobado y causado en el libro.");
    }

    public function rechazar(Request $r, int $gasto): RedirectResponse
    {
        abort_unless(\App\Modules\Contabilidad\Actions\Gastos::puedeAprobar($r->user()), 403);
        $data = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        \App\Modules\Contabilidad\Actions\Gastos::rechazar(GastoOperativo::findOrFail($gasto), $r->user(), $data['motivo']);
        return back()->with('success', 'Gasto rechazado.');
    }

    public function marcarPagado(Request $r, int $gasto): RedirectResponse
    {
        $u = $r->user();
        // Re-audit M5 R2 SEG-B2 · esContable().
        abort_unless($u?->esContable(), 403);
        $g = GastoOperativo::findOrFail($gasto);
        abort_unless($g->estado === 'aprobado', 422, 'Sólo aprobados pueden marcarse pagados.');

        /*
         * Si sale en efectivo, de qué caja.
         *
         * Hasta acá el pago golpeaba la cuenta contable de caja por defecto y
         * nadie sabía de cuál cajón salió la plata. Resultado: al final del
         * día la persona cuenta los billetes, le faltan los $395.000 que
         * entregó, y en la pantalla del arqueo no hay nada que lo explique
         * porque el gasto vivía en otro módulo. El movimiento se crea acá
         * para que aterrice en el día de esa caja.
         */
        $data = $r->validate([
            'caja_id' => [
                $g->metodo_pago === 'efectivo' ? 'required' : 'nullable',
                'integer', 'exists:cajas,id',
            ],
        ], ['caja_id.required' => 'Un gasto en efectivo sale de una caja: decí de cuál.']);

        // El movimiento de caja (si es en efectivo) y el asiento: ver Contabilidad\Actions\Gastos.
        \App\Modules\Contabilidad\Actions\Gastos::pagar($g, $u, $data['caja_id'] ?? null);

        return back()->with('success', "Gasto {$g->numero} pagado y registrado en el libro.");
    }

    /**
     * Lo que el contador decide sobre un gasto: contra qué cuenta va y si se
     * declara.
     *
     * Rehace el asiento en vez de parchearlo. Si no, el libro sigue mostrando
     * la cuenta vieja y la diferencia sólo aparece en el cierre, cuando ya
     * nadie recuerda qué gasto fue.
     */
    public function contabilizar(Request $r, int $gasto): RedirectResponse
    {
        abort_unless($r->user()?->esContable(), 403);

        $data = $r->validate([
            'cuenta_puc' => ['nullable', 'string', 'regex:/^[0-9]{4,10}$/', 'exists:plan_cuentas,codigo'],
            'destino' => ['required', 'string', 'in:'.DestinoFiscal::Siigo->value.','.DestinoFiscal::Interno->value],
            'linea' => ['nullable', 'string', 'in:'.implode(',', array_column(LineaNegocio::cases(), 'value'))],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]);

        $g = GastoOperativo::findOrFail($gasto);

        // Rehace el asiento: ver Contabilidad\Actions\Gastos::reclasificar.
        try {
            \App\Modules\Contabilidad\Actions\Gastos::reclasificar(
                $g,
                $data['cuenta_puc'] ?? null,
                DestinoFiscal::from($data['destino']),
                $data['motivo'] ?? null,
                LineaNegocio::tryFrom((string) ($data['linea'] ?? '')),
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Gasto {$g->numero} reclasificado y asiento rehecho.");
    }
}
