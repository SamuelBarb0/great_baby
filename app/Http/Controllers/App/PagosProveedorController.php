<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Actions\RegistrarPagoProveedor;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Services\CalculadorRetenciones;
use App\Modules\Siigo\Jobs\PushPagoProveedorASiigo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 4 · B.3+ · Pagos a proveedor con retenciones aplicadas automáticamente.
 */
class PagosProveedorController extends Controller implements HasMiddleware
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

    public function index(Request $r): Response
    {
        $q = PagoProveedor::with(['proveedor:id,nombre_completo,ciudad,regimen_iva', 'retenciones']);
        if ($busca = trim((string) $r->query('q', ''))) {
            $q->whereHas('proveedor', fn ($qq) => $qq->where('nombre_completo', 'like', "%$busca%"));
        }

        return Inertia::render('Cartera/PagosProveedor', [
            'filtros' => ['q' => $r->query('q', '')],
            'pagos' => $q->orderByDesc('fecha')->orderByDesc('id')->paginate(25)->through(fn ($p) => [
                'id' => $p->id,
                'fecha' => $p->fecha->format('Y-m-d'),
                'proveedor' => $p->proveedor?->nombre_completo,
                'ciudad' => $p->proveedor?->ciudad,
                'monto_bruto' => (float) $p->monto_bruto,
                'iva' => (float) $p->iva,
                'monto_retenciones' => (float) $p->monto_retenciones,
                'monto_neto' => (float) $p->monto_neto,
                'metodo' => $p->metodo,
                // COMP-B5 · estado del pago para que la UI muestre Confirmar/Anular
                'estado' => $p->estado ?? 'confirmado',
                'confirmado_at' => $p->confirmado_at ? \Carbon\Carbon::parse($p->confirmado_at)->format('Y-m-d H:i') : null,
                'retenciones' => $p->retenciones->map(fn ($r) => [
                    'tipo' => $r->tipo,
                    'tarifa_pct' => (float) $r->tarifa_pct,
                    'valor' => (float) $r->valor,
                    'cuenta_puc' => $r->cuenta_puc,
                ]),
                'siigo_voucher_id' => $p->siigo_voucher_id,
            ]),
            'kpis' => [
                'total_mes' => (float) PagoProveedor::whereMonth('fecha', now()->month)->whereYear('fecha', now()->year)->sum('monto_neto'),
                'retenido_mes' => (float) PagoProveedor::whereMonth('fecha', now()->month)->whereYear('fecha', now()->year)->sum('monto_retenciones'),
                'pagos_mes' => PagoProveedor::whereMonth('fecha', now()->month)->whereYear('fecha', now()->year)->count(),
                'sin_siigo' => PagoProveedor::whereNull('siigo_voucher_id')->count(),
                // COMP-B5 · pendientes de confirmar (nacen pendiente, no llegan a SIIGO hasta aprobar).
                'pendientes_confirmar' => PagoProveedor::where('estado', 'pendiente')->count(),
            ],
        ]);
    }

    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'contacto_id' => ['required', 'integer', 'exists:contactos,id'],
            'orden_compra_id' => ['nullable', 'integer'],
            'monto_bruto' => ['required', 'numeric', 'min:0.01'],
            'iva' => ['nullable', 'numeric', 'min:0'],
            'concepto_retencion' => ['required', 'string', 'max:60'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'gran_contribuyente' => ['nullable', 'boolean'],
            'metodo' => ['required', 'in:transferencia,efectivo,cheque'],
            'cuenta_puc_egreso' => ['nullable', 'string', 'max:20'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        $data['user_id'] = $r->user()->id;

        $pago = RegistrarPagoProveedor::run($data);

        return back()->with('flash', [
            'type' => 'success',
            'message' => "Pago #{$pago->id} registrado · bruto \$" . number_format($pago->monto_bruto, 2)
                . " · retenido \$" . number_format($pago->monto_retenciones, 2)
                . " · neto \$" . number_format($pago->monto_neto, 2) . " · PENDIENTE de confirmación antes de ir a SIIGO.",
        ]);
    }

    // COMP-B5 · Pago proveedor requiere confirmación.
    // El pago nace `pendiente`. Hasta que un revisor confirme (habitualmente
    // Aracely tras verificar NIT/monto/cuenta), no se encola a SIIGO. Evita
    // que un typo del operador se replique al libro contable del cliente.
    public function confirmar(PagoProveedor $pagoProveedor, Request $r): RedirectResponse
    {
        if ($pagoProveedor->estado === 'confirmado') {
            return back()->with('flash', ['type' => 'info', 'message' => "Pago #{$pagoProveedor->id} ya estaba confirmado."]);
        }
        if ($pagoProveedor->estado === 'anulado') {
            return back()->with('flash', ['type' => 'error', 'message' => "Pago #{$pagoProveedor->id} está anulado · no se puede confirmar."]);
        }

        /*
         * Cuatro ojos, que es lo que el comentario de arriba prometía y el
         * código no cumplía.
         *
         * La puerta de la clase es `esContable()` y `confirmar` no agregaba
         * NADA: la misma persona que registraba el pago lo confirmaba un
         * segundo después. El estado `pendiente` existía, el botón existía, y
         * el control no, porque revisar lo propio no es revisar. Y lo que se
         * aprueba acá no es un trámite: al confirmar sale la plata, se escribe
         * el asiento y el documento viaja a SIIGO.
         *
         * No hay excepción para Aracely ni para Gerencia. Un control que la
         * jefa puede saltarse es un control que se salta siempre, y justo en
         * el pago que importa. Hoy seis usuarios pasan `esContable()`, así que
         * siempre hay quien revise.
         */
        if ((int) $pagoProveedor->user_id === (int) $r->user()->id) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => "Pago #{$pagoProveedor->id}: vos lo registraste, así que no lo podés confirmar. "
                    .'Lo tiene que revisar otra persona del equipo contable.',
            ]);
        }

        $pagoProveedor->forceFill([
            'estado' => 'confirmado',
            'confirmado_at' => now(),
            'confirmado_por' => $r->user()->id,
        ])->save();

        /*
         * Y el ASIENTO, que no existia.
         *
         * `RegistrarPagoProveedor` creaba el pago y calculaba las retenciones
         * sin escribir ni una linea en el libro. La cuenta de proveedores SOLO
         * CRECIA —la recepcion la acreditaba y el pago nunca la debitaba— y las
         * retenciones practicadas nunca entraban, que es la base de la
         * declaracion mensual ante la DIAN.
         *
         * Va al CONFIRMAR y no al crear: el pago nace `pendiente` y hasta que
         * alguien lo revisa la plata no salio.
         */
        app(\App\Modules\Cartera\Actions\RegistrarAsientoPagoProveedor::class)
            ->handle($pagoProveedor->fresh());

        PushPagoProveedorASiigo::dispatchManual($pagoProveedor->id);
        return back()->with('flash', [
            'type' => 'success',
            'message' => "Pago #{$pagoProveedor->id} confirmado y encolado a SIIGO.",
        ]);
    }

    /**
     * Anular deja el libro como estaba, no sólo la casilla.
     *
     * Antes ponía `estado = 'anulado'` y se iba. Si el pago estaba confirmado
     * —y sólo se anula lo que se confirmó por error— su asiento seguía vivo:
     * el pasivo con el proveedor cancelado, la plata salida del banco y las
     * retenciones practicadas, todo en el libro, respaldando un pago que ya no
     * existe. Las retenciones son lo grave: son la base de la declaración
     * mensual ante la DIAN, y quedaban declarando una retención que nunca se
     * le hizo a nadie.
     *
     * Se borran las líneas en vez de contra-asentar, que es lo que ya hace la
     * anulación de factura en este mismo sistema. El rastro de quién lo hizo y
     * por qué queda en el pago.
     */
    public function anular(PagoProveedor $pagoProveedor, Request $r): RedirectResponse
    {
        if ($pagoProveedor->siigo_voucher_id) {
            return back()->with('flash', ['type' => 'error', 'message' => "Pago #{$pagoProveedor->id} ya está en SIIGO · usar nota de ajuste allá."]);
        }

        // Anular dos veces borraría un asiento que ya no está y pisaría el
        // motivo del primero.
        if ($pagoProveedor->estado === 'anulado') {
            return back()->with('flash', ['type' => 'info', 'message' => "Pago #{$pagoProveedor->id} ya estaba anulado."]);
        }

        $data = $r->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:500']],
            [],
            ['motivo' => 'motivo'],
        );

        $borradas = \Illuminate\Support\Facades\DB::transaction(function () use ($pagoProveedor, $r, $data) {
            $n = \App\Modules\Cartera\Models\MovimientoContable::query()
                ->where('origen_type', PagoProveedor::class)
                ->where('origen_id', $pagoProveedor->id)
                ->forceDelete();

            $pagoProveedor->forceFill([
                'estado' => 'anulado',
                'anulado_at' => now(),
                'anulado_por' => $r->user()->id,
                'motivo_anulacion' => $data['motivo'],
            ])->save();

            return $n;
        });

        return back()->with('flash', [
            'type' => 'success',
            'message' => "Pago #{$pagoProveedor->id} anulado."
                .($borradas > 0 ? " Se revirtieron {$borradas} línea(s) del libro." : ''),
        ]);
    }

    // QA-FIX #7 · reenviar manualmente si el push automático falló.
    // Solo tiene efecto en pagos ya confirmados — un pendiente no se puede empujar.
    public function reenviarSiigo(PagoProveedor $pagoProveedor): RedirectResponse
    {
        if ($pagoProveedor->estado !== 'confirmado') {
            return back()->with('flash', ['type' => 'error', 'message' => "Pago #{$pagoProveedor->id} no está confirmado · confírmalo primero."]);
        }
        PushPagoProveedorASiigo::dispatchManual($pagoProveedor->id);
        return back()->with('flash', ['type' => 'success', 'message' => "Pago #{$pagoProveedor->id} encolado a SIIGO."]);
    }

    // QA-FIX #11 · preview de retenciones usando el motor real (evita hardcodes
    // en la vista que mienten si Aracely edita las reglas en /retenciones).
    public function previewRetenciones(Request $r, CalculadorRetenciones $calc): JsonResponse
    {
        $data = $r->validate([
            'contacto_id' => ['nullable', 'integer'],
            'monto_bruto' => ['required', 'numeric', 'min:0'],
            'iva' => ['nullable', 'numeric', 'min:0'],
            'concepto' => ['required', 'string'],
            'ciudad' => ['nullable', 'string'],
            'gran_contribuyente' => ['nullable', 'boolean'],
        ]);
        $bruto = (float) $data['monto_bruto'];
        $iva = (float) ($data['iva'] ?? 0);
        $base = $bruto - $iva;
        $esAutoRet = false;
        if (! empty($data['contacto_id'])) {
            $c = \App\Models\Contacto::find($data['contacto_id']);
            $esAutoRet = (bool) ($c?->es_autorretenedor ?? false);
        }
        $lineas = $calc->calcular(
            base: $base,
            concepto: $data['concepto'],
            ciudad: $data['ciudad'] ?? null,
            iva: $iva,
            granContribuyente: (bool) ($data['gran_contribuyente'] ?? false),
            esAutorretenedor: $esAutoRet,
        );
        $total = array_sum(array_column($lineas, 'valor'));
        return response()->json([
            'lineas' => $lineas,
            'total' => $total,
            'neto' => round($bruto - $total, 2),
            'es_autorretenedor' => $esAutoRet,
        ]);
    }
}
