<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las listas de precios, de a lista entera y no de a producto.
 *
 * Las listas existían desde el principio y se podían asignar a un cliente,
 * pero los precios sólo se cargaban producto por producto, entrando a la ficha
 * de cada uno. Con ochenta variantes eso son ochenta pantallas, así que no lo
 * hizo nadie: el 2026-11-07, en producción, las cuatro listas de Great Baby
 * figuraban «sin precios cargados».
 *
 * Y una lista vacía no es un detalle cosmético. El panel del vendedor sólo
 * ofrece clientes `whereNotNull('lista_precios_id')`, y el catálogo del pedido
 * sólo trae lo que tiene precio en la lista del cliente. Sin precios cargados
 * el vendedor abre su panel, busca un cliente y no le aparece nada —que es
 * exactamente lo que pasó en la reunión de ese día.
 *
 * Lo que agrega esta pantalla sobre la ficha de producto:
 *
 *   · se ve la lista completa, con lo que falta y lo que ya está;
 *   · se cargan muchos precios de una sola vez;
 *   · se pueden DERIVAR de otra lista o del costo, que es como se arma una
 *     lista de verdad («Distribuidor es Mayorista menos 12»), en vez de
 *     teclear ochenta números a mano.
 *
 * Derivar no guarda nada: calcula y rellena las casillas para que alguien las
 * mire antes de confirmar. Un error de porcentaje acá cambia el precio de todo
 * el catálogo, y eso no debería poder pasar de un solo clic.
 */
class ListasPreciosController extends Controller implements HasMiddleware
{
    /** Cuántos renglones por pantalla. Un catálogo grande no cabe de una. */
    private const POR_PAGINA = 100;

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                /*
                 * La misma puerta que `ProductosController::guardarPrecios`.
                 *
                 * Se reusa en vez de inventar un permiso nuevo: `PermisosSeeder`
                 * sólo reparte los permisos que nacen en su propia corrida, y el
                 * 2026-10-29 una sección nueva dejó a los siete roles de
                 * producción sin acceso durante semanas. Definir a qué precio se
                 * vende es decisión comercial: Gerencia o el equipo comercial.
                 */
                abort_unless(
                    \App\Auth\Permisos::esRoot($r->user()) || \App\Auth\Permisos::puede($r->user(), 'vendedor'),
                    403,
                    'Las listas de precios las maneja Gerencia o el equipo comercial.'
                );

                return $next($r);
            }),
        ];
    }

    /** Todas las listas, con cuánto les falta. */
    public function index(): Response
    {
        $listas = ListaPrecios::conConteoDePrecios()
            ->orderByRaw('siigo_id IS NOT NULL')
            ->orderByDesc('activa')
            ->orderBy('nombre')
            ->get()
            ->map(fn (ListaPrecios $l) => [
                'id' => $l->id,
                'nombre' => $l->nombre,
                'codigo' => $l->codigo,
                'canal' => $l->canal,
                'activa' => (bool) $l->activa,
                'tarifados' => $l->productos_tarifados,
                'origen' => $l->siigo_id ? 'SIIGO' : 'Great Baby',
                // Cuántos clientes dependen de esta lista para poder comprar.
                'clientes' => \App\Models\Contacto::where('lista_precios_id', $l->id)->count(),
            ]);

        return Inertia::render('Catalogo/ListasPrecios/Index', [
            'listas' => $listas,
            'vendibles' => $this->totalVendibles(),
        ]);
    }

    /** El editor de una lista. */
    public function show(Request $r, ListaPrecios $lista): Response
    {
        $q = trim((string) $r->input('q'));
        $solo = $r->input('solo'); // sin_precio | con_precio | null

        $items = $this->items($lista, $q, $solo, (int) $r->input('page', 1));

        return Inertia::render('Catalogo/ListasPrecios/Editor', [
            'lista' => [
                'id' => $lista->id,
                'nombre' => $lista->nombre,
                'canal' => $lista->canal,
                'activa' => (bool) $lista->activa,
                'origen' => $lista->siigo_id ? 'SIIGO' : 'Great Baby',
            ],
            'items' => $items['filas'],
            'paginacion' => $items['paginacion'],
            'filtros' => ['q' => $q, 'solo' => $solo],
            // Para derivar de otra lista.
            'otras_listas' => ListaPrecios::conConteoDePrecios()
                ->where('id', '!=', $lista->id)
                ->orderBy('nombre')
                ->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'nombre' => $l->nombre,
                    'tarifados' => $l->productos_tarifados,
                ])->values(),
            'resumen' => [
                'vendibles' => $this->totalVendibles(),
                'tarifados' => $this->totalTarifados($lista),
            ],
        ]);
    }

    /**
     * Guarda los precios enviados.
     *
     * Sólo toca los renglones que vinieron en la petición: la pantalla está
     * paginada, y borrar lo que no se envió vaciaría la lista entera al guardar
     * la primera página.
     *
     * Una casilla vacía SÍ borra el precio de ese renglón, igual que en la
     * ficha de producto, porque es la única forma de sacar un producto de una
     * lista. Se dice cuántos se quitaron para que no pase inadvertido.
     */
    public function guardar(Request $r, ListaPrecios $lista): RedirectResponse
    {
        $data = $r->validate([
            'precios' => ['present', 'array'],
            'precios.*.tipo' => ['required', 'in:variante,producto'],
            'precios.*.id' => ['required', 'integer'],
            'precios.*.precio' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $puestos = 0;
        $quitados = 0;

        DB::transaction(function () use ($data, $lista, &$puestos, &$quitados) {
            foreach ($data['precios'] as $fila) {
                $precio = $fila['precio'];
                $esVariante = $fila['tipo'] === 'variante';
                $clase = $esVariante ? PrecioVariante::class : PrecioProducto::class;
                $columna = $esVariante ? 'variante_id' : 'producto_id';

                if ($precio === null || $precio === '' || (float) $precio <= 0) {
                    $quitados += $clase::query()
                        ->where('lista_id', $lista->id)
                        ->where($columna, $fila['id'])
                        ->delete();

                    continue;
                }

                $clase::updateOrCreate(
                    ['lista_id' => $lista->id, $columna => $fila['id']],
                    ['precio' => (float) $precio, 'vigente_desde' => now()->toDateString(), 'vigente_hasta' => null],
                );
                $puestos++;
            }
        });

        $msg = $puestos > 0 ? "Se guardaron {$puestos} precio(s) en {$lista->nombre}." : '';
        if ($quitados > 0) {
            $msg .= " Se quitaron {$quitados}: esos productos dejan de poder pedirse con esta lista.";
        }

        return back()->with($quitados > 0 && $puestos === 0 ? 'warning' : 'success',
            trim($msg) ?: 'No hubo cambios que guardar.');
    }

    /**
     * Calcula precios derivados y los devuelve SIN guardar.
     *
     * Dos formas, que son las dos que se usan de verdad:
     *
     *   · de otra lista ± un porcentaje («Distribuidor es Mayorista menos 12»);
     *   · del costo del proveedor más un margen.
     *
     * Devuelve y no guarda a propósito: un porcentaje mal tecleado acá cambia
     * el precio de todo el catálogo. Que alguien lo vea antes de confirmar.
     */
    public function previsualizar(Request $r, ListaPrecios $lista)
    {
        $data = $r->validate([
            'base' => ['required', 'in:lista,costo'],
            'lista_origen_id' => ['required_if:base,lista', 'nullable', 'integer', 'exists:listas_precios,id'],
            'porcentaje' => ['required', 'numeric', 'min:-99', 'max:999'],
            'solo_vacios' => ['boolean'],
            'redondeo' => ['nullable', 'integer', 'in:1,10,50,100,1000'],
            'ids' => ['present', 'array'],
            'ids.*.tipo' => ['required', 'in:variante,producto'],
            'ids.*.id' => ['required', 'integer'],
            // Lo que hay EN LA CASILLA, que no es lo mismo que lo guardado.
            'ids.*.actual' => ['nullable', 'numeric'],
        ]);

        $factor = 1 + ((float) $data['porcentaje'] / 100);
        $redondeo = (int) ($data['redondeo'] ?? 1);
        $soloVacios = (bool) ($data['solo_vacios'] ?? false);

        $variantes = collect($data['ids'])->where('tipo', 'variante')->pluck('id')->all();
        $productos = collect($data['ids'])->where('tipo', 'producto')->pluck('id')->all();

        $actuales = $this->preciosDe($lista->id, $variantes, $productos);
        $base = $data['base'] === 'lista'
            ? $this->preciosDe((int) $data['lista_origen_id'], $variantes, $productos)
            : $this->costos($variantes, $productos);

        $calculados = [];
        $sinBase = 0;
        $enCero = 0;

        foreach ($data['ids'] as $it) {
            $clave = $it['tipo'].'-'.$it['id'];

            /*
             * «Sólo los que no tienen precio» mira la CASILLA, no lo guardado.
             *
             * Antes comparaba contra la base de datos, así que los precios que
             * la persona acababa de teclear a mano —y todavía no había
             * guardado— contaban como vacíos y el derivado se los pisaba. Era
             * justo el trabajo que había hecho a pulso el que se perdía.
             *
             * Si la casilla no viene en la petición se cae a lo guardado, que
             * es como se comportaba antes.
             */
            $enLaCasilla = array_key_exists('actual', $it)
                ? (float) ($it['actual'] ?? 0)
                : (float) ($actuales[$clave] ?? 0);

            if ($soloVacios && $enLaCasilla > 0) {
                continue;
            }

            if (! isset($base[$clave]) || $base[$clave] <= 0) {
                // Sin base no se inventa un precio: se cuenta y se avisa.
                $sinBase++;

                continue;
            }

            $precio = $this->redondear($base[$clave] * $factor, $redondeo);

            /*
             * Un precio que el redondeo dejó en cero NO se devuelve.
             *
             * Con redondeo a $100, cualquier cosa por debajo de $50 cae a 0 —y
             * `guardar` lee el cero como «borrá el precio de esta fila». O sea
             * que la previsualización, que promete no guardar nada, terminaba
             * sacando productos de la lista. Se cuenta aparte y se avisa.
             */
            if ($precio <= 0) {
                $enCero++;

                continue;
            }

            $calculados[] = [
                'tipo' => $it['tipo'],
                'id' => $it['id'],
                'precio' => $precio,
            ];
        }

        $avisos = [];

        if ($sinBase > 0) {
            $avisos[] = $sinBase.' sin base (no tienen '
                .($data['base'] === 'lista' ? 'precio en la lista de origen' : 'costo de proveedor cargado').')';
        }

        if ($enCero > 0) {
            $avisos[] = $enCero.' quedaban en $0 con ese redondeo y se dejaron como estaban';
        }

        return response()->json([
            'precios' => $calculados,
            'sin_base' => $sinBase,
            'en_cero' => $enCero,
            'mensaje' => $avisos
                ? count($calculados).' calculados · '.implode(' · ', $avisos)
                : count($calculados).' precios calculados. Revisalos y guardá.',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Todo lo que se puede tarifar: variantes de los granulares y productos
     * de los agregados. Son dos tablas y hay que mirar las dos.
     */
    private function items(ListaPrecios $lista, string $q, ?string $solo, int $pagina): array
    {
        $enLista = PrecioVariante::where('lista_id', $lista->id)->vigentes()->pluck('precio', 'variante_id');
        $enListaProd = PrecioProducto::where('lista_id', $lista->id)->vigentes()->pluck('precio', 'producto_id');

        $filtroTexto = function ($query) use ($q) {
            if ($q === '') {
                return;
            }
            $term = '%'.$q.'%';
            $query->where(fn ($w) => $w->where('referencia', 'like', $term)->orWhere('nombre', 'like', $term));
        };

        // Variantes de productos granulares.
        $variantes = ProductoVariante::query()
            ->whereHas('producto', function ($p) use ($filtroTexto) {
                $p->where('activo', true)->where('desglose_stock', true);
                $filtroTexto($p);
            })
            ->with('producto:id,referencia,nombre,precio_proveedor')
            ->orderBy('producto_id')->orderBy('id')
            ->get(['id', 'producto_id', 'codigo_barras', 'color_nombre', 'talla', 'precio_min', 'precio_max'])
            ->map(fn (ProductoVariante $v) => [
                'tipo' => 'variante',
                'id' => $v->id,
                'ref' => $v->producto?->referencia,
                'nombre' => $v->producto?->nombre,
                'detalle' => trim(($v->color_nombre ?? '').' '.($v->talla ? '· '.$v->talla : '')),
                'sku' => $v->codigo_barras,
                'costo' => (float) ($v->producto?->precio_proveedor ?? 0),
                'precio' => isset($enLista[$v->id]) ? (float) $enLista[$v->id] : null,
                'precio_min' => $v->precio_min ? (float) $v->precio_min : null,
                'precio_max' => $v->precio_max ? (float) $v->precio_max : null,
            ]);

        // Productos agregados: colores surtidos, una sola existencia.
        $productos = Producto::query()
            ->where('activo', true)->where('desglose_stock', false)
            ->tap($filtroTexto)
            ->orderBy('referencia')
            ->get(['id', 'referencia', 'nombre', 'precio_proveedor'])
            ->map(fn (Producto $p) => [
                'tipo' => 'producto',
                'id' => $p->id,
                'ref' => $p->referencia,
                'nombre' => $p->nombre,
                'detalle' => 'colores surtidos',
                'sku' => $p->referencia,
                'costo' => (float) ($p->precio_proveedor ?? 0),
                'precio' => isset($enListaProd[$p->id]) ? (float) $enListaProd[$p->id] : null,
                'precio_min' => null,
                'precio_max' => null,
            ]);

        $todos = $variantes->concat($productos);

        if ($solo === 'sin_precio') {
            $todos = $todos->whereNull('precio');
        } elseif ($solo === 'con_precio') {
            $todos = $todos->whereNotNull('precio');
        }

        $todos = $todos->values();
        $total = $todos->count();
        $pagina = max(1, $pagina);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min($pagina, $paginas);

        return [
            'filas' => $todos->slice(($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA)->values(),
            'paginacion' => [
                'pagina' => $pagina,
                'paginas' => $paginas,
                'total' => $total,
                'por_pagina' => self::POR_PAGINA,
            ],
        ];
    }

    /** Precios vigentes de una lista, con clave «tipo-id». */
    private function preciosDe(int $listaId, array $variantes, array $productos): array
    {
        $out = [];

        PrecioVariante::where('lista_id', $listaId)->vigentes()
            ->whereIn('variante_id', $variantes)
            ->get(['variante_id', 'precio'])
            ->each(function ($p) use (&$out) {
                $out['variante-'.$p->variante_id] = (float) $p->precio;
            });

        PrecioProducto::where('lista_id', $listaId)->vigentes()
            ->whereIn('producto_id', $productos)
            ->get(['producto_id', 'precio'])
            ->each(function ($p) use (&$out) {
                $out['producto-'.$p->producto_id] = (float) $p->precio;
            });

        return $out;
    }

    /** Costo de proveedor; en las variantes, el del producto padre. */
    private function costos(array $variantes, array $productos): array
    {
        $out = [];

        ProductoVariante::whereIn('id', $variantes)
            ->with('producto:id,precio_proveedor')
            ->get(['id', 'producto_id'])
            ->each(function ($v) use (&$out) {
                $out['variante-'.$v->id] = (float) ($v->producto?->precio_proveedor ?? 0);
            });

        Producto::whereIn('id', $productos)
            ->get(['id', 'precio_proveedor'])
            ->each(function ($p) use (&$out) {
                $out['producto-'.$p->id] = (float) ($p->precio_proveedor ?? 0);
            });

        return $out;
    }

    /** Un precio de lista no termina en 437: se redondea a la unidad elegida. */
    private function redondear(float $valor, int $a): float
    {
        return $a <= 1 ? round($valor, 2) : round(round($valor / $a) * $a, 2);
    }

    private function totalVendibles(): int
    {
        return ProductoVariante::whereHas('producto',
            fn ($p) => $p->where('activo', true)->where('desglose_stock', true))->count()
            + Producto::where('activo', true)->where('desglose_stock', false)->count();
    }

    /**
     * Cuántos de los VENDIBLES tienen precio en esta lista.
     *
     * Antes contaba filas de precio sin mirar si el producto todavía se vende,
     * mientras el denominador sí filtraba. Con el catálogo que se depura cada
     * tanto, eso deja precios viejos de productos apagados sumando arriba y no
     * abajo: la cobertura podía marcar 100% —o más— en una lista que no vende
     * nada. Una cifra que miente hacia arriba es peor que no tenerla, porque
     * nadie va a ir a revisar una lista que dice estar completa.
     */
    private function totalTarifados(ListaPrecios $lista): int
    {
        return PrecioVariante::where('lista_id', $lista->id)->vigentes()
            ->whereHas('variante.producto',
                fn ($p) => $p->where('activo', true)->where('desglose_stock', true))
            ->count()
            + PrecioProducto::where('lista_id', $lista->id)->vigentes()
                ->whereHas('producto',
                    fn ($p) => $p->where('activo', true)->where('desglose_stock', false))
                ->count();
    }
}
