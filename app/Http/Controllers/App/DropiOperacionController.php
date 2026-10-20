<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Actions\ConfirmarEmpaqueYSiguiente;
use App\Modules\Dropi\Actions\RegistrarDevolucion;
use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiAlistadorLock;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Support\Trazabilidad;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Operación Dropi (paridad Vue con Filament):
 *  - Vista del Alistador (recolección + empaque)
 *  - Registrar Devolución (delega a Action canónica)
 *  - Escáner cámara
 *  - Discrepancias wallet
 *
 * REGLA DE ORO: los cambios de estado se hacen SIEMPRE con $pedido->transicionar(),
 * y las Actions canónicas (RegistrarDevolucion, ConfirmarEmpaqueYSiguiente) mandan
 * sobre cualquier atajo. Nada de $pedido->update(['estado' => ...]) suelto.
 */
class DropiOperacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // N2 · Discrepancias muestra PII de clientes + montos por cobrar → SOLO Aracely.
        //     El resto (alistador, tomar, empacar, devolucionForm/Guardar, escanerCamara/Buscar)
        //     puede seguir habilitado para Alistador.
        return [
            new Middleware(function (Request $r, \Closure $next) {
                $u = $r->user();
                abort_unless($u, 403);

                $endpointsSoloAdmin = ['discrepancias'];
                $accion = $r->route()->getActionMethod();
                if (in_array($accion, $endpointsSoloAdmin, true) && ! $u->esAracely()) {
                    abort(403, 'Solo Aracely/Gerencia puede ver discrepancias.');
                }

                abort_unless($u->esAracely() || (method_exists($u, 'esAlistador') && $u->esAlistador()), 403);
                return $next($r);
            }),
        ];
    }

    // ============================================================
    // VISTA DEL ALISTADOR
    // ============================================================

    public function alistador(Request $request): Response
    {
        $modo = (string) $request->input('modo', 'recoleccion');
        if (! in_array($modo, ['recoleccion', 'empaque'], true)) $modo = 'recoleccion';

        $corte = DropiCorte::orderByDesc('fecha')->orderByDesc('numero')->first();
        $corteId = $corte?->id;

        $recoleccion = [];
        if ($corteId && $modo === 'recoleccion') {
            $pedidos = DropiPedido::where('corte_id', $corteId)
                ->whereNotIn('estado', [
                    EstadoPedidoDropi::Empacado, EstadoPedidoDropi::Despachado,
                    EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado,
                    EstadoPedidoDropi::CanceladoDropi, EstadoPedidoDropi::CanceladoGb,
                    EstadoPedidoDropi::Devuelto,
                ])
                ->with(['items.variante.producto'])
                ->get();
            $grupos = [];
            foreach ($pedidos as $ped) {
                foreach ($ped->items as $it) {
                    $key = $it->sku_dropi ?: 'sin-sku';
                    $grupos[$key] ??= [
                        'sku' => $key,
                        'producto' => $it->variante?->producto?->nombre ?? 'SKU sin match',
                        'referencia' => $it->variante?->producto?->referencia ?? '—',
                        'variante' => trim(($it->variante?->color_nombre ?? '') . ' ' . ($it->variante?->diseno_nombre ?? '') . ' ' . ($it->variante?->talla ?? '')),
                        'total' => 0,
                    ];
                    $grupos[$key]['total'] += (int) $it->cantidad;
                }
            }
            $recoleccion = array_values($grupos);
        }

        $empaque = [];
        if ($corteId && $modo === 'empaque') {
            $bloqueadosPorOtros = DropiAlistadorLock::where('alistador_id', '!=', auth()->id())
                ->where('heartbeat_at', '>=', now()->subMinutes(10))
                ->pluck('pedido_id');

            $empaque = DropiPedido::where('corte_id', $corteId)
                ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario, EstadoPedidoDropi::Alistando])
                ->whereNotIn('id', $bloqueadosPorOtros)
                ->orderBy('created_at')
                ->limit(20)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'guia' => $p->guia,
                    'cliente' => $p->cliente_nombre,
                    'ciudad' => $p->cliente_ciudad,
                    'monto' => (float) $p->monto_esperado_proveedor,
                    'estado' => is_object($p->estado) ? $p->estado->value : $p->estado,
                    'items_count' => (int) $p->items()->count(),
                    'items_unidades' => (int) $p->items()->sum('cantidad'),
                ])->all();
        }

        return Inertia::render('Dropi/Alistador', [
            'modo' => $modo,
            'corte' => $corte ? ['id' => $corte->id, 'numero' => $corte->numero, 'fecha' => $corte->fecha?->toDateString()] : null,
            'recoleccion' => $recoleccion,
            'empaque' => $empaque,
        ]);
    }

    /**
     * Alistador reclama un pedido para empacar. Toma el lock y transiciona el
     * estado a Alistando (idempotente).
     */
    public function tomarPedido(int $pedidoId): RedirectResponse
    {
        $userId = auth()->id();
        try {
            DB::transaction(function () use ($pedidoId, $userId) {
                $lock = DropiAlistadorLock::firstOrCreate(['pedido_id' => $pedidoId], ['alistador_id' => $userId]);
                if ($lock->alistador_id !== $userId) {
                    abort(409, 'Otro alistador ya está empacando este pedido.');
                }
                $lock->update(['heartbeat_at' => now()]);
                $p = DropiPedido::find($pedidoId);
                if ($p && (! ($p->estado instanceof EstadoPedidoDropi) || $p->estado !== EstadoPedidoDropi::Alistando)) {
                    $p->transicionar(EstadoPedidoDropi::Alistando, 'manual', $userId);
                }
            });
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Pedido tomado');
    }

    /**
     * Heartbeat del alistador — mantiene vivo el lock mientras trabaja.
     * El comando dropi:liberar-locks corre cada 2 min contra heartbeat > 10 min.
     */
    public function heartbeat(): RedirectResponse
    {
        $userId = auth()->id();
        DropiAlistadorLock::where('alistador_id', $userId)->update(['heartbeat_at' => now()]);
        return back(status: 204);
    }

    /**
     * Este endpoint YA NO cambia estado directo. Delega a
     * ConfirmarEmpaqueYSiguiente, que exige items pickeados.
     * Si el flujo no completó la Estación, devuelve error legible.
     */
    public function empacarPedido(Request $request, int $pedidoId): RedirectResponse
    {
        $userId = auth()->id();
        $gps = $request->validate(Trazabilidad::reglas())['gps'] ?? null;
        try {
            $miLock = DropiAlistadorLock::where('pedido_id', $pedidoId)->where('alistador_id', $userId)->exists();
            abort_unless($miLock, 409, 'El pedido lo tomó otro alistador o expiró.');

            ConfirmarEmpaqueYSiguiente::run($pedidoId, $userId, $gps);
            DropiAlistadorLock::where('pedido_id', $pedidoId)->delete();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Pedido empacado');
    }

    public function despacharPedido(Request $request, int $pedidoId): RedirectResponse
    {
        $userId = auth()->id();
        $gps = $request->validate(Trazabilidad::reglas())['gps'] ?? null;
        try {
            $p = DropiPedido::find($pedidoId);
            abort_unless($p, 404, 'Pedido no encontrado.');
            // Sólo puede despachar quien lo empacó (o Aracely).
            $puedeDespachar = auth()->user()?->esAracely()
                || \App\Modules\Dropi\Models\EmpaqueRegistro::where('pedido_id', $pedidoId)
                    ->where('operario_id', $userId)->exists();
            abort_unless($puedeDespachar, 409, 'Solo el alistador que empacó (o Aracely) puede despachar.');
            Trazabilidad::exigir($gps);

            $p->transicionar(
                EstadoPedidoDropi::Despachado, 'manual', $userId,
                ['origen' => 'vista_alistador'] + Trazabilidad::payload($gps),
                ['despachado_at' => now()],
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if (class_exists(\App\Modules\Dropi\Jobs\NotificarClienteDespachoJob::class)) {
            \App\Modules\Dropi\Jobs\NotificarClienteDespachoJob::dispatch($pedidoId);
        }
        return back()->with('success', 'Despachado — WhatsApp encolada');
    }

    // ============================================================
    // REGISTRAR DEVOLUCIÓN — DELEGA A LA ACTION CANÓNICA
    // ============================================================

    public function devolucionForm(Request $request): Response
    {
        $guiaBuscar = strtoupper(trim((string) $request->input('guia', '')));
        $pedido = null;
        if ($guiaBuscar) {
            $pedido = DropiPedido::where('guia', $guiaBuscar)
                ->orWhere('dropi_orden_id', $guiaBuscar)
                ->with(['items.variante.producto', 'items.producto'])
                ->first();
        }
        return Inertia::render('Dropi/Devolucion/Registrar', [
            'guiaBuscar' => $guiaBuscar,
            'pedido' => $pedido ? [
                'id' => $pedido->id,
                'guia' => $pedido->guia,
                'cliente' => $pedido->cliente_nombre,
                'ciudad' => $pedido->cliente_ciudad,
                'monto' => (float) $pedido->monto_esperado_proveedor,
                'estado' => is_object($pedido->estado) ? $pedido->estado->value : $pedido->estado,
                'items' => $pedido->items->map(fn ($i) => [
                    'sku' => $i->sku_dropi,
                    'descripcion' => trim(($i->variante?->producto?->nombre ?? '') . ' · ' . ($i->variante?->color_nombre ?? '') . ' ' . ($i->variante?->talla ?? '')),
                    'cantidad' => (int) $i->cantidad,
                ])->all(),
                'devolucion_existente' => DropiDevolucion::where('pedido_id', $pedido->id)->exists(),
                // 5.4 · lo que se re-escanea: un renglón por producto, con los
                // códigos que acepta la pistola (barras de la variante o
                // referencia del agregado).
                'por_escanear' => $this->porEscanear($pedido),
            ] : null,
            'destinos' => array_map(fn ($d) => ['value' => $d->value, 'label' => $d->label()], DestinoDevolucion::cases()),
        ]);
    }

    /**
     * @return list<array{clave:string, referencia:string, descripcion:string, esperadas:int, codigos:list<string>}>
     */
    private function porEscanear(DropiPedido $pedido): array
    {
        $out = [];
        foreach ($pedido->items as $i) {
            if (! $i->variante_id && ! $i->producto_id) continue;
            $clave = \App\Modules\Inventario\Support\CostoPromedio::clave($i->variante_id, $i->producto_id);
            $producto = $i->variante?->producto ?? $i->producto;
            $out[$clave] ??= [
                'clave' => $clave,
                'referencia' => (string) ($producto?->referencia ?? $i->sku_dropi),
                'descripcion' => trim(($producto?->nombre ?? '').' '.($i->variante?->color_nombre ?? '').' '.($i->variante?->talla ?? '')),
                'esperadas' => 0,
                'codigos' => array_values(array_filter(array_map(
                    fn ($c) => $c ? strtoupper(trim((string) $c)) : null,
                    $i->variante_id ? [$i->variante?->codigo_barras] : [$producto?->referencia],
                ))),
            ];
            $out[$clave]['esperadas'] += (int) $i->cantidad;
        }

        return array_values($out);
    }

    public function devolucionGuardar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pedido_id' => ['required', 'integer', 'exists:dropi_pedidos,id'],
            'destino_inventario' => ['required', Rule::in(array_map(fn ($d) => $d->value, DestinoDevolucion::cases()))],
            'notas' => ['nullable', 'string', 'max:500'],
            'recibidos' => ['nullable', 'array'],
            'recibidos.*' => ['integer', 'min:0'],
        ]);

        try {
            $pedido = DropiPedido::findOrFail($data['pedido_id']);
            $r = RegistrarDevolucion::run(
                $pedido->guia,
                DestinoDevolucion::from($data['destino_inventario']),
                (int) auth()->id(),
                $data['notas'] ?? null,
                // La pantalla siempre re-escanea: lo que no se escaneó no volvió.
                $data['recibidos'] ?? [],
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $faltan = array_sum($r['faltantes']);

        return redirect()->route('app.dropi.devolucion.registrar')->with(
            $faltan ? 'warning' : 'success',
            $faltan
                ? "Devolución registrada. Faltaron {$faltan} unidad(es): quedó anotado en la devolución."
                : 'Devolución registrada: todo lo despachado volvió.',
        );
    }

    // ============================================================
    // ESCÁNER CÁMARA
    // ============================================================

    public function escanerCamara(): Response
    {
        return Inertia::render('Dropi/EscanerCamara');
    }

    public function escanerBuscar(Request $request)
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:100'],
        ]);
        $codigo = strtoupper(trim($data['codigo']));
        $pedido = DropiPedido::where('guia', $codigo)
            ->orWhere('dropi_orden_id', $codigo)
            ->with(['items.variante.producto'])
            ->first();

        // N3/UX#2 · el monto SOLO se devuelve a Aracely (para Wallet-autocomplete).
        // Un Alistador que use este endpoint desde el escáner cámara NO lo recibe.
        $incluirMonto = auth()->user()?->esAracely() === true;

        return response()->json([
            'encontrado' => (bool) $pedido,
            'pedido' => $pedido ? array_filter([
                'id' => $pedido->id,
                'guia' => $pedido->guia,
                'cliente' => $pedido->cliente_nombre,
                'ciudad' => $pedido->cliente_ciudad,
                'monto' => $incluirMonto ? (float) $pedido->monto_esperado_proveedor : null,
                'estado' => is_object($pedido->estado) ? $pedido->estado->value : $pedido->estado,
                'items' => $pedido->items->map(fn ($i) => [
                    'sku' => $i->sku_dropi,
                    'descripcion' => trim(($i->variante?->producto?->nombre ?? '') . ' · ' . ($i->variante?->color_nombre ?? '') . ' ' . ($i->variante?->talla ?? '')),
                    'cantidad' => (int) $i->cantidad,
                ])->all(),
            ], fn ($v) => $v !== null) : null,
        ]);
    }

    // ============================================================
    // DISCREPANCIAS WALLET (filtrado en SQL, no en PHP · fix F11)
    // ============================================================

    public function discrepancias(Request $request): Response
    {
        // U27 · filtros de fecha (default: mes actual).
        $desde = $request->input('desde', now('America/Bogota')->startOfMonth()->toDateString());
        $hasta = $request->input('hasta', now('America/Bogota')->endOfDay()->toDateString());

        // F21 · pedidos que YA se debieron cobrar sin movimiento wallet:
        // - Pagado sin fila en wallet
        // - Entregado con más de 40 días sin cobro (fecha configurable en Reglas)
        $diasEsperaCobro = (int) (\App\Support\Reglas::get('dropi.dias_espera_cobro', 40));
        $limiteEntregado = now('America/Bogota')->subDays($diasEsperaCobro);

        $sinCobrar = DropiPedido::query()
            ->leftJoin('dropi_wallet_movimientos as w', 'w.pedido_id', '=', 'dropi_pedidos.id')
            ->whereNull('w.id')
            ->where(function ($q) use ($limiteEntregado, $desde, $hasta) {
                $q->where(function ($qq) use ($desde, $hasta) {
                    $qq->where('dropi_pedidos.estado', EstadoPedidoDropi::Pagado->value)
                        ->whereNotNull('dropi_pedidos.pagado_at')
                        ->whereBetween('dropi_pedidos.pagado_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
                })->orWhere(function ($qq) use ($limiteEntregado) {
                    $qq->where('dropi_pedidos.estado', EstadoPedidoDropi::Entregado->value)
                        ->whereNotNull('dropi_pedidos.entregado_at')
                        ->where('dropi_pedidos.entregado_at', '<=', $limiteEntregado);
                });
            })
            ->select([
                'dropi_pedidos.id', 'dropi_pedidos.guia', 'dropi_pedidos.dropi_orden_id',
                'dropi_pedidos.cliente_nombre', 'dropi_pedidos.monto_esperado_proveedor',
                'dropi_pedidos.pagado_at', 'dropi_pedidos.entregado_at', 'dropi_pedidos.estado',
            ])
            ->orderByDesc(DB::raw('COALESCE(dropi_pedidos.pagado_at, dropi_pedidos.entregado_at)'))
            ->limit(500)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'guia' => $p->guia, 'dropi_id' => $p->dropi_orden_id,
                'cliente' => $p->cliente_nombre, 'monto' => (float) $p->monto_esperado_proveedor,
                'pagado' => $p->pagado_at?->format('Y-m-d'),
                'entregado' => $p->entregado_at?->format('Y-m-d'),
                'estado' => is_object($p->estado) ? $p->estado->value : $p->estado,
            ]);

        // Movimientos wallet huérfanos (sin pedido).
        $movHuerfanos = DropiWalletMovimiento::whereNull('pedido_id')
            ->orderByDesc('fecha')->limit(200)->get()
            ->map(function ($m) {
                $tipo = is_object($m->tipo) ? ($m->tipo->value ?? (string) $m->tipo) : (string) $m->tipo;
                return [
                    'id' => $m->id, 'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : (string) $m->fecha,
                    'tipo' => $tipo, 'monto' => (float) $m->monto, 'categoria' => $m->categoria,
                    'referencia' => $m->dropi_movimiento_id,
                ];
            });

        return Inertia::render('Dropi/Discrepancias', [
            'pedidosSinCobro' => $sinCobrar->all(),
            'movimientosHuerfanos' => $movHuerfanos->all(),
            'kpis' => [
                'pedidos_sin_cobro' => $sinCobrar->count(),
                'monto_faltante' => (float) $sinCobrar->sum('monto'),
                'movimientos_huerfanos' => $movHuerfanos->count(),
            ],
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'dias_espera_cobro' => $diasEsperaCobro,
        ]);
    }
}
