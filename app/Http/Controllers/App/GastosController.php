<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Actions\RegistrarAsientoGasto;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use App\Modules\Gerencia\Models\GastoOperativo;
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
            ->orderByDesc('id')->paginate(30);

        $conteos = GastoOperativo::selectRaw('estado, COUNT(*) as total, SUM(monto) as suma')
            ->when(! $esAdmin, fn ($qb) => $qb->where('solicita_id', $u->id))
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
            'filtros' => ['tipo' => $tipo ?: null, 'estado' => $estado ?: null],
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
        $numero = 'GO-' . now()->format('ym') . '-' . str_pad((string) (GastoOperativo::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);

        /*
         * Un gasto sin factura del proveedor no se puede declarar: no hay con
         * que soportar el IVA ni el costo ante la DIAN. Se marca interno por
         * defecto, que es lo conservador, y queda VISIBLE para que el contador
         * lo cambie si la factura aparece despues.
         */
        $sinFactura = trim((string) ($data['factura_ref'] ?? '')) === '';

        GastoOperativo::create([
            ...$data,
            'numero' => $numero,
            'solicita_id' => auth()->id(),
            'estado' => 'pendiente',
            'cuenta_puc' => self::cuentaDeCategoria($data['categoria']),
            'destino_fiscal' => $sinFactura ? DestinoFiscal::Interno : DestinoFiscal::Siigo,
            'motivo_destino' => $sinFactura ? 'Sin factura del proveedor' : null,
            'linea_negocio' => LineaNegocio::GreatBaby,
        ]);
        return back()->with('success', "Solicitud {$numero} creada.");
    }

    /**
     * Los tipos de gasto y la cuenta de cada uno.
     *
     * Great Baby mando la lista el 2026-11-03: cada concepto con su cuenta
     * del PUC. Hasta ahora la categoria era una etiqueta suelta y la cuenta
     * se escribia a mano gasto por gasto, asi que dos papelerias podian ir a
     * parar a dos cuentas distintas y el informe por cuenta no servia.
     *
     * Se lee de las reglas y no de una constante para que el contador pueda
     * cambiar una cuenta sin que nadie toque codigo.
     *
     * @return array<string,array{etiqueta:string,cuenta:string}>
     */
    public static function catalogoGastos(): array
    {
        $tipos = [
            'aseo_cafeteria' => 'Aseo y cafetería',
            'papeleria' => 'Papelería',
            'arriendo' => 'Arriendo',
            'envios' => 'Envíos y fletes',
            'honorarios' => 'Honorarios',
            'publicidad' => 'Publicidad',
            'servicio_acueducto' => 'Servicio · acueducto',
            'servicio_energia' => 'Servicio · energía',
            'servicio_internet' => 'Servicio · internet',
            'servicio_celular' => 'Servicio · celular',
            'servicio_gas' => 'Servicio · gas',
        ];

        $catalogo = [];
        foreach ($tipos as $clave => $etiqueta) {
            $catalogo[$clave] = [
                'etiqueta' => $etiqueta,
                'cuenta' => (string) setting('gastos.cta_'.$clave),
            ];
        }

        // El cajon de sastre va al final y usa la cuenta de gasto generica.
        $catalogo['otros'] = [
            'etiqueta' => 'Otros',
            'cuenta' => (string) setting('contable.cta_gasto_default'),
        ];

        return $catalogo;
    }

    /** La cuenta que le toca a una categoria, o la generica si no la conoce. */
    private static function cuentaDeCategoria(string $categoria): ?string
    {
        $catalogo = self::catalogoGastos();

        return $catalogo[$categoria]['cuenta']
            ?? ((string) setting('contable.cta_gasto_default') ?: null);
    }

    public function aprobar(Request $r, int $gasto): RedirectResponse
    {
        $u = $r->user();
        abort_unless($u->esAracely() || $u->hasAnyRole(['Gerente']), 403);
        $g = GastoOperativo::findOrFail($gasto);

        /*
         * Solo se aprueba lo PENDIENTE. Sin esto, un POST directo o una pestana
         * vieja devolvia a «aprobado» un gasto ya pagado: la caja ya habia
         * salido en el libro y la pantalla lo mostraba como pendiente de pagar.
         * El libro aguantaba (los asientos son idempotentes), pero el estado
         * mentia.
         */
        abort_unless($g->estado === 'pendiente', 422, 'Sólo se puede aprobar un gasto pendiente.');

        $g->update(['estado' => 'aprobado', 'aprueba_id' => $u->id]);

        // La obligacion ya existe aunque no se haya pagado: se causa ahora.
        // Antes el gasto aprobado no entraba a ningun lado.
        app(RegistrarAsientoGasto::class)->causar($g->fresh());

        return back()->with('success', "Gasto {$g->numero} aprobado y causado en el libro.");
    }

    public function rechazar(Request $r, int $gasto): RedirectResponse
    {
        $u = $r->user();
        abort_unless($u->esAracely() || $u->hasAnyRole(['Gerente']), 403);
        $data = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        $g = GastoOperativo::findOrFail($gasto);
        $g->update([
            'estado' => 'rechazado', 'aprueba_id' => $u->id,
            'notas' => trim(($g->notas ?: '') . "\n[RECHAZO] " . $data['motivo']),
        ]);
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

        DB::transaction(function () use ($g, $data, $u) {
            $g->update([
                'estado' => 'pagado',
                'caja_id' => $data['caja_id'] ?? null,
            ]);

            if (! empty($data['caja_id'])) {
                MovimientoCaja::create([
                    'caja_id' => $data['caja_id'],
                    'fecha' => now('America/Bogota')->toDateString(),
                    'tipo' => 'gasto',
                    'descripcion' => "Gasto {$g->numero} · {$g->descripcion}",
                    // El proveedor es a quien se le entregó la plata; si no
                    // hay, queda quien lo solicitó, que es quien la recibió.
                    'entregado_a' => $g->proveedor ?: ($g->solicita?->name ?? 'Sin registrar'),
                    'valor' => $g->monto,
                    'user_id' => $u->id,
                ]);
            }

            // Cancela el pasivo contra caja o banco segun el medio de pago.
            app(RegistrarAsientoGasto::class)->pagar($g->fresh());
        });

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

        $destino = DestinoFiscal::from($data['destino']);
        $motivo = trim((string) ($data['motivo'] ?? ''));

        if ($destino !== DestinoFiscal::Siigo && $motivo === '') {
            return back()->withErrors([
                'motivo' => 'Para que el gasto no se declare hay que escribir por qué.',
            ]);
        }

        $g = GastoOperativo::findOrFail($gasto);

        $g->update([
            'cuenta_puc' => $data['cuenta_puc'] ?: null,
            'destino_fiscal' => $destino,
            'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : $motivo,
            'linea_negocio' => LineaNegocio::tryFrom((string) ($data['linea'] ?? '')) ?? $g->linea_negocio,
        ]);

        app(RegistrarAsientoGasto::class)->rehacer($g->fresh());

        return back()->with('success', "Gasto {$g->numero} reclasificado y asiento rehecho.");
    }
}
