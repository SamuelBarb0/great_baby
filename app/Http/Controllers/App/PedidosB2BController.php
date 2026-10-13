<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PedidosB2BController extends Controller implements HasMiddleware
{
    /** Ítems descontados del kardex en el último despacho, para el mensaje al usuario. */
    private int $lineasDescontadas = 0;

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // LOG · Pedidos B2B: AdminBodega y Despachador también entran
                //   (necesitan ver qué viene para despachar y en qué estado).
                //   Las acciones de aprobar/rechazar/facturar siguen siendo
                //   responsabilidad gerencial, protegidas por su propia
                //   validación de estado adentro de cada método.
                $u = $r->user();
                abort_unless(
                    $u && ($u->esEquipoBodega() || $u->hasRole('Despachador')),
                    403
                );
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        $estado = (string) $request->input('estado', '');
        $pedidos = PedidoCliente::with(['contacto:id,nombre_completo,razon_social,email', 'lista:id,nombre'])
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            // LOG-J3 · retenidos y nuevos (enviado) arriba porque son los que
            //   requieren una acción humana; luego por recencia. Si hay filtro
            //   específico, el orderByRaw no estorba (no cambia el resultado
            //   dentro de un mismo estado).
            ->orderByRaw("CASE estado
                WHEN 'retenido' THEN 0
                WHEN 'enviado'  THEN 1
                WHEN 'aprobado' THEN 2
                WHEN 'facturado' THEN 3
                WHEN 'despachado' THEN 4
                ELSE 9 END")
            ->orderByDesc('id')->paginate(30)
            ->withQueryString();

        $conteos = PedidoCliente::selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')->pluck('total', 'estado')->all();

        return Inertia::render('PedidosB2B/Index', [
            'pedidos' => $pedidos->through(fn ($p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
                'email' => $p->contacto?->email,
                'lista' => $p->lista?->nombre,
                'estado' => $p->estado,
                'total' => (float) $p->total,
                'fecha' => $p->created_at?->format('Y-m-d H:i'),
                'facturado' => (bool) $p->factura_id,
            ]),
            'conteos' => $conteos,
            'estado_filtro' => $estado ?: null,
        ]);
    }

    public function show(int $pedido): Response
    {
        // LOG-J7 · cargamos siigo_id + cufe para que la UI decida si permite despachar.
        // LOG-J10 · creadoPor/facturadoPor/despachadoPor para armar la línea de tiempo.
        $p = PedidoCliente::with([
            'items.variante:id,codigo_barras', 'contacto', 'lista:id,nombre',
            'factura:id,numero,siigo_id,cufe',
            'facturadoPor:id,name', 'despachadoPor:id,name',
            // LOG-J5 · incluimos el alistador para el timeline + Show card.
            'alistador:id,name',
        ])->findOrFail($pedido);

        return Inertia::render('PedidosB2B/Show', [
            'pedido' => [
                'id' => $p->id,
                'numero' => $p->numero,
                'estado' => $p->estado,
                'subtotal' => (float) $p->subtotal,
                'iva' => (float) $p->iva,
                'total' => (float) $p->total,
                'notas_cliente' => $p->notas_cliente,
                'notas_internas' => $p->notas_internas,
                'motivo_rechazo' => $p->motivo_rechazo,
                // LOG-J2 · motivo de retención automática por cartera.
                'motivo_retencion' => $p->motivo_retencion,
                'creado' => $p->created_at?->format('Y-m-d H:i'),
                'enviado_at' => $p->enviado_at?->format('Y-m-d H:i'),
                'aprobado_at' => $p->aprobado_at?->format('Y-m-d H:i'),
                'facturado_at' => $p->facturado_at?->format('Y-m-d H:i'),
                // LOG-J7 · incluir siigo_id/cufe para que la UI pueda decidir si deja despachar.
                'factura' => $p->factura ? [
                    'id' => $p->factura->id,
                    'numero' => $p->factura->numero,
                    'siigo_id' => $p->factura->siigo_id,
                    'cufe' => $p->factura->cufe,
                ] : null,
                // LOG-J7 · info de despacho.
                'despachado_at' => $p->despachado_at?->format('Y-m-d H:i'),
                'guia_transportadora' => $p->guia_transportadora,
                'transportadora' => $p->transportadora,
                // LOG-J10 · línea de tiempo unificada del pedido · evita que
                // Don Jorge tenga que ir a 4 módulos distintos. Cada hito trae
                // actor, hora y observación. Mostramos SOLO los que ocurrieron.
                'timeline' => array_values(array_filter([
                    [
                        'hito' => 'Pedido creado',
                        'actor' => 'Cliente/Vendedor',
                        'at' => $p->created_at?->format('Y-m-d H:i'),
                        'icon' => '📝',
                        'color' => 'surface',
                        'detalle' => 'En el carrito del Portal B2B.',
                    ],
                    $p->enviado_at ? [
                        'hito' => 'Pedido enviado a aprobación',
                        'actor' => 'Cliente/Vendedor',
                        'at' => $p->enviado_at->format('Y-m-d H:i'),
                        'icon' => '📤',
                        'color' => 'blue',
                        'detalle' => 'Cola de pedidos para gerencia.',
                    ] : null,
                    // LOG-J2 · si el semáforo de cartera lo retuvo, lo pintamos
                    //   justo después de "enviado" para que Don Jorge entienda
                    //   por qué no entró a la cola de alistamiento todavía.
                    $p->estado === 'retenido' ? [
                        'hito' => 'Pedido RETENIDO por cartera',
                        'actor' => 'Semáforo automático',
                        'at' => $p->enviado_at?->format('Y-m-d H:i') ?? $p->created_at?->format('Y-m-d H:i'),
                        'icon' => '⏸',
                        'color' => 'red',
                        'detalle' => $p->motivo_retencion ?: 'Requiere aprobación de Gerencia.',
                    ] : null,
                    $p->aprobado_at ? [
                        'hito' => 'Pedido aprobado',
                        'actor' => 'Gerencia',
                        'at' => $p->aprobado_at->format('Y-m-d H:i'),
                        'icon' => '✓',
                        'color' => 'emerald',
                        'detalle' => 'Listo para facturar.',
                    ] : null,
                    $p->rechazado_at ? [
                        'hito' => 'Pedido rechazado',
                        'actor' => 'Gerencia',
                        'at' => $p->rechazado_at->format('Y-m-d H:i'),
                        'icon' => '✕',
                        'color' => 'red',
                        'detalle' => $p->motivo_rechazo ?: 'Sin motivo registrado.',
                    ] : null,
                    $p->facturado_at ? [
                        'hito' => 'Factura emitida',
                        'actor' => $p->facturadoPor?->name ?? 'Facturadora',
                        'at' => $p->facturado_at->format('Y-m-d H:i'),
                        'icon' => '🧾',
                        'color' => 'brand',
                        'detalle' => $p->factura
                            ? 'N° '.$p->factura->numero.($p->factura->siigo_id ? ' · en SIIGO' : ' · pendiente SIIGO')
                            : 'Sin factura asociada.',
                    ] : null,
                    // LOG-J5 · tres hitos del alistamiento (Cola Don Jorge).
                    $p->alistado_asignado_at ? [
                        'hito' => 'Asignado al alistador',
                        'actor' => $p->alistador?->name ?? 'Logística',
                        'at' => $p->alistado_asignado_at->format('Y-m-d H:i'),
                        'icon' => '👤',
                        'color' => 'surface',
                        'detalle' => 'Entró a la cola de picking.',
                    ] : null,
                    $p->alistado_inicio_at ? [
                        'hito' => 'Alistamiento iniciado',
                        'actor' => $p->alistador?->name ?? 'Alistador',
                        'at' => $p->alistado_inicio_at->format('Y-m-d H:i'),
                        'icon' => '🏃',
                        'color' => 'blue',
                        'detalle' => 'Buscando la mercancía en racks.',
                    ] : null,
                    $p->alistado_fin_at ? [
                        'hito' => $p->alistado_con_novedad
                            ? 'Alistamiento terminado CON NOVEDAD'
                            : 'Alistamiento terminado',
                        'actor' => $p->alistador?->name ?? 'Alistador',
                        'at' => $p->alistado_fin_at->format('Y-m-d H:i'),
                        'icon' => $p->alistado_con_novedad ? '⚠' : '📦',
                        'color' => $p->alistado_con_novedad ? 'red' : 'emerald',
                        'detalle' => ($p->alistado_inicio_at
                            ? max(0, $p->alistado_inicio_at->diffInMinutes($p->alistado_fin_at)).' min'
                            : '').
                            ($p->alistado_tipo_novedad ? ' · '.strtoupper($p->alistado_tipo_novedad) : '').
                            ($p->alistado_notas ? ' · '.$p->alistado_notas : ''),
                    ] : null,
                    // LOG-J5-fix · hito de resolución · aparece sólo si alguien liberó la novedad.
                    $p->novedad_resuelta_at ? [
                        'hito' => 'Novedad resuelta',
                        'actor' => \App\Models\User::find($p->novedad_resuelta_por_id)?->name ?? 'Gerencia',
                        'at' => $p->novedad_resuelta_at->format('Y-m-d H:i'),
                        'icon' => '✓',
                        'color' => 'emerald',
                        'detalle' => $p->novedad_resolucion ?: 'Liberado al empaque.',
                    ] : null,
                    $p->despachado_at ? [
                        'hito' => 'Pedido despachado',
                        'actor' => $p->despachadoPor?->name ?? 'Logística',
                        'at' => $p->despachado_at->format('Y-m-d H:i'),
                        'icon' => '🚚',
                        'color' => 'indigo',
                        'detalle' => ($p->transportadora ? $p->transportadora.' · ' : '').'Guía '.$p->guia_transportadora,
                    ] : null,
                ])),
                'contacto' => [
                    'id' => $p->contacto->id,
                    'nombre' => $p->contacto->razon_social ?: $p->contacto->nombre_completo,
                    'email' => $p->contacto->email,
                    'telefono' => $p->contacto->telefono,
                    'ciudad' => $p->contacto->ciudad,
                ],
                'lista' => $p->lista?->nombre,
            ],
            'items' => $p->items->map(fn ($i) => [
                'sku' => $i->sku_snapshot,
                'desc' => $i->descripcion_snapshot,
                'variante_id' => $i->variante_id,
                // C-F-QA3 · propagar producto_id + flag agregado a la vista admin.
                'producto_id' => $i->producto_id,
                'es_agregado' => $i->variante_id === null && $i->producto_id !== null,
                'cantidad' => (int) $i->cantidad,
                'precio' => (float) $i->precio_unitario,
                'total' => (float) $i->total,
            ])->values(),
        ]);
    }

    public function aprobar(int $pedido): RedirectResponse
    {
        // C-QA-D-3: lock para evitar carrera Aprobar/Rechazar concurrente.
        // LOG-J3 · Gerencia también libera un `retenido` desde este botón —
        //   es el mismo gesto conceptual (aprobar), y así no hay dos endpoints
        //   paralelos. La UI distingue el chip (ámbar vs azul) por `estado`.
        $pre = PedidoCliente::findOrFail($pedido);
        $eraRetenido = $pre->estado === 'retenido';

        DB::transaction(function () use ($pedido) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($p->estado, ['enviado', 'retenido'], true), 422,
                'Sólo se aprueban pedidos enviados o retenidos por cartera.');
            $p->update([
                'estado' => 'aprobado',
                'aprobado_at' => now(),
                // LOG-J3 · si venía retenido, dejamos el motivo como huella para
                //   el timeline (hito 'retenido') pero el pedido ya está vivo.
            ]);
        });

        // LOG-J3 · Notificación: avisar que un retenido fue liberado por Gerencia.
        //   El vendedor/cliente ve la bell y entiende que ya puede imprimir
        //   factura o esperar la llamada del alistador.
        if ($eraRetenido) {
            \App\Models\NotificacionErp::crear([
                'tipo' => 'pedido_liberado',
                'titulo' => "Pedido {$pre->numero} LIBERADO por Gerencia",
                'mensaje' => "El pedido había sido retenido por cartera. Ya está listo para facturar.",
                'color' => 'success',
                'icono' => 'heroicon-o-check-badge',
                'url' => "/app/pedidos-b2b/{$pre->id}",
            ]);
        }

        return back()->with('success',
            $eraRetenido ? 'Pedido LIBERADO por Gerencia. Listo para facturar.'
                         : 'Pedido aprobado. Listo para facturar.');
    }

    public function rechazar(Request $r, int $pedido): RedirectResponse
    {
        // C-QA-D-Bloque2: mín 10 chars — motivos como "no" son inútiles para el cliente.
        $data = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        $pre = PedidoCliente::findOrFail($pedido);
        $eraRetenido = $pre->estado === 'retenido';

        DB::transaction(function () use ($pedido, $data) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            // LOG-J3 · incluimos `retenido` entre los estados rechazables para
            //   cerrar el ciclo cuando Gerencia decide no otorgar el crédito.
            abort_unless(in_array($p->estado, ['enviado', 'retenido', 'aprobado'], true), 422,
                'Estado no permite rechazar.');
            $p->update([
                'estado' => 'rechazado',
                'rechazado_at' => now(),
                'motivo_rechazo' => $data['motivo'],
            ]);
        });

        // LOG-J3 · Si venía retenido, el motivo que ve el cliente/vendedor
        //   es la decisión de Gerencia, no la razón de cartera (que ya quedó
        //   en motivo_retencion). Esto evita confundir al vendedor con dos
        //   mensajes contradictorios.
        \App\Models\NotificacionErp::crear([
            'tipo' => $eraRetenido ? 'pedido_rechazado_cartera' : 'pedido_rechazado',
            'titulo' => "Pedido {$pre->numero} rechazado",
            'mensaje' => \Illuminate\Support\Str::limit($data['motivo'], 140),
            'color' => 'danger',
            'icono' => 'heroicon-o-x-circle',
            'url' => "/app/pedidos-b2b/{$pre->id}",
        ]);

        return back()->with('success', 'Pedido rechazado.');
    }

    /**
     * LOG-J7 · Despachar pedido · gate duro "sin factura no sale".
     *
     * Don Jorge Rojas reportó inventario negativo porque la mercancía salía
     * físicamente sin que hubiera factura emitida. Este endpoint rechaza el
     * intento si `factura_id` es null. También pide guía/transportadora para
     * cerrar el ciclo con huella — así se puede consultar luego la línea de
     * tiempo del pedido (LOG-J10).
     */
    public function despachar(Request $r, int $pedido): RedirectResponse
    {
        $data = $r->validate([
            'guia_transportadora' => ['required', 'string', 'max:60'],
            'transportadora' => ['nullable', 'string', 'max:80'],
        ]);
        DB::transaction(function () use ($pedido, $data, $r) {
            $p = PedidoCliente::whereKey($pedido)->with('factura:id,numero,siigo_id,cufe,estado')
                ->lockForUpdate()->firstOrFail();
            // Gate duro #1 · estado válido.
            abort_unless(in_array($p->estado, ['aprobado', 'facturado'], true), 422,
                'Sólo se despacha un pedido aprobado o facturado.');
            // Gate duro #2 · raíz del problema: factura emitida en el ERP.
            // No exigimos siigo_id ni CUFE acá porque la factura ya está impresa y
            // acompaña físicamente la mercancía — la sync con SIIGO se completa en
            // segundo plano. Trabar al logístico esperando SIIGO lo hace circular
            // entre pantallas sin razón.
            abort_unless($p->factura_id, 422,
                'No se puede despachar sin factura. Generá la factura antes de entregar al transportador.');
            // …y que esa factura siga viva. Sólo se validaba que existiera, así
            // que una factura anulada dejaba salir la mercancía igual.
            $estadoFactura = $p->factura?->estado;
            $estadoFactura = is_object($estadoFactura) ? $estadoFactura->value : $estadoFactura;
            abort_if($estadoFactura === 'anulada', 422,
                'La factura de este pedido está anulada. Generá una factura nueva antes de despachar.');
            // Gate duro #3 · idempotencia.
            abort_if($p->despachado_at, 422, 'Este pedido ya fue despachado.');
            // Gate duro #4 (LOG-J5-fix) · novedad del alistamiento abierta.
            //   Si el alistador cerró con faltante/avería/revisión, no sale
            //   la mercancía hasta que Gerencia/admin de bodega resuelva.
            //   Esto es la raíz del "inventario negativo + mercancía mala al
            //   cliente" que Don Jorge reportó: nunca más se despacha algo
            //   que ya se marcó como incompleto o defectuoso.
            if ($p->alistado_con_novedad && !$p->novedad_resuelta_at) {
                abort(422, 'Este pedido tiene una novedad abierta ('
                    . ($p->alistado_tipo_novedad ?: 'sin tipo')
                    . '). Resolvela en la Cola de alistamiento antes de despachar.');
            }
            $p->update([
                'estado' => 'despachado',
                'despachado_at' => now(),
                'despachado_por_id' => $r->user()->id,
                'guia_transportadora' => $data['guia_transportadora'],
                'transportadora' => $data['transportadora'] ?? null,
            ]);

            // La mercancía sale de la bodega: baja del kardex. Sin esto el ERP
            // seguía mostrando en stock lo que ya iba camino al cliente.
            $lineas = (new \App\Modules\Inventario\Actions\DescontarStockPorDespacho())
                ->ejecutar($p, $r->user()->id);

            $this->lineasDescontadas = $lineas;
        });

        $msg = 'Pedido despachado · guía registrada.';
        if (($this->lineasDescontadas ?? 0) > 0) {
            $msg .= " Se descontaron {$this->lineasDescontadas} ítem(s) del inventario.";
        }

        return back()->with('success', $msg);
    }

    public function facturar(int $pedido): RedirectResponse
    {
        // ── GATE DE CRÉDITO (flujo bodega F2) ────────────────────────────────
        // Corre ANTES de la tx de facturación: si el pedido es a crédito, valida
        // cupo / mora / factura vencida. Si retiene, crea la excepción (que se
        // aprueba en "Excepciones de crédito") y NO factura. Una vez aprobada por
        // Cartera/Gerencia, este mismo botón libera y factura.
        $p0 = PedidoCliente::findOrFail($pedido);
        abort_unless($p0->estado === 'aprobado', 422, 'Sólo se factura un pedido aprobado.');
        abort_if($p0->factura_id, 422, 'Pedido ya facturado.');

        $esCredito = \App\Modules\Cartera\Models\CondicionCredito::query()
            ->where('contacto_id', $p0->contacto_id)->where('activa', true)->exists();

        if ($esCredito) {
            $yaAprobado = \App\Modules\Cartera\Models\SolicitudCredito::query()
                ->where('pedido_id', $p0->id)
                // «aprobada» a secas es lo que escribía la pantalla Vue antes
                // del 2026-10-08. Se acepta para no dejar colgadas las
                // solicitudes que ya quedaron guardadas así.
                ->whereIn('estado', ['aprobada_cartera', 'aprobada_gerencia', 'aprobada'])
                ->exists();

            if (! $yaAprobado) {
                $hayPendiente = \App\Modules\Cartera\Models\SolicitudCredito::query()
                    ->where('pedido_id', $p0->id)->where('estado', 'pendiente')->exists();

                $res = \App\Modules\Cartera\Actions\LiberarPedidoAutomatico::run(
                    $p0->contacto_id,
                    (float) $p0->total,
                    crearSolicitud: ! $hayPendiente, // no duplicar si ya hay una pendiente
                    pedidoId: $p0->id,
                );

                if ($res['estado'] === 'retenido') {
                    $nivel = ucfirst($res['nivel'] ?? 'cartera');
                    return back()->with('warning',
                        "Pedido RETENIDO: {$res['motivo']}. Requiere aprobación de {$nivel}. "
                        . "Gestiónalo en 'Excepciones de crédito'."
                    );
                }
            }
        }

        $tipoFactura = $esCredito ? 'credito' : 'contado';

        // Facturador pack · si vino desde la bandeja del rol Facturador, en
        // session() quedó la decisión manual (send_dian / send_mail) + el user
        // que la emitió, para persistir trazabilidad en facturas_venta y
        // pasarla al job de SIIGO. Si viene desde otro flujo (ej. tester
        // interno), el default es send_dian=true · send_mail=false · user=auth.
        $facturadorSendDian = (bool) session('facturador.send_dian', true);
        $facturadorSendMail = (bool) session('facturador.send_mail', false);
        $facturadorUserId   = (int)  session('facturador.user_id', auth()->id());
        // Limpio ya para que no se filtren decisiones a otra factura.
        session()->forget(['facturador.send_dian', 'facturador.send_mail', 'facturador.user_id']);

        $factura = DB::transaction(function () use ($pedido, $tipoFactura, $facturadorSendDian, $facturadorSendMail, $facturadorUserId) {
            // C-QA-D-3: lock + revalidar dentro de tx.
            $p = PedidoCliente::with('items')->whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless($p->estado === 'aprobado', 422, 'Sólo se factura un pedido aprobado.');
            abort_if($p->factura_id, 422, 'Pedido ya facturado.');
            abort_if($p->items->isEmpty(), 422, 'Pedido sin ítems, no se puede facturar.');
            abort_if((float) $p->total <= 0, 422, 'Pedido con total 0, no se puede facturar.');

            // C-QA-D-Bloque2: fecha vencimiento del crédito del cliente, no hardcoded 30d.
            $plazo = \App\Modules\Cartera\Models\CondicionCredito::query()
                ->where('contacto_id', $p->contacto_id)
                ->where('activa', true)
                ->latest('vigente_desde')
                ->value('plazo_dias') ?? 30;

            // LOG-J7+SIIGO · Si la integración Siigo está activa, la factura nace
            //   electrónica (es_electronica=true) para que `ReintentarEmisionDian`
            //   la mande a /v1/invoices SIEMPRE después de commit. Si Siigo está
            //   apagado, cae a factura interna (DIAN manual) sin perder el flujo.
            $siigoActivo = \App\Modules\Siigo\Models\SiigoConfig::current()->activo ?? false;

            $f = FacturaVenta::create([
                'numero' => $this->siguienteNumeroFactura(),  // C-QA-D-2
                'contacto_id' => $p->contacto_id,
                'fecha_emision' => now()->toDateString(),
                'fecha_vencimiento' => now()->addDays($plazo)->toDateString(),
                'estado' => 'pendiente',
                'tipo' => $tipoFactura,
                'subtotal' => $p->subtotal,
                'descuento' => 0,
                'impuestos' => $p->iva,
                'total' => $p->total,
                'saldo' => $p->total,
                'es_electronica' => (bool) $siigoActivo,
                'origen_type' => \App\Modules\Portal\Models\PedidoCliente::class,
                'origen_id' => $p->id,
                'observaciones' => 'Pedido B2B ' . $p->numero,
                // Facturador pack · trazabilidad de la decisión manual.
                'facturado_por_user_id' => $facturadorUserId,
                'facturado_send_dian'   => $facturadorSendDian,
                'facturado_send_mail'   => $facturadorSendMail,
            ]);

            foreach ($p->items as $it) {
                FacturaVentaItem::create([
                    'factura_id' => $f->id,
                    'variante_id' => $it->variante_id,
                    // C-F-QA3 · propagar producto_id para trazabilidad de facturación agregada
                    // (SIIGO, reportes por producto, notas crédito).
                    'producto_id' => $it->producto_id,
                    'descripcion' => $it->descripcion_snapshot,
                    'cantidad' => $it->cantidad,
                    'precio_unit' => $it->precio_unitario,
                    'descuento_pct' => 0,
                    'impuesto_pct' => $it->iva_porcentaje,
                    'subtotal' => $it->subtotal,
                ]);
            }

            // Lo que el cliente nos va a retener: antes del asiento y de SIIGO,
            // que lo leen de la factura.
            app(\App\Modules\Cartera\Actions\AplicarRetencionesVenta::class)->ejecutar($f);

            // C-QA-D-1 (CRÍTICO): el observer `saved` corre en create con items=0 y NO genera asiento.
            // Disparamos explícitamente después de insertar ítems.
            // El action es idempotente (chequea si ya existe asiento para esta factura).
            (new \App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->refresh());

            $p->update([
                'estado' => 'facturado',
                'facturado_at' => now(),
                'facturado_por_id' => auth()->id(),
                'factura_id' => $f->id,
            ]);

            // QA-D Bloque3: actualizar CRM del contacto (ultima_compra_at + total_comprado_ytd).
            // Antes quedaba frío hasta que otro job barra. Ahora se refleja al instante.
            $contacto = \App\Models\Contacto::find($p->contacto_id);
            if ($contacto) {
                $ytdTotal = FacturaVenta::where('contacto_id', $contacto->id)
                    ->whereYear('fecha_emision', now('America/Bogota')->year)
                    ->whereNotIn('estado', ['borrador', 'anulada'])
                    ->sum('total');
                $contacto->forceFill([
                    'ultima_compra_at' => now(),
                    'total_comprado_ytd' => $ytdTotal,
                ])->saveQuietly();
            }

            return $f;
        });

        // QA-D Bloque3: invalidar cache portal del cliente (saldo, pendientes, vencidas cambian).
        \Illuminate\Support\Facades\Cache::forget("portal.kpis.{$factura->contacto_id}");

        // LOG-Jorge · Observación: "creaste la factura pero no está en SIIGO".
        //   Si la integración está activa, encolamos la emisión AFUERA de la tx
        //   para que la factura B2B viaje a /v1/invoices sin bloquear al usuario.
        //   `ReintentarEmisionDian` ya maneja lock, backoff 1-5-30min-3h,
        //   notificación en la bell y "ya estaba emitida" (idempotente).
        if ($factura->es_electronica) {
            // Las decisiones del Facturador (send_dian/send_mail) viajan en las
            // columnas de la factura · el SiigoEmisionService las lee al armar
            // el payload /v1/invoices.
            \App\Jobs\ReintentarEmisionDian::dispatch($factura->id)
                ->onQueue('siigo');
        }

        $msg = "Facturado como {$factura->numero}.";
        if ($factura->es_electronica) {
            $msg .= ' Enviando a SIIGO en segundo plano.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Re-audit DATOS C3/C4 · consecutivo transaccional real, con validación de
     * rango DIAN de la empresa. Reemplaza el SELECT MAX+1 anterior (race +
     * ancho fijo se rompe en 10 000).
     */
    private function siguienteNumeroFactura(): string
    {
        $prefijo = (setting('empresa.prefijo_dian') ?: 'FV') . '-' . now()->format('ymd') . '-';
        $rangoHasta = (int) setting('empresa.rango_hasta', 0) ?: null;

        return \App\Modules\Cartera\Actions\SiguienteConsecutivoFactura::run(
            $prefijo,
            4,
            $rangoHasta,
        );
    }
}
