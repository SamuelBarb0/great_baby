<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\GarantiaTicket;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M7 · Servicio al Cliente y Garantías.
 * Estados: abierto → en_revision → aprobada|rechazada → en_reposicion → cerrada
 * Contrato: reposición a valor $0 al cliente.
 */
class GarantiasController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['abierto', 'en_revision', 'aprobada', 'rechazada', 'en_reposicion', 'cerrada'];

    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            // C-F-QA5 · Fix CRÍTICO auditor roles: el rol se llama 'ServicioCliente' (no 'SAC').
            // Antes: hasAnyRole(['SAC','Gerente']) → ServicioCliente NUNCA pasaba.
            // La casilla manda. Antes la puerta preguntaba por el rol y la
            //   casilla de /app/roles no la consultaba nadie: el admin creia
            //   repartir accesos y solo movia lo que se ve en el menu.
            abort_unless(\App\Auth\Permisos::puede($u, 'servicio_cliente'), 403);
            return $next($r);
        })];
    }

    public function index(Request $request): Response
    {
        $estado = (string) $request->input('estado', '');
        $q = trim((string) $request->input('q', ''));

        $tickets = GarantiaTicket::with('creadoPor:id,name')
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->when($q, fn ($qb) => $qb->where(function ($w) use ($q) {
                $w->where('numero', 'like', "%{$q}%")
                    ->orWhere('cliente_nombre', 'like', "%{$q}%")
                    ->orWhere('cliente_telefono', 'like', "%{$q}%");
            }))
            ->orderByDesc('id')->paginate(30);

        $conteos = GarantiaTicket::selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado')->all();

        $sla = $this->indicadoresSla();

        return Inertia::render('Garantias/Index', [
            'tickets' => $tickets->through(fn ($t) => [
                'id' => $t->id, 'numero' => $t->numero,
                'cliente' => $t->cliente_nombre, 'telefono' => $t->cliente_telefono,
                'estado' => $t->estado, 'cantidad' => (int) $t->cantidad,
                'creado' => $t->created_at?->format('Y-m-d H:i'),
                'creador' => $t->creadoPor?->name,
                'descripcion' => $t->descripcion_falla,
                'valor' => (float) $t->valor_reposicion,
                // Lo que le costó a la empresa, que no es lo que pagó el
                // cliente: en una garantía el cliente paga cero.
                'costo' => $t->costoTotal(),
                'costo_cargado' => $t->tieneCostoCargado(),
            ]),
            'conteos' => $conteos,
            'sla' => $sla,
            'costos' => $this->indicadoresCosto($request),
            'filtros' => ['estado' => $estado ?: null, 'q' => $q ?: null],
        ]);
    }

    /**
     * Cuánto cuesta la posventa, y en qué se va.
     *
     * El módulo medía tiempos (SLA) y no medía plata. El ticket guardaba
     * `valor_reposicion` y el flujo lo pone en CERO —«contrato: reposición a
     * valor $0»—, que es correcto para el cliente y no dice nada del costo. Sin
     * esto, la pregunta «¿cuál referencia nos está costando la posventa?» no
     * tenía respuesta en ninguna pantalla.
     */
    private function indicadoresCosto(Request $request): array
    {
        $desde = $request->date('desde')?->toDateString()
            ?? now()->startOfMonth()->toDateString();
        $hasta = $request->date('hasta')?->toDateString() ?? now()->toDateString();

        $delPeriodo = GarantiaTicket::conCostoEntre($desde, $hasta)
            // La variante viene para los tickets granulares, que no traen
            // producto propio: sin esto el ranking dispara una consulta por
            // ticket.
            ->with(['producto:id,referencia,nombre', 'variante.producto:id,referencia,nombre'])
            ->get();

        $total = round($delPeriodo->sum(fn (GarantiaTicket $t) => $t->costoTotal()), 2);

        $desglose = ['producto' => 0.0, 'flete_recoger' => 0.0, 'flete_enviar' => 0.0, 'nota_credito' => 0.0, 'otros' => 0.0];
        foreach ($delPeriodo as $t) {
            foreach ($t->desgloseCosto() as $k => $v) {
                if ($k !== 'total') {
                    $desglose[$k] += $v;
                }
            }
        }
        $desglose = array_map(fn ($v) => round($v, 2), $desglose);

        /*
         * Qué referencia cuesta más. Es el dato accionable: una garantía suelta
         * es mala suerte, la misma referencia repitiéndose es un problema de
         * producto, y conviene verlo con el costo al lado y no sólo el conteo.
         */
        /*
         * El ticket granular trae variante y NO producto, así que agrupar por
         * `producto_id` lo dejaba afuera: son todos los tickets anteriores a
         * que existiera el producto agregado. El total sí los contaba, y la
         * pantalla llegaba a decir que no había costos justo debajo del total.
         *
         * Se agrupa por el producto de la variante cuando el ticket no trae
         * producto propio, que es el mismo producto visto desde el otro lado.
         */
        $porProducto = $delPeriodo
            ->map(function (GarantiaTicket $t) {
                $t->setAttribute('producto_agrupado', $t->producto ?: $t->variante?->producto);

                return $t;
            })
            ->filter(fn (GarantiaTicket $t) => $t->producto_agrupado !== null)
            ->groupBy(fn (GarantiaTicket $t) => $t->producto_agrupado->id)
            ->map(fn ($grupo) => [
                'producto' => $grupo->first()->producto_agrupado?->nombre,
                'referencia' => $grupo->first()->producto_agrupado?->referencia,
                'tickets' => $grupo->count(),
                'costo' => round($grupo->sum(fn (GarantiaTicket $t) => $t->costoTotal()), 2),
            ])
            ->sortByDesc('costo')
            ->take(10)
            ->values();

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'tickets' => $delPeriodo->count(),
            'total' => $total,
            'promedio' => $delPeriodo->count() > 0 ? round($total / $delPeriodo->count(), 2) : 0.0,
            'desglose' => $desglose,
            'por_producto' => $porProducto,
            // Una garantía cerrada sin costo cargado no es gratis: es un costo
            // que nadie midió, y hace que este informe cuente de menos.
            'sin_costo' => GarantiaTicket::sinCosto()->count(),
        ];
    }

    public function form(): Response
    {
        return Inertia::render('Garantias/Nueva', [
            'estados' => self::ESTADOS,
        ]);
    }

    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'pedido_guia' => ['nullable', 'string', 'max:50'],
            /*
             * De qué producto es el reclamo: una variante —color y talla— o el
             * producto entero cuando se maneja agregado, los «colores
             * surtidos» que no tienen variantes.
             *
             * Uno de los dos, nunca ninguno. La base ya no lo exige
             * —`variante_id` pasó a nullable para que entre el agregado— y el
             * seguro queda acá: un ticket sin producto no se puede reponer ni
             * costear, y tampoco entra al informe de posventa.
             */
            'variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id', 'required_without:producto_id'],
            'producto_id' => ['nullable', 'integer', 'exists:productos,id', 'required_without:variante_id'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'descripcion_falla' => ['required', 'string', 'min:10', 'max:1000'],
            'fotos' => ['nullable', 'array', 'max:5'],
            'fotos.*' => ['file', 'image', 'max:5120'],
        ], [
            // El mensaje por defecto sería «variante_id es obligatorio cuando
            // no hay producto_id», que no le dice nada a quien atiende.
            'variante_id.required_without' => 'Elegí el producto que falló.',
            'producto_id.required_without' => 'Elegí el producto que falló.',
            'variante_id.exists' => 'Ese producto ya no está en el catálogo.',
            'producto_id.exists' => 'Ese producto ya no está en el catálogo.',
        ]);

        [$sujeto, $errorSujeto] = $this->resolverSujeto($data);

        if ($errorSujeto) {
            return back()->withInput()->with('flash', ['type' => 'error', 'message' => $errorSujeto]);
        }

        /*
         * De qué venta sale el reclamo.
         *
         * Antes sólo cabía una guía de Dropi: un cliente B2B —que es el que
         * compra a crédito y el que más reclama— no tenía por dónde entrar, y
         * su ticket quedaba sin venta de respaldo. Sin eso no hay contra qué
         * validar la referencia, la cantidad ni la fecha, que es justo lo que
         * el contrato pide validar.
         *
         * El mismo campo acepta las dos cosas: una guía de Dropi o un número
         * de factura. Quien atiende no tiene por qué saber de qué canal vino.
         */
        [$origen, $pedidoId] = $this->resolverOrigen($data['pedido_guia'] ?? null);

        if (! empty($data['pedido_guia']) && ! $origen) {
            return back()->withInput()->with('flash', ['type' => 'error',
                'message' => "No encontré la guía ni la factura «{$data['pedido_guia']}». "
                    .'Revisá el número, o dejalo vacío si el cliente no lo tiene.']);
        }

        if ($origen && ($error = $this->validarContraLaVenta($origen, $sujeto, $data))) {
            return back()->withInput()->with('flash', ['type' => 'error', 'message' => $error]);
        }

        // Consecutivo GT-YYMMDD-###
        $numero = 'GT-' . now()->format('ymd') . '-' . str_pad((string) (GarantiaTicket::whereDate('created_at', today())->count() + 1), 3, '0', STR_PAD_LEFT);

        $fotos = [];
        if ($r->hasFile('fotos')) {
            foreach ($r->file('fotos') as $f) {
                $fotos[] = $f->store('garantias', 'public');
            }
        }

        $t = GarantiaTicket::create([
            'numero' => $numero,
            'cliente_nombre' => $data['cliente_nombre'],
            'cliente_telefono' => $data['cliente_telefono'] ?? null,
            'pedido_original_id' => $pedidoId,
            'origen_type' => $origen ? $origen::class : null,
            'origen_id' => $origen?->getKey(),
            'contacto_id' => $origen instanceof \App\Modules\Cartera\Models\FacturaVenta
                ? $origen->contacto_id : null,
            'variante_id' => $sujeto['variante_id'],
            'producto_id' => $sujeto['producto_id'],
            'cantidad' => $data['cantidad'],
            'descripcion_falla' => $data['descripcion_falla'],
            'fotos_evidencia' => $fotos,
            'estado' => 'abierto',
            'creado_por' => auth()->id(),
            // El SLA del área sale de la regla, no de un 3 escrito acá.
            'plazo_concepto_at' => now()->addDays(max(1, (int) setting('garantias.dias_concepto'))),
        ]);

        return redirect()->route('app.garantias.show', $t->id)->with('success', "Garantía {$numero} creada.");
    }


    /**
     * Los indicadores del área: cumplimiento del plazo, demora y motivos.
     *
     * El contrato pide «indicadores de SLA, causa y satisfacción». El ticket
     * guardaba el plazo para dar concepto desde el principio —
     * `plazo_concepto_at`— pero nadie lo miraba: se podía incumplir todos los
     * días sin que apareciera en ninguna pantalla.
     *
     * La satisfacción no está acá a propósito: eso se le pregunta al cliente y
     * todavía no hay por dónde preguntarle. Inventar un número sería peor que
     * no tenerlo.
     *
     * @return array<string,mixed>
     */
    private function indicadoresSla(): array
    {
        $decididos = GarantiaTicket::whereNotNull('decision_at')->get(['decision_at', 'plazo_concepto_at', 'created_at']);

        $aTiempo = $decididos->filter(fn ($t) => $t->plazo_concepto_at
            && $t->decision_at->lte($t->plazo_concepto_at))->count();

        // Lo que está abierto y ya se pasó: es lo urgente de hoy.
        $vencidos = GarantiaTicket::whereNull('decision_at')
            ->whereNotNull('plazo_concepto_at')
            ->where('plazo_concepto_at', '<', now())
            ->count();

        $horas = $decididos
            ->map(fn ($t) => $t->created_at->diffInHours($t->decision_at))
            ->filter(fn ($h) => $h >= 0);

        return [
            'decididos' => $decididos->count(),
            'a_tiempo' => $aTiempo,
            'cumplimiento' => $decididos->count() > 0
                ? round($aTiempo * 100 / $decididos->count(), 1)
                : null,
            'vencidos_abiertos' => $vencidos,
            'horas_promedio' => $horas->isNotEmpty() ? round($horas->avg(), 1) : null,
            'plazo_dias' => (int) setting('garantias.dias_concepto'),
        ];
    }

    /**
     * Encuentra la venta por guía de Dropi o por número de factura.
     *
     * Un solo campo para las dos cosas: quien atiende el reclamo no tiene por
     * qué saber de qué canal vino la venta, y el cliente menos.
     *
     * @return array{0:?\Illuminate\Database\Eloquent\Model,1:?int}
     */
    private function resolverOrigen(?string $referencia): array
    {
        $ref = trim((string) $referencia);
        if ($ref === '') {
            return [null, null];
        }

        $pedido = DropiPedido::where('guia', $ref)->first();
        if ($pedido) {
            return [$pedido, $pedido->id];
        }

        $factura = \App\Modules\Cartera\Models\FacturaVenta::where('numero', $ref)->first();

        return [$factura, null];
    }

    /**
     * De qué producto es el reclamo: una variante, o el producto agregado.
     *
     * `garantia_tickets.variante_id` era NOT NULL, así que todo ticket exigía
     * una variante. Los productos AGREGADOS —`desglose_stock = false`, los
     * «colores surtidos» que entraron por el Excel del cliente— no tienen
     * filas en `producto_variantes`: el stock lo llevan en `stock_directo` y
     * el precio en `precios_producto`. A esos productos, que son la mayor
     * parte del catálogo real, no se les podía abrir garantía.
     *
     * Cuando sí viene una variante se guardan LAS DOS columnas: la variante y
     * su producto. `producto_id` se agregó el 6-nov y el alta no lo escribía
     * nunca, así que el informe «cuál referencia nos está costando la
     * posventa» salía vacío para todo ticket creado desde el formulario,
     * aunque el producto estuviera ahí colgando de la variante. Es el mismo
     * auto-poblado que ya hace `FacturaVentaItem` al crear sus renglones.
     *
     * @param  array<string,mixed>  $data
     * @return array{0:array{variante_id:?int,producto_id:?int},1:?string}
     */
    private function resolverSujeto(array $data): array
    {
        if (! empty($data['variante_id'])) {
            $v = ProductoVariante::findOrFail($data['variante_id']);

            return [['variante_id' => $v->id, 'producto_id' => $v->producto_id], null];
        }

        $p = Producto::findOrFail($data['producto_id']);

        /*
         * Un producto granular SÍ tiene variantes y hay que decir cuál falló:
         * reponer «el coche» sin saber si es el gris o el azul deja la
         * reposición y el descargue de inventario a la adivinanza.
         */
        if ($p->esGranular()) {
            return [['variante_id' => null, 'producto_id' => null],
                "«{$p->nombre}» se maneja por color y talla. Elegí la variante exacta que falló."];
        }

        return [['variante_id' => null, 'producto_id' => $p->id], null];
    }

    /**
     * El buscador del formulario: variantes y productos agregados en una lista.
     *
     * No se reusa `/app/inventario/buscar-variantes`, que devuelve lo mismo,
     * por dos razones: está cerrado a Aracely, alistador y admin de bodega
     * —quien atiende garantías, ServicioCliente, recibía 403— y filtra los
     * agregados por «tiene movimientos en el kardex», que acá sobra: un
     * cliente puede reclamar algo que se vendió antes de que ese producto
     * pasara por inventario.
     */
    public function buscarProducto(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        // Los agregados primero: son los que no se podían reclamar, y son la
        // mayor parte del catálogo que subió el cliente.
        $agregados = Producto::query()
            ->where('desglose_stock', false)
            ->where(fn ($p) => $p->where('nombre', 'like', "%{$q}%")
                ->orWhere('referencia', 'like', "%{$q}%"))
            ->orderBy('nombre')
            ->limit(15)
            ->get(['id', 'referencia', 'nombre'])
            ->map(fn (Producto $p) => [
                'variante_id' => null,
                'producto_id' => $p->id,
                'sku' => $p->referencia,
                'producto' => $p->nombre,
                'detalle' => 'colores surtidos',
                'label' => $p->nombre.' · colores surtidos ['.$p->referencia.']',
            ]);

        $variantes = ProductoVariante::query()
            ->with('producto:id,referencia,nombre')
            ->where(function ($x) use ($q) {
                $x->where('codigo_barras', 'like', "%{$q}%")
                    ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$q}%")
                        ->orWhere('referencia', 'like', "%{$q}%"));
            })
            ->limit(15)
            ->get()
            ->map(function (ProductoVariante $v) {
                $detalle = trim(($v->color_nombre ?? '').' '.($v->talla ?? ''));

                return [
                    'variante_id' => $v->id,
                    'producto_id' => null,
                    'sku' => $v->codigo_barras,
                    'producto' => $v->producto?->nombre,
                    'detalle' => $detalle !== '' ? $detalle : 'sin color ni talla',
                    'label' => trim(($v->producto?->nombre ?? '?').' · '.$detalle).' ['.$v->codigo_barras.']',
                ];
            });

        return response()->json($agregados->concat($variantes)->values());
    }

    /**
     * Que lo que reclaman se haya vendido de verdad en esa venta.
     *
     * Es la validación que pide el contrato —referencia, cantidad y fecha
     * contra la venta original— y la que evita el caso incómodo: reponer algo
     * que nunca se le vendió a esa persona, o reponer diez de una venta de
     * dos.
     *
     * Sólo se valida contra una factura: el pedido de Dropi no guarda sus
     * líneas de la misma forma, y ese flujo ya venía funcionando así.
     *
     * @param  array{variante_id:?int,producto_id:?int}  $sujeto
     * @param  array<string,mixed>  $data
     * @return string|null el motivo del rechazo, o null si todo cuadra
     */
    private function validarContraLaVenta($origen, array $sujeto, array $data): ?string
    {
        if (! $origen instanceof \App\Modules\Cartera\Models\FacturaVenta) {
            return null;
        }

        /*
         * El renglón se busca por el mismo sujeto con el que entra el ticket:
         * por variante cuando el producto se maneja por color y talla, y por
         * producto con `variante_id` nulo cuando es agregado, que es como
         * `FacturaVentaItem` guarda los «colores surtidos».
         *
         * Antes esto sólo miraba `variante_id` y se devolvía sin validar nada
         * cuando no venía. Con el agregado eso dejaría pasar una garantía sin
         * que nadie compruebe que esa factura vendió ese producto, que es
         * justo lo que el contrato pide comprobar.
         */
        $lineas = $sujeto['variante_id']
            ? $origen->items()->where('variante_id', $sujeto['variante_id'])->get()
            : $origen->items()->whereNull('variante_id')
                ->where('producto_id', $sujeto['producto_id'])->get();

        /*
         * Si por variante no aparece, se busca por el PRODUCTO de esa variante.
         *
         * `CrearFacturaManual` guarda sus renglones con `producto_id` y deja
         * `variante_id` en nulo —no le pregunta el color ni la talla a nadie—,
         * así que una garantía de un producto granular contra una factura
         * hecha a mano no encontraba su renglón y se rechazaba con «la factura
         * no incluye ese producto», sobre una factura que sí lo incluía. Es el
         * peor tipo de rechazo: el sistema tiene razón en la forma y se
         * equivoca en el fondo, y quien atiende no tiene cómo discutirle.
         */
        if ($lineas->isEmpty() && $sujeto['variante_id']) {
            $productoId = \App\Modules\Dropi\Models\ProductoVariante::whereKey($sujeto['variante_id'])
                ->value('producto_id');

            if ($productoId) {
                $lineas = $origen->items()->where('producto_id', $productoId)->get();
            }
        }

        if ($lineas->isEmpty()) {
            return "La factura {$origen->numero} no incluye ese producto. "
                .'Revisá que sea la factura correcta.';
        }

        /*
         * Se suman TODOS los renglones de ese producto, no el primero.
         *
         * Una misma factura trae el mismo producto repetido más seguido de lo
         * que parece —dos cajas con precio distinto, una con descuento—, y
         * leyendo sólo el primero se rechazaba una garantía legítima diciendo
         * que se vendieron menos unidades de las que se vendieron.
         */
        $vendidas = (int) $lineas->sum('cantidad');
        $reclamadas = (int) $data['cantidad'];
        if ($reclamadas > $vendidas) {
            return "En la factura {$origen->numero} se vendieron {$vendidas} unidades "
                ."y se están reclamando {$reclamadas}.";
        }

        /*
         * El plazo se cuenta desde la emisión. No bloquea: una garantía fuera
         * de plazo la puede aceptar Gerencia por relación comercial, y esa es
         * una decisión de negocio. Pero queda dicho en pantalla.
         */
        $dias = (int) setting('garantias.dias_plazo');
        if ($dias > 0 && $origen->fecha_emision
            && $origen->fecha_emision->diffInDays(now()) > $dias) {
            session()->flash('flash', ['type' => 'warning',
                'message' => "Ojo: la factura {$origen->numero} tiene más de {$dias} días. "
                    .'La garantía se crea igual, pero revisá si todavía aplica.']);
        }

        return null;
    }

    public function show(int $ticket): Response
    {
        $t = GarantiaTicket::with(['pedidoOriginal:id,guia,cliente_nombre', 'creadoPor:id,name',
            'decidioPor:id,name', 'variante.producto', 'producto', 'origen',
            'contacto:id,nombre_completo'])
            ->findOrFail($ticket);

        return Inertia::render('Garantias/Show', [
            'ticket' => [
                'id' => $t->id, 'numero' => $t->numero,
                'cliente_nombre' => $t->cliente_nombre, 'cliente_telefono' => $t->cliente_telefono,
                // De qué venta sale, sin que la pantalla tenga que saber si
                // fue una guía de Dropi o una factura B2B.
                'origen' => $t->referenciaDeOrigen(),
                'origen_canal' => $t->origen_type ? class_basename($t->origen_type) : null,
                'cliente_b2b' => $t->contacto?->nombre_completo,
                'cantidad' => (int) $t->cantidad,
                'descripcion_falla' => $t->descripcion_falla,
                'notas_decision' => $t->notas_decision,
                'estado' => $t->estado,
                'creado' => $t->created_at?->format('Y-m-d H:i'),
                'creador' => $t->creadoPor?->name,
                'decidio_por' => $t->decidioPor?->name,
                'decision_at' => $t->decision_at?->format('Y-m-d H:i'),
                'plazo_concepto_at' => $t->plazo_concepto_at?->format('Y-m-d'),
                'closed_at' => $t->closed_at?->format('Y-m-d H:i'),
                'valor_reposicion' => (float) $t->valor_reposicion,
                // Lo que le cuesta a la empresa, que es otra cosa: el cliente
                // paga cero y la garantía igual se paga.
                'costo' => $t->desgloseCosto(),
                'costo_nota' => $t->costo_nota,
                'costo_cargado_at' => $t->costo_cargado_at?->format('Y-m-d H:i'),
                'costo_sugerido' => $this->costoSugerido($t),
                'pedido_original' => $t->pedidoOriginal ? [
                    'id' => $t->pedidoOriginal->id, 'guia' => $t->pedidoOriginal->guia,
                    'cliente' => $t->pedidoOriginal->cliente_nombre,
                ] : null,
                /*
                 * De qué producto es la garantía. La pantalla no lo decía:
                 * mandaba la variante y no la mostraba en ninguna parte, así
                 * que quien abría el ticket no podía confirmar qué se va a
                 * reponer.
                 *
                 * Un solo objeto para los dos casos —variante o producto
                 * agregado— para que la pantalla no tenga que saber cómo se
                 * maneja el stock de ese producto.
                 */
                'producto' => $t->variante ? [
                    'sku' => $t->variante->codigo_barras,
                    'nombre' => $t->variante->producto?->nombre,
                    'referencia' => $t->variante->producto?->referencia,
                    'detalle' => trim(($t->variante->color_nombre ?? '').' '.($t->variante->talla ?? '')),
                    'es_agregado' => false,
                ] : ($t->producto ? [
                    'sku' => $t->producto->referencia,
                    'nombre' => $t->producto->nombre,
                    'referencia' => $t->producto->referencia,
                    'detalle' => 'colores surtidos',
                    'es_agregado' => true,
                ] : null),
                'fotos' => collect($t->fotos_evidencia ?? [])->map(fn ($p) => Storage::disk('public')->url($p))->all(),
            ],
        ]);
    }

    public function decidir(Request $r, int $ticket): RedirectResponse
    {
        $data = $r->validate([
            'decision' => ['required', 'in:aprobar,rechazar'],
            'notas_decision' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $t = GarantiaTicket::findOrFail($ticket);
        abort_unless(in_array($t->estado, ['abierto', 'en_revision']), 422, 'Estado no permite decisión.');
        $t->update([
            'estado' => $data['decision'] === 'aprobar' ? 'aprobada' : 'rechazada',
            'notas_decision' => $data['notas_decision'],
            'decision_por' => auth()->id(),
            'decision_at' => now(),
        ]);
        return back()->with('success', 'Decisión registrada.');
    }

    public function iniciarReposicion(int $ticket): RedirectResponse
    {
        $t = GarantiaTicket::findOrFail($ticket);
        abort_unless($t->estado === 'aprobada', 422, 'Solo se puede reponer una garantía aprobada.');
        $t->update([
            'estado' => 'en_reposicion',
            'valor_reposicion' => 0, // contrato: reposición a valor $0
        ]);
        return back()->with('success', 'Reposición iniciada (valor $0 al cliente).');
    }

    /**
     * Carga lo que la garantía le costó a la empresa.
     *
     * Separado de `cerrar()` a propósito: el flete llega facturado semanas
     * después del cierre, así que obligar a saber el costo para poder cerrar
     * el ticket sólo lograría que lo cierren con ceros. Acá se puede volver.
     */
    public function cargarCosto(Request $r, int $ticket): RedirectResponse
    {
        $t = GarantiaTicket::findOrFail($ticket);

        // Ver un ticket y cargarle plata no son lo mismo: el costo entra
        // después a un informe que lee Gerencia.
        abort_unless(\App\Auth\Permisos::puedeAccion($r->user(), 'servicio_cliente', 'editar'), 403,
            'No tenés permiso para cargarle costo a una garantía.');

        $data = $r->validate([
            'costo_producto' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'costo_flete_recoger' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'costo_flete_enviar' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'costo_nota_credito' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'costo_otros' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'costo_nota' => ['nullable', 'string', 'max:255'],
        ]);

        $campos = [];
        foreach (GarantiaTicket::RENGLONES_COSTO as $c) {
            $campos[$c] = (float) ($data[$c] ?? 0);
        }
        $campos['costo_nota'] = $data['costo_nota'] ?? null;

        /*
         * La fecha se pone cuando se carga el costo por primera vez y no se
         * vuelve a mover. Si se reescribiera en cada corrección, un informe
         * mensual cambiaría solo al editar un ticket viejo.
         */
        $campos['costo_cargado_at'] = $t->costo_cargado_at ?? now();

        $t->update($campos);

        return back()->with('success', 'Costo cargado: $'
            .number_format($t->fresh()->costoTotal(), 0, ',', '.').'.');
    }

    /**
     * Lo que costaría reponer, para no empezar el formulario en ceros.
     *
     * Sale del costo de proveedor del producto. Es una sugerencia, no un dato
     * cerrado: el costo real de inventario puede diferir y quien carga el
     * costo lo puede cambiar.
     */
    private function costoSugerido(GarantiaTicket $t): float
    {
        $producto = $t->producto ?? $t->variante?->producto;

        return round((float) ($producto?->precio_proveedor ?? 0) * max(1, (int) $t->cantidad), 2);
    }

    public function cerrar(Request $r, int $ticket): RedirectResponse
    {
        $data = $r->validate(['notas' => ['nullable', 'string', 'max:500']]);
        $t = GarantiaTicket::findOrFail($ticket);
        abort_unless(in_array($t->estado, ['en_reposicion', 'rechazada']), 422, 'Estado no permite cerrar.');
        $t->update([
            'estado' => 'cerrada',
            'closed_at' => now(),
            'notas_decision' => trim(($t->notas_decision ?: '') . "\n[CIERRE] " . ($data['notas'] ?? '')),
        ]);
        return back()->with('success', 'Ticket cerrado.');
    }
}
