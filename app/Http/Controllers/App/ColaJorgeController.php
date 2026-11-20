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
             * Las cuentas que sólo marcan una zona no alistan nada.
             *
             * «Responsable 1/2/3» existen para decir quién responde por cada
             * zona de la bodega, y nacen sin contraseña utilizable: nadie
             * entra con ellas, y el ERP no tiene recuperación de clave para
             * este guard. Llevan rol Alistador porque el sistema exige papel
             * de bodega para ser responsable de una ubicación, así que caían
             * en esta lista.
             *
             * Asignarle un pedido a una de ellas lo PARQUEA: queda a nombre de
             * alguien que no puede entrar a tomarlo, y el alistador de verdad
             * recibe 403 «este pedido es de otro» si intenta iniciarlo. El
             * pedido sólo se desatasca reasignándolo desde acá.
             */
            ->whereNotIn('email', Zona::correosDeEtiqueta())
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
            return [
                'sku' => $it->sku_snapshot,
                'descripcion' => $it->descripcion_snapshot,
                'cantidad' => (int) $it->cantidad,
                'variante' => $it->variante ? [
                    'codigo_barras' => $it->variante->codigo_barras,
                    'color' => $it->variante->color_nombre,
                    'talla' => $it->variante->talla,
                ] : null,
                // Hasta 3 ubicaciones (en orden descendente de stock) para que
                //   el alistador tenga alternativas si una está vacía.
                'ubicaciones' => array_slice($saldos, 0, 3),
                'es_agregado' => !$it->variante_id && $it->producto_id,
            ];
        })->values();

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
        $visitadas = $items->flatMap(function ($it) {
            $faltan = (int) $it['cantidad'];
            $usadas = [];

            foreach ($it['ubicaciones'] as $u) {
                // Exhibicion no es una zona del recorrido: ir alla es una
                // excepcion con traslado, no un estante mas que caminar. Si
                // entrara, la hoja diria «compartido con zona» por algo que no
                // es una zona.
                if ($u['es_respaldo']) {
                    continue;
                }

                $usadas[] = $u;
                $faltan -= (int) $u['stock'];

                if ($faltan <= 0) {
                    break;
                }
            }

            return $usadas;
        });

        /*
         * Cuál es LA zona del pedido, cuando toca más de una.
         *
         * La que más unidades aporta, no la primera del plano: un pedido que
         * saca 19 unidades de la C y 1 de la A es un pedido de la zona C
         * compartido con la A, y al revés la hoja le pondría el nombre del
         * responsable equivocado y mandaría a la persona que casi no trabaja
         * en él.
         */
        $porZona = [];

        foreach ($visitadas as $u) {
            if ($u['zona'] ?? null) {
                $porZona[$u['zona']] = ($porZona[$u['zona']] ?? 0) + (int) $u['stock'];
            }
        }

        // A igualdad de unidades manda el orden del plano, para que la misma
        // hoja impresa dos veces diga siempre lo mismo.
        $orden = array_flip(Zona::letras());
        uksort($porZona, fn ($a, $b) => [-$porZona[$a], $orden[$a] ?? 99] <=> [-$porZona[$b], $orden[$b] ?? 99]);

        $zonas = array_keys($porZona);
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
        $responsable = null;

        if ($principal !== null) {
            $id = $visitadas->firstWhere(fn ($u) => ($u['zona'] ?? null) === $principal)['responsable_id'] ?? null;

            $responsable = $id
                ? \App\Models\User::whereKey($id)->value('name')
                : null;

            $responsable ??= 'Responsable '.Zona::numero($principal);
        }

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
            ],
            'items' => $items,
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
