<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Logistica\Actions\RegistrarDevolucionCliente;
use App\Modules\Logistica\Models\DevolucionCliente;
use App\Modules\Logistica\Models\DevolucionClienteItem;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que el cliente devuelve, desde que llega hasta que se revisa.
 *
 * El procedimiento es de Don Jorge (23-nov-2026): la mercancía entra al
 * almacén 08 con estado «por revisar», se inspecciona a más tardar al día
 * siguiente, y sale de ahí con un traslado al almacén que corresponda.
 *
 * Por eso esta pantalla abre por lo que FALTA REVISAR y no por lo más
 * reciente: el trabajo pendiente es la razón de entrar. Y el plazo se vigila
 * acá, porque hasta hoy no lo vigilaba nada.
 */
class DevolucionClienteController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            /*
             * Por la casilla de logística, no por una lista de roles escrita a
             * mano: así un rol nuevo entra con sólo marcarle el permiso, y los
             * responsables de zona —que son los que reciben la mercancía—
             * quedan dentro sin tener que darles la casilla de bodega, de la
             * que cuelgan los traslados entre sedes.
             */
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(
                    \App\Auth\Permisos::puede($r->user(), 'logistica'),
                    403,
                    'Las devoluciones de cliente son de logística.'
                );

                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        $filtro = $r->query('estado', 'por_revisar');

        $base = DevolucionCliente::query()
            ->with(['contacto:id,nombre_completo,razon_social', 'origen:id,codigo,nombre', 'creador:id,name'])
            ->withCount('items');

        $devoluciones = (clone $base)
            ->when($filtro === 'por_revisar', fn ($q) => $q->where('estado', 'confirmada')->whereNull('revisada_at'))
            ->when($filtro === 'borrador', fn ($q) => $q->where('estado', 'borrador'))
            ->when($filtro === 'revisadas', fn ($q) => $q->whereNotNull('revisada_at'))
            /*
             * Lo más viejo PRIMERO, al revés que casi todas las listas del
             * ERP. Acá el orden no es cronológico por gusto: lo que lleva más
             * días esperando es lo que incumple el plazo, y si se cae de la
             * primera pantalla nadie lo revisa.
             */
            ->orderBy('confirmada_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $conteos = [
            'por_revisar' => (clone $base)->where('estado', 'confirmada')->whereNull('revisada_at')->count(),
            'borrador' => (clone $base)->where('estado', 'borrador')->count(),
            'revisadas' => (clone $base)->whereNotNull('revisada_at')->count(),
        ];

        return Inertia::render('Logistica/DevolucionesCliente/Index', [
            'devoluciones' => $devoluciones->map(fn (DevolucionCliente $d) => $this->fila($d)),
            'conteos' => $conteos,
            'filtro' => $filtro,
            // El plazo que puso el área, para que la pantalla lo diga en vez
            // de que haya que saberlo de memoria.
            'dias_de_plazo' => 1,
        ]);
    }

    public function crear(Request $r): Response
    {
        return Inertia::render('Logistica/DevolucionesCliente/Form', [
            'devolucion' => null,
            'bodegas' => $this->bodegasDondeRecibe($r),
        ]);
    }

    public function show(Request $r, int $devolucion): Response
    {
        $d = DevolucionCliente::with([
            'items.variante:id,codigo_barras,color_nombre,talla,producto_id',
            'items.variante.producto:id,referencia,nombre',
            'items.producto:id,referencia,nombre',
            'contacto:id,nombre_completo,razon_social',
            'pedido:id,numero', 'factura:id,numero',
            'origen:id,codigo,nombre', 'destino:id,codigo,nombre',
            'creador:id,name', 'revisor:id,name',
        ])->findOrFail($devolucion);

        return Inertia::render('Logistica/DevolucionesCliente/Form', [
            'devolucion' => $this->detalle($d),
            'bodegas' => $this->bodegasDondeRecibe($r),
        ]);
    }

    public function guardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:devoluciones_cliente,id'],
            'contacto_id' => ['required', 'integer', 'exists:contactos,id'],
            // Las dos opcionales a propósito: el área recibe devoluciones de
            // las que no siempre aparece el papel, y rechazarlas por eso deja
            // la mercancía en el piso, que es lo que esto viene a evitar.
            'pedido_id' => ['nullable', 'integer', 'exists:pedidos_cliente,id'],
            'factura_id' => ['nullable', 'integer', 'exists:facturas_venta,id'],
            'ubicacion_origen_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ], [], [
            'contacto_id' => 'cliente',
            'ubicacion_origen_id' => 'bodega donde se recibe',
            'motivo' => 'motivo',
        ]);

        $d = isset($data['id'])
            ? DevolucionCliente::findOrFail($data['id'])
            : new DevolucionCliente(['numero' => DevolucionCliente::siguienteNumero(), 'estado' => 'borrador']);

        if ($d->exists && ! $d->esBorrador()) {
            return back()->withErrors(['motivo' => "La devolución {$d->numero} ya está confirmada: no se edita."]);
        }

        $d->fill([
            'contacto_id' => $data['contacto_id'],
            'pedido_id' => $data['pedido_id'] ?? null,
            'factura_id' => $data['factura_id'] ?? null,
            'ubicacion_origen_id' => $data['ubicacion_origen_id'],
            'fecha' => $data['fecha'],
            'motivo' => $data['motivo'],
        ]);

        $d->creada_por ??= $r->user()->id;
        $d->save();

        return redirect("/app/logistica/devoluciones-cliente/{$d->id}")
            ->with('success', "Devolución {$d->numero} guardada.");
    }

    public function itemGuardar(Request $r, int $devolucion): RedirectResponse
    {
        $d = DevolucionCliente::findOrFail($devolucion);

        abort_unless($d->esBorrador(), 409, 'Sólo se agregan líneas mientras está en borrador.');

        $data = $r->validate([
            'variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id'],
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            // Unidades enteras: Great Baby no vende fracciones, y un decimal
            // descuadra el kardex por la parte que se trunca al descontar.
            'cantidad' => ['required', 'integer', 'min:1'],
            'costo_unit' => ['nullable', 'numeric', 'min:0'],
            'motivo_item' => ['nullable', 'string', 'max:200'],
        ]);

        abort_if(
            empty($data['variante_id']) && empty($data['producto_id']),
            422,
            'Hay que decir qué producto se devolvió.'
        );

        /*
         * El costo, si no lo escribieron.
         *
         * Una entrada sin costo queda fuera del promedio de la ubicación, y
         * cuando la mercancía salga del 08 por traslado ese vacío viaja como
         * cero al almacén de destino y ensucia el promedio del inventario
         * bueno. Se sugiere el de la bodega de la que salió, que es lo que esa
         * mercancía le costó a la empresa.
         */
        $costo = $data['costo_unit'] ?? null;

        if ($costo === null) {
            $costo = RegistrarDevolucionCliente::costoSugerido(
                $d->origen,
                $data['variante_id'] ?? null,
                $data['producto_id'] ?? null,
            );
        }

        DevolucionClienteItem::create([
            'devolucion_id' => $d->id,
            'variante_id' => $data['variante_id'] ?? null,
            'producto_id' => $data['producto_id'] ?? null,
            'cantidad' => $data['cantidad'],
            'costo_unit' => $costo,
            'motivo_item' => $data['motivo_item'] ?? null,
        ]);

        return back()->with('success', 'Línea agregada.');
    }

    public function itemEliminar(int $devolucion, int $item): RedirectResponse
    {
        $d = DevolucionCliente::findOrFail($devolucion);

        abort_unless($d->esBorrador(), 409, 'Sólo se quitan líneas mientras está en borrador.');

        DevolucionClienteItem::where('devolucion_id', $d->id)->whereKey($item)->delete();

        return back()->with('success', 'Línea quitada.');
    }

    public function confirmar(int $devolucion): RedirectResponse
    {
        $d = DevolucionCliente::findOrFail($devolucion);

        try {
            $d = app(RegistrarDevolucionCliente::class)->handle($d);
        } catch (\DomainException $e) {
            // El motivo va como error de campo y no como abort: en una
            // petición de Inertia el cuerpo de un abort no se puede leer, y la
            // pantalla acaba diciendo «el servidor rechazó la operación».
            return back()->withErrors(['confirmar' => $e->getMessage()]);
        }

        return back()->with('success',
            "Devolución {$d->numero} confirmada · la mercancía entró a {$d->destino?->codigo} y queda por revisar.");
    }

    public function marcarRevisada(Request $r, int $devolucion): RedirectResponse
    {
        $d = DevolucionCliente::findOrFail($devolucion);

        abort_if($d->estado !== 'confirmada', 409,
            'Sólo se revisa una devolución confirmada: en borrador la mercancía todavía no entró.');

        abort_if($d->revisada_at !== null, 409, 'Esa devolución ya está revisada.');

        /*
         * Revisar no mueve mercancía: deja constancia de que la inspección se
         * hizo y de quién la hizo. Lo que mueve la mercancía del 08 al almacén
         * que corresponda es el TRASLADO, que ya existe y tiene su propia
         * pantalla con sus propios permisos.
         */
        $d->forceFill([
            'revisada_at' => now(),
            'revisada_por' => $r->user()->id,
        ])->save();

        return back()->with('success',
            "Devolución {$d->numero} marcada como revisada. Trasladá la mercancía del {$d->destino?->codigo} al almacén que corresponda.");
    }

    public function descartar(int $devolucion): RedirectResponse
    {
        $d = DevolucionCliente::findOrFail($devolucion);

        abort_unless($d->esBorrador(), 409,
            'Una devolución confirmada no se borra: su mercancía ya está contada en el inventario.');

        $d->delete();

        return redirect('/app/logistica/devoluciones-cliente')
            ->with('success', "Devolución {$d->numero} descartada.");
    }

    /** @return array<string, mixed> */
    private function fila(DevolucionCliente $d): array
    {
        return [
            'id' => $d->id,
            'numero' => $d->numero,
            'cliente' => $d->contacto?->razon_social ?: $d->contacto?->nombre_completo,
            'fecha' => $d->fecha?->format('Y-m-d'),
            'motivo' => $d->motivo,
            'estado' => $d->estado,
            'lineas' => (int) $d->items_count,
            'bodega' => $d->origen?->codigo,
            'creada_por' => $d->creador?->name,
            'confirmada_at' => $d->confirmada_at?->format('Y-m-d H:i'),
            'revisada_at' => $d->revisada_at?->format('Y-m-d H:i'),
            // Los días que lleva esperando, que es el número con el que se
            // mide el plazo del área.
            'dias_esperando' => $d->diasEsperando(),
        ];
    }

    /** @return array<string, mixed> */
    private function detalle(DevolucionCliente $d): array
    {
        return $this->fila($d) + [
            'contacto_id' => $d->contacto_id,
            'pedido_id' => $d->pedido_id,
            'pedido' => $d->pedido?->numero,
            'factura_id' => $d->factura_id,
            'factura' => $d->factura?->numero,
            'ubicacion_origen_id' => $d->ubicacion_origen_id,
            'destino' => $d->destino?->codigo,
            'revisada_por' => $d->revisor?->name,
            'items' => $d->items->map(fn (DevolucionClienteItem $i) => [
                'id' => $i->id,
                'variante_id' => $i->variante_id,
                'producto_id' => $i->producto_id,
                'sku' => $i->variante?->producto?->referencia ?: $i->producto?->referencia,
                'descripcion' => $i->variante?->producto?->nombre ?: $i->producto?->nombre,
                'color' => $i->variante?->color_nombre,
                'talla' => $i->variante?->talla,
                'es_agregado' => $i->esAgregado(),
                'cantidad' => (int) $i->cantidad,
                'costo_unit' => $i->costo_unit !== null ? (float) $i->costo_unit : null,
                'motivo_item' => $i->motivo_item,
            ])->values(),
        ];
    }

    /**
     * Dónde puede recibir esta persona.
     *
     * Acotado por su alcance, igual que el resto del módulo: no se recibe una
     * devolución en una bodega que uno no opera. Aracely y Gerencia ven todas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bodegasDondeRecibe(Request $r): array
    {
        $u = $r->user();

        return InventarioUbicacion::query()
            ->where('activa', true)
            ->whereNotNull('oficina_id')
            /*
             * Sólo bodegas de VENTA. La mercancía vuelve de donde se vendió,
             * así que ofrecer averías, exhibición o la propia bodega de
             * devoluciones es ofrecer sitios de los que nunca salió — y elegir
             * el 08 como origen haría que la devolución entrara a sí misma.
             *
             * Además es de donde sale el costo con el que entra cada línea:
             * una bodega que no vende no tiene ese histórico.
             *
             * Se filtra por CATEGORÍA y no por la bandera `disponible_para_venta`
             * porque hay filas viejas donde las dos se contradicen —ubicaciones
             * de avería con la bandera en true, de antes de que el modelo la
             * derivara de la categoría—. La categoría es la que dice la verdad.
             */
            ->where('categoria', \App\Modules\Dropi\Enums\CategoriaUbicacion::Venta)
            ->when(
                ! \App\Auth\Permisos::esRoot($u),
                fn ($q) => $q->whereIn('id', $u->bodegasQueVeIds() ?: [-1])
            )
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre'])
            ->map(fn ($b) => ['id' => $b->id, 'etiqueta' => "{$b->codigo} · {$b->nombre}"])
            ->values()
            ->all();
    }

    /** Buscar el pedido de un cliente, para enganchar la devolución. */
    public function pedidosDelCliente(Request $r): \Illuminate\Http\JsonResponse
    {
        $contacto = (int) $r->query('contacto_id');

        if (! $contacto) {
            return response()->json([]);
        }

        $pedidos = PedidoCliente::query()
            ->where('contacto_id', $contacto)
            ->whereIn('estado', ['facturado', 'despachado'])
            ->with('factura:id,numero')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'numero', 'factura_id', 'created_at']);

        return response()->json($pedidos->map(fn ($p) => [
            'id' => $p->id,
            'numero' => $p->numero,
            'factura_id' => $p->factura_id,
            'factura' => $p->factura?->numero,
            'fecha' => $p->created_at?->format('Y-m-d'),
        ]));
    }

    /** Los clientes, para el selector. */
    public function clientes(Request $r): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $r->query('q', ''));

        $clientes = Contacto::query()
            ->where('es_cliente', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('nombre_completo', 'like', "%{$q}%")
                ->orWhere('razon_social', 'like', "%{$q}%")
                ->orWhere('numero_documento', 'like', "%{$q}%")))
            ->orderBy('nombre_completo')
            ->limit(25)
            ->get(['id', 'nombre_completo', 'razon_social', 'numero_documento']);

        return response()->json($clientes->map(fn ($c) => [
            'id' => $c->id,
            'etiqueta' => ($c->razon_social ?: $c->nombre_completo).' · '.$c->numero_documento,
        ]));
    }
}
