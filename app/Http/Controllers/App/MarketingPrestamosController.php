<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTraslado;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Marketing\Services\MuestrasMarketing;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LOG-J9 · Panel del rol Marketing con permisos reducidos.
 *
 * Marketing pide productos prestados a bodega para fotos/videos/promoción y
 * los devuelve cuando termina. En vez de un papel en el escritorio, los
 * movimientos quedan como traslados con huella en el kardex.
 *
 * Marketing NO ve/edita precios, inventario comercial ni cartera. Solo
 * mira sus traslados abiertos (lo que le queda por devolver), su almacén y
 * crea nuevas solicitudes de préstamo / devolución (que Aracely o el admin
 * de bodega aprueba desde /app/inventario/traslados).
 */
class MarketingPrestamosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            abort_unless($u && ($u->esAracely() || $u->esMarketing()), 403,
                'Pantalla exclusiva del equipo de Marketing.');
            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        $misBodegas = $this->almacenesMarketing()->get(['id', 'codigo', 'nombre', 'ciudad'])->toArray();
        $ids = array_column($misBodegas, 'id');

        /*
         * Movimientos de/hacia los almacenes de Marketing.
         *
         * El mapeo de antes mandaba `origen_id`, `destino_id`, `solicitante` y
         * `fecha_ejecucion`, mientras la tabla pintaba `origen`, `destino`,
         * `solicitado_por`, `fecha`, `motivo` e `items_count`: casi ninguna
         * columna coincidía y la pantalla mostraba filas en blanco con una
         * flecha en el medio.
         *
         * Y el `estado` viajaba como objeto del enum, así que el
         * `in_array($p['estado'], ['borrador', 'ejecutado'])` que repartía
         * entre abiertos y cerrados **nunca** daba verdadero: todo caía en
         * «Devueltos» con chip verde y «Prestado ahora» marcaba 0 siempre.
         * De paso, 'ejecutado' ni siquiera es un estado del enum
         * (borrador · en_transito · recibido · anulado).
         */
        $prestamos = Traslado::query()
            ->where(fn ($q) => $q->whereIn('destino_id', $ids)->orWhereIn('origen_id', $ids))
            // `solicitante` es la RELACIÓN belongsTo(User,'solicitado_por'),
            // no una columna · pedirla en el select tumbaba la pantalla con
            // "Unknown column 'solicitante'".
            ->with(['solicitante:id,name', 'origen:id,codigo', 'destino:id,codigo',
                    'aprobador:id,name', 'enviador:id,name', 'recogedor:id,name', 'prestamo:id,numero'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'numero', 'origen_id', 'destino_id', 'estado', 'motivo',
                   'solicitado_por', 'fecha_solicitud', 'fecha_ejecucion', 'observaciones', 'created_at',
                   'aprobado_por', 'aprobado_at', 'enviado_por', 'recogido_por', 'estado_mercancia',
                   'observaciones_entrega', 'prestamo_id'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'numero' => $t->numero,
                'origen' => $t->origen?->codigo,
                'destino' => $t->destino?->codigo,
                'motivo' => $t->motivo,
                'items_count' => (int) $t->items_count,
                'solicitado_por' => $t->solicitante?->name,
                'estado' => $t->estado?->value,
                'estado_label' => $t->estado?->label(),
                'fecha' => $t->fecha_solicitud?->format('Y-m-d') ?? $t->created_at?->format('Y-m-d'),
                'ejecutado_at' => $t->fecha_ejecucion?->format('Y-m-d'),
                'observaciones' => $t->observaciones,
                // Hacia el almacén de Marketing = salida de bodega (préstamo);
                // desde el almacén = devolución.
                'es_prestamo' => in_array($t->destino_id, $ids, true),
                // MKT-5 · el paso en palabras de Marketing y quién firmó cada uno.
                'paso' => $this->paso($t, in_array($t->destino_id, $ids, true)),
                'aprobado_por' => $t->aprobador?->name,
                'entregado_por' => $t->enviador?->name,
                'recogido_por' => $t->recogedor?->name,
                'estado_mercancia' => $t->estado_mercancia,
                'estado_mercancia_label' => Traslado::ESTADOS_MERCANCIA[$t->estado_mercancia] ?? null,
                'observaciones_entrega' => $t->observaciones_entrega,
                'prestamo' => $t->prestamo?->numero,
            ])
            ->toArray();

        $vivos = ['borrador', 'en_transito', 'recibido'];

        // En curso: lo que pidió y todavía no volvió a bodega. La devolución
        // es un traslado aparte, así que lo que cierra el ciclo es que la
        // mercancía ya no esté en el almacén — y eso lo dice el kardex, no la
        // lista de traslados. Por eso debajo va «lo que tengo ahora».
        $abiertos = array_values(array_filter(
            $prestamos,
            fn ($p) => $p['es_prestamo'] && in_array($p['estado'], $vivos, true)
        ));
        $cerrados = array_values(array_filter($prestamos, fn ($p) => ! in_array($p, $abiertos, true)));

        return Inertia::render('Marketing/Panel', [
            'mis_bodegas' => $misBodegas,
            'prestamos' => [
                'abiertos' => $abiertos,
                'cerrados' => array_slice($cerrados, 0, 20),
                'total_abiertos' => count($abiertos),
            ],
            // Lo que de verdad sigue en poder de Marketing: el saldo del
            // kardex en sus almacenes. Es la única cuenta que no depende de
            // emparejar cada préstamo con su devolución, cosa que el modelo
            // no guarda en ningún lado.
            'en_mi_almacen' => $this->enMiAlmacen($ids),
            'bodegas_comerciales' => $this->bodegasComerciales()->get(['id', 'codigo', 'nombre'])->toArray(),
            'es_super' => (bool) $r->user()?->esAracely(),
            // Préstamos ya entregados, para decir qué cierra una devolución.
            'para_devolver' => array_values(array_map(
                fn ($p) => ['id' => $p['id'], 'numero' => $p['numero'], 'observaciones' => $p['observaciones']],
                array_filter($prestamos, fn ($p) => $p['es_prestamo'] && $p['estado'] === 'recibido'),
            )),
        ]);
    }

    /**
     * MKT-5 · en qué va la solicitud, dicho como lo vive Marketing. El estado
     * del traslado (borrador, en tránsito…) es el de bodega y no lo dice:
     * «borrador» no distingue entre «nadie la ha mirado» y «ya la aprobaron».
     */
    private function paso(Traslado $t, bool $esPrestamo): string
    {
        return match ($t->estado) {
            EstadoTraslado::Borrador => ! $esPrestamo ? 'Por recibir en bodega'
                : ($t->aprobado_at ? 'Aprobada · bodega alistando' : 'Por aprobar'),
            EstadoTraslado::EnTransito => $esPrestamo ? 'Lista para recoger' : 'En camino a bodega',
            EstadoTraslado::Recibido => $esPrestamo ? 'Entregada' : 'Recibida en bodega',
            EstadoTraslado::Anulado => 'Rechazada o anulada',
            default => $t->estado?->label() ?? '—',
        };
    }

    /**
     * Saldo vivo en los almacenes de Marketing, por producto.
     *
     * @param  list<int>  $ids
     * @return list<array{nombre: string, sku: string, cantidad: int}>
     */
    private function enMiAlmacen(array $ids): array
    {
        if (! $ids) return [];

        return \App\Modules\Dropi\Models\InventarioMovimiento::query()
            ->whereIn('ubicacion_id', $ids)
            // `producto_variantes` no tiene `sku` ni `color`: pedirlas tumbaba el
            // panel con 500 en cuanto Marketing tenía algo en su almacén, o sea
            // justo después del primer préstamo entregado.
            ->with(['variante:id,codigo_barras,producto_id,color_nombre,talla', 'variante.producto:id,nombre',
                    'producto:id,referencia,nombre'])
            ->get(['id', 'variante_id', 'producto_id', 'cantidad'])
            ->groupBy(fn ($m) => $m->variante_id ? 'v:'.$m->variante_id : 'p:'.$m->producto_id)
            ->map(function ($movs) {
                $m = $movs->first();
                $v = $m->variante;

                return [
                    'nombre' => $v?->producto?->nombre ?? $m->producto?->nombre ?? '—',
                    'sku' => $v?->codigo_barras ?? $m->producto?->referencia ?? '—',
                    'detalle' => trim(($v?->color_nombre ?? '').' '.($v?->talla ?? '')) ?: null,
                    'cantidad' => (int) $movs->sum('cantidad'),
                ];
            })
            ->filter(fn ($f) => $f['cantidad'] > 0)
            ->sortByDesc('cantidad')
            ->values()
            ->all();
    }

    /**
     * Solicitud de préstamo (o de devolución) hecha por Marketing.
     *
     * Hasta el 2026-10-09 el panel tenía dos botones de "acciones rápidas" que
     * apuntaban los dos a `/app/inventario/traslados` — pantalla que para el
     * rol Marketing responde **403**. Debajo, un texto le pedía a la persona
     * que fuera allá y eligiera a mano origen, destino y motivo. O sea: la
     * única acción del panel no se podía hacer, y el rol entero quedaba mirando
     * una lista de préstamos que nadie podía originar desde ahí.
     *
     * No se arregla abriéndole Inventario: esa pantalla lista los traslados de
     * toda la empresa y Marketing no tiene por qué verlos. Se arregla acá, que
     * es justo lo que decía el diseño del módulo — Marketing crea la solicitud
     * en borrador y bodega la surte y la ejecuta.
     *
     * El traslado nace sin ítems a propósito: Marketing pide "los bodies de
     * verano para las fotos del viernes" en las observaciones, y quien tiene la
     * mercancía al frente decide qué referencias entran.
     */
    public function prestamoCrear(Request $r): \Illuminate\Http\RedirectResponse
    {
        $data = $r->validate([
            'origen_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'destino_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id', 'different:origen_id'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'prestamo_id' => ['nullable', 'integer', 'exists:traslados_inventario,id'],
        ], [], [
            'origen_id' => 'bodega de origen',
            'destino_id' => 'bodega de destino',
        ]);

        // Una de las dos puntas tiene que ser un almacén de Marketing y la otra
        // una bodega de venta. Sin esto, el rol podría mover mercancía entre
        // dos bodegas comerciales, que es precisamente lo que no le toca.
        $mkt = $this->idsAlmacenesMarketing();
        $comerciales = $this->idsBodegasComerciales();
        $origen = (int) $data['origen_id'];
        $destino = (int) $data['destino_id'];

        $esPrestamo = in_array($origen, $comerciales, true) && in_array($destino, $mkt, true);
        $esDevolucion = in_array($origen, $mkt, true) && in_array($destino, $comerciales, true);

        abort_unless($esPrestamo || $esDevolucion, 403,
            'Un préstamo va de una bodega de venta a un almacén de Marketing, o al revés.');

        // La devolución dice qué préstamo cierra: uno entregado a ese mismo
        // almacén de Marketing. Así queda la pareja préstamo ↔ devolución, que
        // antes sólo se podía adivinar por el texto de las observaciones.
        $prestamoId = null;
        if ($esDevolucion && ! empty($data['prestamo_id'])) {
            $prestamo = Traslado::findOrFail($data['prestamo_id']);
            abort_unless($prestamo->motivo === 'prestamo' && (int) $prestamo->destino_id === $origen
                && $prestamo->estado === EstadoTraslado::Recibido, 422,
                'Ese préstamo no salió hacia este almacén o todavía no se entregó.');
            $prestamoId = $prestamo->id;
        }

        $t = Traslado::create([
            'numero' => Traslado::siguienteNumero(),
            'origen_id' => $origen,
            'destino_id' => $destino,
            'motivo' => 'prestamo',
            'prestamo_id' => $prestamoId,
            'observaciones' => $data['observaciones'] ?? null,
            'solicitado_por' => $r->user()->id,
            'fecha_solicitud' => now('America/Bogota')->toDateString(),
            'estado' => EstadoTraslado::Borrador,
        ]);

        app(MuestrasMarketing::class)->avisarSolicitudNueva($t->load(['origen', 'destino', 'solicitante']));

        return back()->with('success', $esPrestamo
            ? "Solicitud {$t->numero} creada. Cuando la aprueben y bodega la aliste, te llega el aviso."
            : "Devolución {$t->numero} creada. Llevá la mercancía a bodega para que la reciban.");
    }

    /**
     * Qué cuenta como almacén de Marketing y qué como bodega de venta.
     *
     * Van acá y no sueltas en cada método porque el panel y la validación de
     * la solicitud tienen que estar de acuerdo: si una lista ofrece una bodega
     * que la otra no acepta, el botón se dibuja y al pulsarlo da 403.
     */
    private function almacenesMarketing(): \Illuminate\Database\Eloquent\Builder
    {
        // Sólo el prefijo `MKT-`. La consulta anterior era
        // `codigo LIKE 'MKT-%' OR categoria = ReservaProveedor`, y ese OR
        // anulaba el prefijo que el propio diseño puso «para no mezclarlas con
        // reservas reales a proveedores»: las ubicaciones MKT- usan esa misma
        // categoría, así que la condición traía además RES-PROV-01 y
        // RES-PROV-02 —mercancía apartada para Andina y Kidzworld— y el panel
        // las mostraba como «mis almacenes». Con el préstamo ya funcionando,
        // eso dejaría a Marketing pidiendo mercancía contra la reserva de un
        // proveedor.
        return InventarioUbicacion::query()
            ->where('activa', true)
            ->where('codigo', 'like', 'MKT-%')
            ->orderBy('codigo');
    }

    private function bodegasComerciales(): \Illuminate\Database\Eloquent\Builder
    {
        return InventarioUbicacion::query()
            ->where('activa', true)
            ->where('categoria', CategoriaUbicacion::Venta)
            ->orderBy('codigo');
    }

    /** @return list<int> */
    private function idsAlmacenesMarketing(): array
    {
        return $this->almacenesMarketing()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return list<int> */
    private function idsBodegasComerciales(): array
    {
        return $this->bodegasComerciales()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
