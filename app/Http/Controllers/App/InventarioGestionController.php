<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Models\AlertaStockConfig;
use App\Modules\Inventario\Support\CostoPromedio;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Inventario\Models\TomaFisicaItem;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Marketing\Services\MuestrasMarketing;
use App\Modules\Inventario\Models\TrasladoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventarioGestionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            // UBIC-8 · AdminBodega entra con scope limitado por bodegasAsignadasIds().
            abort_unless(
                $u && (
                    $u->esAracely()
                    || (method_exists($u, 'esAlistador') && $u->esAlistador())
                    || (method_exists($u, 'esAdminBodega') && $u->esAdminBodega())
                ),
                403
            );
            return $next($r);
        })];
    }

    // ---------- KARDEX POR VARIANTE ----------
    public function kardex(Request $request): Response
    {
        $codigo = trim((string) $request->input('codigo', ''));
        $variante = null;
        // INV-A6 · soporte para producto AGREGADO (sin variantes). Antes solo
        // ProductoVariante era navegable desde kardex; los agregados no
        // tenían historial consultable aunque sí movimientos en la BD.
        $productoAgregado = null;
        $movimientos = [];
        $saldoTotal = 0;
        $costoPromedio = 0;
        $valorStock = 0;

        if ($codigo) {
            // 1. Buscar variante granular por código de barras.
            $variante = ProductoVariante::with('producto:id,referencia,nombre')
                ->where('codigo_barras', $codigo)
                ->first();
            // 2. Si no hay variante, probar como producto AGREGADO por referencia.
            if (! $variante) {
                $productoAgregado = \App\Modules\Dropi\Models\Producto::where('referencia', $codigo)
                    ->where('desglose_stock', false)
                    ->first(['id', 'referencia', 'nombre', 'desglose_stock']);
            }

            if ($variante || $productoAgregado) {
                $u = $request->user();
                $q = InventarioMovimiento::with('ubicacion:id,codigo,nombre');
                if ($variante) {
                    $q->where('variante_id', $variante->id);
                } else {
                    // Agregado: variante_id NULL + producto_id del agregado.
                    $q->whereNull('variante_id')->where('producto_id', $productoAgregado->id);
                }
                if (! $u->esAracely()) {
                    $bodegas = $u->bodegasAsignadasIds();
                    if (empty($bodegas)) {
                        $q->whereRaw('1=0');
                    } else {
                        $q->whereIn('ubicacion_id', $bodegas);
                    }
                }
                $movs = $q->orderBy('created_at')->limit(500)->get();

                // Sprint 3 · Kardex FORMATO SIIGO · calcula:
                //   1. Costo promedio ponderado tras cada entrada.
                //   2. Valor de cada movimiento (cantidad × costo).
                //   3. Valor total inventario tras cada movimiento (saldo × costo_prom).
                //   4. Documento origen con label legible + link.
                //   5. Estado sync SIIGO (siigo_journal_id, color badge).
                $saldo = 0; $costoProm = 0;
                $movimientos = $movs->map(function ($m) use (&$saldo, &$costoProm) {
                    $cantidad = (int) $m->cantidad;
                    $costoUnit = (float) ($m->costo_unit ?? 0);
                    $valorMov = round(abs($cantidad) * $costoUnit, 2);

                    // Costo promedio ponderado: solo se recalcula en ENTRADAS con costo.
                    if ($cantidad > 0 && $costoUnit > 0) {
                        $saldoAnterior = $saldo;
                        $valorAnterior = $saldoAnterior * $costoProm;
                        $valorNuevo = $valorAnterior + ($cantidad * $costoUnit);
                        $saldoNuevo = $saldoAnterior + $cantidad;
                        $costoProm = $saldoNuevo > 0 ? round($valorNuevo / $saldoNuevo, 4) : 0;
                    }

                    $saldo += $cantidad;
                    $valorStock = round($saldo * $costoProm, 2);

                    // SIIGO badge según siigo_journal_id.
                    $siigoColor = $m->siigo_journal_id
                        ? 'emerald'
                        : (in_array($m->tipo, ['traslado_salida', 'traslado_entrada', 'traslado_reversa_salida', 'traslado_reversa_entrada', 'merma', 'faltante', 'sobrante', 'ajuste_toma_fisica'], true) ? 'amber' : 'gray');

                    return [
                        'id' => $m->id,
                        'fecha' => $m->created_at?->format('Y-m-d H:i'),
                        'tipo' => $m->tipo,
                        'ubicacion' => $m->ubicacion?->codigo . ' · ' . $m->ubicacion?->nombre,
                        'cantidad' => $cantidad,
                        'costo_unit' => $costoUnit,
                        'valor_mov' => $valorMov,
                        'saldo' => $saldo,
                        'costo_prom' => $costoProm,
                        'valor_stock' => $valorStock,
                        'referencia_tipo' => $m->referencia_tipo ? class_basename($m->referencia_tipo) : null,
                        'referencia_id' => $m->referencia_id,
                        'referencia_label' => $this->labelReferencia($m->referencia_tipo, $m->referencia_id),
                        'referencia_link' => $this->linkReferencia($m->referencia_tipo, $m->referencia_id),
                        'notas' => $m->notas,
                        'siigo_journal_id' => $m->siigo_journal_id,
                        'siigo_sync_hace' => $m->siigo_sync_at?->diffForHumans(),
                        'siigo_color' => $siigoColor,
                    ];
                });
                $saldoTotal = $saldo;
                $costoPromedio = $costoProm;
                $valorStock = round($saldo * $costoProm, 2);
            }
        }

        return Inertia::render('Inventario/Kardex', [
            'codigo' => $codigo,
            // INV-A6 · unificamos la salida: la variante usada por el UI puede
            // venir de una ProductoVariante granular O de un Producto agregado.
            // El Vue sigue usando `variante` como objeto único, no hay cambios
            // visuales obligatorios.
            'variante' => $variante ? [
                'id' => $variante->id, 'codigo' => $variante->codigo_barras,
                'producto' => $variante->producto?->nombre, 'referencia' => $variante->producto?->referencia,
                'detalle' => trim(($variante->color_nombre ?? '') . ' ' . ($variante->talla ?? '')),
                'siigo_id' => $variante->siigo_id,
                'siigo_code' => $variante->siigo_code,
            ] : ($productoAgregado ? [
                'id' => $productoAgregado->id, 'codigo' => $productoAgregado->referencia,
                'producto' => $productoAgregado->nombre, 'referencia' => $productoAgregado->referencia,
                'detalle' => 'Producto agregado · sin variantes',
                'siigo_id' => null, 'siigo_code' => null,
                'es_agregado' => true,
            ] : null),
            'movimientos' => $movimientos,
            'saldoTotal' => $saldoTotal,
            'costoPromedio' => $costoPromedio,
            'valorStock' => $valorStock,
        ]);
    }

    /**
     * Sprint 3 · Kardex SIIGO · label legible del documento origen.
     */
    /**
     * INV-A2 · resumen del badge SIIGO para una colección de movimientos.
     * Verde = todos con journal · ámbar = parciales/pendientes ·
     * gris = sin movs todavía. Usado por Traslado/Conteo/ReporteStock Show.
     */
    private function resumirBadgeSiigo(\Illuminate\Support\Collection $movs): array
    {
        if ($movs->isEmpty()) {
            return ['color' => 'gray', 'label' => 'Sin movimientos', 'total' => 0, 'con_siigo' => 0];
        }
        $conSiigo = $movs->filter(fn ($m) => ! empty($m->siigo_journal_id))->count();
        $total = $movs->count();
        $color = match (true) {
            $conSiigo === 0 => 'amber',
            $conSiigo < $total => 'amber',
            default => 'emerald',
        };
        $label = $color === 'emerald'
            ? "SIIGO ✓ · {$total}/{$total} asientos"
            : "Pendiente · {$conSiigo}/{$total} en SIIGO";
        return [
            'color' => $color, 'label' => $label,
            'total' => $total, 'con_siigo' => $conSiigo,
            'ultima_sync_hace' => optional($movs->max('siigo_sync_at'))?->diffForHumans(),
        ];
    }

    private function labelReferencia(?string $tipo, ?int $id): string
    {
        if (! $tipo || ! $id) return '—';
        return match (class_basename($tipo)) {
            'RecepcionCompra' => "Recepción #{$id}",
            'FacturaVenta' => "Factura #{$id}",
            'Traslado' => "Traslado #{$id}",
            'TomaFisica' => "Toma física #{$id}",
            'DropiPedido' => "Pedido Dropi #{$id}",
            default => class_basename($tipo) . " #{$id}",
        };
    }

    /**
     * Sprint 3 · Kardex SIIGO · link Vue al documento origen.
     */
    private function linkReferencia(?string $tipo, ?int $id): ?string
    {
        if (! $tipo || ! $id) return null;
        return match (class_basename($tipo)) {
            'RecepcionCompra' => "/app/compras/recepcion/{$id}",
            'FacturaVenta' => "/app/cartera/facturas/{$id}",
            'Traslado' => "/app/inventario/traslados/{$id}",
            'TomaFisica' => "/app/inventario/conteos/{$id}",
            default => null,
        };
    }

    // ---------- REPORTE STOCK POR BODEGA/UBICACIÓN ----------
    public function reporteStock(): Response
    {
        // Sprint 3 · F.2 · Reporte stock FORMATO SIIGO.
        // Añade: valorización por bodega, costo promedio agregado, valor total inventario,
        // costo promedio y valor por SKU en el top.

        // Costo promedio por variante (basado en entradas con costo).
        $costoPromedios = $this->calcularCostosPromedio();

        // Total unidades por ubicación
        // Contaba `DISTINCT variante_id`, así que una bodega llena de productos
        // agregados decía «0 SKUs» al lado de sus propias unidades.
        $saldos = InventarioMovimiento::selectRaw(
            'ubicacion_id, SUM(cantidad) as total, '
            .'COUNT(DISTINCT COALESCE(CONCAT("v", variante_id), CONCAT("p", producto_id))) as skus'
        )
            ->groupBy('ubicacion_id')
            ->having('total', '!=', 0)
            ->get()->keyBy('ubicacion_id');

        // Valorización por ubicación: Σ (saldo × costo_prom) por cada SKU en esa ubicación.
        $valorPorUbicacion = $this->calcularValorPorUbicacion($costoPromedios);

        $ubicaciones = InventarioUbicacion::where('activa', true)->orderBy('codigo')->get()
            ->map(function ($u) use ($saldos, $valorPorUbicacion) {
                $r = $saldos->get($u->id);
                return [
                    'codigo' => $u->codigo, 'nombre' => $u->nombre,
                    'categoria' => is_object($u->categoria) ? $u->categoria->value : $u->categoria,
                    'skus' => (int) ($r->skus ?? 0),
                    'unidades' => (int) ($r->total ?? 0),
                    'valor' => (float) ($valorPorUbicacion[$u->id] ?? 0),
                ];
            });

        /*
         * Top 30 con más stock.
         *
         * Pedía `whereNotNull('variante_id')`, así que los productos AGREGADOS
         * no aparecían nunca: la pantalla decía «Top 30 con más stock» y dejaba
         * afuera buena parte de la mercancía que hay en bodega.
         */
        $porVariante = InventarioMovimiento::selectRaw('variante_id as sujeto, SUM(cantidad) as total')
            ->whereNotNull('variante_id')
            ->groupBy('variante_id')->having('total', '>', 0)
            ->orderByDesc('total')->limit(30)->get()
            ->map(fn ($r) => ['tipo' => 'v', 'id' => (int) $r->sujeto, 'total' => (int) $r->total]);

        $porProducto = InventarioMovimiento::selectRaw('producto_id as sujeto, SUM(cantidad) as total')
            ->whereNull('variante_id')->whereNotNull('producto_id')
            ->groupBy('producto_id')->having('total', '>', 0)
            ->orderByDesc('total')->limit(30)->get()
            ->map(fn ($r) => ['tipo' => 'p', 'id' => (int) $r->sujeto, 'total' => (int) $r->total]);

        $topStock = $porVariante->concat($porProducto)->sortByDesc('total')->take(30)->values();

        $vars = ProductoVariante::with('producto:id,referencia,nombre')
            ->whereIn('id', $topStock->where('tipo', 'v')->pluck('id')->all())
            ->get()->keyBy('id');
        $prods = Producto::whereIn('id', $topStock->where('tipo', 'p')->pluck('id')->all())
            ->get(['id', 'referencia', 'nombre', 'siigo_code'])->keyBy('id');

        $top = $topStock->map(function ($r) use ($vars, $prods, $costoPromedios) {
            $esVariante = $r['tipo'] === 'v';
            $v = $esVariante ? $vars->get($r['id']) : null;
            $p = $esVariante ? $v?->producto : $prods->get($r['id']);
            $costoProm = (float) ($costoPromedios[$r['tipo'].':'.$r['id']] ?? 0);

            return [
                'codigo' => ($esVariante ? $v?->codigo_barras : $p?->referencia) ?? '—',
                'producto' => $p?->nombre ?? '—',
                'total' => $r['total'],
                'costo_prom' => $costoProm,
                'valor' => round($r['total'] * $costoProm, 2),
                'siigo_code' => $esVariante ? $v?->siigo_code : $p?->siigo_code,
            ];
        });

        return Inertia::render('Inventario/ReporteStock', [
            'ubicaciones' => $ubicaciones->values(),
            'topStock' => $top,
            'kpis' => [
                'ubicaciones_activas' => $ubicaciones->count(),
                'unidades_totales' => (int) $ubicaciones->sum('unidades'),
                'skus_con_stock' => $topStock->count(),
                'valor_total_inventario' => (float) $ubicaciones->sum('valor'),
            ],
        ]);
    }

    /**
     * Sprint 3 · F.2 · costo promedio ponderado por variante desde ENTRADAS
     * (traversal 1× de inventario_movimientos con costo_unit > 0).
     * Devuelve [variante_id => costo_prom].
     */
    private function calcularCostosPromedio(): array
    {
        /*
         * Agrupaba sólo por `variante_id`. Los productos AGREGADOS —los que no
         * tienen variante, que es la mayor parte del catálogo de Great Baby—
         * caían todos en una sola fila con la clave NULL: el costo de una pijama
         * de $12.000 promediado con el de un coche de $400.000, y ese número
         * mezclado aplicado después a los dos. Encontrado el 2026-10-13 en el
         * «Reporte de stock por bodega».
         *
         * Se devuelve con las mismas claves que usa el resto del inventario:
         * `v:{id}` por variante, `p:{id}` por producto agregado.
         */
        $out = [];

        $promedio = function ($campo, $prefijo) use (&$out) {
            $q = InventarioMovimiento::selectRaw(
                "{$campo} as sujeto, SUM(cantidad * costo_unit) as valor_ent, SUM(cantidad) as cant_ent"
            )
                ->where('cantidad', '>', 0)
                ->where('costo_unit', '>', 0)
                ->whereNotNull($campo)
                ->groupBy($campo);

            // Un producto cuenta como agregado sólo si el movimiento no trae
            // variante; si no, se contaría dos veces lo mismo.
            if ($campo === 'producto_id') {
                $q->whereNull('variante_id');
            }

            foreach ($q->get() as $r) {
                $out[$prefijo.':'.$r->sujeto] = $r->cant_ent > 0
                    ? round((float) $r->valor_ent / (float) $r->cant_ent, 4) : 0;
            }
        };

        $promedio('variante_id', 'v');
        $promedio('producto_id', 'p');

        return $out;
    }

    /**
     * Sprint 3 · F.2 · valor de inventario por ubicación · Σ(saldo_variante × costo_prom).
     * Devuelve [ubicacion_id => valor].
     */
    private function calcularValorPorUbicacion(array $costos): array
    {
        $saldos = InventarioMovimiento::selectRaw(
            'ubicacion_id, variante_id, producto_id, SUM(cantidad) as saldo'
        )
            ->groupBy('ubicacion_id', 'variante_id', 'producto_id')
            ->having('saldo', '>', 0)
            ->get();
        $out = [];
        foreach ($saldos as $r) {
            $costo = (float) ($costos[CostoPromedio::clave($r->variante_id, $r->producto_id)] ?? 0);
            $out[$r->ubicacion_id] = ($out[$r->ubicacion_id] ?? 0) + ((int) $r->saldo * $costo);
        }
        return array_map(fn ($v) => round($v, 2), $out);
    }

    // ---------- CONTEO FÍSICO ----------
    public function conteosIndex(): Response
    {
        $tomas = TomaFisica::with(['ubicacion:id,codigo,nombre', 'creador:id,name'])
            ->orderByDesc('id')->paginate(30);
        return Inertia::render('Inventario/Conteo/Index', [
            'tomas' => $tomas->through(fn ($t) => [
                'id' => $t->id, 'numero' => $t->numero,
                'ubicacion' => $t->ubicacion?->codigo . ' · ' . $t->ubicacion?->nombre,
                'creador' => $t->creador?->name,
                'estado' => $t->estado, 'tipo' => $t->tipo, 'alcance' => $t->alcance,
                'fecha' => $t->fecha_conteo?->format('Y-m-d'),
                'items_diferentes' => (int) $t->items_diferentes,
            ]),

            // Sin esto el botón «Nuevo conteo» era decorativo: abría el modal
            // con el desplegable de ubicaciones VACÍO, así que nunca se podía
            // crear una toma física. La pantalla declaraba la prop y el
            // controlador no la mandaba. Encontrado el 2026-10-13 haciendo el
            // recorrido completo.
            'ubicaciones' => InventarioUbicacion::where('activa', true)
                // Las de SIIGO son bodegas importadas del catálogo: no se
                // cuentan, son destino contable. Mismo criterio que en la
                // pantalla de ubicaciones.
                ->where('codigo', 'not like', 'SIIGO-%')
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function conteoCrear(Request $r): RedirectResponse
    {
        // Re-audit M3 PATRÓN η · alcance con regex whitelist coherente con
        //   PrepararTomaFisica (marca:N | categoria:N).
        // Re-audit M3 PATRÓN M19 · valida `alcance` con regex, no string libre.
        $data = $r->validate([
            'ubicacion_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'tipo' => ['required', 'in:ciclico,total,puntual'],
            'alcance' => ['nullable', 'string', 'regex:/^(marca|categoria):\d+$/'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        // Re-audit M3 λ (SEG-M2) · Alistador sólo crea toma en bodegas asignadas.
        $u = $r->user();
        if (! $u->esAracely()) {
            $bodegas = $u->bodegasAsignadasIds();
            abort_unless(
                in_array((int) $data['ubicacion_id'], $bodegas, true),
                403,
                'Sólo puedes crear tomas en bodegas asignadas a tu perfil.'
            );
        }

        // Re-audit M3 PATRÓN η · uso `TomaFisica::siguienteNumero()` atómico.
        //   Antes: `count()+1` bajo concurrencia = colisión. Además formato
        //   TF-ymd-NNN divergía del TF-YYYY-NNNNNN del método del modelo.
        $data['numero'] = TomaFisica::siguienteNumero();
        $data['creada_por'] = auth()->id();
        $data['fecha_conteo'] = now('America/Bogota')->toDateString();
        $data['estado'] = \App\Modules\Inventario\Enums\EstadoTomaFisica::Borrador;
        $toma = TomaFisica::create($data);
        return back()->with('success', "Toma {$toma->numero} creada.");
    }

    // ---------- TRASLADOS ----------
    public function trasladosIndex(Request $r): Response
    {
        // PATRÓN α · pasar lista de ubicaciones para el selector por NOMBRE
        //   (antes: modal pedía "Origen (ID)" y "Destino (ID)" → operario
        //   tenía que ir a otro tab a copiar números).
        $ubicaciones = InventarioUbicacion::where('activa', true)
            ->orderBy('codigo')->get(['id','codigo','nombre','categoria']);

        $q = Traslado::with(['origen:id,codigo,nombre', 'destino:id,codigo,nombre', 'solicitante:id,name'])
            ->orderByDesc('id');
        if ($estado = $r->input('estado')) $q->where('estado', $estado);
        if ($origen = $r->input('origen_id')) $q->where('origen_id', $origen);

        $trasl = $q->paginate(30)->withQueryString();

        return Inertia::render('Inventario/Traslado/Index', [
            'traslados' => $trasl->through(fn ($t) => [
                'id' => $t->id, 'numero' => $t->numero,
                'origen' => $t->origen?->codigo . ' · ' . $t->origen?->nombre,
                'destino' => $t->destino?->codigo . ' · ' . $t->destino?->nombre,
                'solicitante' => $t->solicitante?->name,
                'estado' => is_object($t->estado) ? $t->estado->value : $t->estado,
                'motivo' => $t->motivo,
                'fecha' => optional($t->fecha_solicitud)->toDateString(),
            ]),
            'ubicaciones' => $ubicaciones,
            'filtros' => $r->only(['estado','origen_id']),
        ]);
    }

    // ---------- TRASLADO SHOW (repeater items + acciones state machine) ----------
    public function trasladoShow(int $id): Response
    {
        $t = Traslado::with(['items.variante.producto', 'origen', 'destino', 'solicitante', 'ejecutor',
                'aprobador', 'enviador', 'recogedor', 'prestamo'])
            ->findOrFail($id);
        $esMuestra = $t->esMuestra();

        // INV-A2 · badge SIIGO del traslado · se calcula desde los movimientos
        // kardex ligados: si todos los traslado_salida/entrada tienen
        // siigo_journal_id → verde (asientos ya en SIIGO); si alguno falta → ámbar;
        // sin movs todavía → gris.
        $siigoBadge = $this->resumirBadgeSiigo(
            \App\Modules\Dropi\Models\InventarioMovimiento::where('referencia_tipo', Traslado::class)
                ->where('referencia_id', $t->id)
                ->get(['id', 'siigo_journal_id', 'siigo_sync_at'])
        );

        return Inertia::render('Inventario/Traslado/Show', [
            'traslado' => [
                'id' => $t->id, 'numero' => $t->numero,
                'origen' => ['id' => $t->origen_id, 'nombre' => $t->origen?->codigo . ' · ' . $t->origen?->nombre],
                'destino' => ['id' => $t->destino_id, 'nombre' => $t->destino?->codigo . ' · ' . $t->destino?->nombre],
                'estado' => is_object($t->estado) ? $t->estado->value : $t->estado,
                'siigo' => $siigoBadge,
                'motivo' => $t->motivo,
                'observaciones' => $t->observaciones,
                'solicitante' => $t->solicitante?->name,
                'ejecutor' => $t->ejecutor?->name,
                'fecha_solicitud' => optional($t->fecha_solicitud)->format('Y-m-d'),
                'fecha_envio' => optional($t->fecha_envio)->format('Y-m-d H:i'),
                'fecha_ejecucion' => optional($t->fecha_ejecucion)->format('Y-m-d H:i'),
                // MKT-5 · muestras de Marketing: quién aprobó, entregó y recogió.
                'muestra' => $esMuestra ? [
                    'es_prestamo' => $t->esPrestamoMarketing(),
                    'aprobado_por' => $t->aprobador?->name,
                    'aprobado_at' => optional($t->aprobado_at)->format('Y-m-d H:i'),
                    'enviado_por' => $t->enviador?->name,
                    'recogido_por' => $t->recogedor?->name,
                    'estado_mercancia' => $t->estado_mercancia,
                    'estado_mercancia_label' => Traslado::ESTADOS_MERCANCIA[$t->estado_mercancia] ?? null,
                    'observaciones_entrega' => $t->observaciones_entrega,
                    'prestamo' => $t->prestamo?->numero,
                ] : null,
                'items' => $t->items->map(fn ($it) => [
                    'id' => $it->id,
                    'variante_id' => $it->variante_id,
                    'sku' => $it->variante?->codigo_barras,
                    'producto' => $it->variante?->producto?->nombre,
                    'detalle' => trim(($it->variante?->color_nombre ?? '') . ' ' . ($it->variante?->talla ?? '')),
                    'cantidad_solicitada' => (float) $it->cantidad_solicitada,
                    'cantidad_ejecutada' => (float) $it->cantidad_ejecutada,
                    'notas' => $it->notas,
                ])->values(),
            ],
            'equipo_marketing' => $esMuestra ? app(MuestrasMarketing::class)->equipo() : [],
            'estados_mercancia' => $esMuestra ? Traslado::ESTADOS_MERCANCIA : [],
        ]);
    }

    public function trasladoItemGuardar(Request $r, int $id): RedirectResponse
    {
        // INV-A7 · acepta producto agregado (producto_id XOR variante_id).
        // Antes solo validaba variante → productos en modo agregado no se
        // podían trasladar desde el UI, aunque los Actions ya los soportaban.
        $data = $r->validate([
            'variante_id' => ['nullable', 'required_without:producto_id', 'integer', 'exists:producto_variantes,id'],
            'producto_id' => ['nullable', 'required_without:variante_id', 'integer', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'min:0.0001', 'max:999999'],
            'notas' => ['nullable', 'string', 'max:200'],
        ]);
        if (! empty($data['variante_id']) && ! empty($data['producto_id'])) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'Elegí variante O producto agregado, no ambos.']);
        }
        $t = Traslado::findOrFail($id);
        abort_unless(
            (is_object($t->estado) ? $t->estado->value : $t->estado) === 'borrador',
            409, 'Sólo se editan items en estado Borrador.'
        );
        // scope por bodega
        $u = $r->user();
        if (! $u->esAracely()) {
            $bod = $u->bodegasAsignadasIds();
            abort_unless(in_array((int)$t->origen_id, $bod, true) && in_array((int)$t->destino_id, $bod, true), 403);
        }
        \App\Modules\Inventario\Models\TrasladoItem::updateOrCreate(
            [
                'traslado_id' => $t->id,
                'variante_id' => $data['variante_id'] ?? null,
                'producto_id' => $data['producto_id'] ?? null,
            ],
            ['cantidad_solicitada' => round((float) $data['cantidad'], 4), 'notas' => $data['notas'] ?? null]
        );
        return back()->with('success', 'Ítem guardado.');
    }

    public function trasladoItemEliminar(Request $r, int $id, int $itemId): RedirectResponse
    {
        $t = Traslado::findOrFail($id);
        abort_unless(
            (is_object($t->estado) ? $t->estado->value : $t->estado) === 'borrador',
            409, 'Sólo se editan items en estado Borrador.'
        );
        $u = $r->user();
        if (! $u->esAracely()) {
            $bod = $u->bodegasAsignadasIds();
            abort_unless(in_array((int)$t->origen_id, $bod, true) && in_array((int)$t->destino_id, $bod, true), 403);
        }
        \App\Modules\Inventario\Models\TrasladoItem::where('traslado_id', $t->id)->whereKey($itemId)->delete();
        return back()->with('success', 'Ítem eliminado.');
    }

    /**
     * Un traslado sólo lo mueve quien responde por sus dos bodegas.
     *
     * Varios métodos de este controller ya lo validaban y otros no: por esos
     * huecos un admin de bodega podía enviar, recibir o anular traslados de
     * otra sede, que mueven stock y generan asiento en SIIGO.
     */
    private function autorizarTraslado(Traslado $t): void
    {
        $u = request()->user();
        if (! $u || $u->esAracely()) {
            return;
        }
        $bodegas = $u->bodegasAsignadasIds();
        abort_unless(
            in_array((int) $t->origen_id, $bodegas, true) && in_array((int) $t->destino_id, $bodegas, true),
            403,
            'Este traslado es de otra bodega.'
        );
    }

    /**
     * Una toma física sólo la opera quien responde por esa bodega. Sin esto se
     * podía escribir la cantidad contada de otra sede y cerrarla: un ajuste de
     * inventario ajeno, con su asiento contable, disfrazado de conteo.
     */
    private function autorizarToma(TomaFisica $toma): void
    {
        $u = request()->user();
        if (! $u || $u->esAracely()) {
            return;
        }
        abort_unless(
            in_array((int) $toma->ubicacion_id, $u->bodegasAsignadasIds(), true),
            403,
            'Esta toma física es de otra bodega.'
        );
    }

    public function trasladoEnviar(int $id): RedirectResponse
    {
        $t = Traslado::findOrFail($id);
        $this->autorizarTraslado($t);
        try {
            $t = app(\App\Modules\Inventario\Actions\EjecutarTraslado::class)->enviar($t);
            if ($t->esPrestamoMarketing()) {
                app(MuestrasMarketing::class)->avisarLista($t);
                return back()->with('success', "Muestras {$t->numero} listas: se le avisó a Marketing que pase a recogerlas.");
            }
            return back()->with('success', "Traslado {$t->numero} enviado.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function trasladoRecibir(Request $r, int $id): RedirectResponse
    {
        $t = Traslado::with(['origen', 'destino'])->findOrFail($id);
        $this->autorizarTraslado($t);

        // MKT-5 · en las muestras de Marketing la entrega queda firmada: quién
        // se la llevó (o la trajo de vuelta) y en qué estado iba.
        if ($t->esMuestra()) {
            $data = $r->validate([
                'recogido_por' => ['required', 'integer', 'exists:users,id'],
                'estado_mercancia' => ['required', 'in:'.implode(',', array_keys(Traslado::ESTADOS_MERCANCIA))],
                'observaciones_entrega' => ['nullable', 'string', 'max:1000', 'required_unless:estado_mercancia,completo'],
            ], [
                'observaciones_entrega.required_unless' => 'Si no va completa y en buen estado, anotá qué pasó.',
            ], [
                'recogido_por' => $t->esPrestamoMarketing() ? 'quién la recogió' : 'quién la trajo',
                'estado_mercancia' => 'estado de la mercancía',
            ]);
            try {
                app(MuestrasMarketing::class)->entregar($t, (int) $data['recogido_por'],
                    $data['estado_mercancia'], $data['observaciones_entrega'] ?? null);
                return back()->with('success', $t->esPrestamoMarketing()
                    ? "Muestras {$t->numero} entregadas a Marketing."
                    : "Devolución {$t->numero} recibida en bodega.");
            } catch (\Throwable $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        try {
            app(\App\Modules\Inventario\Actions\EjecutarTraslado::class)->recibir($t);
            return back()->with('success', "Traslado {$t->numero} recibido.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function trasladoAnular(Request $r, int $id): RedirectResponse
    {
        $data = $r->validate(['motivo' => ['required','string','min:5','max:500']]);
        $t = Traslado::findOrFail($id);
        $this->autorizarTraslado($t);
        try {
            $t = app(\App\Modules\Inventario\Actions\EjecutarTraslado::class)->anular($t, $data['motivo']);
            if ($t->esMuestra()) {
                app(MuestrasMarketing::class)->avisarAnulada($t, $data['motivo']);
            }
            return back()->with('success', "Traslado {$t->numero} anulado.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** MKT-5 · aprobar una solicitud de muestras de Marketing. */
    public function trasladoAprobar(Request $r, int $id): RedirectResponse
    {
        $t = Traslado::with(['origen', 'destino'])->findOrFail($id);
        $this->autorizarTraslado($t);
        try {
            app(MuestrasMarketing::class)->aprobar($t, $r->user());
            return back()->with('success', "Solicitud {$t->numero} aprobada. Agregá las referencias y marcala lista.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ---------- CONTEO SHOW + ACCIONES ----------
    public function conteoShow(int $id): Response
    {
        $toma = TomaFisica::with(['items.variante.producto', 'ubicacion', 'creador', 'cerrador'])->findOrFail($id);
        $estado = is_object($toma->estado) ? $toma->estado->value : $toma->estado;

        // INV-A2 · badge SIIGO del conteo · ajuste_toma_fisica dispara journal
        // en SIIGO (post BUG-INV). Mostramos el estado del asiento aquí.
        $siigoBadge = $this->resumirBadgeSiigo(
            \App\Modules\Dropi\Models\InventarioMovimiento::where('referencia_tipo', TomaFisica::class)
                ->where('referencia_id', $toma->id)
                ->get(['id', 'siigo_journal_id', 'siigo_sync_at'])
        );

        return Inertia::render('Inventario/Conteo/Show', [
            'toma' => [
                'id' => $toma->id, 'numero' => $toma->numero,
                'ubicacion' => $toma->ubicacion?->codigo . ' · ' . $toma->ubicacion?->nombre,
                'creador' => $toma->creador?->name,
                'cerrador' => $toma->cerrador?->name,
                'estado' => $estado,
                'siigo' => $siigoBadge,
                'tipo' => $toma->tipo, 'alcance' => $toma->alcance,
                'fecha_conteo' => optional($toma->fecha_conteo)->format('Y-m-d'),
                'cerrada_at' => optional($toma->cerrada_at)->format('Y-m-d H:i'),
                'items_diferentes' => (int) $toma->items_diferentes,
                'valor_ajuste' => (float) $toma->valor_ajuste,
                'items' => $toma->items->map(fn ($it) => [
                    'id' => $it->id,
                    'sku' => $it->variante?->codigo_barras,
                    'producto' => $it->variante?->producto?->nombre,
                    'detalle' => trim(($it->variante?->color_nombre ?? '') . ' ' . ($it->variante?->talla ?? '')),
                    'saldo_sistema' => (float) $it->saldo_sistema,
                    'cantidad_contada' => $it->cantidad_contada === null ? null : (float) $it->cantidad_contada,
                    'diferencia' => (float) $it->diferencia,
                    'costo_unit' => (float) $it->costo_unit,
                ])->values(),
            ],
        ]);
    }

    public function conteoIniciar(int $id): RedirectResponse
    {
        $toma = TomaFisica::findOrFail($id);
        $this->autorizarToma($toma);
        try {
            \App\Modules\Inventario\Actions\PrepararTomaFisica::run($toma);
            return back()->with('success', "Toma {$toma->numero} lista para capturar.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function conteoItemGuardar(Request $r, int $id, int $itemId): RedirectResponse
    {
        $data = $r->validate([
            'cantidad_contada' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);
        $toma = TomaFisica::findOrFail($id);
        $this->autorizarToma($toma);
        $item = \App\Modules\Inventario\Models\TomaFisicaItem::whereKey($itemId)->firstOrFail();
        abort_unless($item->toma_id === $toma->id, 403);
        abort_unless(
            (is_object($toma->estado) ? $toma->estado->value : $toma->estado) === 'en_conteo',
            409, 'Sólo se captura mientras la toma está En Conteo.'
        );
        $item->cantidad_contada = ($data['cantidad_contada'] === null || $data['cantidad_contada'] === '')
            ? null : round((float) $data['cantidad_contada'], 4);
        $item->save();
        return back()->with('success', 'Cantidad guardada.');
    }

    public function conteoCerrar(int $id): RedirectResponse
    {
        $toma = TomaFisica::findOrFail($id);
        $this->autorizarToma($toma);
        try {
            \App\Modules\Inventario\Actions\CerrarTomaFisica::run($toma);
            return back()->with('success', "Toma {$toma->numero} cerrada.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Sprint 3 · A.4 · Elimina una toma que aún no ha empezado (estado=borrador,
     * cero items contados). Preserva integridad contable: si hay conteos ya
     * hechos, no elimina, sugerir anular en su lugar.
     */
    public function conteoEliminar(int $id): RedirectResponse
    {
        $toma = TomaFisica::findOrFail($id);
        if ($toma->estado !== 'borrador') {
            return back()->with('flash', [
                'type' => 'error',
                'message' => "Solo se pueden eliminar tomas en BORRADOR. Esta está {$toma->estado} · usa Anular.",
            ]);
        }
        $contados = $toma->items()->whereNotNull('cantidad_contada')->count();
        if ($contados > 0) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => "La toma tiene {$contados} items ya contados · usa Anular en vez de eliminar.",
            ]);
        }
        $num = $toma->numero;
        $toma->items()->delete();
        $toma->delete();
        return back()->with('flash', ['type' => 'success', 'message' => "Toma {$num} eliminada."]);
    }

    /**
     * Sprint 3 · A.4 · Anula una toma en curso (estado=en_conteo).
     * NO borra los items — deja registro de auditoría.
     */
    public function conteoAnular(Request $r, int $id): RedirectResponse
    {
        $data = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:300']]);
        $toma = TomaFisica::findOrFail($id);

        // INV-A8 · usar enum en vez de strings sueltos. Si el cast está activo
        // $toma->estado es el enum; comparamos contra los cases para evitar
        // divergencia silenciosa si alguien cambia los valores del enum.
        $estadoActual = $toma->estado instanceof \App\Modules\Inventario\Enums\EstadoTomaFisica
            ? $toma->estado
            : \App\Modules\Inventario\Enums\EstadoTomaFisica::tryFrom((string) $toma->estado);
        $permitidos = [
            \App\Modules\Inventario\Enums\EstadoTomaFisica::Borrador,
            \App\Modules\Inventario\Enums\EstadoTomaFisica::EnConteo,
        ];
        if (! in_array($estadoActual, $permitidos, true)) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => "No se puede anular una toma en estado {$estadoActual?->label()}.",
            ]);
        }

        $toma->estado = \App\Modules\Inventario\Enums\EstadoTomaFisica::Anulada;
        $toma->observaciones = trim(($toma->observaciones ?? '') . "\n[ANULADA " . now()->toDateString() . " por " . auth()->user()?->name . "] " . $data['motivo']);
        $toma->save();

        return back()->with('flash', ['type' => 'success', 'message' => "Toma {$toma->numero} anulada."]);
    }

    // ---------- ALERTAS CRUD ----------
    public function alertaGuardar(Request $r): RedirectResponse
    {
        // INV-A5 · soporta producto agregado (producto_id) además de variante,
        // y stock_maximo (que ya existe en la tabla pero no se exponía).
        // Validación XOR: debe venir variante_id O producto_id, no ambos ni ninguno.
        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:alertas_stock_config,id'],
            'variante_id' => ['nullable', 'required_without:producto_id', 'integer', 'exists:producto_variantes,id'],
            'producto_id' => ['nullable', 'required_without:variante_id', 'integer', 'exists:productos,id'],
            'ubicacion_id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id'],
            'stock_minimo' => ['required', 'integer', 'min:0'],
            'stock_maximo' => ['nullable', 'integer', 'min:0'],
            'punto_reorden' => ['nullable', 'integer', 'min:0'],
            'cantidad_reorden' => ['nullable', 'integer', 'min:0'],
            'notificar_email' => ['boolean'],
            'notificar_whatsapp' => ['boolean'],
            'activa' => ['boolean'],
        ]);
        if (! empty($data['variante_id']) && ! empty($data['producto_id'])) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'Elegí variante O producto agregado, no ambos.']);
        }

        if (! empty($data['id'])) {
            $a = AlertaStockConfig::findOrFail($data['id']);
            $a->fill($data)->save();
        } else {
            AlertaStockConfig::updateOrCreate(
                [
                    'variante_id' => $data['variante_id'] ?? null,
                    'producto_id' => $data['producto_id'] ?? null,
                    'ubicacion_id' => $data['ubicacion_id'] ?? null,
                ],
                $data
            );
        }
        return back()->with('success', 'Alerta guardada.');
    }

    public function alertaEliminar(int $id): RedirectResponse
    {
        AlertaStockConfig::whereKey($id)->delete();
        return back()->with('success', 'Alerta eliminada.');
    }

    public function trasladoCrear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'origen_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'destino_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id', 'different:origen_id'],
            'motivo' => ['required', 'string', 'max:200'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        // Re-audit M3 λ (SEG-C2) · Alistador scoped por bodegas asignadas.
        //   Aracely/Gerencia pasan; Alistador debe tener AMBAS bodegas en su
        //   perfil. Antes cualquier Alistador podía drenar bodega ajena.
        $u = $r->user();
        if (! $u->esAracely()) {
            $bodegas = $u->bodegasAsignadasIds();
            abort_unless(
                in_array((int) $data['origen_id'], $bodegas, true)
                && in_array((int) $data['destino_id'], $bodegas, true),
                403,
                'Sólo puedes crear traslados entre bodegas asignadas a tu perfil.'
            );
        }

        // Re-audit M3 PATRÓN η · `Traslado::siguienteNumero()` atómico TRA-YYYY-NNNNNN
        //   (antes `count()+1` con formato TR-ymd-NNN incoherente).
        $data['numero'] = Traslado::siguienteNumero();
        $data['solicitado_por'] = auth()->id();
        $data['fecha_solicitud'] = now('America/Bogota')->toDateString();
        $data['estado'] = \App\Modules\Inventario\Enums\EstadoTraslado::Borrador;
        $t = Traslado::create($data);
        return back()->with('success', "Traslado {$t->numero} creado.");
    }

    // ---------- ALERTAS STOCK ----------
    public function alertasIndex(Request $r): Response
    {
        $configs = AlertaStockConfig::with(['variante.producto:id,nombre', 'ubicacion:id,codigo,nombre'])
            ->orderByDesc('id')->paginate(30);
        $ubicaciones = InventarioUbicacion::where('activa', true)->orderBy('codigo')->get(['id','codigo','nombre']);
        return Inertia::render('Inventario/Alertas/Index', [
            // FIX-S0 · botón "Importar Excel" solo para Aracely/Gerencia (es
            // acción masiva con contrapartida contable). Antes se mostraba
            // también al Alistador aunque el endpoint devolvía 403.
            'can_importar_alertas' => (bool) $r->user()?->esAracely(),
            'alertas' => $configs->through(fn ($a) => [
                'id' => $a->id,
                'variante_id' => $a->variante_id,
                'producto' => $a->variante?->producto?->nombre,
                'sku' => $a->variante?->codigo_barras,
                'ubicacion_id' => $a->ubicacion_id,
                'ubicacion' => $a->ubicacion ? ($a->ubicacion->codigo . ' · ' . $a->ubicacion->nombre) : '— Global —',
                'minimo' => (int) $a->stock_minimo,
                'reorden' => (int) $a->punto_reorden,
                'cantidad_reorden' => (int) $a->cantidad_reorden,
                'notificar_email' => (bool) $a->notificar_email,
                'notificar_whatsapp' => (bool) $a->notificar_whatsapp,
                'activa' => (bool) $a->activa,
            ]),
            'ubicaciones' => $ubicaciones,
        ]);
    }

    // ---------- INV-B1 · BUSCADOR INTELIGENTE GLOBAL ----------

    /**
     * INV-B1 · Buscador inteligente para Inventario / Logística.
     *
     * Una sola consulta que busca en TRES ejes y los devuelve agrupados:
     *   · variantes (código de barras, nombre producto, referencia)
     *   · productos agregados (sin desglose: referencia / nombre)
     *   · ubicaciones (código / nombre / ciudad)
     *
     * Cada resultado trae su "drill-down" para que la UI arme el link al
     * Kardex / Reporte stock / Ubicación. Respeta scope por bodega para
     * AdminBodega y Alistador (no filtra textos, filtra el stock mostrado).
     */
    public function buscadorInteligente(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'ok' => true, 'q' => $q, 'variantes' => [], 'productos' => [], 'ubicaciones' => [],
            ]);
        }
        $u = $r->user();
        $bodegasPermitidas = $u->esAracely() ? null : $u->bodegasAsignadasIds();

        // FIX-S0 · si el user tiene scope restringido pero vacío (Alistador/
        // AdminBodega sin bodegas aún asignadas), devolvemos resultados pero
        // marcando que el stock no es confiable para no mostrar "0" engañoso.
        $scopeVacio = ($bodegasPermitidas !== null) && empty($bodegasPermitidas);

        // --- 1) Variantes (código de barras, nombre o referencia padre) ---
        $variantes = ProductoVariante::query()
            ->with('producto:id,referencia,nombre,desglose_stock')
            ->where(function ($x) use ($q) {
                $x->where('codigo_barras', 'like', "%{$q}%")
                    ->orWhereHas('producto', fn ($p) =>
                        $p->where('nombre', 'like', "%{$q}%")
                          ->orWhere('referencia', 'like', "%{$q}%")
                    );
            })
            ->limit(10)
            ->get();

        $variantesOut = $variantes->map(function ($v) use ($bodegasPermitidas, $scopeVacio) {
            if ($scopeVacio) {
                $stock = null; // sin bodegas asignadas → no reportamos stock
            } else {
                $qMov = InventarioMovimiento::where('variante_id', $v->id);
                if ($bodegasPermitidas !== null) {
                    $qMov->whereIn('ubicacion_id', $bodegasPermitidas);
                }
                $stock = (int) $qMov->sum('cantidad');
            }
            return [
                'tipo' => 'variante',
                'id' => $v->id,
                'sku' => $v->codigo_barras,
                'producto' => $v->producto?->nombre,
                'referencia' => $v->producto?->referencia,
                'detalle' => trim(($v->color_nombre ?? '').' '.($v->talla ?? '')),
                'stock' => $stock,
                'url_kardex' => route('app.inventario.kardex').'?codigo='.urlencode($v->codigo_barras),
            ];
        })->values();

        // --- 2) Productos agregados (sin variantes, stock a nivel producto) ---
        $productos = Producto::query()
            ->where('desglose_stock', false)
            ->where(function ($x) use ($q) {
                $x->where('referencia', 'like', "%{$q}%")
                  ->orWhere('nombre', 'like', "%{$q}%");
            })
            ->limit(8)
            ->get(['id', 'referencia', 'nombre']);

        $productosOut = $productos->map(function ($p) use ($bodegasPermitidas, $scopeVacio) {
            if ($scopeVacio) {
                $stock = null;
            } else {
                $qMov = InventarioMovimiento::where('producto_id', $p->id)->whereNull('variante_id');
                if ($bodegasPermitidas !== null) {
                    $qMov->whereIn('ubicacion_id', $bodegasPermitidas);
                }
                $stock = (int) $qMov->sum('cantidad');
            }
            return [
                'tipo' => 'producto_agregado',
                'id' => $p->id,
                'referencia' => $p->referencia,
                'nombre' => $p->nombre,
                'stock' => $stock,
                'url_kardex' => route('app.inventario.kardex').'?codigo='.urlencode($p->referencia),
            ];
        })->values();

        // --- 3) Ubicaciones (código, nombre o ciudad) ---
        $qUbic = InventarioUbicacion::query()
            ->where(function ($x) use ($q) {
                $x->where('codigo', 'like', "%{$q}%")
                  ->orWhere('nombre', 'like', "%{$q}%")
                  ->orWhere('ciudad', 'like', "%{$q}%");
            });
        if ($bodegasPermitidas !== null) {
            $qUbic->whereIn('id', $bodegasPermitidas);
        }
        $ubicacionesOut = $qUbic->limit(8)->get(['id', 'codigo', 'nombre', 'ciudad', 'categoria', 'activa'])
            ->map(function ($u2) {
                $skuCount = (int) InventarioMovimiento::where('ubicacion_id', $u2->id)
                    ->selectRaw('COUNT(DISTINCT COALESCE(variante_id, 0), COALESCE(producto_id, 0)) as c')
                    ->value('c');
                return [
                    'tipo' => 'ubicacion',
                    'id' => $u2->id,
                    'codigo' => $u2->codigo,
                    'nombre' => $u2->nombre,
                    'ciudad' => $u2->ciudad,
                    'categoria' => is_object($u2->categoria) ? $u2->categoria->value : $u2->categoria,
                    'activa' => (bool) $u2->activa,
                    'skus_distintos' => $skuCount,
                    'url_reporte' => route('app.inventario.reporte').'?ubicacion_id='.$u2->id,
                ];
            })->values();

        return response()->json([
            'ok' => true,
            'q' => $q,
            'variantes' => $variantesOut,
            'productos' => $productosOut,
            'ubicaciones' => $ubicacionesOut,
            'total' => $variantesOut->count() + $productosOut->count() + $ubicacionesOut->count(),
            'scope_vacio' => $scopeVacio,
        ]);
    }

    // ---------- AUTOCOMPLETE VARIANTES ----------
    public function buscarVariantes(Request $r)
    {
        $q = trim((string) $r->input('q', ''));
        if (strlen($q) < 2) return response()->json([]);
        $vars = ProductoVariante::query()
            ->with('producto:id,referencia,nombre')
            ->where(function ($x) use ($q) {
                $x->where('codigo_barras', 'like', "%{$q}%")
                  ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$q}%")->orWhere('referencia', 'like', "%{$q}%"));
            })
            ->limit(15)->get();
        return response()->json($vars->map(fn ($v) => [
            'id' => $v->id,
            'sku' => $v->codigo_barras,
            'producto' => $v->producto?->nombre,
            'detalle' => trim(($v->color_nombre ?? '') . ' ' . ($v->talla ?? '')),
            'label' => trim(($v->producto?->nombre ?? '?') . ' · ' . ($v->color_nombre ?? '') . ' ' . ($v->talla ?? '') . ' [' . $v->codigo_barras . ']'),
        ]));
    }

    // ---------- INV-B2 · IMPORT EXCEL MASIVO (Conteos, Traslados, Alertas) ----------

    /**
     * INV-B2 · Parser común. Lee XLSX/CSV y devuelve filas estandarizadas.
     * Primera columna = SKU/código de barras o referencia de producto agregado,
     * segunda columna = cantidad. Devuelve: array de ['sku','cantidad','linea','variante_id','producto_id'].
     * Omite header si la primera celda no es numérica/código válido.
     */
    private function parsearArchivoSku(Request $r): array
    {
        $r->validate(['archivo' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);
        $file = $r->file('archivo');
        $filas = [];
        try {
            $ext = strtolower($file->getClientOriginalExtension());
            if (in_array($ext, ['csv', 'txt'], true)) {
                $fh = fopen($file->getRealPath(), 'r');
                // FIX-S0 · auto-detectar separador CSV (ES usa ';', EN usa ','),
                // leyendo la primera línea cruda.
                $primera = fgets($fh);
                rewind($fh);
                $sep = (substr_count((string) $primera, ';') > substr_count((string) $primera, ',')) ? ';' : ',';
                $num = 0;
                while (($row = fgetcsv($fh, 2000, $sep)) !== false) {
                    $num++;
                    $filas[] = ['linea' => $num, 'cols' => array_map('trim', $row)];
                }
                fclose($fh);
            } else {
                // FIX-S0 · SEGURIDAD · calculateFormulas=false para no evaluar
                // =WEBSERVICE/=HYPERLINK/=INDIRECT en el servidor (SSRF/DoS).
                // readDataOnly=true para omitir estilos y acelerar.
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getRealPath());
                if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
                $sheet = $reader->load($file->getRealPath())->getActiveSheet();
                foreach ($sheet->toArray(null, false, false, false) as $i => $row) {
                    $filas[] = ['linea' => $i + 1, 'cols' => array_map(fn ($v) => trim((string) $v), $row)];
                }
            }
        } catch (\Throwable $e) {
            abort(422, 'No pude leer el archivo: '.$e->getMessage());
        }

        // FIX-S0 · límite duro para evitar que 20k filas bloqueen la request.
        abort_if(count($filas) > 5000, 422, 'Máximo 5000 filas por import. Dividí el archivo en varios lotes.');

        // Omitir header si la primera fila tiene "sku" o "código" en col A.
        if (isset($filas[0]['cols'][0]) && preg_match('/sku|c[oó]d|referencia|producto/i', (string) $filas[0]['cols'][0])) {
            array_shift($filas);
        }

        $resultado = [];
        foreach ($filas as $f) {
            $sku = (string) ($f['cols'][0] ?? '');
            $cant = $f['cols'][1] ?? null;
            if ($sku === '' && ($cant === null || $cant === '')) continue; // fila vacía
            $resultado[] = [
                'linea' => $f['linea'],
                'sku' => $sku,
                'cantidad' => $cant,
                'extra' => $f['cols'][2] ?? null,
            ];
        }
        return $resultado;
    }

    /**
     * INV-B2 · Resuelve SKU a (variante_id | producto_id). Devuelve null si no existe.
     * Admite código de barras exacto de variante O referencia exacta de producto agregado.
     *
     * FIX-S0 · acepta mapas pre-cargados en memoria (pattern "arrange once,
     * resolve many") para no hacer 2 queries por fila dentro de la
     * transacción del import; cuando no se pasan, cae al modo query-por-fila.
     */
    private function resolverSku(string $sku, ?array $varMap = null, ?array $prodMap = null): ?array
    {
        $sku = trim($sku);
        if ($sku === '') return null;
        if ($varMap !== null) {
            if (isset($varMap[$sku])) return ['variante_id' => $varMap[$sku], 'producto_id' => null];
            if ($prodMap !== null && isset($prodMap[$sku])) return ['variante_id' => null, 'producto_id' => $prodMap[$sku]];
            return null;
        }
        $v = ProductoVariante::where('codigo_barras', $sku)->first(['id']);
        if ($v) return ['variante_id' => $v->id, 'producto_id' => null];
        $p = Producto::where('referencia', $sku)->where('desglose_stock', false)->first(['id']);
        if ($p) return ['variante_id' => null, 'producto_id' => $p->id];
        return null;
    }

    /**
     * FIX-S0 · Pre-carga mapas SKU → id para resolver filas en O(1) sin tocar
     * la BD por cada iteración del import masivo.
     */
    private function construirMapasSku(array $filas): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            fn ($f) => (string) ($f['sku'] ?? ''),
            $filas
        ))));
        if (empty($skus)) return [[], []];
        $varMap = ProductoVariante::whereIn('codigo_barras', $skus)
            ->pluck('id', 'codigo_barras')->map(fn ($id) => (int) $id)->all();
        $prodMap = Producto::whereIn('referencia', $skus)
            ->where('desglose_stock', false)
            ->pluck('id', 'referencia')->map(fn ($id) => (int) $id)->all();
        return [$varMap, $prodMap];
    }

    /**
     * INV-B2 · Import masivo de cantidades contadas para una Toma Física.
     * Formato Excel: col A = SKU/referencia, col B = cantidad contada.
     * Sólo actualiza items YA cargados en la toma (no crea nuevas filas: la
     * toma los crea al iniciar la captura con el snapshot teórico). Las no
     * encontradas se reportan como "no_matcheadas".
     */
    public function conteoImportarMasivo(Request $r, int $id): JsonResponse
    {
        $toma = TomaFisica::findOrFail($id);
        abort_unless(
            (is_object($toma->estado) ? $toma->estado->value : $toma->estado) === 'en_conteo',
            409, 'Sólo se importan cantidades mientras la toma está En Conteo.'
        );
        $u = $r->user();
        if (! $u->esAracely()) {
            abort_unless(in_array((int) $toma->ubicacion_id, $u->bodegasAsignadasIds(), true), 403);
        }

        $filas = $this->parsearArchivoSku($r);
        [$varMap, $prodMap] = $this->construirMapasSku($filas);
        $items = TomaFisicaItem::where('toma_id', $toma->id)->get()->keyBy(function ($i) {
            return $i->variante_id ? "V:{$i->variante_id}" : "P:{$i->producto_id}";
        });

        $actualizadas = 0; $noMatcheadas = []; $invalidas = [];
        DB::transaction(function () use ($filas, $items, $varMap, $prodMap, &$actualizadas, &$noMatcheadas, &$invalidas) {
            foreach ($filas as $f) {
                $resolv = $this->resolverSku($f['sku'], $varMap, $prodMap);
                if (! $resolv) { $noMatcheadas[] = "Línea {$f['linea']}: SKU '{$f['sku']}' no existe"; continue; }
                $cant = is_numeric($f['cantidad']) ? (float) $f['cantidad'] : null;
                if ($cant === null || $cant < 0) { $invalidas[] = "Línea {$f['linea']}: cantidad '{$f['cantidad']}' inválida"; continue; }
                $key = $resolv['variante_id'] ? "V:{$resolv['variante_id']}" : "P:{$resolv['producto_id']}";
                $it = $items->get($key);
                if (! $it) { $noMatcheadas[] = "Línea {$f['linea']}: SKU '{$f['sku']}' no está en esta toma"; continue; }
                $it->cantidad_contada = round($cant, 4);
                $it->save();
                $actualizadas++;
            }
        });

        return response()->json([
            'ok' => true,
            'actualizadas' => $actualizadas,
            'no_matcheadas' => $noMatcheadas,
            'invalidas' => $invalidas,
            'mensaje' => "Importadas {$actualizadas} cantidades contadas"
                . (count($noMatcheadas) ? " · ".count($noMatcheadas)." SKUs no encontrados" : '')
                . (count($invalidas) ? " · ".count($invalidas)." cantidades inválidas" : '')
                . '.',
        ]);
    }

    /**
     * INV-B2 · Import masivo de items para un Traslado en Borrador.
     * Formato Excel: col A = SKU/referencia, col B = cantidad solicitada.
     * Hace updateOrCreate por (traslado_id, variante_id, producto_id).
     */
    public function trasladoImportarMasivo(Request $r, int $id): JsonResponse
    {
        $t = Traslado::findOrFail($id);
        abort_unless(
            (is_object($t->estado) ? $t->estado->value : $t->estado) === 'borrador',
            409, 'Sólo se cargan items en traslados en estado Borrador.'
        );
        $u = $r->user();
        if (! $u->esAracely()) {
            $bod = $u->bodegasAsignadasIds();
            abort_unless(in_array((int) $t->origen_id, $bod, true) && in_array((int) $t->destino_id, $bod, true), 403);
        }

        $filas = $this->parsearArchivoSku($r);
        [$varMap, $prodMap] = $this->construirMapasSku($filas);
        $creadas = 0; $actualizadas = 0; $noMatcheadas = []; $invalidas = [];
        DB::transaction(function () use ($filas, $t, $varMap, $prodMap, &$creadas, &$actualizadas, &$noMatcheadas, &$invalidas) {
            foreach ($filas as $f) {
                $resolv = $this->resolverSku($f['sku'], $varMap, $prodMap);
                if (! $resolv) { $noMatcheadas[] = "Línea {$f['linea']}: SKU '{$f['sku']}' no existe"; continue; }
                $cant = is_numeric($f['cantidad']) ? (float) $f['cantidad'] : null;
                if ($cant === null || $cant <= 0) { $invalidas[] = "Línea {$f['linea']}: cantidad '{$f['cantidad']}' inválida"; continue; }
                $item = TrasladoItem::updateOrCreate(
                    [
                        'traslado_id' => $t->id,
                        'variante_id' => $resolv['variante_id'],
                        'producto_id' => $resolv['producto_id'],
                    ],
                    ['cantidad_solicitada' => round($cant, 4), 'notas' => $f['extra'] ?: null]
                );
                $item->wasRecentlyCreated ? $creadas++ : $actualizadas++;
            }
        });

        return response()->json([
            'ok' => true,
            'creadas' => $creadas,
            'actualizadas' => $actualizadas,
            'no_matcheadas' => $noMatcheadas,
            'invalidas' => $invalidas,
            'mensaje' => "Importados: {$creadas} nuevos, {$actualizadas} actualizados"
                . (count($noMatcheadas) ? " · ".count($noMatcheadas)." no encontrados" : '')
                . (count($invalidas) ? " · ".count($invalidas)." inválidos" : '')
                . '.',
        ]);
    }

    /**
     * INV-B2 · Import masivo de configuración de alertas de stock.
     * Formato Excel: col A = SKU/referencia, col B = stock_minimo,
     * col C (opcional) = stock_maximo.
     * Se ancla a `ubicacion_id` del request (NULL = global para esa variante).
     */
    public function alertasImportarMasivo(Request $r): JsonResponse
    {
        $r->validate(['ubicacion_id' => ['nullable', 'integer', 'exists:inventario_ubicaciones,id']]);
        abort_unless($r->user()->esAracely(), 403, 'Sólo gerencia carga alertas en lote.');

        $ubicacionId = $r->input('ubicacion_id') ? (int) $r->input('ubicacion_id') : null;
        $filas = $this->parsearArchivoSku($r);
        [$varMap, $prodMap] = $this->construirMapasSku($filas);
        $creadas = 0; $actualizadas = 0; $noMatcheadas = []; $invalidas = [];

        DB::transaction(function () use ($filas, $ubicacionId, $varMap, $prodMap, &$creadas, &$actualizadas, &$noMatcheadas, &$invalidas) {
            foreach ($filas as $f) {
                $resolv = $this->resolverSku($f['sku'], $varMap, $prodMap);
                if (! $resolv) { $noMatcheadas[] = "Línea {$f['linea']}: SKU '{$f['sku']}' no existe"; continue; }
                $min = is_numeric($f['cantidad']) ? (int) $f['cantidad'] : null;
                if ($min === null || $min < 0) { $invalidas[] = "Línea {$f['linea']}: stock mínimo '{$f['cantidad']}' inválido"; continue; }
                $max = is_numeric($f['extra']) ? max((int) $f['extra'], $min) : null;

                $a = AlertaStockConfig::updateOrCreate(
                    [
                        'variante_id' => $resolv['variante_id'],
                        'producto_id' => $resolv['producto_id'],
                        'ubicacion_id' => $ubicacionId,
                    ],
                    ['stock_minimo' => $min, 'stock_maximo' => $max, 'activa' => true, 'notificar_email' => true]
                );
                $a->wasRecentlyCreated ? $creadas++ : $actualizadas++;
            }
        });

        return response()->json([
            'ok' => true,
            'creadas' => $creadas,
            'actualizadas' => $actualizadas,
            'no_matcheadas' => $noMatcheadas,
            'invalidas' => $invalidas,
            'mensaje' => "Alertas: {$creadas} nuevas, {$actualizadas} actualizadas"
                . (count($noMatcheadas) ? " · ".count($noMatcheadas)." no encontradas" : '')
                . (count($invalidas) ? " · ".count($invalidas)." inválidas" : '')
                . '.',
        ]);
    }

    /**
     * INV-B2 · Plantilla descargable XLSX con 2 columnas (SKU + cantidad)
     * y 3 filas de ejemplo. Sirve para los 3 flujos (conteo/traslado/alerta).
     * El parámetro `tipo` sólo cambia el label de la cabecera.
     */
    public function plantillaMasivaExcel(Request $r)
    {
        $tipo = $r->query('tipo', 'conteo');
        $labelB = match ($tipo) {
            'traslado' => 'cantidad_solicitada',
            'alerta' => 'stock_minimo',
            default => 'cantidad_contada',
        };
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $s = $spreadsheet->getActiveSheet();
        $s->setTitle(ucfirst($tipo));
        $s->setCellValue('A1', 'sku_o_referencia');
        $s->setCellValue('B1', $labelB);
        if ($tipo === 'alerta') $s->setCellValue('C1', 'stock_maximo');
        if ($tipo === 'traslado') $s->setCellValue('C1', 'notas');
        $s->setCellValue('A2', 'AND2512-79/154-02LEÓ-6M');
        $s->setCellValue('B2', 10);
        $s->setCellValue('A3', 'BAB4402-11');
        $s->setCellValue('B3', 5);
        foreach (['A', 'B', 'C'] as $c) $s->getColumnDimension($c)->setAutoSize(true);

        $temp = tempnam(sys_get_temp_dir(), 'plantilla_'.$tipo).'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($temp);

        return response()->download($temp, "plantilla_{$tipo}.xlsx")->deleteFileAfterSend(true);
    }
}
