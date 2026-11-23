<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use App\Modules\Logistica\Support\RepartoPorUbicacion;
use App\Modules\Logistica\Support\ZonaDeLaBodega as Zona;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LOG-J5 · Cola Don Jorge · reemplaza su hoja de Excel manual.
 *
 * Vista Kanban con 3 columnas:
 *   • En cola (facturado · sin asignar)
 *   • En alistamiento (asignado y/o iniciado · sin terminar)
 *   • Listo para empaque (alistamiento terminado · sin despachar)
 *
 * Prioridad para ordenar la cola: contado > crédito; dentro del mismo tipo,
 * clientes de Bucaramanga (zona local) primero y luego por antigüedad. Es la
 * regla que Don Jorge dijo que usa a mano hoy.
 *
 * Reglas de estado:
 *   1. Un pedido entra a la cola cuando está `facturado` (la mercancía ya
 *      tiene documento, LOG-J7). Si no, no debería alistarse.
 *   2. Sólo quien lo tomó puede iniciarlo/terminarlo. Admin de bodega puede
 *      reasignar en cualquier momento.
 *   3. Al terminar deja huella en el timeline (LOG-J10).
 */
class ColaJorgeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Gate: Aracely/Gerencia (ve todo), admin de bodega, alistador o
            //   despachador. Un vendedor no entra acá.
            //
            // A5 FIX · B1 CRÍTICO · Despachador antes caía en 403 nada más
            //   loguearse porque Dashboard lo redirige acá. Lo agregamos.
            new Middleware(function (Request $r, \Closure $next) {
                $u = $r->user();
                // Casilla: Cola de alistamiento y despacho. Preguntaba por cuatro
                //   roles escritos a mano, asi que un rol nuevo nunca podia entrar
                //   por mas que le marcaran la casilla.
                abort_unless(
                    \App\Auth\Permisos::puede($u, 'logistica'),
                    403,
                    'Esta cola es de logística.'
                );
                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        $u = $r->user();

        // Base: pedidos facturados que todavía no se despacharon.
        //   Un pedido terminado se queda en la columna "Listo" hasta que
        //   logística lo entregue a transportadora (LOG-J7).
        // LOG-J4 · si el user es AdminBodega (no Aracely), filtramos la cola
        //   a los pedidos cuya ubicacion_origen_id esté dentro de las bodegas
        //   que él administra. Aracely/Gerencia ven la cola de todas las sedes.
        //
        // A5 FIX · B2 CRÍTICO · Antes el `when(! empty($misBodegas), ...)`
        //   fallaba OPEN si el user no tenía bodegas asignadas (el filtro no
        //   aplicaba → veía TODAS las sedes). Ahora fail-closed: si no es
        //   Aracely y no tiene bodegas, forzamos [-1] para no mostrar nada.
        if ($u->esAracely()) {
            $misBodegas = null;
        } else {
            /*
             * H2 · Antes preguntaba `bodegasAsignadasIds()`, que sólo hereda
             * si el usuario es AdminBodega. Un Alistador marcado responsable
             * de su propia bodega recibía [] → [-1] → cola en cero, todos los
             * días. Y al responsable de un rack le pasaba lo mismo: el id del
             * rack no es el origen del pedido, lo es la sede.
             *
             * `bodegasQueVeIds()` hereda por responsable sin pedir rol —quién
             * entra a esta pantalla ya lo decidió el middleware de arriba— y
             * sube del rack a su sede. No abre ninguna puerta de escritura:
             * mover inventario sigue preguntando por el otro método.
             */
            $ids = $u->bodegasQueVeIds();
            $misBodegas = ! empty($ids) ? $ids : [-1];
        }

        $base = PedidoCliente::with([
            'contacto:id,nombre_completo,razon_social,ciudad',
            'factura:id,numero',
            'alistador:id,name',
            'ubicacionOrigen:id,codigo,nombre',
        ])
            ->where('estado', 'facturado')
            ->whereNull('despachado_at')
            ->when($misBodegas !== null, fn ($q) => $q->whereIn('ubicacion_origen_id', $misBodegas))
            // LOG-J5 · la prioridad se calcula en SQL para que el orden
            //   sobreviva a los reloads de 10 s: armado después, las tarjetas
            //   bailarían en cada recarga.
            //
            // 17-nov-2026 · el orden ya no es el que habíamos puesto nosotros
            //   —contado, luego Bucaramanga, luego fecha— sino la jerarquía de
            //   cinco escalones que entregó el área por escrito. El valor del
            //   pedido entra en la cuenta: antes uno de ochenta mil le ganaba a
            //   uno de tres millones por haberse facturado diez minutos antes.
            ;

        $base = app(\App\Modules\Logistica\Support\PrioridadDeLaCola::class)->aplicar($base);

        $pedidos = $base->get();

        // Reparto por columna · simple, sin query extra.
        //   LOG-J5-fix · un pedido con novedad abierta NO va a "Listos para
        //   empaque" — va a su propia columna "⚠ Con novedad" para que alguien
        //   lo resuelva ANTES de que la mercancía mala siga al despacho.
        $enCola         = $pedidos->filter(fn ($p) => !$p->alistado_inicio_at && !$p->alistado_fin_at)->values();
        $enAlistamiento = $pedidos->filter(fn ($p) => $p->alistado_inicio_at && !$p->alistado_fin_at)->values();
        $conNovedad     = $pedidos->filter(fn ($p) => $p->alistado_fin_at && $p->alistado_con_novedad && !$p->novedad_resuelta_at)->values();
        $listos         = $pedidos->filter(fn ($p) => $p->alistado_fin_at && (!$p->alistado_con_novedad || $p->novedad_resuelta_at))->values();

        $mapCard = fn (PedidoCliente $p) => [
            'id' => $p->id,
            'numero' => $p->numero,
            'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
            'ciudad' => $p->contacto?->ciudad,
            'total' => (float) $p->total,
            'factura' => $p->factura?->numero,
            'tipo_pago' => (int) $p->prio_tipo === 0 ? 'contado' : 'credito',
            'es_local' => (int) $p->prio_zona === 0,
            'alistador' => $p->alistador ? ['id' => $p->alistador->id, 'name' => $p->alistador->name] : null,
            'asignado_at' => $p->alistado_asignado_at?->format('H:i'),
            'inicio_at' => $p->alistado_inicio_at?->format('H:i'),
            /*
             * El instante completo, no sólo la hora del reloj.
             *
             * El cronómetro de «En alistamiento» armaba la hora de arranque
             * sobre la fecha de HOY, porque acá sólo iba «16:30». Un pedido
             * que alguien tomó ayer a las cuatro y media y nunca cerró
             * amanecía diciendo «0 min» — justo el pedido olvidado que la
             * columna existe para delatar.
             *
             * En segundos Unix: la pantalla no tiene que adivinar ni la fecha
             * ni la zona horaria.
             */
            'inicio_ts' => $p->alistado_inicio_at?->timestamp,
            'fin_at' => $p->alistado_fin_at?->format('H:i'),
            // En segundos, y la pantalla decide cómo leerlo. `diffInMinutes`
            // devuelve FLOTANTE en Carbon 3, así que un alistamiento de 19
            // segundos se pintaba tal cual: «0.31666666666666665 min».
            'duracion_seg' => ($p->alistado_inicio_at && $p->alistado_fin_at)
                ? max(0, (int) round($p->alistado_inicio_at->diffInSeconds($p->alistado_fin_at)))
                : null,
            'facturado_at' => $p->facturado_at?->format('Y-m-d H:i'),
            'notas' => $p->alistado_notas,
            // LOG-J5-fix · info de novedad para que la UI pinte la 4ª columna.
            'con_novedad' => (bool) $p->alistado_con_novedad,
            'tipo_novedad' => $p->alistado_tipo_novedad,
            'resuelta_at' => $p->novedad_resuelta_at?->format('H:i'),
            'resolucion' => $p->novedad_resolucion,
        ];

        /*
         * Alistadores disponibles para el dropdown de asignación.
         *
         * Decía `'Admin bodega'`, con espacio, y el rol en la base se llama
         * `AdminBodega`. O sea que el `whereIn` nunca lo encontró: el propio
         * Jorge no aparecía en la lista de a quién asignarle un pedido, en su
         * propia pantalla.
         */
        $alistadores = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Alistador', 'AdminBodega']))
            /*
             * LOS RESPONSABLES DE ZONA VUELVEN A LA LISTA (23-nov-2026).
             *
             * Acá se excluían las cuentas «responsable1/2/3@greatbaby.com», y
             * la razón era buena mientras fue cierta: el seeder las crea con
             * una contraseña aleatoria de 48 caracteres justamente para que
             * nadie entre con ellas, el ERP no tiene recuperación de clave
             * para este guard, y asignarle un pedido a una cuenta en la que
             * nadie puede entrar lo PARQUEA —queda a nombre de alguien que no
             * va a tomarlo, y el alistador de verdad recibe 403 «este pedido
             * es de otro»—.
             *
             * La premisa cambió: el 23-nov se les puso contraseña y se
             * entregaron como las cuentas de los tres responsables de zona.
             * Son personas que entran a trabajar, así que excluirlas de la
             * lista dejaba a Don Jorge sin poder asignarles un solo pedido —
             * medio sistema abierto para ellos y la puerta cerrada en la única
             * pantalla donde se reparte el trabajo.
             *
             * Si algún día se abre una zona nueva y su cuenta queda sin dueño,
             * el riesgo vuelve: no asignarle pedidos, y si alguno se parquea,
             * se desatasca reasignándolo desde esta misma pantalla.
             */
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Logistica/ColaJorge', [
            'en_cola' => $enCola->map($mapCard),
            'en_alistamiento' => $enAlistamiento->map($mapCard),
            'con_novedad' => $conNovedad->map($mapCard),
            'listos' => $listos->map($mapCard),
            'alistadores' => $alistadores,
            'rol' => [
                'puede_reasignar' => $u->esAracely() || $u->esAdminBodega(),
                'puede_resolver' => $u->esAracely() || $u->esAdminBodega(),
                'yo_id' => $u->id,
            ],
            /*
             * LOG-H1 · Por qué está vacía.
             *
             * El filtro de arriba es fail-closed a propósito: sin bodegas
             * asignadas no se ve nada, y eso está bien. El problema era otro:
             * una cola vacía POR FALTA DE CONFIGURACIÓN se veía idéntica a un
             * día sin trabajo. El bodeguero de Bogotá se quedaba esperando
             * pedidos que nunca le iban a llegar, y nadie lo supo hasta que
             * se revisó a mano.
             *
             * Quién lo arregla no es él: ver las ubicaciones puede, pero
             * asignar el responsable es de gerencia. Así que la pantalla
             * dice a quién pedírselo en vez de ofrecerle un botón que le
             * daría 403.
             */
            'sin_bodegas' => $misBodegas === [-1],
            'puede_asignarse_bodegas' => \App\Auth\Permisos::esRoot($u),

            /*
             * La OTRA razón por la que la cola amanece vacía, y la que va a
             * pasar en producción el primer día.
             *
             * Un pedido entra a la cola por `ubicacion_origen_id`, y ese campo
             * lo llena la regla de ruteo de la ciudad del cliente. Si las
             * reglas están vacías —que es como nace la tabla— `resolver()`
             * devuelve null, el pedido se guarda sin bodega y el `whereIn` de
             * arriba nunca empareja un NULL. Resultado: se factura todo el día
             * y la cola sigue en cero, sin que nada en pantalla lo explique.
             *
             * Se cuenta y se dice con número, igual que en la pantalla de
             * Reglas de ruteo: «hay 23 pedidos sin bodega» se entiende;
             * «configure el ruteo» no.
             */
            // Cuantos pedidos caben en una oleada: es el numero de casillas
            // de la zona de alistamiento, porque no se pueden armar mas de los
            // que caben en el piso.
            'casillas_disponibles' => \App\Modules\Logistica\Support\ZonaDeAlistamiento::cuantasCaben(),
            'pedidos_sin_bodega' => PedidoCliente::whereNull('ubicacion_origen_id')
                ->where('estado', 'facturado')
                ->whereNull('despachado_at')
                ->count(),
            'kpis' => [
                'total_en_cola' => $enCola->count(),
                'total_en_curso' => $enAlistamiento->count(),
                'total_con_novedad' => $conNovedad->count(),
                'total_listos' => $listos->count(),
                'contado_pendiente' => $enCola->where('prio_tipo', 0)->count(),
            ],
        ]);
    }

    public function asignar(Request $r, int $pedido): RedirectResponse
    {
        $data = $r->validate([
            'alistador_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $u = $r->user();
        abort_unless($u->esAracely() || $u->esAdminBodega(), 403,
            'Sólo Aracely o el admin de bodega asignan la cola.');

        DB::transaction(function () use ($pedido, $data) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless($p->estado === 'facturado', 422, 'Sólo se alistan pedidos facturados.');
            abort_if($p->alistado_fin_at, 422, 'Este pedido ya se terminó de alistar.');
            $p->update([
                'alistador_id' => $data['alistador_id'],
                'alistado_asignado_at' => now(),
            ]);
        });

        return back()->with('success', 'Pedido asignado al alistador.');
    }

    /**
     * Traer mercancía de Exhibición para completar un pedido.
     *
     * ───────────────────────────────────────────────────────────────────────
     * POR QUÉ EXISTE
     *
     * La hoja de picking le dice al alistador, impreso: «si los racks no
     * completan la cantidad, tome de EXHIBICIÓN y reporte el traslado. El
     * pedido sale completo o no sale». Y el área confirmó el 20-nov-2026 que
     * eso lo hace «sin requerir autorización previa».
     *
     * Pero no tenía con qué reportarlo: la pantalla de traslados es de quien
     * manda la bodega. Así que o se llevaba la mercancía sin registrarla —y
     * Exhibición quedaba descuadrada— o el pedido esperaba al jefe. Las dos
     * salidas son malas y la bodega venía eligiendo la primera.
     *
     * Esto es la tercera: un traslado acotado, que sólo puede ir DESDE el
     * almacén marcado como respaldo de surtido HACIA una bodega que esa
     * persona ya opera. Nada más.
     *
     * Se envía y se recibe en el mismo acto, a propósito: la mercancía no está
     * «en tránsito», la tiene en la mano. Dejarla en tránsito obligaría a un
     * segundo paso que nadie va a dar con el pedido a medio alistar.
     */
    public function surtirDesdeRespaldo(Request $r, int $pedido): RedirectResponse
    {
        $datos = $r->validate([
            'origen_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id'],
            // Unidades enteras: Great Baby no vende fracciones, y un decimal
            // acá descuadra Exhibición contra la bodega por la parte que el
            // kardex trunca.
            'cantidad' => ['required', 'integer', 'min:1'],
        ], [], [
            'origen_id' => 'ubicación de origen',
            'cantidad' => 'cantidad',
        ]);

        abort_if(
            ! $datos['producto_id'] && ! $datos['variante_id'],
            422,
            'Hay que decir qué producto se trae de Exhibición.',
        );

        $u = $r->user();
        $p = PedidoCliente::with('items')->findOrFail($pedido);

        abort_unless($p->ubicacion_origen_id, 422,
            'Este pedido no tiene bodega de origen: no hay a dónde traer la mercancía.');

        /*
         * Quién puede: el alistador del pedido, o quien opere esa bodega. NO
         * se pide el permiso `traslados` — ese es del jefe de bodega, y la
         * razón entera de esta pantalla es que el alistador no lo tiene.
         */
        abort_unless(
            $u->esAracely()
            || $p->alistador_id === $u->id
            || in_array((int) $p->ubicacion_origen_id, array_map('intval', $u->bodegasQueVeIds()), true),
            403,
            'Este pedido no es de una bodega suya.',
        );

        /*
         * Y SÓLO LO QUE ESTÉ EN EL PEDIDO, Y SÓLO LO QUE FALTE.
         *
         * Esto no se comprobaba. El endpoint validaba de dónde sale la
         * mercancía y quién la pide, pero no QUÉ: cualquiera con rol de
         * alistador podía tomar el id de un pedido suyo, pedir la referencia
         * más cara del catálogo —que no está en ese pedido— en la cantidad que
         * se le ocurriera, y el traslado se ejecutaba. Mercancía saliendo de
         * Exhibición a nombre de un pedido que no la lleva.
         *
         * El tope es lo PENDIENTE de la línea, no la cantidad pedida: en un
         * segundo viaje, lo que falta por traer es lo que no ha salido. Traer
         * de más deja a Exhibición descuadrada contra una bodega que no
         * necesitaba esas unidades.
         */
        /*
         * El `??` importa: `validate()` NO devuelve las claves `nullable` que
         * no vinieron en la petición, y acá una de las dos siempre falta —se
         * manda variante O producto, nunca las dos—.
         */
        $varianteId = (int) ($datos['variante_id'] ?? 0);
        $productoId = (int) ($datos['producto_id'] ?? 0);

        $linea = $p->items->first(fn ($i) => $varianteId
            ? (int) $i->variante_id === $varianteId
            : ((int) $i->producto_id === $productoId && $i->variante_id === null));

        abort_unless($linea, 422,
            "Ese producto no está en el pedido {$p->numero}: de Exhibición sólo se trae lo que el pedido lleva.");

        $pendiente = max(0, (int) $linea->cantidad - (int) (float) ($linea->cantidad_despachada ?? 0));

        abort_if($pendiente <= 0, 422,
            "De esa línea del pedido {$p->numero} ya salió todo: no hay qué traer.");

        abort_if((int) $datos['cantidad'] > $pendiente, 422, sprintf(
            'De %s el pedido %s sólo necesita %d unidad%s más, y estás trayendo %d.',
            $linea->sku_snapshot ?: 'ese producto', $p->numero,
            $pendiente, $pendiente === 1 ? '' : 'es', (int) $datos['cantidad']
        ));

        $respaldo = \App\Modules\Inventario\Models\Oficina::respaldoDeSurtido();

        abort_unless($respaldo, 422,
            'Ninguna oficina está marcada como respaldo de surtido en el catálogo de la bodega.');

        $origen = \App\Modules\Dropi\Models\InventarioUbicacion::findOrFail($datos['origen_id']);
        $oficinaDelOrigen = $origen->oficina_id
            ?: \App\Modules\Dropi\Models\InventarioUbicacion::find($origen->bodega_id)?->oficina_id;

        abort_unless((int) $oficinaDelOrigen === (int) $respaldo->id, 422,
            "De ahí no se surte: {$origen->codigo} no es del respaldo ({$respaldo->etiqueta()}).");

        /*
         * TODO EN UNA SOLA TRANSACCIÓN, el envío y la recepción incluidos.
         *
         * Antes el traslado se creaba y commiteaba acá, y `enviar()` y
         * `recibir()` corrían afuera. Dos formas de romperse, las dos vistas en
         * una bodega:
         *
         *   · si en Exhibición no alcanza, `enviar()` lanza y el traslado ya
         *     commiteado queda huérfano en Borrador —uno por cada reintento—;
         *   · y si `recibir()` falla después de `enviar()`, la mercancía salió
         *     de Exhibición y no entró a la bodega: desaparece del sistema.
         *
         * Con todo adentro, o el movimiento completo queda escrito o no queda
         * nada, que es lo único aceptable cuando se mueve stock.
         */
        $traslado = DB::transaction(function () use ($datos, $p, $u, $origen, $respaldo, $productoId, $varianteId) {
            $t = \App\Modules\Inventario\Models\Traslado::create([
                'numero' => \App\Modules\Inventario\Models\Traslado::siguienteNumero(),
                'origen_id' => $origen->id,
                'destino_id' => $p->ubicacion_origen_id,
                'estado' => \App\Modules\Inventario\Enums\EstadoTraslado::Borrador,
                'motivo' => \App\Modules\Inventario\Models\Traslado::MOTIVO_SURTIDO,
                'observaciones' => "Surtido desde {$respaldo->etiqueta()} para completar el pedido {$p->numero}.",
                'solicitado_por' => $u->id,
                'fecha_solicitud' => now()->toDateString(),
            ]);

            \App\Modules\Inventario\Models\TrasladoItem::create([
                'traslado_id' => $t->id,
                // Mismas claves resueltas arriba: la que no vino vale null, no
                // «clave inexistente».
                'producto_id' => $productoId ?: null,
                'variante_id' => $varianteId ?: null,
                'cantidad_solicitada' => $datos['cantidad'],
            ]);

            $accion = app(\App\Modules\Inventario\Actions\EjecutarTraslado::class);

            // La mercancía la tiene en la mano: sale y entra en el mismo acto.
            $enviado = $accion->enviar($t->fresh('items'));
            $accion->recibir($enviado->fresh('items'));

            return $enviado->fresh('items');
        });

        return back()->with('success',
            "Traído de {$origen->codigo} · {$traslado->numero}. Exhibición quedó descontada.");
    }

    public function iniciar(Request $r, int $pedido): RedirectResponse
    {
        $u = $r->user();
        DB::transaction(function () use ($u, $pedido) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless($p->estado === 'facturado', 422, 'Sólo se alistan pedidos facturados.');
            abort_if($p->alistado_inicio_at, 422, 'Este pedido ya se inició.');
            // Permiso: si no está asignado, se auto-asigna al que arranca
            //   (el flujo real de Jorge: a veces el alistador agarra uno de
            //   la cola directamente). Si YA está asignado a otro, bloqueamos
            //   salvo que sea admin.
            if ($p->alistador_id && $p->alistador_id !== $u->id && ! ($u->esAracely() || $u->esAdminBodega())) {
                abort(403, 'Este pedido está asignado a otro alistador.');
            }
            $p->update([
                'alistador_id' => $p->alistador_id ?: $u->id,
                'alistado_asignado_at' => $p->alistado_asignado_at ?: now(),
                'alistado_inicio_at' => now(),
            ]);
        });
        return back()->with('success', 'Alistamiento iniciado · reloj corriendo.');
    }

    /**
     * LOG-J5-fix · El alistador cierra el pedido declarando si hubo o no
     * novedad. Si hubo (faltante/avería/revisión), el pedido NO fluye a
     * "Listos para empaque" — queda en "Con novedad" y disparamos alerta
     * para que compras/gerencia resuelvan antes de dejar salir mercancía
     * incompleta o defectuosa. Si no hubo, flujo normal.
     */
    /**
     * LOG-J6 · Hoja de picking imprimible · reemplaza el papel que Jorge
     *   anota a mano. Por cada línea del pedido mostramos la UBICACIÓN
     *   donde está el stock (Rack · Sección · Nivel según el codigo de la
     *   InventarioUbicacion, p.ej. "R-A-01").
     *
     * Para variantes granulares consultamos el saldo real por ubicación
     * desde `inventario_movimientos` (suma positiva). Para productos
     * agregados (sin variante_id) decimos "varias ubicaciones" porque el
     * alistador decide dónde arma el pedido según colores disponibles.
     */
    /**
     * Donde esta la mercancia de cada item de un pedido.
     *
     * Devuelve [clave => [ubicaciones]], con la clave `v:<id>` para lo
     * desglosado por variante y `p:<id>` para lo agregado, ya ordenadas en el
     * orden en que la hoja manda a buscar.
     *
     * Vive aparte porque lo usan dos pantallas: la hoja de un pedido y la
     * oleada, que recoge varios de una sola pasada. Repetida en las dos se
     * separan al primer cambio, y de este orden depende que el alistador no
     * camine de mas.
     *
     * @return array<string, array<int, array>>
     */
    private function dondeEstaCadaItem(PedidoCliente $p): array
    {
        /*
         * La sede de la que sale este pedido, con sus posiciones hijas.
         *
         * Si el pedido no trae bodega —pasa cuando las reglas de ruteo están
         * vacías— no se restringe: es mejor imprimir dónde está la mercancía
         * que imprimir una hoja sin ubicaciones.
         */
        $sedeDelPedido = null;
        $idsDeRespaldo = [];

        if ($p->ubicacion_origen_id) {
            $sedeDelPedido = \App\Modules\Dropi\Models\InventarioUbicacion::query()
                ->where('id', $p->ubicacion_origen_id)
                ->orWhere('bodega_id', $p->ubicacion_origen_id)
                ->pluck('id')
                ->all();

            /*
             * Exhibicion tambien cuenta, y el area lo pide por escrito.
             *
             * Su procedimiento de preparacion de pedidos trae una «directriz
             * critica de abastecimiento»: hay que surtir el 100% de lo pedido,
             * y si falta en los racks el operario debe tomar de la sala de
             * exhibicion —oficina 14— gestionando el traslado. La razon es
             * comercial: Great Baby vende al por mayor y un pedido incompleto
             * deja picos de referencia que despues no se venden.
             *
             * Asi que la hoja tiene que DECIR que hay existencias ahi. Lo que
             * no puede es ofrecerlo como un estante mas: no es el recorrido
             * normal, es una excepcion que obliga a registrar un traslado.
             * Por eso entra al alcance pero se marca distinto mas abajo.
             *
             * Cual oficina es el respaldo sale del catalogo, no de un 14
             * escrito aca: el area puede mover esa marca desde la pantalla.
             */
            $respaldo = \App\Modules\Inventario\Models\Oficina::respaldoDeSurtido();

            if ($respaldo) {
                $idsDeRespaldo = \App\Modules\Dropi\Models\InventarioUbicacion::query()
                    ->where('oficina_id', $respaldo->id)
                    ->where('activa', true)
                    ->pluck('id')
                    ->all();

                $sedeDelPedido = array_values(array_unique([...$sedeDelPedido, ...$idsDeRespaldo]));
            }
        }

        /*
         * Dónde está cada ítem, para que el alistador sepa a qué estante ir.
         *
         * Esto buscaba SÓLO por `variante_id`. Los productos AGREGADOS —los que
         * no se desglosan por color ni talla, que son la mayor parte del
         * catálogo de Great Baby— tienen la variante en null, así que su lista
         * de ubicaciones salía siempre vacía y la hoja imprimía
         * «⚠ SIN STOCK — reportar a Jorge» aunque hubiera mercancía de sobra.
         * Comprobado el 2026-10-14 con 52 unidades en dos bodegas.
         *
         * El costo real no es la hoja fea: es que manda al alistador a reportar
         * un faltante que no existe, y un faltante abre novedad y retiene el
         * pedido.
         */
        $saldos = fn (string $campo, $ids) => $ids->isEmpty() ? collect() : \DB::table('inventario_movimientos as m')
            ->join('inventario_ubicaciones as u', 'u.id', '=', 'm.ubicacion_id')
            ->whereIn("m.{$campo}", $ids)
            ->whereNull('m.deleted_at')   // INV-A6 · lo anulado no es stock
            // Un producto cuenta como agregado sólo si el movimiento no trae
            // variante; si no, se sumaría el stock de sus variantes.
            ->when($campo === 'producto_id', fn ($q) => $q->whereNull('m.variante_id'))
            ->where('u.activa', true)
            /*
             * Sólo lo que se puede vender, y sólo de la sede del pedido.
             *
             * La hoja mandaba al alistador a buscar en AVERÍAS, BAJAS y
             * GARANTÍA —mercancía chatarrizada o guardada como prueba del
             * reclamo al proveedor— y a meterla en el pedido de un cliente.
             * Y como la consulta no miraba de qué bodega sale el pedido, a un
             * pedido de Bucaramanga le podía imprimir como primera opción un
             * estante de la bodega de otra ciudad.
             *
             * `bodegasDeLaSede()` devuelve la sede del pedido más sus
             * posiciones hijas: el rack pertenece a la sede, así que la
             * mercancía que ya se bajó a estantería sigue encontrándose.
             */
            ->where('u.disponible_para_venta', true)
            ->when($sedeDelPedido, fn ($q) => $q->whereIn('u.id', $sedeDelPedido))
            // `u.pasillo` es el RACK, y de ahi sale la zona operativa: la hoja
            // tiene que decir por que zona se camina y quien responde por ella.
            ->groupBy("m.{$campo}", 'u.id', 'u.codigo', 'u.nombre', 'u.categoria', 'u.pasillo', 'u.responsable_user_id')
            ->selectRaw("m.{$campo} as sujeto, u.id as ubicacion_id, u.codigo, u.nombre, u.categoria, u.pasillo, u.responsable_user_id, SUM(m.cantidad) as stock")
            ->having('stock', '>', 0)
            ->orderByDesc('stock')
            ->get();

        $saldosPorSujeto = [];
        foreach ([['variante_id', 'v'], ['producto_id', 'p']] as [$campo, $pre]) {
            $ids = $campo === 'variante_id'
                ? $p->items->pluck('variante_id')->filter()->unique()->values()
                : $p->items->whereNull('variante_id')->pluck('producto_id')->filter()->unique()->values();

            foreach ($saldos($campo, $ids) as $row) {
                $saldosPorSujeto["{$pre}:{$row->sujeto}"][] = [
                    // El id va porque desde la hoja se dispara el surtido
                    // («traer de Exhibición») y hay que decir de DÓNDE sale.
                    'id' => (int) $row->ubicacion_id,
                    'codigo' => $row->codigo,
                    'nombre' => $row->nombre,
                    'categoria' => $row->categoria,
                    'stock' => (int) $row->stock,
                    'rack' => $row->pasillo,
                    'zona' => Zona::deRack($row->pasillo),
                    // Si sale de aca, no es el recorrido normal: toca traslado.
                    'es_respaldo' => in_array((int) $row->ubicacion_id, $idsDeRespaldo, true),
                    'responsable_id' => $row->responsable_user_id ? (int) $row->responsable_user_id : null,
                ];
            }
        }


        /*
         * El orden en que se manda a buscar, aplicado una sola vez.
         *
         *   1. Lo apto para despacho primero (categoria «venta»).
         *   2. Entre esas, las POSICIONES antes que las bodegas enteras.
         *   3. Y el respaldo de surtido —Exhibicion— de ultimo, siempre: alla
         *      se va cuando los racks no alcanzan, no antes.
         *
         * El punto 2 es el que importa en bodega: «PPAL» no le dice a nadie
         * donde pararse, y «R5-S2-N3-D» si. Mientras la mercancia se este
         * pasando a posiciones va a haber de las dos, y ordenando solo por
         * stock la hoja mandaria siempre a la bodega entera —que es la que
         * acumula mas— y las 435 posiciones no servirian para nada.
         *
         * `usort` es estable desde PHP 8, asi que dentro de cada grupo se
         * conserva el orden por stock que trajo la consulta.
         */
        foreach ($saldosPorSujeto as &$lista) {
            usort($lista, function ($a, $b) {
                return [$a['es_respaldo'] ? 1 : 0, $a['categoria'] === 'venta' ? 0 : 1, $a['rack'] ? 0 : 1]
                   <=> [$b['es_respaldo'] ? 1 : 0, $b['categoria'] === 'venta' ? 0 : 1, $b['rack'] ? 0 : 1];
            });
        }
        unset($lista);

        return $saldosPorSujeto;
    }

    public function imprimir(Request $r, int $pedido): Response
    {
        $p = PedidoCliente::with([
            'items.variante:id,codigo_barras,color_nombre,talla,producto_id',
            'items.variante.producto:id,referencia,nombre',
            'contacto:id,nombre_completo,razon_social,ciudad,direccion,telefono',
            'factura:id,numero',
            'alistador:id,name',
            // El asesor que vendio: su nombre va en la hoja, para que la bodega
            // pueda preguntarle sin tener que averiguar quien fue.
            'vendedor:id,name',
        ])->findOrFail($pedido);

        // Un AdminBodega sólo imprime pedidos facturados · sanity check.
        abort_unless(in_array($p->estado, ['facturado', 'despachado', 'aprobado'], true), 422,
            'Sólo se imprime hoja de picking de pedidos aprobados/facturados.');

        /*
         * Y SÓLO DE SU BODEGA.
         *
         * Acá no se comprobaba de qué sede era el pedido: bastaba la casilla
         * `logistica` —que tienen AdminBodega, Alistador y Despachador— para
         * imprimir CUALQUIER pedido cambiando el número en la dirección. Y una
         * hoja de picking no es una lista de referencias: lleva el nombre del
         * cliente, su dirección, su teléfono y el nombre del asesor que vendió.
         *
         * Es el mismo criterio que ya aplica `oleada()` sobre los pedidos que
         * se mandan a armar, con el mismo método: quien ve el pedido en su
         * cola es quien puede imprimirlo. Va antes de buscar dónde está cada
         * ítem, para no gastar esas consultas en una hoja que no va a salir.
         */
        $misBodegas = \App\Auth\Permisos::esRoot($r->user()) ? null : $r->user()->bodegasQueVeIds();

        abort_if(
            $misBodegas !== null && $p->ubicacion_origen_id
                && ! in_array($p->ubicacion_origen_id, $misBodegas, true),
            403,
            "El pedido {$p->numero} es de otra bodega."
        );

        $saldosPorSujeto = $this->dondeEstaCadaItem($p);

        $items = $p->items->map(function ($it) use ($saldosPorSujeto) {
            $clave = $it->variante_id ? 'v:'.$it->variante_id : 'p:'.$it->producto_id;
            $saldos = $saldosPorSujeto[$clave] ?? [];
            /*
             * El orden en que la hoja manda a buscar.
             *
             *   1. Lo apto para despacho primero (categoría «venta»).
             *   2. Entre esas, las POSICIONES antes que las bodegas enteras.
             *   3. Y dentro de cada grupo, la de más stock primero.
             *
             * El punto 2 es el que importa en bodega: «PPAL» no le dice a
             * nadie dónde pararse, y «R5-S2-N3-D» sí. Mientras la mercancía
             * se esté pasando a posiciones va a haber de las dos, y si se
             * ordenara sólo por stock la hoja mandaría siempre a la bodega
             * entera —que es la que acumula más— y las 435 posiciones no
             * servirían para nada.
             *
             * `usort` es estable desde PHP 8, así que dentro de cada grupo se
             * conserva el orden por stock que trajo la consulta.
             */
            /*
             * LO QUE HAY QUE RECOGER ES LO QUE FALTA, NO LO QUE PIDIÓ EL CLIENTE.
             *
             * Decía `$it->cantidad` a secas. Con el despacho parcial eso manda
             * al alistador a recoger el pedido COMPLETO otra vez: salieron 4 de
             * 6 en el primer viaje y la hoja del segundo seguía pidiendo 6.
             *
             * En bodega eso termina de una de dos formas, las dos malas: o
             * recoge 6 y salen 10 de un pedido de 6, o se para frente al
             * estante contando y descubre que la hoja no cuadra con la caja que
             * ya se fue.
             */
            $yaSalio = (int) (float) ($it->cantidad_despachada ?? 0);
            $porRecoger = max(0, (int) $it->cantidad - $yaSalio);

            return [
                'sku' => $it->sku_snapshot,
                'descripcion' => $it->descripcion_snapshot,
                'cantidad' => $porRecoger,
                // Para que la hoja del segundo viaje pueda decir de dónde sale
                // ese número, en vez de mostrar uno más chico sin explicación.
                'cantidad_pedida' => (int) $it->cantidad,
                'ya_salio' => $yaSalio,
                'variante' => $it->variante ? [
                    'codigo_barras' => $it->variante->codigo_barras,
                    'color' => $it->variante->color_nombre,
                    'talla' => $it->variante->talla,
                ] : null,
                // Hasta 3 ubicaciones (en orden descendente de stock) para que
                //   el alistador tenga alternativas si una está vacía.
                'ubicaciones' => array_slice($saldos, 0, 3),
                'es_agregado' => !$it->variante_id && $it->producto_id,
                // Para disparar el surtido desde la hoja hay que decir QUÉ se
                // trae, y eso es el par producto/variante del renglón.
                'producto_id' => $it->producto_id ? (int) $it->producto_id : null,
                'variante_id' => $it->variante_id ? (int) $it->variante_id : null,
            ];
        })
            /*
             * Y las líneas que ya salieron enteras NO se imprimen.
             *
             * Dejarlas con un cero invita a recogerlas igual: quien camina con
             * la hoja lee el renglón, no la columna. Si el pedido vuelve por un
             * segundo viaje, la hoja es la de lo que falta.
             */
            ->filter(fn ($i) => $i['cantidad'] > 0)
            ->values();

        // LOG-J6 fix · si Don Jorge quiere horas automáticas, usamos las que
        //   el ERP ya registró en la Cola de alistamiento (asignación, inicio,
        //   fin). Si todavía no arrancó, mostramos la hora de impresión como
        //   "preparado a las ..." para que la trazabilidad quede completa.
        //   El quien-imprime se saca del user autenticado — si no hay un
        //   alistador asignado todavía, él queda como responsable por default.
        $usuario = $r->user();

        /*
         * Por que zona se camina, y si hay que caminar dos.
         *
         * El area reparte la bodega en tres zonas con un responsable cada una,
         * y su lista de picking trae las casillas «ZONA», «RESPONSABLE DE LA
         * ZONA» y «COMPARTIDO CON ZONA». Esa ultima es la que importa: un
         * pedido que toca racks de dos zonas necesita dos personas o un
         * recorrido el doble de largo, y eso hay que saberlo ANTES de empezar,
         * no a mitad de camino.
         *
         * No se mira solo la PRIMERA ubicacion de cada item: se miran las que
         * de verdad hay que visitar. Si el item pide 50 unidades y la primera
         * ubicacion tiene 12, el alistador va a tener que ir tambien a la
         * segunda; contar solo la primera imprimiria «todo sale de la zona A»
         * y lo mandaria a la C sin avisarle, que es justo lo que esta casilla
         * existe para evitar.
         *
         * Las ubicaciones vienen ordenadas con lo vendible primero y de mayor
         * a menor stock, asi que se acumulan en ese orden hasta cubrir la
         * cantidad del item.
         */
        /*
         * El reparto vive en `RepartoPorUbicacion` y no acá: la hoja del pedido
         * y la de la oleada tienen que caminar igual, y dos copias de este
         * bucle se desincronizan sin que nadie lo note hasta que el papel y el
         * estante dejan de coincidir.
         */
        $visitadas = $items->flatMap(fn ($it) => RepartoPorUbicacion::paradas($it));

        /*
         * Cuál es LA zona del pedido, cuando toca más de una.
         *
         * La que más unidades aporta, no la primera del plano: un pedido que
         * saca 19 unidades de la C y 1 de la A es un pedido de la zona C
         * compartido con la A, y al revés la hoja le pondría el nombre del
         * responsable equivocado y mandaría a la persona que casi no trabaja
         * en él.
         */
        /*
         * OJO CON EL TIPO DE LA CLAVE.
         *
         * Las zonas de fábrica se llaman «1», «2» y «3» —así las entregó el
         * área— y PHP convierte toda clave de array que parezca un entero en
         * un entero. Así que `$porZona['1']` guarda bajo `1`, y el `===` de más
         * abajo comparaba el string «1» que devuelve `Zona::deRack()` contra el
         * int 1 de `array_keys()`: SIEMPRE false.
         *
         * Consecuencia, comprobada el 22-nov-2026: la hoja de picking nunca
         * imprimía el nombre real del responsable. Siempre caía al genérico
         * «Responsable 1», que es justo lo que el comentario de abajo explica
         * que NO debe pasar. Y sólo ocurre con zonas de nombre numérico, que es
         * exactamente la configuración de fábrica.
         *
         * Por eso todo lo que toque estas claves se normaliza a texto.
         */
        $porZona = [];

        foreach ($visitadas as $u) {
            $zona = isset($u['zona']) ? (string) $u['zona'] : '';

            if ($zona !== '') {
                // Se pesa con lo que SALE de esa ubicación, no con lo que hay.
                $porZona[$zona] = ($porZona[$zona] ?? 0) + (int) ($u['toma'] ?? 0);
            }
        }

        // A igualdad de unidades manda el orden del plano, para que la misma
        // hoja impresa dos veces diga siempre lo mismo.
        $orden = array_flip(array_map('strval', Zona::letras()));
        uksort($porZona, fn ($a, $b) => [-$porZona[(string) $a], $orden[(string) $a] ?? 99]
            <=> [-$porZona[(string) $b], $orden[(string) $b] ?? 99]);

        $zonas = array_map('strval', array_keys($porZona));
        $principal = $zonas[0] ?? null;
        $compartidas = array_slice($zonas, 1);

        /*
         * Quien responde por la zona sale de la ubicacion, no de una cadena
         * armada aca.
         *
         * El area los identifica como «Responsable 1/2/3» mientras no tengan
         * nombre propio, pero eso es el nombre POR DEFECTO de unas cuentas que
         * se pueden renombrar desde la pantalla de usuarios. Si la hoja lo
         * armara sola —«Responsable 2»— el dia que esas cuentas tengan nombre
         * la hoja seguiria imprimiendo el numero y nadie sabria a quien
         * buscar. Asi que se lee el responsable real de la ubicacion, y solo
         * si nadie responde por ella se cae al numero de la zona.
         */
        /*
         * Se resuelve para CUALQUIER zona, no sólo la principal: cuando la hoja
         * se parte, cada sección lleva impreso el nombre de quien responde por
         * ella. Imprimir el de la zona principal en todas las bandas sería peor
         * que no imprimir ninguno.
         */
        $responsableDe = function (?string $zona) use ($visitadas): ?string {
            if ($zona === null || $zona === '') {
                return null;
            }

            // Las dos partes a texto: ver la nota sobre las claves de arriba.
            $id = $visitadas->first(
                fn ($u) => (string) ($u['zona'] ?? '') === $zona && ! empty($u['responsable_id'])
            )['responsable_id'] ?? null;

            return ($id ? \App\Models\User::whereKey($id)->value('name') : null)
                ?? 'Responsable '.Zona::numero($zona);
        };

        $responsable = $responsableDe($principal);

        /*
         * El telefono del asesor sale de su ficha de Gestion Humana.
         *
         * La tabla `users` no guarda telefono —se reviso— y agregarle una
         * columna seria tener el mismo dato en dos lados: el que la empresa
         * mantiene de verdad es el de la ficha del empleado, que es el que
         * actualiza Gestion Humana cuando alguien cambia de numero. Si el
         * asesor no tiene ficha, la hoja imprime el nombre sin telefono en vez
         * de inventarse uno.
         */
        $telefonoAsesor = $p->vendedor_id
            ? \App\Modules\Rrhh\Models\Empleado::where('user_id', $p->vendedor_id)->value('telefono')
            : null;

        /*
         * LA HOJA PARTIDA POR ZONA.
         *
         * El procedimiento del área dice «picking por zonas», y hasta acá la
         * hoja sólo lo decía en una casilla: se imprimía UNA lista con los
         * renglones de las tres zonas mezclados y la banda rotulada con la
         * principal. Con eso, un pedido compartido se alista de dos maneras, y
         * las dos malas: una persona lo camina entero —justo lo que el reparto
         * por zonas existe para evitar— o se fotocopia la hoja y cada quien
         * tacha a ojo los renglones que cree suyos.
         *
         * Lo que se parte es el RENGLÓN POR ZONA, no el renglón entero: una
         * línea de 50 que saca 48 de la zona 1 y 2 de la zona 3 sale en las dos
         * secciones, con 48 en una y 2 en la otra. Partir por «dónde hay stock»
         * pondría 50 en cada una y entre las dos personas recogerían 100.
         *
         * Las unidades que ningún estante cubre —exhibición, racks sin zona o
         * mercancía que no está— NO se caen de la hoja: van a una sección final
         * con su motivo. Si se perdieran, la suma de las secciones dejaría de
         * dar el total y nadie lo notaría hasta que falte mercancía en la caja.
         */
        $porSeccion = [];
        $sinUbicacion = [];

        foreach ($items as $it) {
            foreach (RepartoPorUbicacion::paradas($it) as $u) {
                $toma = (int) ($u['toma'] ?? 0);

                if ($toma <= 0) {
                    continue;
                }

                // Un rack que no está en el reparto de zonas va al final, no a
                // una zona inventada: mandar a caminar a donde no es cuesta más
                // que decir «este no tiene zona».
                $zona = isset($u['zona']) && (string) $u['zona'] !== '' ? (string) $u['zona'] : null;

                if ($zona === null) {
                    /*
                     * Sin zona NO quiere decir sin sitio. El caso mayoritario
                     * mientras la bodega migra a posiciones es la mercancía
                     * parada en la FILA DE LA BODEGA, que no tiene rack: esa
                     * ubicación hay que imprimirla igual, o el alistador lee
                     * «no está asignado a ninguna zona» y no sabe a dónde ir.
                     */
                    $sinUbicacion[] = ['item' => $it, 'cantidad' => $toma, 'motivo' => 'sin_zona', 'ubicacion' => $u];

                    continue;
                }

                $porSeccion[$zona] ??= [];
                $clave = $it['sku'].'|'.($it['variante_id'] ?? '').'|'.($it['producto_id'] ?? '');

                if (isset($porSeccion[$zona][$clave])) {
                    // Dos estantes de la MISMA zona para el mismo renglón: una
                    // sola línea con las dos ubicaciones, no dos renglones.
                    $porSeccion[$zona][$clave]['cantidad'] += $toma;
                    $porSeccion[$zona][$clave]['ubicaciones'][] = $u;
                } else {
                    $porSeccion[$zona][$clave] = array_merge($it, [
                        'cantidad' => $toma,
                        'ubicaciones' => [$u],
                    ]);
                }
            }

            if ($resto = RepartoPorUbicacion::sinCubrir($it)) {
                $enVitrina = collect($it['ubicaciones'])->first(fn ($u) => ! empty($u['es_respaldo']));

                /*
                 * «Sin stock» sólo cuando de verdad no hay dónde buscar.
                 *
                 * La lista de ubicaciones viene recortada a las tres primeras
                 * (`array_slice($saldos, 0, 3)`), así que una referencia
                 * repartida en cinco racks puede quedar sin cubrir aunque haya
                 * mercancía de sobra en el cuarto. Decirle «sin stock» al
                 * alistador le hace reportar un faltante que no existe, y un
                 * faltante abre novedad y retiene el pedido.
                 */
                $hayMas = count($it['ubicaciones']) >= 3;

                $sinUbicacion[] = [
                    'item' => $it,
                    'cantidad' => $resto,
                    'motivo' => match (true) {
                        $enVitrina !== null => 'exhibicion',
                        $hayMas => 'buscar_mas',
                        default => 'sin_stock',
                    },
                    'ubicacion' => $enVitrina,
                ];
            }
        }

        // Las secciones salen en el mismo orden que ya manda la banda: primero
        // la que más aporta, y a igualdad, el orden del plano.
        $secciones = [];

        foreach ($zonas as $z) {
            $filas = array_values($porSeccion[$z] ?? []);

            if ($filas === []) {
                continue;
            }

            $secciones[] = [
                'zona' => $z,
                'responsable' => $responsableDe($z),
                'items' => $filas,
                'unidades' => array_sum(array_column($filas, 'cantidad')),
            ];
        }

        if ($sinUbicacion !== []) {
            $secciones[] = [
                'zona' => null,
                'responsable' => null,
                'items' => array_map(fn (array $f) => array_merge($f['item'], [
                    'cantidad' => $f['cantidad'],
                    'motivo' => $f['motivo'],
                    'ubicaciones' => $f['ubicacion'] ? [$f['ubicacion']] : [],
                ]), $sinUbicacion),
                'unidades' => array_sum(array_column($sinUbicacion, 'cantidad')),
            ];
        }

        /*
         * Reimprimir UNA sección.
         *
         * Pasa todo el tiempo: la hoja de la zona 2 se moja, se traspapela o la
         * persona entra al turno después. Reimprimir el pedido entero para eso
         * desperdicia papel y, peor, pone a circular dos copias de las otras
         * secciones — y nadie sabe cuál se caminó.
         *
         * Se valida contra las zonas de ESTE pedido y se corta con 422. Filtrar
         * en silencio dejaría salir una hoja con el membrete completo y cero
         * renglones: quien la recibe cree que no hay nada que recoger.
         */
        /*
         * El parámetro se normaliza antes de tocarlo: `?zona[]=1` llega como
         * ARRAY y `(string)` sobre un array es un error fatal —la hoja salía
         * con 500 en vez del 422 que este bloque cree devolver—. Y `?zona=%20`
         * queda en cadena vacía: eso NO es pedir una zona, es no pedir
         * ninguna, así que la hoja sale completa como siempre.
         */
        $pedida = $r->query('zona');
        $soloZona = is_array($pedida) ? '' : trim((string) ($pedida ?? ''));

        if ($soloZona !== '') {
            $elegida = collect($secciones)->first(fn ($s) => (string) ($s['zona'] ?? '') === $soloZona);

            abort_if($elegida === null, 422, sprintf(
                'El pedido %s no tiene nada que recoger en la zona %s. Sus zonas son: %s.',
                $p->numero, $soloZona, $zonas ? implode(', ', $zonas) : '(ninguna)'
            ));

            $secciones = [$elegida];
        }

        return Inertia::render('Logistica/HojaPicking', [
            'pedido' => [
                'id' => $p->id,
                'numero' => $p->numero,
                'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
                'ciudad' => $p->contacto?->ciudad,
                'direccion' => $p->contacto?->direccion,
                'telefono' => $p->contacto?->telefono,
                'factura' => $p->factura?->numero,
                'alistador' => $p->alistador?->name,
                'alistador_asignado_at' => $p->alistado_asignado_at?->format('Y-m-d H:i'),
                'alistado_inicio_at' => $p->alistado_inicio_at?->format('Y-m-d H:i'),
                'alistado_fin_at' => $p->alistado_fin_at?->format('Y-m-d H:i'),
                'impreso_por' => $usuario?->name,
                'total' => (float) $p->total,
                'generado_at' => now()->format('Y-m-d H:i'),

                // Los campos de la lista que entrego el area (17-nov-2026).
                'numero_picking' => 'PCK-'.str_pad((string) $p->id, 6, '0', STR_PAD_LEFT),
                'zona' => $principal,
                'responsable_zona' => $responsable,
                'compartido_con' => $compartidas,
                'asesor' => $p->vendedor?->name,
                'asesor_telefono' => $telefonoAsesor,
                /*
                 * ¿Esta hoja es de un segundo viaje?
                 *
                 * Va impreso y en grande. Una hoja que pide 2 de un pedido de 6
                 * sin explicar por qué parece una hoja mal generada, y lo
                 * primero que hace quien la recibe es ir a buscar las otras 4.
                 */
                'viaje_anterior' => (bool) $p->despacho_parcial,
                'ya_despachado' => (int) $p->items->sum('cantidad_despachada'),
                'total_pedido' => (int) $p->items->sum('cantidad'),
                /*
                 * La casilla donde se consolida, impresa en cada sección.
                 *
                 * El ERP la asignaba desde la primera oleada y no se imprimía
                 * en ninguna parte: quien terminaba su zona se quedaba con la
                 * mercancía en la mano preguntando dónde dejarla. Con la hoja
                 * partida es peor —son dos o tres personas llegando por
                 * separado— así que va en la banda de cada una.
                 */
                'casilla' => $p->casilla_consolidacion,
            ],
            /*
             * `items` sigue siendo la lista PLANA de todo el pedido, y se
             * queda. Es lo que mira quien quiere el pedido completo de un
             * vistazo, es el respaldo si el reparto por zonas no está
             * configurado, y es sobre lo que cuentan los tests. `secciones` se
             * agrega al lado: no la reemplaza.
             */
            'items' => $items,
            'secciones' => $secciones,
            // Para el botón de reimprimir una sola: la hoja ya sabe cuál está
            // mostrando sin tener que mirar la URL.
            'zona_filtrada' => $soloZona ?: null,
        ]);
    }


    /**
     * La hoja de una OLEADA: varios pedidos recogidos de una sola pasada.
     *
     * El procedimiento del area pone tres pasos —picking por zonas,
     * consolidacion y packing— y esta es la herramienta del primero y el
     * segundo. Hasta aca el sistema sabia alistar UN pedido: con cien en cola
     * eso son cien recorridos, y una referencia que este en la mitad de las
     * facturas son cincuenta viajes al mismo estante.
     *
     * La hoja trae las dos caras del proceso:
     *   · RECOGER, por ubicacion y en el orden de la bodega, con el total de
     *     todos los pedidos juntos.
     *   · REPARTIR, por pedido, diciendo en que casilla de la zona de
     *     alistamiento se arma cada uno.
     *
     * Las casillas se asignan al abrir la hoja y quedan guardadas: si alguien
     * la reimprime, cada pedido sigue en el mismo sitio del piso. Sin eso la
     * segunda impresion mandaria a armar el mismo pedido en otra casilla.
     */
    public function oleada(Request $r): Response
    {
        $u = $r->user();

        $ids = collect(explode(',', (string) $r->query('pedidos', '')))
            ->map(fn ($x) => (int) trim($x))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 422, 'No se eligio ningun pedido para la oleada.');

        $caben = \App\Modules\Logistica\Support\ZonaDeAlistamiento::cuantasCaben();

        abort_if($ids->count() > $caben, 422,
            "La zona de alistamiento tiene {$caben} casillas: no se pueden armar mas de {$caben} pedidos a la vez. "
            .'Si el area agrando la zona, se ajusta en Reglas.');

        $pedidos = PedidoCliente::with([
            'items.variante:id,codigo_barras,color_nombre,talla,producto_id',
            'contacto:id,nombre_completo,razon_social,ciudad',
            'factura:id,numero',
        ])->whereIn('id', $ids)->get();

        abort_if($pedidos->isEmpty(), 404, 'Esos pedidos ya no existen.');

        /*
         * Solo pedidos que de verdad se pueden alistar, y solo de SU bodega.
         *
         * Sin esto, pasar ids a mano en la direccion armaria una oleada con
         * pedidos de otra sede o que todavia no estan facturados.
         */
        $misBodegas = \App\Auth\Permisos::esRoot($u) ? null : $u->bodegasQueVeIds();

        foreach ($pedidos as $p) {
            abort_unless(in_array($p->estado, ['facturado', 'despachado', 'aprobado'], true), 422,
                "El pedido {$p->numero} no esta listo para alistar.");

            abort_if(
                $misBodegas !== null && $p->ubicacion_origen_id && ! in_array($p->ubicacion_origen_id, $misBodegas, true),
                403,
                "El pedido {$p->numero} es de otra bodega."
            );
        }

        $this->repartirCasillas($pedidos);

        $ubicaciones = [];

        foreach ($pedidos as $p) {
            $ubicaciones[$p->id] = $this->dondeEstaCadaItem($p);
        }

        $oleada = app(\App\Modules\Logistica\Support\OleadaDeAlistamiento::class)->armar($pedidos, $ubicaciones);

        return Inertia::render('Logistica/HojaOleada', [
            'oleada' => $oleada,
            'generado_at' => now()->format('Y-m-d H:i'),
            'impreso_por' => $u?->name,
        ]);
    }

    /**
     * Le da a cada pedido su casilla en la zona de alistamiento.
     *
     * El que ya tenga una se la queda: reimprimir la hoja no puede mover un
     * pedido que ya esta a medio armar en el piso. Los demas toman las libres
     * en orden, sin pisar las de otras oleadas en curso.
     */
    private function repartirCasillas($pedidos): void
    {
        $zona = \App\Modules\Logistica\Support\ZonaDeAlistamiento::class;

        // Las que estan en uso ahora mismo, en cualquier oleada.
        $ocupadas = PedidoCliente::whereNotNull('casilla_consolidacion')
            ->whereNull('despachado_at')
            ->pluck('casilla_consolidacion')
            ->all();

        $libres = $zona::libres($ocupadas);

        foreach ($pedidos as $p) {
            if ($zona::existe($p->casilla_consolidacion)) {
                continue;
            }

            $casilla = array_shift($libres);

            if ($casilla === null) {
                // No deberia pasar —el tope se valido arriba— pero si la zona
                // esta llena de oleadas viejas sin despachar, se dice en vez
                // de dejar pedidos sin sitio en silencio.
                abort(422, 'No quedan casillas libres en la zona de alistamiento: '
                    .'hay pedidos de otras oleadas todavia sin despachar.');
            }

            $p->forceFill(['casilla_consolidacion' => $casilla])->save();
        }
    }

    public function finalizar(Request $r, int $pedido): RedirectResponse
    {
        $data = $r->validate([
            'notas' => ['nullable', 'string', 'max:500'],
            // sin_novedad · faltante · averia · revision · otro
            'tipo_novedad' => ['required', 'in:sin_novedad,faltante,averia,revision,otro'],
        ]);
        // Si marcó cualquier novedad != sin_novedad, obligamos a describirla
        //   en notas — "faltante" sin detalle no sirve para nada.
        if ($data['tipo_novedad'] !== 'sin_novedad' && empty(trim((string) ($data['notas'] ?? '')))) {
            abort(422, 'Si marcás una novedad, describí qué pasó en las notas.');
        }

        $u = $r->user();
        $pedidoFresco = null;

        DB::transaction(function () use ($u, $pedido, $data, &$pedidoFresco) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless($p->alistado_inicio_at, 422, 'No se puede terminar lo que no se inició.');
            abort_if($p->alistado_fin_at, 422, 'Este pedido ya está terminado.');
            if ($p->alistador_id !== $u->id && ! ($u->esAracely() || $u->esAdminBodega())) {
                abort(403, 'Sólo el alistador asignado puede terminar.');
            }

            $conNovedad = $data['tipo_novedad'] !== 'sin_novedad';

            $p->update([
                'alistado_fin_at' => now(),
                'alistado_notas' => $data['notas'] ?? null,
                'alistado_con_novedad' => $conNovedad,
                'alistado_tipo_novedad' => $conNovedad ? $data['tipo_novedad'] : null,
            ]);
            $pedidoFresco = $p;
        });

        // LOG-J5-fix · si cerró con novedad, alertamos broadcast — gerencia y
        //   compras lo ven en la bell y entran a la Cola Jorge columna 4 a
        //   decidir qué hacer antes de que llegue al empaque.
        if ($pedidoFresco && $pedidoFresco->alistado_con_novedad) {
            $tipos = [
                'faltante' => 'FALTANTE',
                'averia'   => 'MERCANCÍA AVERIADA',
                'revision' => 'REQUIERE REVISIÓN',
                'otro'     => 'novedad',
            ];
            $etiqueta = $tipos[$pedidoFresco->alistado_tipo_novedad] ?? 'novedad';
            \App\Models\NotificacionErp::crear([
                'tipo' => 'pedido_novedad_alistamiento',
                'titulo' => "⚠ Pedido {$pedidoFresco->numero} con {$etiqueta}",
                'mensaje' => \Illuminate\Support\Str::limit($pedidoFresco->alistado_notas ?? '', 160),
                'color' => 'warning',
                'icono' => 'heroicon-o-exclamation-triangle',
                'url' => '/app/logistica/cola-jorge',
            ]);
            return back()->with('warning',
                "Pedido cerrado con {$etiqueta}. NO va a empaque — queda esperando resolución.");
        }

        return back()->with('success', 'Alistamiento terminado · pedido listo para empaque.');
    }

    /**
     * LOG-J5-fix · Gerencia/admin de bodega resuelve la novedad (reemplazo,
     * reposición de compras, o se decide despachar como está). Al resolver
     * el pedido fluye a "Listos para empaque" y el despacho se desbloquea.
     */
    public function resolverNovedad(Request $r, int $pedido): RedirectResponse
    {
        $data = $r->validate([
            'resolucion' => ['required', 'string', 'min:10', 'max:300'],
        ]);
        $u = $r->user();
        abort_unless($u->esAracely() || $u->esAdminBodega(), 403,
            'Sólo Gerencia o admin de bodega resuelve novedades.');

        DB::transaction(function () use ($u, $pedido, $data) {
            $p = PedidoCliente::whereKey($pedido)->lockForUpdate()->firstOrFail();
            abort_unless($p->alistado_con_novedad && !$p->novedad_resuelta_at, 422,
                'Este pedido no tiene novedad abierta.');
            $p->update([
                'novedad_resuelta_at' => now(),
                'novedad_resuelta_por_id' => $u->id,
                'novedad_resolucion' => $data['resolucion'],
            ]);
        });

        return back()->with('success', 'Novedad resuelta · pedido liberado al empaque.');
    }
}
