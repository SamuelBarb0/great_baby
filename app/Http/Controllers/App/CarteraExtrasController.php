<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\CobranzaRegistro;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Cartera\Models\SolicitudCredito;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Support\ExportadorCsv;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FIL-C · Cartera restante Vue:
 *  - Reportes cartera (edad saldos, top morosos, consignaciones, PDF)
 *  - CRUD Cobranza Registro (bitácora envíos WhatsApp/email)
 *  - CRUD Solicitud Crédito (workflow excepciones)
 *  - Movimiento Contable (drill-down)
 */
class CarteraExtrasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            // A1 FIX #10 · 'Cobrador' removido · nunca estuvo seeded, era shadow role.
            /*
             * Tres pantallas con tres casillas distintas: cobranzas, excepciones
             * de credito y libro diario. La puerta de clase deja entrar con
             * cualquiera; cada metodo exige la suya mas abajo.
             */
            abort_unless(\App\Auth\Permisos::tieneAlguno($u, [
                'cobranzas', 'excepciones_credito', 'libro_diario', 'cartera_dashboard',
            ]), 403);
            return $next($r);
        })];
    }

    // ---------- REPORTES CARTERA ----------
    public function reportes(): Response
    {
        $hoy = today('America/Bogota');
        $desde30 = $hoy->copy()->subDays(30);

        $totalCartera = (float) FacturaVenta::whereIn('estado', ['pendiente','abonada','vencida'])
            ->whereNull('deleted_at')->sum('saldo');

        // Edad saldos (0-30, 31-60, 61-90, 91-120, 120+)
        $edades = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '91-120' => 0, '120+' => 0];
        $vencidas = FacturaVenta::whereIn('estado', ['pendiente','abonada','vencida'])
            ->whereNull('deleted_at')->get(['saldo', 'fecha_vencimiento']);
        foreach ($vencidas as $f) {
            $dias = $hoy->diffInDays($f->fecha_vencimiento, absolute: false);
            $dias = -$dias; // positivo si venció, negativo si por vencer
            $saldo = (float) $f->saldo;
            if ($dias <= 30) $edades['0-30'] += $saldo;
            elseif ($dias <= 60) $edades['31-60'] += $saldo;
            elseif ($dias <= 90) $edades['61-90'] += $saldo;
            elseif ($dias <= 120) $edades['91-120'] += $saldo;
            else $edades['120+'] += $saldo;
        }

        $topMorosos = DB::table('facturas_venta as f')
            ->join('contactos as c', 'c.id', '=', 'f.contacto_id')
            ->whereNull('f.deleted_at')->whereNull('c.deleted_at')
            ->whereNotIn('f.estado', ['pagada','anulada'])
            ->where('f.fecha_vencimiento', '<', $hoy)
            ->selectRaw('c.id, c.nombre_completo, c.razon_social, c.telefono, SUM(f.saldo) as saldo, COUNT(*) as facturas')
            ->groupBy('c.id','c.nombre_completo','c.razon_social','c.telefono')
            ->orderByDesc('saldo')->limit(20)->get();

        $consignaciones = PagoVenta::whereBetween('fecha', [$desde30, $hoy])
            ->selectRaw('medio_pago, COUNT(*) as n, SUM(monto_aplicado) as total')
            ->groupBy('medio_pago')->orderByDesc('total')->get();

        return Inertia::render('Cartera/Reportes', [
            'kpis' => [
                'total_cartera' => $totalCartera,
                'vencidas_count' => FacturaVenta::where('estado','vencida')->whereNull('deleted_at')->count(),
                'cobrado_30d' => (float) PagoVenta::whereBetween('fecha', [$desde30, $hoy])->sum('monto_aplicado'),
            ],
            'edades' => $edades,
            'topMorosos' => $topMorosos->map(fn ($r) => [
                'contacto_id' => (int) $r->id,
                'nombre' => $r->razon_social ?: $r->nombre_completo,
                'telefono' => $r->telefono,
                'saldo' => (float) $r->saldo,
                'facturas' => (int) $r->facturas,
            ]),
            'consignaciones' => $consignaciones->map(fn ($r) => [
                'medio' => $r->medio_pago, 'n' => (int) $r->n, 'total' => (float) $r->total,
            ]),
        ]);
    }

    // ---------- COBRANZA REGISTRO ----------
    /** Casilla: Cobranzas. */
    public function cobranzasIndex(Request $request): Response
    {
        $cobros = CobranzaRegistro::with(['factura:id,numero', 'gestor:id,name'])
            ->orderByDesc('id')->paginate(50);
        return Inertia::render('Cartera/Cobranzas/Index', [
            'cobros' => $cobros->through(fn ($c) => [
                'id' => $c->id,
                'factura' => $c->factura?->numero,
                'canal' => $c->canal,
                'tramo' => $c->tramo,
                'estado' => $c->estado,
                'mensaje' => $c->mensaje,
                'gestor' => $c->gestor?->name,
                'enviado_at' => $c->enviado_at?->format('Y-m-d H:i'),
            ]),
        ]);
    }

    public function cobranzaCrear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'factura_id' => ['required', 'integer', 'exists:facturas_venta,id'],
            'canal' => ['required', 'in:whatsapp,email,llamada,visita,sms'],
            'tramo' => ['required', 'string', 'max:20'],
            'mensaje' => ['required', 'string', 'max:1000'],
        ]);
        $factura = FacturaVenta::findOrFail($data['factura_id']);
        CobranzaRegistro::create([
            ...$data,
            'contacto_id' => $factura->contacto_id,
            'estado' => 'enviado',
            'gestor_id' => auth()->id(),
            'enviado_at' => now(),
        ]);
        return back()->with('success', 'Cobranza registrada.');
    }

    // ---------- SOLICITUD CRÉDITO ----------
    /** Casilla: Excepciones de credito. */
    public function solicitudesIndex(Request $request): Response
    {
        $estado = (string) $request->input('estado', '');
        $sols = SolicitudCredito::with(['contacto:id,nombre_completo,razon_social', 'solicitante:id,name'])
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('id')->paginate(30);

        return Inertia::render('Cartera/Solicitudes/Index', [
            'solicitudes' => $sols->through(fn ($s) => [
                'id' => $s->id,
                'contacto' => $s->contacto?->razon_social ?: $s->contacto?->nombre_completo,
                'monto_pedido' => (float) $s->monto_pedido,
                'motivo' => $s->motivo_retencion,
                'estado' => $s->estado, 'nivel' => $s->nivel_actual,
                'solicitada_por' => $s->solicitante?->name,
                'creada' => $s->created_at?->format('Y-m-d H:i'),
                'resuelta' => $s->resuelta_at?->format('Y-m-d H:i'),
            ]),
            'filtro' => $estado,
        ]);
    }

    public function solicitudResolver(Request $r, int $solicitud): RedirectResponse
    {
        $data = $r->validate([
            'decision' => ['required', 'in:aprobar,rechazar,escalar'],
            'notas' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // Re-audit FUNC C5 · guard estado + guard nivel vs rol del usuario.
        $s = SolicitudCredito::where('estado', 'pendiente')->findOrFail($solicitud);

        // Nivel_actual del escalamiento determina qué rol puede resolver.
        //   supervisor  → Cobrador, Aracely, Gerencia
        //   gerencia    → SOLO Aracely / Gerencia
        //   direccion   → SOLO Aracely / Gerencia (Director si existe)
        $u = auth()->user();
        $nivel = strtolower((string) $s->nivel_actual);
        $puedeResolver = match ($nivel) {
            'gerencia', 'direccion' => $u->esAracely() || $u->hasRole('Gerente'),
            default => $u->esContable(),  // A1 FIX #10 · 'Cobrador' era shadow role
        };
        abort_unless($puedeResolver, 403,
            "Tu rol no puede resolver solicitudes escaladas a {$nivel}. Escalar a Gerencia.");

        // El estado aprobado lleva el nivel que lo autorizó, igual que hace la
        // pantalla de Filament y como lo documenta la migración de la tabla.
        //
        // Acá se guardaba «aprobada» a secas, y la compuerta de crédito de
        // `PedidosB2BController::facturar()` sólo reconoce `aprobada_cartera` y
        // `aprobada_gerencia`: la aprobación no servía de nada. El pedido
        // quedaba retenido para siempre y cada intento de facturar creaba OTRA
        // solicitud pendiente. Comprobado el 2026-10-08 con el pedido
        // E2E-261006-174330: aprobado desde esta pantalla, siguió sin poder
        // facturarse y quedó con dos solicitudes abiertas.
        $aprobada = in_array($nivel, ['gerencia', 'direccion'], true)
            ? 'aprobada_gerencia'
            : 'aprobada_cartera';

        $nuevo = ['aprobar' => $aprobada, 'rechazar' => 'rechazada', 'escalar' => 'escalada'][$data['decision']];
        $s->update([
            'estado' => $nuevo,
            'resolucion_notas' => $data['notas'],
            'resuelta_por' => auth()->id(),
            'resuelta_at' => now(),
        ]);
        return back()->with('success', 'Solicitud ' . $nuevo);
    }

    // ---------- MOVIMIENTO CONTABLE ----------
    /** Casilla: Libro diario. */
    public function movimientos(Request $request): Response
    {
        // Re-audit M5 SEG-A3 + R2 SEG-M1 · regex numérico BLOQUEA inyección LIKE.
        // Sin regex, `?cuenta=%` o `?cuentas=_305` interpretaba wildcards LIKE y
        // devolvía TODAS las cuentas (nómina, IVA, patrimonio) — enumeración
        // silenciosa del PUC completo, peor aún para roles no-esContable.
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'cuenta' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/'],
            'cuentas' => ['nullable', 'string', 'regex:/^[0-9]{1,10}(,[0-9]{1,10}){0,20}$/'],
            'incluir_anulados' => ['nullable', 'boolean'],
            // F2c · los dos ejes. Se validan sueltos y `FiltroEjes` los
            // normaliza: cualquier basura cae en «todas».
            'linea' => ['nullable', 'string', 'max:20'],
            'destino' => ['nullable', 'string', 'max:20'],
        ]);

        $desde = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->endOfMonth()->toDateString();
        $cuenta = trim((string) ($data['cuenta'] ?? ''));
        // Re-audit R2 PATRÓN α · toggle propagado al drill-down. Antes el hub
        // saltaba a esta vista y perdía los reversos silenciosamente.
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);

        // Re-audit M5 FUNC-A4 · aceptar `cuentas=2365,2367,2368` para retenciones DIAN.
        $cuentasMulti = collect(explode(',', (string) ($data['cuentas'] ?? '')))
            ->map(fn ($c) => trim($c))
            ->filter()
            ->values();

        // Re-audit R2 SEG-M3 · scope de CUENTA (Cobrador solo ve 13xx).
        // Re-audit R4 SEG-A1 · limitación conocida: NO hay scope por tercero
        // porque no existe modelo cobrador↔contactos (requeriría fase de RRHH).
        // Cobrador legítimo ve toda la cartera de todos los vendedores. Registro
        // en audit para trazabilidad hasta que negocio defina si necesita
        // aislamiento por cartera asignada.
        $u = $request->user();
        $scopeCartera = ! ($u?->esContable() ?? false);

        // Re-audit R4 SEG-M1 · bitácora de consulta con anulados.
        if ($incluirAnulados) {
            \Illuminate\Support\Facades\Log::channel(config('logging.channels.audit') ? 'audit' : 'stack')
                ->info('cartera.movimientos.incluir_anulados', [
                    'user_id' => $u?->id, 'rango' => [$desde, $hasta],
                    'cuenta' => $cuenta ?: null, 'cuentas' => $cuentasMulti->join(','),
                ]);
        }

        $ejes = FiltroEjes::deRequest($request);

        $base = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->tap(fn ($q) => $ejes->aplicar($q))
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($scopeCartera, fn ($q) => $q->where('cuenta_puc', 'like', '13%'))
            // Re-audit R2 DATOS-A2 · whereIn cuando son códigos exactos (aprovecha
            // el índice compuesto inverso). Solo cae a LIKE prefix cuando el usuario
            // pasa 1 código y quiere el árbol completo (?cuenta=13 → todo cartera).
            // Notación PUC: prefijos generan LIKE `X%` — 2365 captura 236505 (RETEFTE
            // compras) y 236525 (servicios). Seguro contra injection porque el regex
            // del validate limita a dígitos. whereIn no serviría acá (asientos van
            // a subcuentas, no al grupo padre).
            ->when($cuenta, fn ($q) => $q->where('cuenta_puc', 'like', $cuenta . '%'))
            ->when($cuentasMulti->isNotEmpty(), function ($q) use ($cuentasMulti) {
                $q->where(function ($qq) use ($cuentasMulti) {
                    foreach ($cuentasMulti as $c) {
                        $qq->orWhere('cuenta_puc', 'like', $c . '%');
                    }
                });
            });

        // H8 · totales del rango filtrado (∑ debe / ∑ haber / diferencia / count).
        // Antes: la UI solo mostraba la suma de la página actual → inútil para
        // conciliar un mes. Ahora `totales` viene del backend con el rango completo.
        $totales = (clone $base)
            ->selectRaw('COALESCE(SUM(debe),0) AS suma_debe, COALESCE(SUM(haber),0) AS suma_haber, COUNT(*) AS total_filas')
            ->first();

        $movs = $base->orderByDesc('fecha')->orderByDesc('id')->paginate(100)->withQueryString();

        return Inertia::render('Cartera/Movimientos/Index', [
            'movimientos' => $movs->through(fn ($m) => [
                'id' => $m->id,
                'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : $m->fecha,
                'cuenta' => $m->cuenta_puc,
                'debe' => (float) $m->debe,
                'haber' => (float) $m->haber,
                'descripcion' => $m->descripcion,
                'origen' => $m->origen_type ? class_basename($m->origen_type) . '#' . $m->origen_id : '—',
                // Hasta hoy los dos ejes existían en la fila pero no se veían
                // en ningún lado, así que no había forma de revisar a mano si
                // un asiento había quedado del lado correcto.
                'linea' => $m->linea_negocio?->label() ?? '—',
                'destino' => $m->destino_fiscal?->label() ?? '—',
                'destino_color' => $m->destino_fiscal?->color() ?? 'gray',
            ]),
            'filtros' => array_merge(
                ['desde' => $desde, 'hasta' => $hasta, 'cuenta' => $cuenta ?: null, 'cuentas' => $cuentasMulti->isNotEmpty() ? $cuentasMulti->join(',') : null, 'incluir_anulados' => $incluirAnulados],
                $ejes->aParametros(),
            ),
            'opcionesEjes' => FiltroEjes::opciones(),
            'totales' => [
                'suma_debe' => (float) ($totales->suma_debe ?? 0),
                'suma_haber' => (float) ($totales->suma_haber ?? 0),
                'diferencia' => (float) (($totales->suma_debe ?? 0) - ($totales->suma_haber ?? 0)),
                'total_filas' => (int) ($totales->total_filas ?? 0),
            ],
        ]);
    }

    /**
     * F2c · Bajar el libro tal como se está viendo.
     *
     * Rearma la MISMA consulta que `movimientos()`, filtros incluidos. Antes no
     * había export acá: para conciliar un mes había que copiar la tabla a mano,
     * página por página, y la paginación es de 100 filas.
     */
    public function movimientosCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'cuenta' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/'],
            'cuentas' => ['nullable', 'string', 'regex:/^[0-9]{1,10}(,[0-9]{1,10}){0,20}$/'],
            'incluir_anulados' => ['nullable', 'boolean'],
            'linea' => ['nullable', 'string', 'max:20'],
            'destino' => ['nullable', 'string', 'max:20'],
        ]);

        $desde = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->endOfMonth()->toDateString();
        $cuenta = trim((string) ($data['cuenta'] ?? ''));
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);

        $cuentasMulti = collect(explode(',', (string) ($data['cuentas'] ?? '')))
            ->map(fn ($c) => trim($c))->filter()->values();

        // El mismo scope de cuenta que la pantalla: un Cobrador que exporta no
        // puede llevarse el PUC completo por la puerta de atrás.
        $scopeCartera = ! ($request->user()?->esContable() ?? false);

        $ejes = FiltroEjes::deRequest($request);

        /*
         * Bajar el libro deja rastro.
         *
         * El otro export del libro —`/app/contabilidad/reportes/exportar-csv`—
         * exige `esRoot` y escribe en la bitacora, con el argumento de que el
         * Contador puede VER el libro pero no llevarselo. Este export pide solo
         * `esContable`, asi que hoy ese control tiene una puerta de atras.
         *
         * Igualar las dos puertas es una decision de negocio (si el Contador
         * puede o no exportar), asi que no la tomo yo. Lo que si corresponde es
         * que quede registrado quien se llevo que.
         */
        \Illuminate\Support\Facades\Log::channel(config('logging.channels.audit') ? 'audit' : 'stack')
            ->info('cartera.movimientos.export_csv', [
                'user_id' => $request->user()?->id,
                'rango' => [$desde, $hasta],
                'cuenta' => $cuenta ?: null,
                'filtro_ejes' => $ejes->descripcion(),
                'incluye_anulados' => $incluirAnulados,
            ]);

        $consulta = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->tap(fn ($q) => $ejes->aplicar($q))
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($scopeCartera, fn ($q) => $q->where('cuenta_puc', 'like', '13%'))
            ->when($cuenta, fn ($q) => $q->where('cuenta_puc', 'like', $cuenta.'%'))
            ->when($cuentasMulti->isNotEmpty(), function ($q) use ($cuentasMulti) {
                $q->where(function ($qq) use ($cuentasMulti) {
                    foreach ($cuentasMulti as $c) {
                        $qq->orWhere('cuenta_puc', 'like', $c.'%');
                    }
                });
            })
            ->orderBy('fecha')->orderBy('id');

        $totales = (clone $consulta)->reorder()
            ->selectRaw('COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h, COUNT(*) n')->first();

        return ExportadorCsv::descargar(
            'libro-movimientos',
            ['Fecha', 'Cuenta PUC', 'Débito', 'Crédito', 'Línea', 'Destino', 'Descripción', 'Origen', 'Tercero'],
            $consulta,
            fn ($m) => [
                $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : $m->fecha,
                $m->cuenta_puc,
                (float) $m->debe,
                (float) $m->haber,
                $m->linea_negocio?->label(),
                $m->destino_fiscal?->label(),
                $m->descripcion,
                $m->origen_type ? class_basename($m->origen_type).'#'.$m->origen_id : null,
                $m->tercero_id ? class_basename((string) $m->tercero_type).'#'.$m->tercero_id : null,
            ],
            [
                '',
                "Rango: {$desde} a {$hasta}",
                'Filtro: '.$ejes->descripcion()
                    .($cuenta !== '' ? " · cuenta {$cuenta}" : '')
                    .($cuentasMulti->isNotEmpty() ? ' · cuentas '.$cuentasMulti->join(', ') : '')
                    .($incluirAnulados ? ' · incluye anulados' : ''),
                'Débito: '.number_format((float) ($totales->d ?? 0), 2, ',', '.'),
                'Crédito: '.number_format((float) ($totales->h ?? 0), 2, ',', '.'),
                'Diferencia: '.number_format((float) (($totales->d ?? 0) - ($totales->h ?? 0)), 2, ',', '.'),
                'Filas: '.number_format((int) ($totales->n ?? 0), 0, ',', '.'),
            ],
        );
    }
}
