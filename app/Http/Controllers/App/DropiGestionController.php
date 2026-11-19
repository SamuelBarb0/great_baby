<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Actions\CerrarCorte;
use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * DRP-B · CRUD Vue de gestión Dropi (paridad Filament):
 *  - Pedidos: editar estado + datos cliente
 *  - Cortes: crear, editar, cerrar/liquidar
 *  - Wallet movimientos: crear ajuste manual
 *  - Ubicaciones inventario: CRUD completo
 */
class DropiGestionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esAracely(), 403);
                return $next($r);
            }),
        ];
    }

    // ---------- PEDIDO (edit) ----------
    public function pedidoEditar(int $pedido): Response
    {
        $p = DropiPedido::with(['items.variante.producto', 'corte:id,numero'])->findOrFail($pedido);
        return Inertia::render('Dropi/Pedido/Editar', [
            'pedido' => [
                'id' => $p->id,
                'guia' => $p->guia,
                'dropi_orden_id' => $p->dropi_orden_id,
                'transportadora' => $p->transportadora,
                'estado' => is_object($p->estado) ? $p->estado->value : $p->estado,
                'cliente_nombre' => $p->cliente_nombre,
                'cliente_doc' => $p->cliente_doc,
                'cliente_telefono' => $p->cliente_telefono,
                'cliente_direccion' => $p->cliente_direccion,
                'cliente_ciudad' => $p->cliente_ciudad,
                'cliente_depto' => $p->cliente_depto,
                'monto_esperado_proveedor' => (float) $p->monto_esperado_proveedor,
                'ganancia_vendedor' => (float) $p->ganancia_vendedor,
                'flete_transportadora' => (float) $p->flete_transportadora,
                'corte_numero' => $p->corte?->numero,
                'items' => $p->items->map(fn ($i) => [
                    'sku' => $i->sku_dropi,
                    'descripcion' => trim(($i->variante?->producto?->nombre ?? '') . ' · ' . ($i->variante?->color_nombre ?? '') . ' ' . ($i->variante?->talla ?? '')),
                    'cantidad' => (int) $i->cantidad,
                    'precio' => (float) $i->precio_proveedor_unit,
                ])->all(),
            ],
            'estadosDisponibles' => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label() ?? $c->name], EstadoPedidoDropi::cases()),
            'trazabilidad' => \App\Modules\Dropi\Support\Trazabilidad::lineaDeTiempo($p),
        ]);
    }

    public function pedidoActualizar(Request $r, int $pedido): RedirectResponse
    {
        $estados = array_map(fn ($c) => $c->value, EstadoPedidoDropi::cases());
        $data = $r->validate([
            'estado' => ['required', 'string', Rule::in($estados)],
            'cliente_nombre' => ['nullable', 'string', 'max:200'],
            'cliente_telefono' => ['nullable', 'string', 'max:50'],
            'cliente_direccion' => ['nullable', 'string', 'max:500'],
            'cliente_ciudad' => ['nullable', 'string', 'max:100'],
            'cliente_depto' => ['nullable', 'string', 'max:100'],
            'transportadora' => ['nullable', 'string', 'max:100'],
            'monto_esperado_proveedor' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $p = DropiPedido::with('corte')->findOrFail($pedido);

            $corteCerrado = $p->corte && (
                ($p->corte->estado instanceof EstadoCorte && $p->corte->estado === EstadoCorte::Cerrado)
                || (is_string($p->corte->estado) && $p->corte->estado === EstadoCorte::Cerrado->value)
            );
            if ($corteCerrado) {
                abort(422, "Pedido {$p->guia}: no se puede editar — el corte {$p->corte->numero} está cerrado.");
            }

            $estadoNuevo = EstadoPedidoDropi::from($data['estado']);
            $camposDirectos = array_diff_key($data, ['estado' => true]);
            $p->update($camposDirectos);

            if ($p->estado !== $estadoNuevo) {
                $p->transicionar($estadoNuevo, 'manual', (int) auth()->id(), ['origen' => 'pedido_editar']);
            }
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', 'Pedido actualizado.');
    }

    // ---------- CORTES ----------
    public function cortesIndex(): Response
    {
        $cortes = DropiCorte::withCount('pedidos')->orderByDesc('id')->paginate(30);
        return Inertia::render('Dropi/Corte/Index', [
            'cortes' => $cortes->through(fn ($c) => [
                'id' => $c->id,
                'numero' => $c->numero,
                'fecha' => $c->fecha?->toDateString(),
                'estado' => $c->estado,
                'pedidos_count' => (int) $c->pedidos_count,
                'pedidos_totales' => (int) $c->pedidos_totales,
                'cerrado_at' => $c->cerrado_at?->format('Y-m-d H:i'),
            ]),
        ]);
    }

    public function corteCrear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'numero' => ['required', 'integer', 'min:1'],
            'fecha' => ['required', 'date'],
        ]);
        DropiCorte::create([
            'numero' => $data['numero'],
            'fecha' => $data['fecha'],
            'estado' => 'abierto',
        ]);
        return back()->with('success', 'Corte creado.');
    }

    public function corteCerrar(int $corte): RedirectResponse
    {
        try {
            $r = CerrarCorte::run($corte, (int) auth()->id());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $c = DropiCorte::find($corte);
        return back()->with('success', sprintf(
            'Corte %d cerrado. Remisiones: %d · Facturas B2B: %d · Hash: %s',
            $c?->numero ?? 0,
            $r['remisiones'] ?? 0,
            $r['facturas_b2b'] ?? 0,
            substr((string) ($r['hash'] ?? ''), 0, 12),
        ));
    }

    // ---------- WALLET MOVIMIENTOS ----------
    public function walletIndex(): Response
    {
        $movs = DropiWalletMovimiento::orderByDesc('fecha')->paginate(30);
        $bruto = (float) DropiWalletMovimiento::sum('monto');
        // Re-audit H3 · misma regla: solo restar sanciones que NO afectaron el wallet.
        $sanciones = (float) \App\Modules\Dropi\Models\DropiSancion::query()
            ->whereIn('tipo', ['pago_sobre_devuelto', 'categoria_explicita'])
            ->sum('diferencia');

        return Inertia::render('Dropi/Wallet/Index', [
            'movimientos' => $movs->through(function ($m) {
                $tipo = is_object($m->tipo) ? ($m->tipo->value ?? (string) $m->tipo) : (string) $m->tipo;
                $fuente = is_array($m->fuente) ? json_encode($m->fuente, JSON_UNESCAPED_UNICODE) : (string) ($m->fuente ?? '');
                return [
                    'id' => $m->id,
                    'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : (string) $m->fecha,
                    'tipo' => $tipo,
                    'monto' => (float) $m->monto,
                    'pedido_id' => $m->pedido_id,
                    'categoria' => $m->categoria,
                    'fuente' => $fuente,
                    'referencia' => $m->dropi_movimiento_id,
                ];
            }),
            'saldo' => $bruto - $sanciones,
            'saldo_bruto' => $bruto,
            'sanciones_total' => $sanciones,
        ]);
    }

    public function walletCrear(Request $r): RedirectResponse
    {
        $tipos = array_map(fn ($c) => $c->value, TipoMovimientoWallet::cases());
        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'tipo' => ['required', 'string', Rule::in($tipos)],
            'monto' => ['required', 'numeric'],
            'pedido_id' => ['nullable', 'integer', 'exists:dropi_pedidos,id'],
            'guia' => ['nullable', 'string', 'max:60'],
            // Re-audit DR-ι (SEG-B4) · whitelist categoría — antes string libre
            //   envenenaba reportes agrupados por categoría con typos/valores
            //   arbitrarios. Estas son las categorías conocidas del negocio;
            //   se pueden ampliar en `config/dropi.php`.
            'categoria' => ['nullable', 'string', 'max:100', Rule::in([
                'ajuste_manual', 'flete_devuelto', 'costo_bancario', 'reembolso',
                'sanción_dropi', 'bono', 'comisión', 'otro',
            ])],
        ]);

        // N6 seg · signo del monto coherente con el tipo — evita mostrar saldo
        // inflado/negativo por un ajuste con signo equivocado.
        //   ingresos (PagoGuia/Indemnizacion): monto >= 0
        //   egresos (RetiroBanco/FleteGarantia/Tarjeta): monto <= 0
        $tiposIngreso = ['pago_guia', 'indemnizacion'];
        $tiposEgreso = ['retiro_banco', 'flete_garantia', 'tarjeta'];
        if (in_array($data['tipo'], $tiposIngreso, true) && $data['monto'] < 0) {
            return back()->with('error', "El tipo {$data['tipo']} exige monto positivo.")->withInput();
        }
        if (in_array($data['tipo'], $tiposEgreso, true) && $data['monto'] > 0) {
            return back()->with('error', "El tipo {$data['tipo']} exige monto negativo.")->withInput();
        }

        // U9/U20 · si vino guía en vez de pedido_id, resolver.
        if (empty($data['pedido_id']) && ! empty($data['guia'])) {
            $g = strtoupper(trim($data['guia']));
            $data['pedido_id'] = DropiPedido::where('guia', $g)->value('id');
        }
        unset($data['guia']);

        // Ajuste manual → sintetizar ID único e idempotente (P7).
        $synthId = 'synth:manual:' . hash('sha256', implode('|', [
            $data['fecha'], $data['tipo'], (string) $data['monto'],
            (string) ($data['pedido_id'] ?? ''), (string) ($data['categoria'] ?? ''),
            (string) microtime(true),
        ]));

        DropiWalletMovimiento::create([
            ...$data,
            'dropi_movimiento_id' => $synthId,
            'fuente' => ['origen' => 'ajuste_manual', 'user_id' => auth()->id()],
        ]);
        return back()->with('success', 'Movimiento wallet registrado.');
    }

    // ---------- UBICACIONES INVENTARIO ----------
    public function ubicacionesIndex(): Response
    {
        $ubicaciones = InventarioUbicacion::orderBy('codigo')->paginate(50);
        return Inertia::render('Dropi/Ubicaciones/Index', [
            'ubicaciones' => $ubicaciones->through(fn ($u) => [
                'id' => $u->id,
                'codigo' => $u->codigo,
                'nombre' => $u->nombre,
                'categoria' => is_object($u->categoria) ? $u->categoria->value : $u->categoria,
                'disponible_para_venta' => (bool) $u->disponible_para_venta,
                'activa' => (bool) $u->activa,
                'notas' => $u->notas,
            ]),
            'categorias' => array_map(
                fn ($c) => ['value' => $c->value, 'label' => $c->label()],
                \App\Modules\Dropi\Enums\CategoriaUbicacion::cases()
            ),
        ]);
    }

    public function ubicacionGuardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
            'codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:100'],
            'categoria' => ['nullable', 'string', 'max:50'],
            'disponible_para_venta' => ['boolean'],
            'activa' => ['boolean'],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['id'] ?? null) {
            InventarioUbicacion::findOrFail($data['id'])->update($data);
        } else {
            InventarioUbicacion::create($data);
        }
        return back()->with('success', 'Ubicación guardada.');
    }

    public function ubicacionEliminar(int $ubicacion): RedirectResponse
    {
        $u = InventarioUbicacion::findOrFail($ubicacion);

        // U29 · guard: no borrar si hay movimientos históricos ni saldo actual.
        $tieneMovs = \App\Modules\Dropi\Models\InventarioMovimiento::where('ubicacion_id', $u->id)->exists();
        if ($tieneMovs) {
            return back()->with('error', "No se puede eliminar {$u->codigo}: tiene movimientos históricos. Desactívala en su lugar.");
        }

        try {
            $u->delete();
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo eliminar la ubicación: ' . $e->getMessage());
        }
        return back()->with('success', 'Ubicación eliminada.');
    }
}
