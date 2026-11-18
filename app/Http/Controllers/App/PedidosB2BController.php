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

    /**
     * Las tres decisiones del pedido, cada una con su dueño.
     *
     * Están acá arriba y no sueltas dentro de cada método para que la pantalla
     * pueda preguntar lo MISMO que después se va a exigir. El defecto que
     * arreglan era exactamente ese desajuste: la pantalla decidía por estado y
     * la puerta no decidía nada.
     */
    private function puedeAprobar(?\App\Models\User $u): bool
    {
        // Aprobar o rechazar un pedido es decisión comercial, y además es el
        // mismo botón que libera un pedido retenido por cartera.
        return (bool) $u && ($u->esAracely() || $u->esContable());
    }

    private function puedeFacturar(?\App\Models\User $u): bool
    {
        // La misma puerta que la Bandeja de facturación, para que no vuelvan
        // a separarse: quien factura allá factura acá.
        return (bool) $u && ($u->esAracely() || $u->esFacturador());
    }

    private function puedeDespachar(?\App\Models\User $u): bool
    {
        // Esta sí es de bodega: es la salida física al transportador.
        return (bool) $u && ($u->esAracely() || $u->esEquipoBodega() || $u->hasRole('Despachador'));
    }

    /**
     * ¿Este usuario sólo puede ver LOS SUYOS?
     *
     * El vendedor entra por una puerta distinta: su propio Panel y su
     * Seguimiento le pintan enlaces a `/app/pedidos-b2b/{id}`, y hasta ahora
     * los cuatro le daban 403 — el menú lo ofrecía y la puerta lo negaba, en
     * su propia pantalla y sobre sus propios pedidos. Pero abrirle la lista
     * entera sería mostrarle la cartera de sus compañeros, así que entra
     * acotado: sólo las filas donde él es el vendedor.
     */
    private function soloLosSuyos(?\App\Models\User $u): bool
    {
        return (bool) $u
            && ! ($u->esAracely() || $u->esEquipoBodega() || $u->hasRole('Despachador')
                || $u->esContable() || $u->esFacturador())
            && \App\Auth\Permisos::puede($u, 'vendedor');
    }

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                /*
                 * Quién ENTRA a la pantalla. Quién puede APRETAR cada botón se
                 * decide abajo, acción por acción.
                 *
                 * El comentario que estaba acá decía que aprobar/rechazar/
                 * facturar «siguen siendo responsabilidad gerencial, protegidas
                 * por su propia validación de estado adentro de cada método».
                 * Un chequeo de estado no es un chequeo de autorización: lo
                 * único que exigía `aprobar()` era que el pedido estuviera en
                 * `enviado` o `retenido`. Se comprobó en pantalla — un
                 * despachador abría el pedido y veía **Aprobar**, **Rechazar**
                 * y **Facturar**, y el botón de un retenido le decía «Liberar
                 * (Gerencia)» mientras le pedía confirmar que «Gerencia
                 * autoriza vender aunque el semáforo de cartera lo bloqueó».
                 * Funcionaba: emitía factura real contra la resolución DIAN.
                 *
                 * Además entran cartera y facturación, que antes no podían ni
                 * ver la pantalla donde se toman sus propias decisiones.
                 */
                $u = $r->user();
                abort_unless(
                    $u && ($u->esEquipoBodega() || $u->hasRole('Despachador')
                        || $u->esContable() || $u->esFacturador() || $u->esAracely()
                        // El vendedor entra sólo a los suyos; ver `soloLosSuyos()`.
                        || \App\Auth\Permisos::puede($u, 'vendedor')),
                    403
                );
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        $estado = (string) $request->input('estado', '');
        // El vendedor ve SÓLO los suyos. Fail-closed: si mañana alguien entra
        // por una puerta nueva sin pasar por acá, ve su propia lista vacía, no
        // la de todos.
        $mios = $this->soloLosSuyos($request->user()) ? $request->user()->id : null;

        $pedidos = PedidoCliente::with(['contacto:id,nombre_completo,razon_social,email', 'lista:id,nombre'])
            ->when($mios, fn ($q) => $q->where('vendedor_id', $mios))
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
            ->when($mios, fn ($q) => $q->where('vendedor_id', $mios))
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

    public function show(Request $r, int $pedido): Response
    {
        // LOG-J7 · cargamos siigo_id + cufe para que la UI decida si permite despachar.
        // LOG-J10 · creadoPor/facturadoPor/despachadoPor para armar la línea de tiempo.
        $p = PedidoCliente::with([
            'items.variante:id,codigo_barras', 'contacto', 'lista:id,nombre',
            'factura:id,numero,siigo_id,cufe',
            'aprobadoPor:id,name', 'facturadoPor:id,name', 'despachadoPor:id,name',
            // LOG-J5 · incluimos el alistador para el timeline + Show card.
            'alistador:id,name',
            // Quién firmó la liberación de cartera y de qué bodega sale.
            'retencionLiberadaPor:id,name', 'entregadoPor:id,name',
            'ubicacionOrigen:id,codigo,nombre',
        ])->findOrFail($pedido);

        /*
         * El recorte del vendedor también acá: si no, bastaba con cambiar el
         * número en la barra de direcciones para leer el pedido de un
         * compañero. Un filtro que sólo vive en el listado no es un filtro.
         */
        abort_if($this->soloLosSuyos($r->user()) && $p->vendedor_id !== $r->user()->id,
            403, 'Este pedido es de otro vendedor.');

        return Inertia::render('PedidosB2B/Show', [
            /*
             * Lo que ESTE usuario puede apretar. La pantalla pintaba los
             * botones mirando sólo `pedido.estado`, así que un despachador
             * veía «Aprobar», «Rechazar» y «Facturar» — y funcionaban.
             */
            'puedo' => [
                'aprobar' => $this->puedeAprobar($r->user()),
                'facturar' => $this->puedeFacturar($r->user()),
                'despachar' => $this->puedeDespachar($r->user()),
            ],
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
                // Multiempresa · razón social que factura (NULL = la principal).
                'empresa_id' => \App\Models\EmpresaConfig::porId($p->empresa_id)->id,
                'empresa' => \App\Models\EmpresaConfig::porId($p->empresa_id)->nombreCorto(),
                // De qué bodega sale. Null = de ninguna, que es el caso que
                // deja el pedido sin mercancía apartada y fuera de la cola.
                'ubicacion_origen_id' => $p->ubicacion_origen_id,
                'bodega_origen' => $p->ubicacionOrigen?->nombre,
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
                    /*
                     * LOG-J2 · si el semáforo de cartera lo retuvo, va justo
                     * después de «enviado» para que se entienda por qué no
                     * entró a la cola de alistamiento.
                     *
                     * Se mira `retenido_at` y NO el estado actual: con el
                     * estado, el hito desaparecía en el momento en que
                     * Gerencia liberaba el pedido, y después nadie podía ver
                     * que el semáforo lo había frenado. El `estado` sigue
                     * valiendo para los pedidos anteriores a esta columna, que
                     * están retenidos y no tienen fecha.
                     */
                    ($p->retenido_at || $p->estado === 'retenido') ? [
                        'hito' => 'Pedido RETENIDO por cartera',
                        'actor' => 'Semáforo automático',
                        'at' => ($p->retenido_at ?? $p->enviado_at ?? $p->created_at)?->format('Y-m-d H:i'),
                        'icon' => '⏸',
                        'color' => 'red',
                        'detalle' => $p->motivo_retencion ?: 'Requiere aprobación de Gerencia.',
                    ] : null,
                    /*
                     * Quién pasó por encima del semáforo. Va aparte del hito
                     * de aprobación porque no es lo mismo aprobar un pedido
                     * limpio que autorizar venderle a quien debe.
                     */
                    $p->retencion_liberada_at ? [
                        'hito' => 'Retención liberada por Gerencia',
                        'actor' => $p->retencionLiberadaPor?->name ?? 'Gerencia',
                        'at' => $p->retencion_liberada_at->format('Y-m-d H:i'),
                        'icon' => '🔓',
                        'color' => 'amber',
                        'detalle' => 'Se autorizó vender aunque cartera lo había bloqueado'
                            .($p->motivo_retencion ? ": {$p->motivo_retencion}." : '.'),
                    ] : null,
                    $p->aprobado_at ? [
                        'hito' => 'Pedido aprobado',
                        // Decía «Gerencia» fijo. Dejó de ser impreciso y pasó
                        // a ser falso cuando el Contador también pudo aprobar.
                        'actor' => $p->aprobadoPor?->name ?? 'Gerencia',
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
                        // `diffInMinutes` devuelve flotante en Carbon 3: la línea de
                        // tiempo mostraba «0.31666666666666665 min» en un alistamiento
                        // de 19 segundos. Se redondea, y por debajo del minuto se
                        // cuenta en segundos para que diga algo.
                        'detalle' => ($p->alistado_inicio_at
                            ? $this->duracionLegible($p->alistado_inicio_at, $p->alistado_fin_at)
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
                    /*
                     * Y la entrega cierra el ciclo.
                     *
                     * El historial terminaba en «despachado»: quién recibió y
                     * cuándo vivía sólo en la pantalla de guías, así que desde
                     * el pedido no se podía responder la pregunta más común
                     * que hace un cliente por teléfono.
                     */
                    $p->entregado_at ? [
                        'hito' => 'Pedido entregado',
                        'actor' => $p->entregadoPor?->name ?? 'Logística',
                        'at' => $p->entregado_at->format('Y-m-d H:i'),
                        'icon' => '🏁',
                        'color' => 'emerald',
                        'detalle' => $p->entrega_nota ?: 'Sin constancia de quién recibió.',
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
            'empresas' => \App\Models\EmpresaConfig::activas()
                ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCorto()])->values(),
            /*
             * De qué bodega sale, y de cuáles podría salir.
             *
             * Sólo las sedes propias y vendibles: de las 55 ubicaciones, 43
             * son espejos del catálogo compartido de SIIGO y muchas son de
             * otras empresas. Rutear un pedido a una de ésas lo dejaría
             * colgando de una bodega que no existe en el piso.
             */
            'bodegas_origen' => \App\Modules\Dropi\Models\InventarioUbicacion::query()
                ->whereNull('bodega_id')
                /*
                 * Y la bodega que el pedido YA tiene va siempre, aunque hoy no
                 * cumpla los filtros.
                 *
                 * Una bodega que se desactivó, o que dejó de venderse, sigue
                 * siendo de dónde sale este pedido. Si no está en la lista, el
                 * `<select>` cae en la opción vacía y la pantalla dice «— sin
                 * bodega —» de un pedido que sí la tiene: quien mira cree que
                 * hay que asignarla y al guardar le cambia el origen a una
                 * mercancía que ya está apartada en otro sitio.
                 */
                ->where(fn ($q) => $q
                    ->where(fn ($ok) => $ok
                        ->where('activa', true)
                        ->where('disponible_para_venta', true)
                        ->where('codigo', 'not like', 'SIIGO-%'))
                    ->orWhere('id', $p->ubicacion_origen_id))
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'activa'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'nombre' => "{$b->codigo} · {$b->nombre}".($b->activa ? '' : '  (desactivada)'),
                ])
                ->values(),
            /*
             * Un pedido rechazado no cambia de bodega: `cambiarBodega` lo
             * niega, con razón —no hay nada que despachar—, pero la pantalla
             * ofrecía el selector igual y el servidor contestaba que no.
             */
            'puede_cambiar_bodega' => $this->puedeAprobar($r->user())
                && $p->despachado_at === null
                && $p->estado !== 'rechazado',
        ]);
    }

    /**
     * Multiempresa · cambiar la razón social que factura. Sólo mientras no
     * haya factura: después, el número, el NIT y la cuenta de SIIGO ya son
     * los de la empresa que facturó.
     */
    /**
     * Decirle a un pedido de qué bodega sale.
     *
     * Existe porque el aviso que aparece al aprobar —«el pedido no tiene
     * bodega de origen, así que no se apartó nada: asignale una bodega y
     * volvé a aprobarlo»— mandaba a hacer algo que la pantalla no permitía.
     * Quien lo leía no tenía dónde asignarla, así que el aviso se volvía ruido
     * y el pedido se quedaba sin apartar mercancía y fuera de la cola de
     * bodega.
     *
     * Desde que existen las reglas de ruteo, los pedidos nuevos nacen con
     * bodega. Esto es para los viejos, y para el caso en que la ciudad del
     * cliente no corresponda con de dónde va a salir de verdad.
     *
     * Reasignar vuelve a apartar: la reserva vieja se suelta y se toma en la
     * bodega nueva. Si no, la mercancía quedaría apartada en una sede de la
     * que el pedido ya no sale.
     */
    public function cambiarBodega(Request $r, int $pedido): RedirectResponse
    {
        abort_unless($this->puedeAprobar($r->user()), 403,
            'Cambiar la bodega de origen es decisión de Gerencia o cartera.');

        $data = $r->validate([
            'ubicacion_origen_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
        ]);

        $p = PedidoCliente::with('items')->findOrFail($pedido);

        /*
         * Los motivos se devuelven como error de campo y no con `abort(422)`:
         * en una petición de Inertia el abort deja un cuerpo que la pantalla no
         * sabe leer, así que el usuario no ve nada y cree que no pasó nada.
         */
        $noSePuede = match (true) {
            $p->despachado_at !== null => 'Ese pedido ya salió de bodega: cambiarle el origen movería stock que ya no está ahí.',
            $p->estado === 'rechazado' => 'El pedido está rechazado.',
            default => null,
        };

        if ($noSePuede) {
            return back()->withErrors(['ubicacion_origen_id' => $noSePuede]);
        }

        $destino = \App\Modules\Dropi\Models\InventarioUbicacion::findOrFail($data['ubicacion_origen_id']);

        /*
         * El servidor tiene que negar lo mismo que la pantalla esconde.
         *
         * El desplegable ofrece sólo sedes propias, activas y vendibles; por
         * el POST entraba cualquier id, incluidos los 43 espejos que el
         * importador trajo del catálogo compartido de SIIGO —«TK0007UREA»,
         * «Bodega pruebas luis», muchas de otras empresas— y las bodegas de
         * averías. Un pedido con origen en una de ésas no tiene stock ni
         * aparece en ninguna cola.
         */
        $destinoMal = match (true) {
            $destino->bodega_id !== null => "«{$destino->nombre}» es una posición dentro de otra bodega. El origen de un pedido es la bodega.",
            ! $destino->activa => "«{$destino->nombre}» está desactivada.",
            ! $destino->disponible_para_venta => "De «{$destino->nombre}» no se vende: es una zona de averías, bajas o garantías.",
            str_starts_with((string) $destino->codigo, 'SIIGO-') => "«{$destino->nombre}» vino del catálogo de SIIGO y no es una bodega de la empresa.",
            default => null,
        };

        if ($destinoMal) {
            return back()->withErrors(['ubicacion_origen_id' => $destinoMal]);
        }

        $p->update(['ubicacion_origen_id' => $destino->id]);

        /*
         * Y se vuelve a apartar, pero sólo si hay algo que apartar.
         *
         * Un pedido APROBADO sí: tiene mercancía reservada en la bodega vieja
         * y hay que moverla a la nueva.
         *
         * Un pedido FACTURADO no. Al facturar se suelta lo apartado a
         * propósito —desde ahí manda la factura y el despacho descuenta el
         * stock de verdad—, así que volver a reservar contaba la misma caja
         * dos veces y el disponible salía más bajo de lo que es. Esa reserva
         * no la suelta nadie después: el único que la soltaba era el facturar
         * que ya pasó, y de ahí en adelante sólo expira sola a los días.
         *
         * En un borrador o un rechazado tampoco hay nada que reservar.
         */
        $faltantes = [];
        $yaFacturado = $p->estado === 'facturado' || $p->factura_id !== null;

        if ($p->estado === 'aprobado' && ! $yaFacturado) {
            $reserva = app(\App\Modules\Inventario\Actions\ReservarPedido::class)->handle($p->fresh('items'));
            $faltantes = $reserva['faltantes'] ?? [];
        }

        $msg = "Este pedido sale de {$destino->nombre}.";
        if ($faltantes) {
            $msg .= ' Ojo: ahí no alcanza para '.count($faltantes).' ítem(s).';
        } elseif ($yaFacturado) {
            $msg .= ' Ya está facturado, así que no se apartó nada: '
                .'el despacho descuenta el stock de esa bodega.';
        }

        return back()->with($faltantes ? 'warning' : 'success', $msg);
    }

    public function cambiarEmpresa(Request $r, int $pedido): RedirectResponse
    {
        $data = $r->validate([
            'empresa_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('empresa_config', 'id')->where('activa', true)],
        ]);

        $p = PedidoCliente::findOrFail($pedido);
        abort_if($p->factura_id, 422, 'El pedido ya está facturado: la empresa no se puede cambiar.');
        abort_if($p->estado === 'rechazado', 422, 'El pedido está rechazado.');

        $p->update(['empresa_id' => (int) $data['empresa_id']]);

        return back()->with('success', 'Este pedido lo factura '.\App\Models\EmpresaConfig::porId($p->empresa_id)->nombreCorto().'.');
    }

    public function aprobar(Request $r, int $pedido): RedirectResponse
    {
        abort_unless($this->puedeAprobar($r->user()), 403,
            'Aprobar o liberar un pedido es decisión de Gerencia o cartera.');

        // C-QA-D-3: lock para evitar carrera Aprobar/Rechazar concurrente.
        // LOG-J3 · Gerencia también libera un `retenido` desde este botón —
        //   es el mismo gesto conceptual (aprobar), y así no hay dos endpoints
        //   paralelos. La UI distingue el chip (ámbar vs azul) por `estado`.
        $pre = PedidoCliente::findOrFail($pedido);
        $eraRetenido = $pre->estado === 'retenido';

        $aprobadorId = $r->user()->id;

        DB::transaction(function () use ($pedido, $aprobadorId) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($p->estado, ['enviado', 'retenido'], true), 422,
                'Sólo se aprueban pedidos enviados o retenidos por cartera.');

            $venia = $p->estado === 'retenido';

            $p->update([
                'estado' => 'aprobado',
                'aprobado_at' => now(),
                // Quién aprobó, que no se guardaba. Era el único paso de la
                // cadena sin autor, justo el que autoriza que salga mercancía.
                'aprobado_por_id' => $aprobadorId,
                /*
                 * Y la retención queda firmada.
                 *
                 * Antes el hito «retenido por cartera» se pintaba leyendo
                 * `estado === 'retenido'`, así que al liberar DESAPARECÍA del
                 * historial: después nadie podía ver que el semáforo lo había
                 * bloqueado ni quién decidió vender igual. Autorizar un
                 * despacho a un cliente con facturas vencidas es una decisión
                 * comercial, y las decisiones se firman.
                 */
                'retenido_at' => $venia ? ($p->retenido_at ?: ($p->enviado_at ?: $p->created_at)) : $p->retenido_at,
                'retencion_liberada_at' => $venia ? now() : $p->retencion_liberada_at,
                'retencion_liberada_por_id' => $venia ? $aprobadorId : $p->retencion_liberada_por_id,
            ]);
        });

        /*
         * Aprobar aparta la mercancía.
         *
         * Hasta hoy no se apartaba nada: dos vendedores podían prometer la
         * misma caja y el segundo se enteraba el día del alistamiento. La
         * maquinaria de reservas existía completa y no la llamaba nadie.
         *
         * Va FUERA de la transacción de arriba a propósito: si el stock no
         * alcanza, el pedido igual queda aprobado —ya pasó el filtro de
         * cartera, que es otra decisión— y lo que falta se avisa.
         */
        $reserva = app(\App\Modules\Inventario\Actions\ReservarPedido::class)
            ->handle(PedidoCliente::with('items')->findOrFail($pedido));

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

        $msg = $eraRetenido ? 'Pedido LIBERADO por Gerencia. Listo para facturar.'
                            : 'Pedido aprobado. Listo para facturar.';

        /*
         * Si no se apartó NADA, eso se dice antes que cualquier otra cosa.
         *
         * Un pedido sin bodega de origen se aprueba igual pero no reserva, y
         * la pantalla decía «Pedido aprobado» sin una sola señal: la mercancía
         * se le seguía ofreciendo a otros pedidos y aparecía vendida dos veces
         * el día del alistamiento.
         */
        if (($reserva['sin_reservar'] ?? null) !== null) {
            return back()->with('flash', ['type' => 'warning',
                'message' => $msg.' '.$reserva['sin_reservar']]);
        }

        // Lo que no alcanzó se dice ahora, no el día del alistamiento.
        if ($reserva['faltantes'] !== []) {
            $detalle = collect($reserva['faltantes'])
                ->map(fn ($f) => "{$f['sku']} (pide {$f['pedido']}, hay {$f['disponible']})")
                ->take(4)->implode(' · ');
            $mas = count($reserva['faltantes']) > 4 ? ' y más' : '';

            return back()->with('flash', ['type' => 'warning',
                'message' => $msg." Ojo: no alcanzó el stock de {$detalle}{$mas}. Se apartó lo que había."]);
        }

        return back()->with('success', $msg);
    }

    public function rechazar(Request $r, int $pedido): RedirectResponse
    {
        abort_unless($this->puedeAprobar($r->user()), 403,
            'Rechazar un pedido es decisión de Gerencia o cartera.');

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

        // Lo que se había apartado vuelve a estar disponible: un pedido
        // rechazado no le puede seguir quitando mercancía a los demás.
        app(\App\Modules\Inventario\Actions\ReservarPedido::class)->liberar($pre);

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
    private ?string $motivoStock = null;

    /** Cuánto duró algo, en palabras: «19 s», «4 min», «1 h 12 min». */
    private function duracionLegible(?\Carbon\Carbon $desde, ?\Carbon\Carbon $hasta): string
    {
        if (! $desde || ! $hasta) {
            return '';
        }

        $seg = max(0, (int) round($desde->diffInSeconds($hasta)));

        if ($seg < 60) {
            return "{$seg} s";
        }

        $min = (int) round($seg / 60);

        return $min < 60 ? "{$min} min" : intdiv($min, 60).' h '.($min % 60).' min';
    }

    public function despachar(Request $r, int $pedido): RedirectResponse
    {
        abort_unless($this->puedeDespachar($r->user()), 403,
            'Despachar es de bodega.');

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
            $accion = new \App\Modules\Inventario\Actions\DescontarStockPorDespacho();
            $this->lineasDescontadas = $accion->ejecutar($p, $r->user()->id);
            $this->motivoStock = $accion->motivo;
        });

        $msg = 'Pedido despachado · guía registrada.';
        if (($this->lineasDescontadas ?? 0) > 0) {
            $msg .= " Se descontaron {$this->lineasDescontadas} ítem(s) del inventario.";
        }

        // Un despacho que no bajó inventario NO es un despacho exitoso a secas:
        // el estante y el sistema quedan distintos y nadie se entera hasta el
        // conteo. Se avisa en pantalla, no sólo en el log.
        if ($this->motivoStock) {
            return back()->with('warning', 'Pedido despachado, PERO el inventario no bajó. '
                .$this->motivoStock.' Avisá a logística para cuadrarlo a mano.');
        }

        return back()->with('success', $msg);
    }

    public function facturar(Request $r, int $pedido): RedirectResponse
    {
        /*
         * Facturar emite un documento real contra la resolución DIAN de la
         * empresa y consume consecutivo. La misma puerta que la Bandeja de
         * facturación.
         */
        abort_unless($this->puedeFacturar($r->user()), 403,
            'Facturar es del equipo de facturación.');

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

            /*
             * Quién decide si la factura viaja a SIIGO: el DESTINO FISCAL.
             *
             * Decía `es_electronica => (bool) $siigoActivo`, o sea que miraba
             * si la integración estaba viva en ese segundo e ignoraba por
             * completo lo que había elegido el usuario. Dos consecuencias, las
             * dos malas:
             *
             *   · Con SIIGO caído, una factura FISCAL nacía marcada «no va» y
             *     quedaba así para siempre: nada la vuelve a evaluar. Fue lo
             *     que pasó en producción — 20 facturas fiscales, cero en SIIGO,
             *     y la bandeja de pendientes mostrando 0 porque filtra por la
             *     misma columna.
             *   · Con SIIGO vivo, una factura marcada INTERNA nacía electrónica
             *     igual y se iba para allá. Declarar algo que alguien decidió
             *     expresamente no declarar es el peor de los dos.
             *
             * Que SIIGO esté arriba o abajo no cambia el destino de la
             * factura: cambia cuándo sale. Para eso está la cola y el
             * interruptor `push_auto`.
             *
             * Multiempresa · factura la razón social que eligió el pedido, con
             * su prefijo y su cuenta de SIIGO (NULL = Great Baby).
             */
            $empresa = \App\Models\EmpresaConfig::porId($p->empresa_id);

            // El pedido B2B no lleva destino propio —la columna sólo existe en
            // los documentos donde alguien lo decide— así que su factura nace
            // fiscal, igual que el default de la tabla. Se escribe explícito
            // para que de acá salga la marca y no de un default silencioso.
            $destinoFactura = \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo;
            $viajaASiigo = $destinoFactura->tieneParteFiscal();

            $f = FacturaVenta::create([
                'empresa_id' => $empresa->id,
                'numero' => \App\Modules\Cartera\Support\NumeroFactura::siguiente($empresa),  // C-QA-D-2
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
                'destino_fiscal' => $destinoFactura,
                'es_electronica' => $viajaASiigo,
                /*
                 * El vendedor y la bodega VIAJAN del pedido a la factura.
                 *
                 * No se copiaban, y las dos columnas existen en
                 * `facturas_venta` con su llave foránea. Resultado: las 24
                 * facturas del sistema tenían `vendedor_id` en NULL, y
                 * `CalcularComisionMensual` filtra justo por esa columna
                 * (`FacturaVenta::where('vendedor_id', ...)`), así que toda
                 * comisión daba $0 por construcción, sobre todo lo facturado.
                 * `ubicacion_id` deja sin efecto la resolución DIAN y las
                 * cuentas PUC por bodega, que se configuran por ubicación.
                 */
                'vendedor_id' => $p->vendedor_id,
                'ubicacion_id' => $p->ubicacion_origen_id,
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
        /*
         * Facturado: se suelta lo apartado.
         *
         * A partir de acá manda la factura, y el despacho descuenta el stock
         * de verdad. Si la reserva siguiera viva, la misma mercancía quedaria
         * contada dos veces y el disponible saldria mas bajo de lo que es.
         */
        app(\App\Modules\Inventario\Actions\ReservarPedido::class)
            ->liberar(PedidoCliente::findOrFail($pedido));

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
}
