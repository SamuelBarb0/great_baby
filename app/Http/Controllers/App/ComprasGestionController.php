<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\RecibirMercancia;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FIL-A · CRUD detalle Compras (paridad Filament):
 *  - Orden de compra: crear, editar items, aprobar, anular
 *  - Recepción: preparar desde OC, confirmar
 *  - Importación: crear, agregar gastos, liquidar (prorratear costos)
 *  - Reporte compras
 */
class ComprasGestionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // LOG · AdminBodega entra para RECIBIR mercancía y gestionar
                //   devoluciones a proveedor desde el menú Bodega. Las OC y
                //   el reporte de compras siguen siendo de Aracely/Contador
                //   (ellos NO aparecen en el menú del AdminBodega).
                abort_unless($r->user()?->esEquipoBodega(), 403);
                return $next($r);
            }),
        ];
    }

    // ================== ORDEN DE COMPRA ==================
    public function ocShow(int $orden): Response
    {
        $o = OrdenCompra::with(['proveedor:id,nombre_completo,razon_social', 'items'])->findOrFail($orden);

        // COMP-B6 · Semáforo SIIGO de la OC = agregación del estado SIIGO de sus
        // recepciones. Verde (todas con siigo_id), amarillo (al menos una pendiente),
        // rojo (ninguna sincronizada), gris (sin recepciones todavía).
        $recepciones = \App\Modules\Compras\Models\RecepcionCompra::where('orden_id', $o->id)
            ->where('estado', 'confirmada')
            ->get(['id', 'numero', 'estado', 'siigo_id', 'siigo_number', 'siigo_sync_at', 'fecha_recepcion']);
        $totRec = $recepciones->count();
        $sincronizadas = $recepciones->whereNotNull('siigo_id')->count();
        $siigoEstado = match (true) {
            $totRec === 0 => 'sin_recepciones',
            $sincronizadas === $totRec => 'ok',
            $sincronizadas === 0 => 'pendiente',
            default => 'parcial',
        };

        return Inertia::render('Compras/OC/Show', [
            'orden' => [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo,
                'proveedor_id' => $o->proveedor_id,
                'estado' => is_object($o->estado) ? $o->estado->value : $o->estado,
                'tipo' => $o->tipo,
                'moneda' => $o->moneda,
                'tasa_cambio' => (float) $o->tasa_cambio,
                'fecha_emision' => $o->fecha_emision?->toDateString(),
                'fecha_esperada' => $o->fecha_esperada?->toDateString(),
                'subtotal' => (float) $o->subtotal,
                'iva' => (float) $o->iva,
                'total' => (float) $o->total,
                'descuento' => (float) $o->descuento,
                'observaciones' => $o->observaciones,
                'items' => $o->items->map(fn ($i) => [
                    'id' => $i->id, 'descripcion' => $i->descripcion,
                    'cantidad' => (float) $i->cantidad, 'cantidad_recibida' => (float) $i->cantidad_recibida,
                    'precio_unit' => (float) $i->precio_unit, 'iva_pct' => (float) $i->iva_pct,
                    'descuento_pct' => (float) $i->descuento_pct,
                    'subtotal' => (float) $i->subtotal, 'total' => (float) $i->total,
                ])->all(),
                'siigo' => [
                    'estado' => $siigoEstado,
                    'total' => $totRec,
                    'sincronizadas' => $sincronizadas,
                    'pendientes' => $totRec - $sincronizadas,
                    'recepciones' => $recepciones->map(fn ($r) => [
                        'id' => $r->id,
                        'numero' => $r->numero,
                        'fecha' => $r->fecha_recepcion?->toDateString(),
                        'siigo_id' => $r->siigo_id,
                        'siigo_number' => $r->siigo_number,
                        'siigo_sync_at' => $r->siigo_sync_at?->toIso8601String(),
                    ])->all(),
                ],
            ],
        ]);
    }

    public function ocForm(): Response
    {
        return Inertia::render('Compras/OC/Nueva', [
            // COMP-B10 · incluir numero_documento para búsqueda por NIT en el autocomplete.
            'proveedores' => \App\Models\Contacto::where('es_proveedor', true)->where('activo', true)
                ->orderBy('nombre_completo')->limit(500)->get(['id', 'nombre_completo', 'razon_social', 'numero_documento'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'nombre' => $c->razon_social ?: $c->nombre_completo,
                    'numero_documento' => $c->numero_documento,
                ]),
            // Re-audit M2 UX-A4 · bodegas destino para elección explícita en OC.
            'bodegas' => \App\Modules\Dropi\Models\InventarioUbicacion::orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre]),
        ]);
    }

    public function ocCrear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'proveedor_id' => ['required', 'integer', 'exists:contactos,id'],
            // Re-audit M2 UX-A4 · bodega ahora obligatoria al crear (antes silenciosa).
            'bodega_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'tipo' => ['required', 'string', 'max:30'],
            'moneda' => ['required', 'string', 'size:3'],
            'tasa_cambio' => ['required', 'numeric', 'min:0'],
            'fecha_esperada' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descripcion' => ['required', 'string', 'max:200'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.001'],
            'items.*.precio_unit' => ['required', 'numeric', 'min:0'],
            'items.*.iva_pct' => ['nullable', 'numeric'],
            'items.*.descuento_pct' => ['nullable', 'numeric'],
            // Se validaban como enteros sueltos: un id inventado pasaba y
            // dejaba la línea apuntando a un producto que no existe.
            'items.*.producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'items.*.variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id'],
        ]);
        $oc = CrearOrdenCompra::run($data);
        return redirect()->route('app.compras.oc.show', $oc->id)->with('success', "OC {$oc->numero} creada.");
    }

    public function ocAprobar(int $orden): RedirectResponse
    {
        $o = OrdenCompra::findOrFail($orden);
        AprobarOrdenCompra::run($o);
        return back()->with('success', "OC {$o->numero} aprobada.");
    }

    /**
     * Sprint 3 · A.1 · Duplica una OC (crea otra en borrador con los mismos ítems).
     * Útil cuando Aracely quiere reordenar al mismo proveedor casi lo mismo.
     */
    public function ocDuplicar(int $orden): RedirectResponse
    {
        $o = OrdenCompra::with('items')->findOrFail($orden);

        // Reutiliza CrearOrdenCompra armando el payload desde la OC original.
        $payload = [
            'proveedor_id' => $o->proveedor_id,
            'bodega_id' => $o->bodega_id,
            'tipo' => $o->tipo,
            'moneda' => $o->moneda ?? 'COP',
            'tasa_cambio' => (float) ($o->tasa_cambio ?? 1),
            'fecha_esperada' => null,
            'observaciones' => "Duplicada desde {$o->numero}",
            'items' => $o->items->map(fn ($i) => [
                'descripcion' => $i->descripcion,
                'cantidad' => (float) $i->cantidad,
                'precio_unit' => (float) $i->precio_unit,
                'iva_pct' => (float) ($i->iva_pct ?? 0),
                'descuento_pct' => (float) ($i->descuento_pct ?? 0),
                'producto_id' => $i->producto_id,
                'variante_id' => $i->variante_id,
            ])->all(),
        ];
        $nueva = CrearOrdenCompra::run($payload);
        return redirect()->route('app.compras.oc.show', $nueva->id)
            ->with('flash', ['type' => 'success', 'message' => "OC {$nueva->numero} duplicada desde {$o->numero}."]);
    }

    /**
     * Sprint 3 · A.1 D.1 · Reenvía la última recepción confirmada de la OC a SIIGO.
     * Bypasea kill-switch (es acción manual explícita).
     */
    public function ocReenviarSiigo(int $orden): RedirectResponse
    {
        $o = OrdenCompra::with(['recepciones' => fn ($q) => $q->where('estado', 'confirmada')->orderByDesc('id')])
            ->findOrFail($orden);
        $rc = $o->recepciones->first();
        if (! $rc) {
            return back()->with('flash', ['type' => 'error', 'message' => 'La OC no tiene recepciones confirmadas para reenviar.']);
        }
        \App\Modules\Siigo\Jobs\PushRecepcionASiigo::dispatchManual($rc->id);
        return back()->with('flash', [
            'type' => 'success',
            'message' => "Recepción {$rc->numero} encolada · se envía a SIIGO en breve.",
        ]);
    }

    public function ocAnular(Request $r, int $orden): RedirectResponse
    {
        $data = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:300']]);
        $o = OrdenCompra::findOrFail($orden);
        // Re-audit M2 FUNC-C4 · agregado Anulada (idempotencia — evita re-anular).
        abort_if(in_array($o->estado, [EstadoOrdenCompra::Recibida, EstadoOrdenCompra::Cerrada, EstadoOrdenCompra::Anulada]), 422, 'OC ya recibida/cerrada/anulada.');
        // Re-audit M2 PATRÓN B (DATOS-C2) · asignación DIRECTA. Antes `$o->update(['estado'=>...])`
        // era mass-assign silencioso: `estado` no estaba en fillable y Laravel lo DESCARTABA
        // → la OC quedaba "anulada" solo en observaciones pero seguía viva. Con $guarded=['id']
        // + asignación directa el estado se persiste correcto.
        $o->estado = EstadoOrdenCompra::Anulada;
        $o->anulado_at = now();
        $o->anulado_por = auth()->id();
        $o->motivo_anulacion = $data['motivo'];
        $o->observaciones = trim(($o->observaciones ?? '') . "\n[ANULADA " . now()->toDateString() . " por " . auth()->user()?->name . "] " . $data['motivo']);
        $o->save();
        // Re-audit M2 R3 PATRÓN R (SEG-M2) · usa array_key_exists — antes
        // `config('logging.channels.audit')` returnaba truthy incluso si `[]`.
        \Illuminate\Support\Facades\Log::channel(
            array_key_exists('audit', config('logging.channels') ?? []) ? 'audit' : 'stack'
        )->info('compras.oc.anular', [
            'user_id' => auth()->id(), 'orden_id' => $o->id, 'numero' => $o->numero,
            'total' => (float) $o->total, 'motivo' => $data['motivo'],
        ]);
        return back()->with('success', "OC {$o->numero} anulada.");
    }

    // ================== RECEPCIÓN ==================
    public function recepcionShow(int $recepcion): Response
    {
        $rc = RecepcionCompra::with(['orden:id,numero', 'items'])->findOrFail($recepcion);
        return Inertia::render('Compras/Recepcion/Show', [
            'recepcion' => [
                'id' => $rc->id, 'numero' => $rc->numero,
                'orden_id' => $rc->orden?->id,
                'orden_numero' => $rc->orden?->numero,
                'estado' => $rc->estado,
                'fecha_recepcion' => $rc->fecha_recepcion?->format('Y-m-d H:i'),
                'remision_proveedor' => $rc->remision_proveedor,
                'factura_proveedor' => $rc->factura_proveedor,
                'transportista' => $rc->transportista,
                'observaciones' => $rc->observaciones,
                'total_recibido' => (float) $rc->total_recibido,
                // Sprint 3 · D.1 · exponer estado SIIGO al Vue Show.
                'siigo_id' => $rc->siigo_id,
                'siigo_number' => $rc->siigo_number,
                'siigo_sync_hace' => $rc->siigo_sync_at?->diffForHumans(),
                'items' => $rc->items->map(fn ($i) => [
                    'id' => $i->id,
                    'cantidad_recibida' => (float) $i->cantidad_recibida,
                    'costo_unit' => (float) $i->costo_unit,
                    'subtotal' => (float) $i->subtotal,
                    'lote' => $i->lote,
                    'observaciones' => $i->observaciones,
                    // LOG-J8 · destino al descargar (apto/averia/cuarentena/revision/faltante) + motivo.
                    'clasificacion' => $i->clasificacion ?? 'apto',
                    'motivo_clasificacion' => $i->motivo_clasificacion,
                ])->all(),
            ],
        ]);
    }

    /**
     * Sprint 3 · D.1 · reenvía la recepción a SIIGO desde la vista de detalle.
     */
    public function recepcionReenviarSiigo(int $recepcion): RedirectResponse
    {
        $rc = RecepcionCompra::findOrFail($recepcion);
        if ($rc->estado !== 'confirmada') {
            return back()->with('flash', ['type' => 'error', 'message' => 'Solo se pueden reenviar recepciones confirmadas.']);
        }
        \App\Modules\Siigo\Jobs\PushRecepcionASiigo::dispatchManual($rc->id);
        return back()->with('flash', [
            'type' => 'success',
            'message' => "Recepción {$rc->numero} encolada a SIIGO.",
        ]);
    }

    public function recepcionDesdeOc(int $orden): RedirectResponse
    {
        // Redirige a form-preparar-recepción con items pre-llenados desde OC.
        return redirect()->route('app.compras.recepcion.nueva', ['oc' => $orden]);
    }

    public function recepcionForm(Request $request): Response
    {
        $ocId = (int) $request->input('oc', 0);
        $oc = $ocId ? OrdenCompra::with(['items', 'proveedor:id,nombre_completo,razon_social'])->find($ocId) : null;

        // Sin OC elegida, la pantalla mandaba a /app/compras a buscarla — y esa
        // pantalla exige perfil contable, así que quien recibe la mercancía
        // chocaba contra un 403 justo en su tarea principal. Le damos acá mismo
        // las órdenes que puede recibir.
        $ocsPendientes = $oc ? [] : OrdenCompra::query()
            ->whereIn('estado', ['aprobada', 'parcial'])
            ->with('proveedor:id,nombre_completo,razon_social')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (OrdenCompra $o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo,
                'estado' => is_object($o->estado) ? $o->estado->value : $o->estado,
                'fecha_esperada' => optional($o->fecha_esperada)->format('Y-m-d'),
                'total' => (float) $o->total,
            ])->all();

        return Inertia::render('Compras/Recepcion/Nueva', [
            'ocs_pendientes' => $ocsPendientes,
            'oc' => $oc ? [
                'id' => $oc->id, 'numero' => $oc->numero,
                'proveedor' => $oc->proveedor?->razon_social ?: $oc->proveedor?->nombre_completo,
                'items' => $oc->items->map(fn ($i) => [
                    'id' => $i->id, 'descripcion' => $i->descripcion,
                    'cantidad_pendiente' => (float) $i->cantidad - (float) $i->cantidad_recibida,
                    'precio_unit' => (float) $i->precio_unit,
                ])->filter(fn ($x) => $x['cantidad_pendiente'] > 0)->values(),
            ] : null,
        ]);
    }

    public function recepcionCrear(Request $r): RedirectResponse
    {
        // Re-audit M2 R3 PATRÓN Q (FUNC-C3) · TX UNIFICADA. Antes había una tx
        //   externa que committeaba `RecepcionCompra` + items, y DESPUÉS otra
        //   tx en `RecibirMercancia::handle`. Si el Action tronaba, quedaba
        //   recepción huérfana en `borrador` + cada retry creaba OTRA con nuevo
        //   consecutivo → acumulación de "recepciones fantasma".
        //   Ahora TODO va bajo el mismo `DB::transaction` — o se persiste el
        //   flujo completo o se rollea todo. `RecibirMercancia` sigue haciendo
        //   su propia tx anidada (Laravel las combina, sin doble commit).
        //
        // Re-audit M2 R3 PATRÓN Q (FUNC-A3) · dedupe `orden_item_id` — antes un
        //   payload con duplicados pasaba items y el Action detectaba
        //   sobre-recepción en el segundo intento, pero la recepción ya estaba
        //   committeada. Ahora fusionamos duplicados sumando cantidades ANTES
        //   de crear la recepción.
        //
        // Re-audit M2 R3 PATRÓN M (FUNC-C1) · fillable clave = `recibido_por`.
        //   Antes pasaba `'creado_por'` → mass-assign silencioso lo descartaba,
        //   la columna quedaba NULL y perdíamos trazabilidad de autor.

        $data = $r->validate([
            'orden_id' => ['required', 'integer', 'exists:compras_ordenes,id'],
            'remision_proveedor' => ['nullable', 'string', 'max:100'],
            'factura_proveedor' => ['nullable', 'string', 'max:100'],
            'transportista' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            // La tabla es `compra_orden_items` en singular (así la declara
            // `OrdenCompraItem::$table`). Con el nombre en plural la regla
            // `exists` reventaba con «table doesn't exist» y CONFIRMAR UNA
            // RECEPCIÓN FALLABA SIEMPRE con 500: no se podía meter mercancía a
            // bodega por la interfaz. Encontrado el 2026-10-14 recibiendo una
            // OC real; el botón se quedaba en «Guardando…» sin decir nada.
            'items.*.orden_item_id' => ['required', 'integer', 'exists:compra_orden_items,id'],
            'items.*.cantidad_recibida' => ['required', 'numeric', 'min:0.001'],
            'items.*.lote' => ['nullable', 'string', 'max:80'],
            // LOG-J8 · cada línea declara su destino (apto/averia/cuarentena/revision/faltante).
            // Default: apto (comportamiento retro-compatible con el flujo anterior).
            'items.*.clasificacion' => ['nullable', 'string', 'in:apto,averia,cuarentena,revision,faltante'],
            'items.*.motivo_clasificacion' => ['nullable', 'string', 'max:180'],
        ]);

        $orden = OrdenCompra::with('items')->findOrFail($data['orden_id']);
        abort_unless(in_array($orden->estado, [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Parcial, EstadoOrdenCompra::Enviada]), 422, 'La OC no está en estado receptable.');
        abort_if($orden->bodega_id === null, 422, 'La OC no tiene bodega destino asignada.');

        $itemsOc = $orden->items->pluck('id')->all();
        foreach ($data['items'] as $it) {
            abort_unless(in_array((int) $it['orden_item_id'], $itemsOc, true), 422, 'Item de recepción no pertenece a esta OC.');
        }

        // Dedupe: si vienen 2 líneas con mismo orden_item_id + MISMA clasificación
        // fusionamos qty. Si varían clasificaciones, se mantienen como líneas
        // separadas (la misma OC puede repartir el mismo SKU entre apto/avería/
        // cuarentena cuando sólo parte del lote llegó en condiciones).
        $itemsFusionados = [];
        foreach ($data['items'] as $it) {
            $clas = $it['clasificacion'] ?? 'apto';
            $key = (int) $it['orden_item_id'] . ':' . $clas;
            if (isset($itemsFusionados[$key])) {
                $itemsFusionados[$key]['cantidad_recibida'] += (float) $it['cantidad_recibida'];
                $itemsFusionados[$key]['lote'] = $itemsFusionados[$key]['lote'] ?: ($it['lote'] ?? null);
                // motivos: concatenamos para no perder contexto cuando el usuario
                // ingresó dos novedades del mismo producto.
                if (! empty($it['motivo_clasificacion']) && $itemsFusionados[$key]['motivo_clasificacion'] !== $it['motivo_clasificacion']) {
                    $itemsFusionados[$key]['motivo_clasificacion'] = trim(($itemsFusionados[$key]['motivo_clasificacion'] ?? '').' · '.$it['motivo_clasificacion']);
                }
            } else {
                $itemsFusionados[$key] = [
                    'orden_item_id' => (int) $it['orden_item_id'],
                    'cantidad_recibida' => (float) $it['cantidad_recibida'],
                    'lote' => $it['lote'] ?? null,
                    'clasificacion' => $clas,
                    'motivo_clasificacion' => $it['motivo_clasificacion'] ?? null,
                ];
            }
        }

        $rc = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $orden, $itemsFusionados) {
            $rc = RecepcionCompra::create([
                'numero' => RecepcionCompra::siguienteNumero(),
                'orden_id' => $orden->id,
                'bodega_id' => $orden->bodega_id,
                'estado' => 'borrador',
                'fecha_recepcion' => now(),
                'remision_proveedor' => $data['remision_proveedor'] ?? null,
                'factura_proveedor' => $data['factura_proveedor'] ?? null,
                'transportista' => $data['transportista'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                // Clave real del $fillable (antes 'creado_por' se descartaba).
                'recibido_por' => auth()->id(),
            ]);
            foreach ($itemsFusionados as $it) {
                $ocItem = $orden->items->firstWhere('id', $it['orden_item_id']);
                $costoUnit = (float) $ocItem->precio_unit;
                $cantidad = (float) $it['cantidad_recibida'];
                $rc->items()->create([
                    'orden_item_id' => $ocItem->id,
                    'variante_id' => $ocItem->variante_id,
                    // Copiaba sólo la variante. Los productos AGREGADOS —la
                    // mayor parte del catálogo de Great Baby— no tienen
                    // variante, así que llegaban acá sin nada con qué
                    // identificarse y `RecibirMercancia` se saltaba el kardex:
                    // la recepción quedaba registrada y el stock no subía.
                    'producto_id' => $ocItem->producto_id,
                    'cantidad_recibida' => $cantidad,
                    'costo_unit' => $costoUnit,
                    'subtotal' => round($costoUnit * $cantidad, 2),
                    'lote' => $it['lote'] ?? null,
                    // LOG-J8 · persistir destino + motivo. El Action RecibirMercancia
                    // lee `clasificacion` para decidir a qué almacén lógico va el
                    // kardex (apto → stock vendible, el resto → stock no vendible).
                    'clasificacion' => $it['clasificacion'] ?? 'apto',
                    'motivo_clasificacion' => $it['motivo_clasificacion'] ?? null,
                ]);
            }

            // Confirmación dentro de la MISMA tx externa. Si esto truena,
            // rollback total incluida la recepción recién creada.
            return RecibirMercancia::run($rc);
        });

        return redirect()->route('app.compras.recepcion.show', $rc->id)->with('success', "Recepción {$rc->numero} creada.");
    }

    // ================== IMPORTACIÓN ==================

    /**
     * Sprint 4 · A.3 · Listado de contenedores (paridad Filament).
     */
    public function importacionIndex(Request $r): Response
    {
        $q = Importacion::query();
        if ($busca = trim((string) $r->query('q', ''))) {
            $q->where(function ($qq) use ($busca) {
                $qq->where('numero', 'like', "%$busca%")
                   ->orWhere('contenedor', 'like', "%$busca%")
                   ->orWhere('bl_awb', 'like', "%$busca%");
            });
        }
        if ($estado = $r->query('estado')) $q->where('estado', $estado);

        return Inertia::render('Compras/Importacion/Index', [
            'filtros' => ['q' => $r->query('q', ''), 'estado' => $estado],
            'importaciones' => $q->orderByDesc('id')->paginate(20)->through(fn ($i) => [
                'id' => $i->id,
                'numero' => $i->numero,
                'contenedor' => $i->contenedor,
                'bl_awb' => $i->bl_awb,
                'estado' => is_object($i->estado) ? $i->estado->value : $i->estado,
                'moneda_origen' => $i->moneda_origen,
                'tasa_cambio_liquidacion' => (float) $i->tasa_cambio_liquidacion,
                'eta' => $i->eta?->format('Y-m-d'),
                'fecha_llegada' => $i->fecha_llegada?->format('Y-m-d'),
                'fecha_liquidacion' => $i->fecha_liquidacion?->format('Y-m-d'),
                'total_fob' => (float) $i->lineas()->sum('costo_fob_total'),
                'total_gastos' => (float) $i->gastos()->sum('monto_base'),
                'lineas_count' => $i->lineas()->count(),
            ]),
            'kpis' => [
                'total' => Importacion::count(),
                // QA-FIX #6 · abarca todos los estados intermedios (en_transito + en_puerto + nacionalizada).
                'en_transito' => Importacion::whereIn('estado', ['en_transito', 'en_puerto', 'nacionalizada'])->count(),
                'por_liquidar' => Importacion::whereNotNull('fecha_llegada')->whereNull('fecha_liquidacion')->count(),
                'liquidados_mes' => Importacion::whereMonth('fecha_liquidacion', now()->month)->whereYear('fecha_liquidacion', now()->year)->count(),
            ],
        ]);
    }

    public function importacionShow(int $importacion): Response
    {
        $imp = Importacion::with(['lineas', 'gastos'])->findOrFail($importacion);
        return Inertia::render('Compras/Importacion/Show', [
            'importacion' => [
                'id' => $imp->id, 'numero' => $imp->numero,
                'contenedor' => $imp->contenedor, 'bl_awb' => $imp->bl_awb,
                'estado' => is_object($imp->estado) ? $imp->estado->value : $imp->estado,
                'puerto_origen' => $imp->puerto_origen, 'puerto_destino' => $imp->puerto_destino,
                'incoterm' => $imp->incoterm, 'moneda_origen' => $imp->moneda_origen,
                'tasa_cambio_liquidacion' => (float) $imp->tasa_cambio_liquidacion,
                'fecha_zarpe' => $imp->fecha_zarpe?->toDateString(),
                'eta' => $imp->eta?->toDateString(),
                'fecha_llegada' => $imp->fecha_llegada?->toDateString(),
                'fecha_liquidacion' => $imp->fecha_liquidacion?->toDateString(),
                'observaciones' => $imp->observaciones,
                'lineas' => $imp->lineas->map(fn ($l) => [
                    'id' => $l->id,
                    'cantidad' => (float) $l->cantidad,
                    'costo_fob_unit' => (float) $l->costo_fob_unit,
                    'costo_fob_total' => (float) $l->costo_fob_total,
                    'gasto_prorrateado' => (float) $l->gasto_prorrateado,
                    'costo_final_unit' => (float) $l->costo_final_unit,
                ])->all(),
                'gastos' => $imp->gastos->map(fn ($g) => [
                    'id' => $g->id, 'concepto' => $g->concepto,
                    'moneda' => $g->moneda, 'monto' => (float) $g->monto,
                    'monto_base' => (float) $g->monto_base,
                    'capitalizable' => (bool) $g->capitalizable,
                    'metodo_prorrateo' => $g->metodo_prorrateo,
                    'fecha' => $g->fecha?->toDateString(),
                ])->all(),
                'total_gastos' => (float) $imp->gastos->sum('monto_base'),
                'total_fob' => (float) $imp->lineas->sum('costo_fob_total'),
            ],
        ]);
    }

    /**
     * Re-audit M2 UX-C1 · pantalla nueva importación (antes solo por Filament).
     */
    public function importacionForm(): Response
    {
        return Inertia::render('Compras/Importacion/Nueva');
    }

    public function importacionCrear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'contenedor' => ['nullable', 'string', 'max:50'],
            'bl_awb' => ['nullable', 'string', 'max:50'],
            'proveedor_pais' => ['nullable', 'string', 'max:100'],
            'puerto_origen' => ['nullable', 'string', 'max:100'],
            'puerto_destino' => ['nullable', 'string', 'max:100'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'moneda_origen' => ['required', 'string', 'size:3'],
            'tasa_cambio_liquidacion' => ['nullable', 'numeric', 'min:0'],
            'fecha_zarpe' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
        ]);
        // Re-audit M2 PATRÓN C + I · usa siguienteNumero() atómico del modelo
        // (con lockForUpdate + año en TZ Colombia). Antes: `count()+1` sin lock =
        // colisión concurrente; `whereYear('created_at')` sin TZ = 31-dic 20:00
        // Bogotá cruzaba al año siguiente en UTC → salto de secuencia.
        $numero = Importacion::siguienteNumero();
        $imp = Importacion::create([...$data, 'numero' => $numero, 'creado_por' => auth()->id()]);
        return redirect()->route('app.compras.importacion.show', $imp->id)->with('success', "Importación {$imp->numero} creada.");
    }

    public function importacionGastoAgregar(Request $r, int $importacion): RedirectResponse
    {
        // Re-audit M2 PATRÓN L (FUNC-A6 / DATOS-A7) · unificado con Action:
        // valor|cantidad|peso|volumen (antes HTTP aceptaba "fob" que caía al
        // default silenciosamente y rechazaba "peso" que Filament sí ofrece).
        $data = $r->validate([
            'concepto' => ['required', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'moneda' => ['required', 'string', 'size:3'],
            'monto' => ['required', 'numeric', 'min:0'],
            'capitalizable' => ['boolean'],
            'metodo_prorrateo' => ['required', 'string', 'in:valor,cantidad,peso,volumen'],
            'factura_proveedor' => ['nullable', 'string', 'max:100'],
            'fecha' => ['required', 'date'],
            'proveedor_id' => ['nullable', 'integer'],
        ]);
        $imp = Importacion::findOrFail($importacion);
        abort_if(
            $imp->estado === EstadoImportacion::Liquidada,
            422,
            'La importación ya está liquidada. Requiere reversar liquidación (a definir) para agregar gastos.'
        );
        // Re-audit M2 R3 PATRÓN R (SEG-B2) · si moneda ≠ COP y no hay TRM
        // definida, ABORTA — antes se usaba 1 silenciosamente y USD 1200
        // entraba al asiento como COP 1200 (subvaluación grave del costo).
        if ($data['moneda'] !== 'COP' && ((float) $imp->tasa_cambio_liquidacion) <= 0) {
            abort(422, "Falta definir la tasa de cambio de la importación (moneda={$data['moneda']}). Edítala y agrega TRM antes de agregar gastos en moneda extranjera.");
        }
        $data['monto_base'] = $data['monto'] * (float) ($imp->tasa_cambio_liquidacion ?: 1);
        $imp->gastos()->create($data);
        return back()->with('success', 'Gasto agregado.');
    }

    public function importacionLiquidar(int $importacion): RedirectResponse
    {
        $imp = Importacion::findOrFail($importacion);
        abort_if($imp->estado === EstadoImportacion::Liquidada, 422, 'Ya liquidada.');
        LiquidarImportacion::run($imp);
        return back()->with('success', 'Importación liquidada — costos prorrateados.');
    }

    // ================== REPORTE COMPRAS ==================
    public function reporte(\Illuminate\Http\Request $request): Response
    {
        // COMP-B9 · filtros fecha editables + comparativa mes anterior.
        $tz = 'America/Bogota';
        $inicio = $request->filled('desde')
            ? \Carbon\Carbon::parse($request->string('desde'), $tz)->startOfDay()
            : now($tz)->startOfMonth();
        $fin = $request->filled('hasta')
            ? \Carbon\Carbon::parse($request->string('hasta'), $tz)->endOfDay()
            : now($tz)->endOfMonth();
        // Mismo rango un mes antes para la comparativa delta%.
        $inicioPrev = $inicio->copy()->subMonthNoOverflow();
        $finPrev    = $fin->copy()->subMonthNoOverflow();

        $ocPendientes = OrdenCompra::whereIn('estado', [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Enviada, EstadoOrdenCompra::Parcial])->count();
        $ocPeriodo = (float) OrdenCompra::whereBetween('fecha_emision', [$inicio, $fin])->sum('total');
        $ocPrev    = (float) OrdenCompra::whereBetween('fecha_emision', [$inicioPrev, $finPrev])->sum('total');
        $contenedoresPorLiquidar = Importacion::whereIn('estado', [EstadoImportacion::Nacionalizada, EstadoImportacion::EnPuerto])->count();
        $totalFobPeriodo = Importacion::whereBetween('created_at', [$inicio, $fin])
            ->with('lineas')->get()->sum(fn ($i) => $i->lineas->sum('costo_fob_total'));

        $topProveedores = OrdenCompra::selectRaw('proveedor_id, COUNT(*) as ocs, SUM(total) as total')
            ->whereBetween('fecha_emision', [$inicio, $fin])
            ->groupBy('proveedor_id')->orderByDesc('total')->limit(10)
            ->with('proveedor:id,nombre_completo,razon_social,numero_documento')
            ->get()->map(fn ($r) => [
                'proveedor_id' => $r->proveedor?->id,
                'nombre' => $r->proveedor?->razon_social ?: $r->proveedor?->nombre_completo,
                'nit' => $r->proveedor?->numero_documento,
                'ocs' => (int) $r->ocs, 'total' => (float) $r->total,
            ])->all();

        // COMP-B9 · cruce con SIIGO /v1/accounts-payable (si está disponible).
        // No bloquea el reporte si falla · solo omite la columna "SIIGO".
        $cxpSiigo = $this->cruzarCxPConSiigo($topProveedores, $inicio, $fin);

        $delta = fn ($a, $b) => $b > 0 ? round((($a - $b) / $b) * 100, 1) : null;

        return Inertia::render('Compras/Reporte', [
            'periodo' => [
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
                'inicio_prev' => $inicioPrev->toDateString(),
                'fin_prev' => $finPrev->toDateString(),
            ],
            'kpis' => [
                'oc_pendientes' => $ocPendientes,
                'oc_periodo' => $ocPeriodo,
                'oc_prev' => $ocPrev,
                'oc_delta_pct' => $delta($ocPeriodo, $ocPrev),
                'contenedores_por_liquidar' => $contenedoresPorLiquidar,
                'total_fob_periodo' => (float) $totalFobPeriodo,
            ],
            'topProveedores' => $topProveedores,
            'siigo' => $cxpSiigo, // ['disponible'=>bool, 'por_nit'=>[...], 'mensaje'=>?string]
        ]);
    }

    /**
     * COMP-B9 · Trae el saldo CxP por proveedor desde SIIGO para los NIT del
     * top10 local. Si SIIGO está caído o no habilitado, degradamos silencioso
     * (reporte igual se renderiza, solo se oculta la columna comparativa).
     */
    private function cruzarCxPConSiigo(array $topProveedores, \Carbon\Carbon $inicio, \Carbon\Carbon $fin): array
    {
        try {
            $cliente = app(\App\Modules\Siigo\Clients\SiigoClient::class);
            // Agrupamos por NIT · un GET por proveedor con limit chico (10) basta.
            $out = [];
            foreach ($topProveedores as $tp) {
                if (empty($tp['nit'])) continue;
                $r = $cliente->request('GET', '/v1/accounts-payable', [
                    'identification' => $tp['nit'],
                    'due_date_start' => $inicio->toDateString(),
                    'due_date_end' => $fin->toDateString(),
                    'page_size' => 25,
                ]);
                if (! $r->ok()) continue;
                $data = $r->json();
                $items = $data['results'] ?? (is_array($data) ? $data : []);
                $saldo = 0.0; $facturas = 0;
                foreach ($items as $it) {
                    $saldo += (float) ($it['balance'] ?? $it['total'] ?? 0);
                    $facturas++;
                }
                $out[$tp['nit']] = [
                    'facturas' => $facturas,
                    'saldo' => round($saldo, 2),
                ];
            }
            return ['disponible' => true, 'por_nit' => $out, 'mensaje' => null];
        } catch (\Throwable $e) {
            return ['disponible' => false, 'por_nit' => [], 'mensaje' => $e->getMessage()];
        }
    }
}
