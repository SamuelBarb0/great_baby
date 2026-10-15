<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Compras\Actions\RegistrarDevolucionProveedor;
use App\Modules\Compras\Models\DevolucionProveedor;
use App\Modules\Compras\Models\DevolucionProveedorItem;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Siigo\Jobs\PushDevolucionProveedorASiigo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * COMP-B1 UI · Wizard de devoluciones a proveedor.
 *
 *   1. Index: lista con filtros + badge SIIGO.
 *   2. Create/Edit (borrador): header + repeater de items + motivo.
 *   3. Confirmar: dispara RegistrarDevolucionProveedor::confirmar().
 *   4. Reenviar manual a SIIGO si falló el push automático.
 */
class DevolucionProveedorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            // LOG · El AdminBodega también crea devoluciones a proveedor
            //   (es quien detecta la mercancía averiada en recepción).
            abort_unless(
                $u && ($u->esEquipoBodega() || $u->hasAnyRole(['Contador'])),
                403,
                'Solo gerencia, bodega o contabilidad gestiona devoluciones.'
            );
            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        $items = DevolucionProveedor::with(['proveedor:id,nombre_completo,numero_documento', 'ubicacion:id,codigo,nombre'])
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn ($d) => [
                'id' => $d->id,
                'numero' => $d->numero,
                'fecha' => $d->fecha?->format('Y-m-d'),
                'proveedor' => $d->proveedor?->nombre_completo,
                'proveedor_doc' => $d->proveedor?->numero_documento,
                'ubicacion' => $d->ubicacion ? "{$d->ubicacion->codigo} · {$d->ubicacion->nombre}" : null,
                'motivo' => $d->motivo,
                'estado' => $d->estado,
                'total' => (float) $d->total,
                'siigo_id' => $d->siigo_id,
                'siigo_sync_at' => $d->siigo_sync_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Compras/DevolucionesProveedor/Index', ['items' => $items]);
    }

    public function create(): Response
    {
        return Inertia::render('Compras/DevolucionesProveedor/Form', [
            'devolucion' => null,
            'proveedores' => Contacto::query()
                ->where('es_proveedor', true)
                ->orderBy('nombre_completo')
                ->limit(500)
                ->get(['id', 'nombre_completo as nombre', 'numero_documento']),
            'ubicaciones' => InventarioUbicacion::where('activa', true)
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function show(int $id): Response
    {
        $d = DevolucionProveedor::with([
            'proveedor:id,nombre_completo,numero_documento',
            'ubicacion:id,codigo,nombre',
            'items.variante.producto:id,referencia,nombre',
            'items.producto:id,referencia,nombre',
            'recepcion:id,numero',
        ])->findOrFail($id);

        return Inertia::render('Compras/DevolucionesProveedor/Form', [
            'devolucion' => [
                'id' => $d->id,
                'numero' => $d->numero,
                'fecha' => $d->fecha?->format('Y-m-d'),
                'proveedor_id' => $d->proveedor_id,
                'proveedor' => $d->proveedor ? [
                    'id' => $d->proveedor->id,
                    'nombre' => $d->proveedor->nombre_completo,
                    'numero_documento' => $d->proveedor->numero_documento,
                ] : null,
                'ubicacion_id' => $d->ubicacion_id,
                'ubicacion' => $d->ubicacion ? "{$d->ubicacion->codigo} · {$d->ubicacion->nombre}" : null,
                'recepcion_id' => $d->recepcion_id,
                'recepcion_numero' => $d->recepcion?->numero,
                'motivo' => $d->motivo,
                'estado' => $d->estado,
                'subtotal' => (float) $d->subtotal,
                'iva' => (float) $d->iva,
                'total' => (float) $d->total,
                'siigo_id' => $d->siigo_id,
                'siigo_sync_at' => $d->siigo_sync_at?->format('Y-m-d H:i'),
                'confirmada_at' => $d->confirmada_at?->format('Y-m-d H:i'),
                'items' => $d->items->map(fn ($it) => [
                    'id' => $it->id,
                    'variante_id' => $it->variante_id,
                    'producto_id' => $it->producto_id,
                    'sku' => $it->variante?->codigo_barras,
                    'referencia' => $it->variante?->producto?->referencia ?? $it->producto?->referencia,
                    'nombre' => $it->variante?->producto?->nombre ?? $it->producto?->nombre,
                    'detalle' => trim(($it->variante?->color_nombre ?? '').' '.($it->variante?->talla ?? '')),
                    'cantidad' => (float) $it->cantidad,
                    'costo_unit' => (float) $it->costo_unit,
                    'iva_pct' => (float) $it->iva_pct,
                    'subtotal' => (float) $it->subtotal,
                    'motivo_item' => $it->motivo_item,
                ])->values(),
            ],
            'proveedores' => Contacto::query()
                ->where('es_proveedor', true)
                ->orderBy('nombre_completo')
                ->limit(500)
                ->get(['id', 'nombre_completo as nombre', 'numero_documento']),
            'ubicaciones' => InventarioUbicacion::where('activa', true)
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function guardar(Request $r): JsonResponse
    {
        $datos = $r->validate([
            'id' => ['nullable', 'integer', 'exists:devoluciones_proveedor,id'],
            'proveedor_id' => ['required', 'integer', 'exists:contactos,id'],
            'ubicacion_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            // Misma trampa que en recepciones: la tabla es `compras_recepciones`
            // (así la declara `RecepcionCompra::$table`), no `recepciones_compra`.
            // Al ser `nullable` sólo reventaba cuando alguien SÍ elegía la
            // recepción de origen, que es el caso normal de una devolución a
            // proveedor. Encontrado el 2026-10-14 barriendo las reglas `exists`
            // contra las tablas que existen de verdad.
            'recepcion_id' => ['nullable', 'integer', 'exists:compras_recepciones,id'],
            'fecha' => ['required', 'date'],
            'motivo' => ['required', 'string', 'max:200'],
        ]);

        if (! empty($datos['id'])) {
            $d = DevolucionProveedor::findOrFail($datos['id']);
            abort_if($d->estado !== 'borrador', 409, 'Solo se edita en Borrador.');
            $d->fill($datos)->save();
        } else {
            $datos['numero'] = $this->siguienteNumero();
            $datos['estado'] = 'borrador';
            $datos['creada_por'] = $r->user()->id;
            $datos['subtotal'] = 0; $datos['iva'] = 0; $datos['total'] = 0;
            $d = DevolucionProveedor::create($datos);
        }
        return response()->json([
            'ok' => true,
            'id' => $d->id,
            'numero' => $d->numero,
            'mensaje' => "Devolución {$d->numero} guardada.",
        ]);
    }

    public function itemGuardar(Request $r, int $id): JsonResponse
    {
        $datos = $r->validate([
            'variante_id' => ['nullable', 'required_without:producto_id', 'integer', 'exists:producto_variantes,id'],
            'producto_id' => ['nullable', 'required_without:variante_id', 'integer', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'min:0.0001'],
            'costo_unit' => ['required', 'numeric', 'min:0'],
            'iva_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'motivo_item' => ['nullable', 'string', 'max:200'],
        ]);
        $d = DevolucionProveedor::findOrFail($id);
        abort_if($d->estado !== 'borrador', 409, 'Solo se agregan items en Borrador.');

        if (! empty($datos['variante_id']) && ! empty($datos['producto_id'])) {
            return response()->json(['ok' => false, 'mensaje' => 'Elegí variante O producto agregado, no ambos.'], 422);
        }

        $cant = round((float) $datos['cantidad'], 4);
        $costo = round((float) $datos['costo_unit'], 4);
        $ivaPct = round((float) ($datos['iva_pct'] ?? 0), 2);
        $subtotal = round($cant * $costo, 2);

        $item = DevolucionProveedorItem::create([
            'devolucion_id' => $d->id,
            'variante_id' => $datos['variante_id'] ?? null,
            'producto_id' => $datos['producto_id'] ?? null,
            'cantidad' => $cant,
            'costo_unit' => $costo,
            'iva_pct' => $ivaPct,
            'subtotal' => $subtotal,
            'motivo_item' => $datos['motivo_item'] ?? null,
        ]);

        $this->recalcularTotales($d);

        return response()->json([
            'ok' => true,
            'item_id' => $item->id,
            'subtotal' => (float) $d->subtotal,
            'iva' => (float) $d->iva,
            'total' => (float) $d->total,
            'mensaje' => 'Ítem agregado.',
        ]);
    }

    public function itemEliminar(Request $r, int $id, int $itemId): JsonResponse
    {
        $d = DevolucionProveedor::findOrFail($id);
        abort_if($d->estado !== 'borrador', 409, 'Solo se eliminan items en Borrador.');
        DevolucionProveedorItem::where('devolucion_id', $d->id)->where('id', $itemId)->delete();
        $this->recalcularTotales($d);
        return response()->json([
            'ok' => true,
            'subtotal' => (float) $d->subtotal, 'iva' => (float) $d->iva, 'total' => (float) $d->total,
            'mensaje' => 'Ítem eliminado.',
        ]);
    }

    /**
     * COMP-B2 · Preview de los asientos contables que se generarán al
     * confirmar la devolución. Replica EL MISMO cálculo que ejecuta el
     * Action, pero sin tocar BD · le muestra a Aracely la partida doble
     * antes de dar baja de kardex y CxP.
     *
     * Devuelve los 3 renglones (DB 2205 CxP, CR cta inventario de la
     * ubicación, CR 2408 IVA) + totales + validación cuadrado.
     */
    public function previewAsiento(int $id): JsonResponse
    {
        $d = DevolucionProveedor::with(['items', 'ubicacion', 'proveedor:id,nombre_completo'])->findOrFail($id);

        $items = $d->items;
        if ($items->isEmpty()) {
            return response()->json(['ok' => false, 'mensaje' => 'La devolución no tiene ítems.'], 422);
        }

        // Recalculamos como lo hace el Action (sin confiar en los totales
        // cacheados, por si el front mandó items distintos).
        $subtotal = 0; $iva = 0;
        foreach ($items as $it) {
            $base = round((float) $it->cantidad * (float) $it->costo_unit, 2);
            $subtotal += $base;
            $iva += round($base * ((float) $it->iva_pct / 100), 2);
        }
        $total = round($subtotal + $iva, 2);

        // Cuenta de inventario efectiva · por ubicación o la global default.
        $ctaInv = $d->ubicacion?->ctaInventarioEfectiva()
            ?? (string) setting('contable.cta_inventario_default', '1435');

        $renglones = [
            [
                'cuenta' => '2205',
                'glosa' => "Devolución {$d->numero} · reversa CxP a {$d->proveedor?->nombre_completo}",
                'debe' => round($total, 2),
                'haber' => 0,
                'nota' => 'Baja la cuenta por pagar al proveedor por el total devuelto.',
            ],
            [
                'cuenta' => $ctaInv,
                'glosa' => "Devolución {$d->numero} · baja inventario de {$d->ubicacion?->codigo}",
                'debe' => 0,
                'haber' => round($subtotal, 2),
                'nota' => 'Sale la mercancía del kardex a costo (sin IVA).',
            ],
        ];
        if ($iva > 0) {
            $renglones[] = [
                'cuenta' => '2408',
                'glosa' => "Devolución {$d->numero} · reversa IVA descontable",
                'debe' => 0,
                'haber' => round($iva, 2),
                'nota' => 'Reversa el IVA descontable que se tomó en la compra original.',
            ];
        }

        $totalDebe = array_sum(array_column($renglones, 'debe'));
        $totalHaber = array_sum(array_column($renglones, 'haber'));

        return response()->json([
            'ok' => true,
            'numero' => $d->numero,
            'proveedor' => $d->proveedor?->nombre_completo,
            'ubicacion' => $d->ubicacion ? "{$d->ubicacion->codigo} · {$d->ubicacion->nombre}" : null,
            'renglones' => $renglones,
            'totales' => [
                'debe' => round($totalDebe, 2),
                'haber' => round($totalHaber, 2),
                'cuadrado' => abs($totalDebe - $totalHaber) < 0.01,
                'subtotal' => $subtotal,
                'iva' => $iva,
                'total' => $total,
            ],
            'items_count' => $items->count(),
            'items_unidades' => (float) $items->sum('cantidad'),
            'siigo_destino' => 'POST /v1/credit-notes · NC de compra',
        ]);
    }

    public function confirmar(Request $r, int $id): JsonResponse
    {
        $d = DevolucionProveedor::findOrFail($id);
        try {
            $d = app(RegistrarDevolucionProveedor::class)->confirmar($d);
            return response()->json([
                'ok' => true,
                'estado' => $d->estado,
                'mensaje' => "Devolución {$d->numero} confirmada. Push a SIIGO en segundo plano.",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }
    }

    /**
     * FIX-S0 · descartar borrador. Permite al usuario liberar una devolución
     * que creó por error (antes quedaban zombies con total=0).
     */
    public function descartar(Request $r, int $id): JsonResponse
    {
        $d = DevolucionProveedor::findOrFail($id);
        abort_if($d->estado !== 'borrador', 409, 'Solo se descartan devoluciones en Borrador.');
        $numero = $d->numero;
        DB::transaction(function () use ($d) {
            DevolucionProveedorItem::where('devolucion_id', $d->id)->delete();
            $d->delete();
        });
        return response()->json(['ok' => true, 'mensaje' => "Borrador {$numero} descartado."]);
    }

    public function reenviarSiigo(Request $r, int $id): JsonResponse
    {
        $d = DevolucionProveedor::findOrFail($id);
        abort_if($d->estado !== 'confirmada', 409, 'Solo se reenvían devoluciones Confirmadas.');
        if ($d->siigo_id) {
            return response()->json([
                'ok' => false,
                'mensaje' => "Ya tiene NC en SIIGO (#{$d->siigo_id}). No se reenvía para no duplicar.",
            ], 409);
        }
        PushDevolucionProveedorASiigo::dispatchManual($d->id);
        return response()->json(['ok' => true, 'mensaje' => 'Reenviando a SIIGO…']);
    }

    /**
     * Autocomplete de variantes CON STOCK > 0 en la ubicación elegida.
     *
     * FIX-S0 · antes mostraba también variantes con stock=0 en esa bodega
     * (porque filtraba el stock después del match). Ahora filtra primero por
     * existencia real en la ubicación. También devuelve `costo_promedio` para
     * que el front autollene el campo "Costo unit" al seleccionar.
     */
    public function buscarItems(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q', ''));
        $ubicacionId = (int) $r->input('ubicacion_id', 0);
        if (mb_strlen($q) < 2 || ! $ubicacionId) {
            return response()->json([]);
        }

        // 1. Variantes candidatas por búsqueda textual.
        $vars = ProductoVariante::query()
            ->with('producto:id,referencia,nombre')
            ->where(function ($x) use ($q) {
                $x->where('codigo_barras', 'like', "%{$q}%")
                  ->orWhereHas('producto', fn ($p) =>
                      $p->where('nombre', 'like', "%{$q}%")
                        ->orWhere('referencia', 'like', "%{$q}%")
                  );
            })
            ->limit(30)
            ->get();

        // 2. Pre-calculamos saldo + costo promedio ponderado en una sola query
        //    por la ubicación · evita N+1.
        $ids = $vars->pluck('id')->all();
        $saldos = InventarioMovimiento::whereIn('variante_id', $ids)
            ->where('ubicacion_id', $ubicacionId)
            ->selectRaw('variante_id, SUM(cantidad) as saldo, '
                      .'CASE WHEN SUM(cantidad) > 0 '
                      .'THEN SUM(CASE WHEN cantidad > 0 THEN cantidad * COALESCE(costo_unit, 0) ELSE 0 END) '
                      .'   / NULLIF(SUM(CASE WHEN cantidad > 0 THEN cantidad ELSE 0 END), 0) '
                      .'ELSE 0 END as costo_prom')
            ->groupBy('variante_id')
            ->get()
            ->keyBy('variante_id');

        $out = $vars->map(function ($v) use ($saldos) {
            $row = $saldos->get($v->id);
            return [
                'variante_id' => $v->id,
                'producto_id' => null,
                'sku' => $v->codigo_barras,
                'referencia' => $v->producto?->referencia,
                'nombre' => $v->producto?->nombre,
                'detalle' => trim(($v->color_nombre ?? '').' '.($v->talla ?? '')),
                'stock_actual' => (int) ($row?->saldo ?? 0),
                'costo_promedio' => round((float) ($row?->costo_prom ?? 0), 2),
                'label' => trim(($v->producto?->nombre ?? '?').' · '.($v->color_nombre ?? '').' '.($v->talla ?? '').' ['.$v->codigo_barras.']'),
            ];
        })
        // 3. Filtrar: solo con stock positivo en la bodega.
        ->filter(fn ($r) => $r['stock_actual'] > 0)
        ->values();

        return response()->json($out);
    }

    /**
     * FIX-S0 · generación atómica del consecutivo (antes race condition:
     * 2 users concurrentes podían obtener DEV-YYYY-NNNNNN duplicado).
     * Transacción + lock sobre la fila máxima del año.
     */
    private function siguienteNumero(): string
    {
        $year = now()->format('Y');
        $prefix = "DEV-{$year}-";
        return DB::transaction(function () use ($prefix) {
            $last = DevolucionProveedor::where('numero', 'like', "{$prefix}%")
                ->orderByDesc('numero')
                ->lockForUpdate()
                ->value('numero');
            $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
            return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        });
    }

    private function recalcularTotales(DevolucionProveedor $d): void
    {
        $sub = 0; $iva = 0;
        foreach ($d->items()->get() as $it) {
            $base = round((float) $it->cantidad * (float) $it->costo_unit, 2);
            $sub += $base;
            $iva += round($base * ((float) $it->iva_pct / 100), 2);
        }
        $d->subtotal = round($sub, 2);
        $d->iva = round($iva, 2);
        $d->total = round($sub + $iva, 2);
        $d->save();
    }
}
