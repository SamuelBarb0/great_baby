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

        // ¿Sólo los que salieron a medias y todavía deben mercancía? Va como
        // filtro PROPIO y no como un estado más: el estado del pedido sigue
        // siendo «facturado», y meterlo en el mismo selector rompería el
        // conteo por estado y el orden de abajo.
        $soloPendientes = $request->boolean('resto_pendiente');

        $pedidos = PedidoCliente::with(['contacto:id,nombre_completo,razon_social,email', 'lista:id,nombre'])
            ->withSum('items as unidades_pedidas', 'cantidad')
            ->withSum('items as unidades_despachadas', 'cantidad_despachada')
            ->when($mios, fn ($q) => $q->where('vendedor_id', $mios))
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->when($soloPendientes, fn ($q) => $q->where('resto_pendiente', true))
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

        // Cuántos deben mercancía. Se cuenta por `resto_pendiente` y NO por
        // `despacho_parcial`, que es pegajoso: un pedido que salió en dos
        // viajes y ya se completó lo lleva en true para siempre, y aparecería
        // acá como si le faltara algo.
        $parcialesPendientes = PedidoCliente::where('resto_pendiente', true)
            ->when($mios, fn ($q) => $q->where('vendedor_id', $mios))
            ->count();

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
                /*
                 * Un pedido que salió a medias se veía EXACTAMENTE igual que uno
                 * que no ha salido: el mismo badge «FACTURADO». El estado no
                 * alcanza para distinguirlos porque es el mismo a propósito —el
                 * pedido vuelve a la cola— así que la diferencia tiene que ir
                 * aparte.
                 *
                 * Las unidades se castean a (int) y el NULL se lee como 0: en
                 * `cantidad_despachada` el NULL significa «sale completo» para
                 * el descuento de stock, pero en una pantalla significa
                 * «todavía no salió nada». Copiar aquel `??` diría «6 de 6 ya
                 * salieron» sobre un pedido que no ha salido: la mentira
                 * exactamente inversa a la que esto viene a arreglar.
                 */
                'resto_pendiente' => (bool) $p->resto_pendiente,
                'unidades_pedidas' => (int) ($p->unidades_pedidas ?? 0),
                'unidades_despachadas' => (int) ($p->unidades_despachadas ?? 0),
            ]),
            'conteos' => $conteos,
            // Aparte de `conteos`, que es el GROUP BY por estado. Si entrara ahí
            // se sumaría dos veces: un parcial ya se cuenta como «facturado».
            'parciales_pendientes' => $parcialesPendientes,
            'estado_filtro' => $estado ?: null,
            'solo_pendientes' => $soloPendientes,
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
            // Las cajas con sus guías. Hasta hoy esta tabla se escribía y no la
            // leía NADIE: un pedido de tres cajas guardaba las tres guías y la
            // pantalla sólo mostraba la primera.
            'cajas:id,pedido_id,numero,viaje,guia,created_at',
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
                /*
                 * DESPACHO PARCIAL · lo que la pantalla necesita para no mentir.
                 *
                 * Sin esto, un pedido que salió a medias se veía EXACTAMENTE
                 * igual que uno que no ha salido: mismo estado, mismo botón, ni
                 * la guía ni cuánto se llevó el transportador. Todo eso existía
                 * sólo dentro del modal de despachar, así que quien abría el
                 * pedido al día siguiente no tenía cómo saberlo.
                 *
                 * `despacho_parcial` es ACUMULATIVO: una vez que un pedido sale
                 * a medias queda marcado para siempre, aunque después se
                 * complete. Por eso la pantalla tiene que mirar las dos: la
                 * marca dice «salió en partes» y `resto_pendiente` dice si
                 * todavía falta algo.
                 */
                'despacho_parcial' => (bool) $p->despacho_parcial,
                'resto_pendiente' => (bool) $p->resto_pendiente,
                'despachado_por' => $p->despachadoPor?->name,
                'cajas' => $p->cajas->map(fn ($c) => [
                    'numero' => (int) $c->numero,
                    // De qué envío salió. Con un solo viaje no se muestra —sería
                    // ruido—; con dos es lo primero que se pregunta cuando el
                    // cliente dice que le llegaron dos de tres.
                    'viaje' => (int) $c->viaje,
                    'guia' => $c->guia,
                    'salio' => $c->created_at?->format('Y-m-d H:i'),
                ])->values(),
                // Cuántos envíos lleva el pedido. Si es 1, la pantalla no
                // menciona viajes.
                'viajes' => (int) ($p->cajas->max('viaje') ?: 0),
                'primer_despacho_at' => $p->primer_despacho_at?->format('Y-m-d H:i'),
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
                    /*
                     * Un hito POR VIAJE, no uno solo.
                     *
                     * Colgaba de `despachado_at`, que en un despacho parcial se
                     * deja en NULL hasta que salga todo: la historia del pedido
                     * no registraba ninguna salida mientras faltara mercancía, y
                     * al completarse mostraba un único «Pedido despachado» con
                     * la guía del último viaje. La primera caja —la que el
                     * cliente ya recibió y por la que llama— no existía en
                     * ninguna parte del historial.
                     */
                    ...$this->hitosDeSalida($p),
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
                // El id va porque el despacho parcial manda cuánto sale de
                // CADA línea, y hay que poder decir de cuál.
                'id' => $i->id,
                'sku' => $i->sku_snapshot,
                'desc' => $i->descripcion_snapshot,
                'variante_id' => $i->variante_id,
                // C-F-QA3 · propagar producto_id + flag agregado a la vista admin.
                'producto_id' => $i->producto_id,
                'es_agregado' => $i->variante_id === null && $i->producto_id !== null,
                'cantidad' => (int) $i->cantidad,
                // Lo que ya salió, para que un segundo despacho ofrezca el
                // resto y no vuelva a ofrecer todo.
                'despachada' => (int) ($i->cantidad_despachada ?? 0),
                'pendiente' => max(0, (int) $i->cantidad - (int) ($i->cantidad_despachada ?? 0)),
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
                        ->where('codigo', 'not like', 'SIIGO-%')
                        // Ni los racks que quedaron sin padre: un pedido con
                        // origen en un estante no tiene de dónde despacharse.
                        ->whereNull('pasillo')->whereNull('estante')->whereNull('nivel'))
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
            /*
             * Las transportadoras con las que se despacha, de la regla
             * `logistica.transportadoras`.
             *
             * El campo era texto libre, así que la misma empresa entraba como
             * «Servientrega», «SERVIENTREGA» y «servientrega s.a.». Con eso
             * `urlDeRastreo()` no encontraba el enlace y, peor, no se podía
             * responder cuál transportadora se demora más — que es justo para
             * lo que existe `logistica.dias_sin_entregar_alerta`.
             *
             * Va la que el pedido YA tiene aunque no esté en la lista: si se
             * despachó con una que el área dejó de usar, el `<select>` caería
             * en vacío y al guardar le borraría la transportadora a un pedido
             * que sí salió por ella.
             */
            'transportadoras' => collect((array) setting('logistica.transportadoras'))
                ->map(fn ($t) => trim((string) $t))
                ->filter()
                ->push(trim((string) $p->transportadora))
                ->filter()
                ->unique()
                ->sort()
                ->values(),
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
            $destino->bodega_id !== null
            || $destino->pasillo !== null
            || $destino->estante !== null
            || $destino->nivel !== null
                => "«{$destino->nombre}» es una posición dentro de una bodega, no una bodega. El origen de un pedido es la bodega.",
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
        /*
         * Cambiar quién factura cambia el NIT, la resolución DIAN y la cuenta
         * con la que se emite en SIIGO. No tenía puerta: ni acá ni en la
         * pantalla, mientras que su vecina `cambiarBodega` —que decide algo
         * menos delicado— sí la tiene.
         */
        abort_unless($this->puedeAprobar($r->user()), 403,
            'Cambiar quién factura el pedido es decisión de Gerencia o cartera.');

        $data = $r->validate([
            'empresa_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('empresa_config', 'id')->where('activa', true)],
        ]);

        $p = PedidoCliente::findOrFail($pedido);

        /*
         * Los motivos van como error de campo y no con `abort(422)`: en una
         * petición de Inertia el abort deja un cuerpo que la pantalla no sabe
         * leer, así que el usuario no ve nada y cree que no pasó nada. Es la
         * misma razón que ya está escrita en `cambiarBodega`.
         */
        $noSePuede = match (true) {
            (bool) $p->factura_id => 'El pedido ya está facturado: la empresa no se puede cambiar.',
            $p->estado === 'rechazado' => 'El pedido está rechazado.',
            default => null,
        };

        if ($noSePuede) {
            return back()->withErrors(['empresa_id' => $noSePuede]);
        }

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

    /**
     * LAS SALIDAS DEL PEDIDO, UNA POR VIAJE.
     *
     * Un pedido puede salir en varias tandas, y cada una lleva sus propias
     * guías. Como historia, lo que importa es poder responder «¿cuándo salió
     * la caja que el cliente dice que no llegó?», y para eso hace falta un
     * hito por viaje con sus números.
     *
     * Los pedidos anteriores a las guías por caja no tienen filas en
     * `pedido_cajas`: ésos conservan el hito de siempre, armado con
     * `despachado_at` y la guía de la cabecera.
     *
     * @return array<int, array<string, mixed>>
     */
    private function hitosDeSalida(PedidoCliente $p): array
    {
        if ($p->cajas->isEmpty()) {
            return $p->despachado_at ? [[
                'hito' => 'Pedido despachado',
                'actor' => $p->despachadoPor?->name ?? 'Logística',
                'at' => $p->despachado_at->format('Y-m-d H:i'),
                'icon' => '🚚',
                'color' => 'indigo',
                'detalle' => ($p->transportadora ? $p->transportadora.' · ' : '').'Guía '.$p->guia_transportadora,
            ]] : [];
        }

        $porViaje = $p->cajas->groupBy('viaje')->sortKeys();
        $ultimo = (int) $porViaje->keys()->last();
        $varios = $porViaje->count() > 1;
        $hitos = [];

        foreach ($porViaje as $viaje => $cajas) {
            $esUltimo = (int) $viaje === $ultimo;
            $guias = $cajas->pluck('guia')->filter()->unique()->values();

            /*
             * Quién despachó se guarda una sola vez, en la cabecera, y es el
             * del ÚLTIMO viaje. Ponerle ese nombre a los anteriores sería
             * inventar: de esos sólo consta que los despachó logística.
             */
            $actor = $esUltimo ? ($p->despachadoPor?->name ?? 'Logística') : 'Logística';

            $detalle = $cajas->count().' caja'.($cajas->count() > 1 ? 's' : '')
                .($p->transportadora ? ' · '.$p->transportadora : '')
                .($guias->isNotEmpty() ? ' · Guía'.($guias->count() > 1 ? 's' : '').' '.$guias->implode(', ') : '');

            // Sólo el último viaje sabe si todavía se debe algo: `resto_pendiente`
            // es el estado de HOY, no el de cuando salió aquella caja.
            if ($esUltimo && $p->resto_pendiente) {
                $faltan = max(0, (int) $p->items->sum('cantidad') - (int) $p->items->sum('cantidad_despachada'));
                $detalle .= " · quedan {$faltan} unidades por despachar";
            }

            $hitos[] = [
                'hito' => $varios ? "Salió el viaje {$viaje}" : 'Pedido despachado',
                'actor' => $actor,
                'at' => ($cajas->min('created_at') ?? $p->salidaDeBodega())?->format('Y-m-d H:i'),
                'icon' => '🚚',
                'color' => $esUltimo && $p->resto_pendiente ? 'amber' : 'indigo',
                'detalle' => $detalle,
            ];
        }

        return $hitos;
    }

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
            /*
             * Las guías de las demás cajas.
             *
             * Un pedido puede salir en varias cajas, y con Interrapidísimo cada
             * una lleva su propia guía (lo dijo el área el 20-nov-2026). Antes
             * sólo cabía una: de un pedido de tres cajas se guardaba una y las
             * otras dos quedaban anotadas en un papel. La guía es con lo que se
             * reclama cuando una caja no llega.
             *
             * La primera sigue yendo en `guia_transportadora`, que es lo que
             * leen el seguimiento y el enlace de rastreo. Éstas son de la caja
             * 2 en adelante.
             */
            'guias_extra' => ['nullable', 'array', 'max:49'],
            'guias_extra.*' => ['nullable', 'string', 'max:60'],
            /*
             * DESPACHO PARCIAL · cuánto sale de cada línea.
             *
             * «Se autoriza el despacho de la mercancía disponible, sin esperar
             * a completar la totalidad» (área, 20-nov-2026). Hasta hoy el
             * despacho no manejaba cantidades: salía todo o abortaba con un
             * 422 y el camión esperando.
             *
             * Viene como {id_de_linea: cantidad}. Si no viene, sale completo,
             * que es como se despachó siempre.
             */
            'cantidades' => ['nullable', 'array'],
            /*
             * ENTEROS. Great Baby vende unidades, no fracciones.
             *
             * Con `numeric` entraba un 2,5: la línea guardaba 2.5000 y el
             * kardex bajaba `(int) 2.5 = 2`. Media unidad salía de la bodega
             * sin bajar del sistema, y como `exigirSaldo()` hace el mismo
             * truncado, ni siquiera había cómo notarlo. Después, portal, lista
             * y detalle mostraban tres números distintos del mismo pedido,
             * porque cada uno castea a su manera.
             */
            'cantidades.*' => ['nullable', 'integer', 'min:0'],
            /*
             * Y qué pasa con lo que NO salió. Lo elige quien despacha, caso
             * por caso, porque depende de si el cliente va a esperar el resto
             * — y eso no lo sabe el código.
             *
             *   true  → sigue debiéndose: el pedido vuelve a la cola.
             *   false → ya no sale: el pedido se cierra con lo entregado.
             */
            'resto_pendiente' => ['nullable', 'boolean'],
        ], [], [
            'guia_transportadora' => 'guía',
            'guias_extra' => 'guías de las demás cajas',
            'cantidades' => 'cantidades a despachar',
        ]);

        /*
         * Dos cajas no pueden llevar la misma guía: o alguien la pegó dos
         * veces, o se la copió de otra caja. Las dos son errores que después
         * aparecen como «el cliente reclama una caja que el sistema dice que
         * sí llegó».
         */
        $todas = collect([$data['guia_transportadora']])
            ->merge($data['guias_extra'] ?? [])
            ->map(fn ($g) => trim((string) $g))
            ->filter()
            ->values();

        if ($todas->count() !== $todas->unique()->count()) {
            return back()->withErrors([
                'guia_transportadora' => 'Hay guías repetidas entre las cajas. Cada caja lleva la suya.',
            ]);
        }
        /*
         * Los cinco motivos por los que NO se despacha salían con `abort(422)`
         * y nunca llegaban a la pantalla.
         *
         * La pantalla sí los espera: `PedidosB2B/Show.vue` guarda el motivo en
         * `despachoError` dentro de su `onError`. Pero Inertia no llama a
         * `onError` cuando la respuesta no es suya, y un `abort` no lo es: el
         * flujo corta antes. Resultado: el modal se quedaba abierto, sin
         * mensaje, con el botón habilitado otra vez — y la persona reintenta
         * sin saber qué pasó. En una bodega eso es alguien parado frente al
         * transportador sin entender por qué no sale la guía.
         *
         * Las comprobaciones siguen DENTRO de la transacción y después del
         * `lockForUpdate`, que es lo que hace que la idempotencia valga; lo
         * único que cambia es que el motivo se guarda y se devuelve como error
         * de campo al salir. Si hay motivo se corta antes de tocar nada, así
         * que no hay nada que deshacer.
         */
        $noSePuede = null;

        /*
         * Estas dos se declaran acá y entran a la transacción por referencia
         * porque el MENSAJE de después las necesita.
         *
         * Decía «Pedido despachado · guía registrada» siempre, también cuando
         * habían salido 12 de 20. Quien despacha lee eso y entiende que el
         * pedido se fue completo; el que lo mandó a medias ya lo sabe, pero el
         * que lee la pantalla mañana, no.
         */
        $faltaAlgo = false;
        $quedaPendiente = false;

        /*
         * EL SEXTO MOTIVO, EL QUE SE HABÍA QUEDADO AFUERA.
         *
         * Los cinco de abajo se convirtieron a `withErrors` por lo que explica
         * el comentario largo que sigue. Pero quedaba uno más, y es el más
         * frecuente en bodega: `DescontarStockPorDespacho::exigirSaldo()` corta
         * con `abort(422)` cuando no alcanza la mercancía, y ese abort nace
         * DENTRO de la transacción, en otro archivo, así que no pasaba por el
         * `$noSePuede` de acá.
         *
         * Resultado probado el 21-nov-2026: se intentó despachar 4 unidades de
         * un producto con saldo 0 y en pantalla salió «El servidor rechazó la
         * operación». El mensaje bueno —que dice el SKU, cuánto pide y cuánto
         * hay— viajaba en el cuerpo de una página HTML que Inertia no lee.
         *
         * EL `catch` VA FUERA DE LA TRANSACCIÓN, Y NO ES UN DETALLE DE ESTILO.
         * Adentro, atrapar la excepción la daría por manejada y la transacción
         * COMMITEARÍA: el pedido quedaría marcado como despachado, con guías y
         * con `cantidad_despachada` sumada, y sin un solo movimiento de kardex
         * —el estante y el sistema distintos, que es justo lo que este módulo
         * existe para evitar—. Y el candado de idempotencia cerraría la puerta
         * del segundo viaje. Afuera, el abort revierte todo y después se
         * traduce a un error de campo que el modal sí sabe pintar.
         *
         * Es el mismo patrón que ya usa `ComprasGestionController::guardar()`
         * para los abort de `UbicacionDelPar`.
         */
        try {
            DB::transaction(function () use ($pedido, $data, $r, $todas, &$noSePuede, &$faltaAlgo, &$quedaPendiente) {
                $p = PedidoCliente::whereKey($pedido)->with('factura:id,numero,siigo_id,cufe,estado')
                    ->lockForUpdate()->firstOrFail();

                $estadoFactura = $p->factura?->estado;
                $estadoFactura = is_object($estadoFactura) ? $estadoFactura->value : $estadoFactura;

                /*
                 * CUÁNTO SALE EN TOTAL · pasada de SÓLO LECTURA.
                 *
                 * Se cuenta acá arriba, sin guardar nada, porque el motivo
                 * «no hay nada que despachar» tiene que poder cortar ANTES de
                 * la primera escritura. Más abajo se vuelve a recorrer para
                 * guardar; eso cuesta un recorrido de más sobre una lista de
                 * pocas líneas y vale lo que evita.
                 *
                 * Lo que evita: el 21-nov-2026 se despachó un pedido con 0
                 * unidades en la única línea. Quedó la guía escrita, una caja
                 * creada y el pedido marcado como parcial, sin que saliera
                 * nada. Esa guía fantasma ya aparecía en Seguimiento de guías
                 * sobre un pedido que decía «Pendiente de despacho» — y si
                 * alguien se la pasa al cliente, el cliente rastrea un envío
                 * que no existe.
                 *
                 * Si el corte se pusiera después del recorrido que guarda, el
                 * `return` del closure COMMITEA lo ya escrito y las líneas
                 * quedarían con `cantidad_despachada = 0`. Y eso no es
                 * cosmético: para `DescontarStockPorDespacho` el NULL significa
                 * «sale completo» y el 0 significa «no sale nada», así que un
                 * pedido tocado por un despacho-cero abortado dejaría de
                 * descontar para siempre.
                 */
                $pedidasLectura = $data['cantidades'] ?? [];
                $totalSale = 0.0;

                foreach ($p->items as $item) {
                    $pendiente = max(0, (float) $item->cantidad - (float) ($item->cantidad_despachada ?? 0));
                    $totalSale += array_key_exists($item->id, $pedidasLectura)
                        ? max(0, min($pendiente, (float) $pedidasLectura[$item->id]))
                        : $pendiente;
                }

                /*
                 * ¿Es el PRIMER viaje de este pedido, o viene de uno parcial?
                 *
                 * Se mira acá y no más abajo porque el recorrido que guarda
                 * ACUMULA sobre `cantidad_despachada`: después de él ya no se
                 * puede distinguir «nunca salió» de «salió y volvió».
                 *
                 * De esto depende qué hacer con las cajas ya guardadas.
                 */
                $yaHabiaSalido = (float) $p->items->sum('cantidad_despachada') > 0;

                /*
                 * Las guías que este pedido YA lleva de viajes anteriores.
                 *
                 * La comprobación de guías repetidas de arriba sólo compara
                 * las de ESTA petición: en el segundo viaje se podía volver a
                 * teclear la del primero y quedaba guardada dos veces, que es
                 * exactamente el daño que esa comprobación quería evitar —«el
                 * cliente reclama una caja que el sistema dice que sí llegó».
                 */
                $guiasPrevias = $yaHabiaSalido
                    ? $p->cajas()->pluck('guia')->map(fn ($g) => trim((string) $g))->filter()->all()
                    : [];

                $repetidaDeOtroViaje = $todas->first(fn ($g) => in_array($g, $guiasPrevias, true));

                $noSePuede = match (true) {
                    // Gate duro #1 · estado válido.
                    ! in_array($p->estado, ['aprobado', 'facturado'], true)
                        => 'Sólo se despacha un pedido aprobado o facturado.',

                    // Gate duro #2 · raíz del problema: factura emitida en el ERP.
                    // No exigimos siigo_id ni CUFE acá porque la factura ya está
                    // impresa y acompaña físicamente la mercancía — la sync con
                    // SIIGO se completa en segundo plano. Trabar al logístico
                    // esperando SIIGO lo hace circular entre pantallas sin razón.
                    ! $p->factura_id
                        => 'No se puede despachar sin factura. Generá la factura antes de entregar al transportador.',

                    // …y que esa factura siga viva. Sólo se validaba que existiera,
                    // así que una factura anulada dejaba salir la mercancía igual.
                    $estadoFactura === 'anulada'
                        => 'La factura de este pedido está anulada. Generá una factura nueva antes de despachar.',

                    // Gate duro #3 · idempotencia.
                    (bool) $p->despachado_at
                        => 'Este pedido ya fue despachado.',

                    // Gate duro #4 (LOG-J5-fix) · novedad del alistamiento abierta.
                    //   Si el alistador cerró con faltante/avería/revisión, no sale
                    //   la mercancía hasta que Gerencia/admin de bodega resuelva.
                    //   Esto es la raíz del «inventario negativo + mercancía mala
                    //   al cliente» que Don Jorge reportó: nunca más se despacha
                    //   algo que ya se marcó como incompleto o defectuoso.
                    $p->alistado_con_novedad && ! $p->novedad_resuelta_at
                        => 'Este pedido tiene una novedad abierta ('
                            .($p->alistado_tipo_novedad ?: 'sin tipo')
                            .'). Resolvela en la Cola de alistamiento antes de despachar.',

                    /*
                     * Gate duro #5 · que salga ALGO.
                     *
                     * Un despacho de cero unidades no es un despacho: es una
                     * guía en el aire. El `items->isNotEmpty()` está porque un
                     * pedido sin líneas —que a mano o por seeder se puede
                     * armar— daría total 0 por otra razón, y el mensaje lo
                     * mandaría a corregir una casilla que no existe.
                     */
                    $p->items->isNotEmpty() && $totalSale <= 0
                        => $p->items->sum('cantidad_despachada') > 0
                            ? 'Este pedido ya salió completo: no queda nada por despachar.'
                            : 'No hay nada que despachar: poné al menos una unidad en alguna línea.',

                    // Gate duro #6 · una guía no se repite entre viajes.
                    $repetidaDeOtroViaje !== null
                        => "La guía {$repetidaDeOtroViaje} ya se usó en un envío anterior de este pedido. "
                            .'Cada caja lleva la suya.',

                    default => null,
                };

                if ($noSePuede) {
                    return;
                }

                /*
                 * CUÁNTO SALE DE CADA LÍNEA.
                 *
                 * Se escribe ANTES de descontar, porque `DescontarStockPorDespacho`
                 * lee `cantidad_despachada` para saber qué bajar del kardex y qué
                 * saldo exigir. Si se escribiera después, el descuento saldría por
                 * la cantidad pedida y el parcial no serviría de nada.
                 *
                 * Nunca más de lo pedido: despachar de más no es un parcial, es un
                 * error, y descontaría stock que nadie compró.
                 */
                $pedidas = $data['cantidades'] ?? [];
                $envio = [];
                $faltaAlgo = false;

                foreach ($p->items as $item) {
                    $yaSalio = (float) ($item->cantidad_despachada ?? 0);
                    $pendiente = max(0, (float) $item->cantidad - $yaSalio);

                    /*
                     * Lo que sale EN ESTE VIAJE. Topado por lo que todavía se debe:
                     * en el segundo despacho de un pedido parcial, la pantalla
                     * muestra lo pendiente, y si alguien teclea de más no puede
                     * salir más de lo que el cliente compró.
                     */
                    $sale = array_key_exists($item->id, $pedidas)
                        ? max(0, min($pendiente, (float) $pedidas[$item->id]))
                        : $pendiente;

                    $envio[$item->id] = $sale;

                    if ($sale < $pendiente) {
                        $faltaAlgo = true;
                    }

                    // ACUMULA, no pisa: un pedido que sale en dos viajes lleva la
                    // suma de los dos, no sólo lo del último.
                    $item->forceFill(['cantidad_despachada' => $yaSalio + $sale])->save();
                }

                $p->setRelation('items', $p->items->fresh());

                /*
                 * ¿Vuelve a la cola, o se cierra con lo entregado?
                 *
                 * Lo elige quien despacha. Depende de si el cliente va a esperar el
                 * resto, y eso no lo sabe el código. Sin nada que falte, la
                 * pregunta no existe: el pedido se cierra igual.
                 */
                $quedaPendiente = $faltaAlgo && (bool) ($data['resto_pendiente'] ?? false);

                /*
                 * SI VUELVE A LA COLA, VUELVE LISTO PARA ALISTAR DE NUEVO.
                 *
                 * Volver a «facturado» no alcanzaba. La cola reparte sus
                 * columnas por las marcas del alistamiento, y las del primer
                 * viaje seguían puestas: el pedido reaparecía en «Listos para
                 * empaque» —con el cronómetro del día anterior— en vez de en la
                 * cola de alistar. Y ahí se quedaba atascado, porque `asignar`,
                 * `iniciar` y `finalizar` cortan con 422 cuando esas marcas
                 * existen. La única forma de moverlo era despacharlo otra vez
                 * desde la pantalla del pedido, sin que nadie recogiera lo que
                 * faltaba.
                 *
                 * La casilla también se suelta: el pedido no está armándose en
                 * el piso mientras espera el segundo viaje, y `repartirCasillas`
                 * la cuenta como ocupada mientras no haya `despachado_at` —que
                 * en un parcial es justamente NULL—. Cuando vuelva a entrar en
                 * una oleada se le da una nueva.
                 *
                 * No se pierde el rastro del primer alistamiento: el modelo es
                 * auditable y estas columnas están en el `$fillable`, así que
                 * quién lo alistó y cuándo queda en la bitácora.
                 */
                if ($quedaPendiente) {
                    $p->forceFill([
                        'alistador_id' => null,
                        'alistado_asignado_at' => null,
                        'alistado_inicio_at' => null,
                        'alistado_fin_at' => null,
                        'alistado_notas' => null,
                        'alistado_con_novedad' => false,
                        'alistado_tipo_novedad' => null,
                        'novedad_resuelta_at' => null,
                        'novedad_resuelta_por_id' => null,
                        'novedad_resolucion' => null,
                        'casilla_consolidacion' => null,
                    ])->save();
                }

                $p->update([
                    /*
                     * El estado depende de qué se decidió con lo que no salió.
                     *
                     *   resto pendiente → vuelve a «facturado», que es el estado
                     *     que la cola de alistamiento mira. Así el pedido reaparece
                     *     para el segundo despacho en vez de desaparecer con una
                     *     deuda abierta.
                     *   resto cerrado   → «despachado», con lo entregado.
                     *
                     * Sin parcial, nada de esto aplica: despachado y listo.
                     */
                    'estado' => $quedaPendiente ? 'facturado' : 'despachado',
                    /*
                     * `despachado_at` se deja VACÍO mientras quede algo por
                     * entregar, y no por prolijidad: el gate de idempotencia de
                     * arriba («este pedido ya fue despachado») lo mira, así que
                     * ponerlo cerraría la puerta del segundo viaje.
                     *
                     * La fecha del primer envío no se pierde: queda en el
                     * movimiento de kardex y en la línea de tiempo.
                     */
                    'despachado_at' => $quedaPendiente ? null : now(),
                    /*
                     * La fecha de la PRIMERA caja, que se pone una vez y no se
                     * vuelve a tocar.
                     *
                     * Es la que sirve para contar días de tránsito y para
                     * reclamarle a la transportadora. `despachado_at` no puede
                     * hacer ese trabajo: en un parcial se deja NULL a propósito,
                     * así que un pedido cuya primera caja salió hace ocho días
                     * figuraba como si nunca hubiera salido y el seguimiento no
                     * tenía desde cuándo contar.
                     */
                    'primer_despacho_at' => $p->primer_despacho_at ?? now(),
                    'despachado_por_id' => $r->user()->id,
                    'guia_transportadora' => $data['guia_transportadora'],
                    /*
                     * La transportadora del segundo viaje no BORRA la del
                     * primero.
                     *
                     * El campo es opcional, así que un segundo despacho con el
                     * selector en «— sin transportadora —» dejaba el pedido sin
                     * empresa: `urlDeRastreo()` necesita las dos —nombre y
                     * guía— así que el cliente se quedaba sin el enlace de
                     * rastreo que ya tenía, y la pantalla de guías perdía con
                     * quién reclamar la caja que iba en camino.
                     *
                     * Si el segundo viaje va por otra empresa, se escribe esa:
                     * lo que no puede es que un campo vacío borre un dato que
                     * ya existía.
                     */
                    'transportadora' => ($data['transportadora'] ?? null) ?: $p->transportadora,
                    'despacho_parcial' => $faltaAlgo || $p->despacho_parcial,
                    'resto_pendiente' => $quedaPendiente,
                ]);

                /*
                 * Y EL DETALLE DE LAS CAJAS, QUE SE ACUMULA ENTRE VIAJES.
                 *
                 * Antes acá se borraban todas y se recreaban. El comentario que
                 * lo justificaba decía «un re-despacho no puede dejar las guías
                 * del intento anterior mezcladas con las nuevas», y describía un
                 * reintento fallido — que no existe: si la transacción revienta
                 * hay rollback y las cajas nunca llegaron a crearse.
                 *
                 * Lo que sí existe es el segundo viaje, que lo habilitó este
                 * mismo cambio: con `resto_pendiente` el pedido vuelve a
                 * «facturado» con `despachado_at` en NULL justamente para poder
                 * volver a entrar acá. Así que el borrado no disparaba en un
                 * caso imaginario: disparaba en el único caso nuevo.
                 *
                 * Probado el 21-nov-2026: salieron 4 de 6 con guía PARCIAL-A y
                 * después las 2 restantes con PARCIAL-B. Quedó UNA caja. La
                 * primera remesa sólo sobrevivía en la nota de un movimiento de
                 * kardex, así que al cliente que reclamaba esa caja no se le
                 * podía dar el número ni mirando la base.
                 *
                 * El borrado se conserva SÓLO para el primer viaje, y no por
                 * simetría: el backfill de la migración le creó caja 1 a todo
                 * pedido que tuviera guía escrita, incluidos los que nunca
                 * salieron (el sembrador de QA arma uno así a propósito). Si no
                 * se limpiara, el primer despacho real de ese pedido empezaría a
                 * numerar en 2 y arrastraría una guía que nunca viajó.
                 */
                if (! $yaHabiaSalido) {
                    $p->cajas()->delete();
                }

                // Numerar desde la última: el `unique(pedido_id, numero)` de la
                // tabla no admite repetir, y además la caja 3 de un pedido tiene
                // que llamarse 3 aunque haya salido en el segundo viaje.
                $desde = (int) $p->cajas()->max('numero');

                /*
                 * Y de qué viaje son. El número de caja no alcanza para saber
                 * cuáles salieron juntas, que es lo primero que se pregunta
                 * cuando el cliente dice que le llegaron dos de tres.
                 */
                $viaje = (int) $p->cajas()->max('viaje') + 1;

                foreach ($todas as $i => $guia) {
                    $p->cajas()->create([
                        'numero' => $desde + $i + 1,
                        'viaje' => $viaje,
                        'guia' => $guia,
                    ]);
                }

                // La mercancía sale de la bodega: baja del kardex. Sin esto el ERP
                // seguía mostrando en stock lo que ya iba camino al cliente.
                $accion = new \App\Modules\Inventario\Actions\DescontarStockPorDespacho();
                // Se le dice QUÉ sale en este viaje: en un segundo despacho, la
                // línea lleva acumulado lo de los dos, y del kardex sólo debe
                // bajar lo de ahora.
                $this->lineasDescontadas = $accion->ejecutar($p, $r->user()->id, $envio);
                $this->motivoStock = $accion->motivo;
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            /*
             * Un 5xx no es «no se pudo despachar», es «el sistema se rompió»:
             * mostrarlo como error del campo de la guía mandaría a la persona
             * a corregir un número que está bien. Ese sube tal cual.
             */
            if ($e->getStatusCode() >= 500) {
                throw $e;
            }

            return back()->withErrors([
                'guia_transportadora' => $e->getMessage() ?: 'No se pudo despachar.',
            ]);
        }

        if ($noSePuede) {
            return back()->withErrors(['guia_transportadora' => $noSePuede]);
        }

        /*
         * El tablero del cliente cambia con esto, así que su caché se suelta.
         *
         * Despachar mueve el contador de «pedidos que salieron en partes» y la
         * fecha de salida que la lista del portal muestra. Sin este olvido, el
         * cliente que entra a ver si su pedido salió sigue viendo el tablero de
         * hace un minuto — justo cuando acaba de recibir el aviso.
         */
        $contactoId = (int) PedidoCliente::whereKey($pedido)->value('contacto_id');

        \Illuminate\Support\Facades\Cache::forget(
            \App\Http\Controllers\Portal\PortalHomeController::llaveKpis($contactoId)
        );

        /*
         * El mensaje dice lo que PASÓ, no siempre lo mismo.
         *
         * Las tres frases son tres hechos distintos y quien despacha necesita
         * saber en cuál quedó: si el pedido vuelve a la cola, si se cerró
         * sabiendo que falta mercancía, o si salió completo.
         */
        $msg = match (true) {
            $quedaPendiente => 'Salió parte del pedido · guía registrada. '
                .'Lo que falta queda pendiente y el pedido vuelve a la cola.',
            $faltaAlgo => 'Salió parte del pedido · guía registrada. '
                .'El resto se dio por cerrado: ya no sale.',
            default => 'Pedido despachado · guía registrada.',
        };

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
        \Illuminate\Support\Facades\Cache::forget(\App\Http\Controllers\Portal\PortalHomeController::llaveKpis((int) $factura->contacto_id));

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
