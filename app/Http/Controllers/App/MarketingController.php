<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Marketing\Models\MarketingCreativo;
use App\Modules\Marketing\Models\MarketingPost;
use App\Modules\Marketing\Services\EtapasProducto;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M8 · Marketing y Contenido:
 *  - Ficha rich de producto (copy + specs + keywords + beneficios + público
 *    objetivo + «listo para publicar» + visibilidad para Ventas) · MKT-1
 *  - Parrilla mensual: calendario de posts por canal/estado, ligado al
 *    producto y al contenedor, con grabación → edición → entrega · MKT-3
 *  - Tablero: en qué etapa va cada producto, por contenedor · MKT-4
 */
class MarketingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // Era `esAracely()` fijo: el rol Marketing veía la parrilla en el
                // menú y recibía 403. Ahora es un permiso propio —hoy sólo de
                // gerencia, igual que antes— que se le puede dar a Marketing desde
                // /app/roles sin tocar código.
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'marketing_parrilla'), 403);
                return $next($r);
            }, only: ['parrilla', 'postCrear', 'postActualizar', 'postEtapa', 'postEliminar']),
            // La ficha, la publicación y el tablero son el trabajo diario del rol
            // Marketing (MKT-1 y MKT-4). Antes la ficha también pedía el permiso
            // de la parrilla y Marketing no la podía abrir.
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(MarketingCreativo::puedeGestionar($r->user()), 403);
                return $next($r);
            }, only: ['fichaEditar', 'fichaGuardar', 'publicar', 'piezas', 'tablero']),
        ];
    }

    // ---------- FICHA RICH PRODUCTO ----------
    public function fichaEditar(Request $r, int $producto, EtapasProducto $etapas): Response
    {
        $p = Producto::findOrFail($producto);

        return Inertia::render('Marketing/Ficha', [
            'producto' => [
                'id' => $p->id, 'referencia' => $p->referencia, 'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'copy_comercial' => $p->copy_comercial,
                'specs' => is_string($p->specs_json) ? json_decode($p->specs_json, true) : ($p->specs_json ?? []),
                'keywords_seo' => $p->keywords_seo,
                'beneficios' => $p->beneficios,
                'publico_objetivo' => $p->publico_objetivo,
                'listo_publicar' => (bool) $p->listo_publicar,
                'publicado' => (bool) $p->publicado,
                'activo' => (bool) $p->activo,
                'publicado_cambiado' => $p->publicado_cambiado_en
                    ? $p->publicado_cambiado_en->format('Y-m-d H:i').' · '.(User::find($p->publicado_cambiado_por)?->name ?? '—')
                    : null,
                'piezas_video' => (bool) $p->piezas_video,
                'piezas_reel' => (bool) $p->piezas_reel,
                'piezas_imagenes' => (int) $p->piezas_imagenes,
            ],
            'etapa' => $etapas->para(collect([$p]))[$p->id],
            'etapas' => EtapasProducto::ETAPAS,
            'creativos' => MarketingCreativo::with(['producto:id,nombre,referencia', 'importacion:id,numero,contenedor', 'autor:id,name'])
                ->where('producto_id', $p->id)->latest()->get()
                ->map(fn (MarketingCreativo $c) => $c->paraVista($r->user())),
            'max_kb' => MarketingCreativo::MAX_KB,
        ]);
    }

    public function fichaGuardar(Request $r, int $producto): RedirectResponse
    {
        $data = $r->validate([
            'copy_comercial' => ['nullable', 'string', 'max:3000'],
            'specs' => ['nullable', 'array'],
            'specs.*.k' => ['required_with:specs.*.v', 'string', 'max:60'],
            'specs.*.v' => ['required_with:specs.*.k', 'string', 'max:200'],
            'keywords_seo' => ['nullable', 'string', 'max:500'],
            'beneficios' => ['nullable', 'string', 'max:2000'],
            'publico_objetivo' => ['nullable', 'string', 'max:300'],
            'listo_publicar' => ['boolean'],
            'piezas_video' => ['boolean'],
            'piezas_reel' => ['boolean'],
            'piezas_imagenes' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);
        $p = Producto::findOrFail($producto);
        $p->update([
            'copy_comercial' => $data['copy_comercial'] ?? null,
            'specs_json' => json_encode($data['specs'] ?? [], JSON_UNESCAPED_UNICODE),
            'keywords_seo' => $data['keywords_seo'] ?? null,
            'beneficios' => $data['beneficios'] ?? null,
            'publico_objetivo' => $data['publico_objetivo'] ?? null,
            'listo_publicar' => (bool) ($data['listo_publicar'] ?? false),
            'piezas_video' => (bool) ($data['piezas_video'] ?? false),
            'piezas_reel' => (bool) ($data['piezas_reel'] ?? false),
            'piezas_imagenes' => (int) ($data['piezas_imagenes'] ?? 0),
        ]);
        return back()->with('success', 'Ficha marketing actualizada.');
    }

    /**
     * MKT-1 · El «botoncito de activar o desactivar»: apagado, el producto no
     * sale en el catálogo de Ventas (pedido del vendedor y portal de clientes);
     * el catálogo interno lo sigue mostrando, marcado como oculto.
     */
    public function publicar(Request $r, int $producto): RedirectResponse
    {
        $data = $r->validate(['publicado' => ['required', 'boolean']]);
        $p = Producto::findOrFail($producto);

        if ($data['publicado'] && ! $p->activo) {
            return back()->with('error', 'El producto está inactivo en el catálogo: actívelo primero.');
        }

        $p->update([
            'publicado' => (bool) $data['publicado'],
            'publicado_cambiado_en' => now(),
            'publicado_cambiado_por' => $r->user()->id,
        ]);

        return back()->with('success', $data['publicado']
            ? "«{$p->nombre}» ya aparece para Ventas."
            : "«{$p->nombre}» quedó oculto para Ventas.");
    }

    /** MKT-4 · Qué piezas lleva el producto, desde el tablero sin abrir la ficha. */
    public function piezas(Request $r, int $producto): RedirectResponse
    {
        $data = $r->validate([
            'piezas_video' => ['required', 'boolean'],
            'piezas_reel' => ['required', 'boolean'],
            'piezas_imagenes' => ['required', 'integer', 'min:0', 'max:50'],
        ]);
        Producto::findOrFail($producto)->update($data);

        return back()->with('success', 'Piezas actualizadas.');
    }

    // ---------- TABLERO POR CONTENEDOR (MKT-4) ----------
    public function tablero(Request $r, EtapasProducto $etapas): Response
    {
        $q = trim((string) $r->input('q', ''));
        $etapa = (string) $r->input('etapa', '');
        $contenedorId = (int) $r->input('contenedor', 0);

        $cantidades = $contenedorId ? $etapas->productosDelContenedor($contenedorId) : [];

        $productos = Producto::query()
            ->when($contenedorId, fn ($qb) => $qb->whereIn('id', array_keys($cantidades)))
            ->when($q !== '', fn ($qb) => $qb->where(fn ($w) => $w->where('referencia', 'like', "%{$q}%")->orWhere('nombre', 'like', "%{$q}%")))
            ->orderBy('referencia')
            ->get(['id', 'referencia', 'nombre', 'activo', 'publicado', 'listo_publicar', 'piezas_video', 'piezas_reel', 'piezas_imagenes']);

        // La etapa se calcula, no se guarda, así que se filtra después de
        // calcularla. Sin contenedor ni búsqueda solo se listan los productos
        // que están en movimiento (con compra o con contenido): el resto del
        // catálogo no tiene nada que seguir aquí.
        $info = $etapas->para($productos);
        $conteo = collect($info)->pluck('etapa')->filter()->countBy()->all();
        if ($etapa !== '') {
            $productos = $productos->filter(fn ($p) => $info[$p->id]['etapa'] === $etapa)->values();
        } elseif (! $contenedorId && $q === '') {
            $productos = $productos->filter(fn ($p) => $info[$p->id]['etapa'] !== null)->values();
        }

        $porPagina = 50;
        $paginas = (int) max(1, ceil($productos->count() / $porPagina));
        $pagina = min($paginas, max(1, (int) $r->input('page', 1)));

        return Inertia::render('Marketing/Tablero', [
            'productos' => $productos->forPage($pagina, $porPagina)->values()->map(fn ($p) => [
                'id' => $p->id,
                'referencia' => $p->referencia,
                'nombre' => $p->nombre,
                'activo' => (bool) $p->activo,
                'publicado' => (bool) $p->publicado,
                'listo_publicar' => (bool) $p->listo_publicar,
                'cantidad' => $cantidades[$p->id] ?? null,
                ...$info[$p->id],
            ]),
            'total' => $productos->count(),
            'pagina' => $pagina,
            'paginas' => $paginas,
            'conteo' => $conteo,
            'etapas' => EtapasProducto::ETAPAS,
            'filtros' => ['q' => $q, 'etapa' => $etapa, 'contenedor' => $contenedorId ?: null],
            'contenedores' => $this->contenedores(),
        ]);
    }

    // ---------- PARRILLA DE CONTENIDO ----------
    public function parrilla(Request $request): Response
    {
        $anio = (int) $request->input('anio', now('America/Bogota')->year);
        $mes = (int) $request->input('mes', now('America/Bogota')->month);
        $inicio = \Carbon\Carbon::create($anio, $mes, 1)->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();

        $posts = MarketingPost::with(['producto:id,nombre,referencia', 'creador:id,name', 'importacion:id,numero,contenedor'])
            ->withCount('creativos')
            ->whereBetween('fecha_publicacion', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha_publicacion')
            ->get();

        // Agrupar por día para el calendario
        $porDia = [];
        foreach ($posts as $p) {
            $key = $p->fecha_publicacion->format('Y-m-d');
            $porDia[$key][] = [
                'id' => $p->id, 'titulo' => $p->titulo, 'canal' => $p->canal,
                'tipo' => $p->tipo, 'estado' => $p->estado,
                'producto' => $p->producto?->nombre,
                'producto_id' => $p->producto_id,
                'contenedor' => $p->importacion ? $this->etiquetaContenedor($p->importacion) : null,
                'importacion_id' => $p->importacion_id,
                // MKT-3 · producción del contenido
                'etapa' => $p->etapa,
                'siguiente_etapa' => $p->siguienteEtapa(),
                'fechas_etapa' => [
                    'grabacion' => $p->grabado_en?->format('Y-m-d'),
                    'edicion' => $p->editado_en?->format('Y-m-d'),
                    'entregado' => $p->entregado_en?->format('Y-m-d'),
                ],
                'creativos' => $p->creativos_count,
            ];
        }

        $conteos = $posts->countBy('estado')->all();

        return Inertia::render('Marketing/Parrilla', [
            'anio' => $anio, 'mes' => $mes,
            'inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString(),
            'diasDelMes' => $fin->day,
            'porDia' => $porDia,
            'total' => $posts->count(),
            'conteos' => $conteos,
            'productos' => Producto::where('activo', true)->orderBy('nombre')->limit(200)->get(['id', 'nombre', 'referencia'])
                ->map(fn ($x) => ['id' => $x->id, 'label' => $x->nombre . ' (' . $x->referencia . ')']),
            'contenedores' => $this->contenedores(),
        ]);
    }

    public function postCrear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'fecha_publicacion' => ['required', 'date'],
            'hora_publicacion' => ['nullable', 'date_format:H:i'],
            'titulo' => ['required', 'string', 'max:200'],
            'copy' => ['required', 'string', 'max:2000'],
            'canal' => ['required', 'in:instagram,facebook,tiktok,whatsapp_status,email,web,otros'],
            'tipo' => ['required', 'in:post,reel,story,live,email,blog'],
            'estado' => ['nullable', 'in:idea,produccion,programado,publicado,cancelado'],
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'importacion_id' => ['nullable', 'integer', 'exists:compras_importaciones,id'],
            'produccion' => ['nullable', 'boolean'],
            'url_publicacion' => ['nullable', 'url', 'max:500'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);
        // MKT-3 · Un post que hay que grabar arranca en la etapa de grabación.
        $produccion = (bool) ($data['produccion'] ?? false);
        unset($data['produccion']);
        MarketingPost::create([
            ...$data,
            'creado_por' => auth()->id(),
            'estado' => $data['estado'] ?? 'idea',
            'etapa' => $produccion ? 'grabacion' : null,
            'grabado_en' => $produccion ? now() : null,
        ]);
        return back()->with('success', 'Post agregado a parrilla.');
    }

    public function postActualizar(Request $r, int $post): RedirectResponse
    {
        $data = $r->validate([
            'estado' => ['required', 'in:idea,produccion,programado,publicado,cancelado'],
            'url_publicacion' => ['nullable', 'url', 'max:500'],
        ]);
        MarketingPost::findOrFail($post)->update($data);
        return back()->with('success', 'Post actualizado.');
    }

    /**
     * MKT-3 · Avanza la producción: grabación → edición → entrega. Al
     * entregar, el material del post queda a la vista de las dos áreas
     * (mayorista y dropship): lo que estaba «interno» pasa a «solo ver» y lo
     * que ya era de descarga se queda así. Se sube una sola vez.
     */
    public function postEtapa(int $post): RedirectResponse
    {
        $p = MarketingPost::findOrFail($post);
        $siguiente = $p->siguienteEtapa();
        if ($siguiente === null) {
            return back()->with('error', 'Este contenido ya se entregó.');
        }
        if ($siguiente === 'entregado' && ! $p->creativos()->exists()) {
            return back()->with('error', 'Suba el material del post antes de entregarlo.');
        }

        $columna = ['grabacion' => 'grabado_en', 'edicion' => 'editado_en', 'entregado' => 'entregado_en'][$siguiente];
        $p->update(['etapa' => $siguiente, $columna => now()]);

        if ($siguiente === 'entregado') {
            $p->creativos()->where('permiso', 'interno')->update(['permiso' => 'solo_ver']);

            return back()->with('success', 'Contenido entregado: mayorista y dropship ya lo ven.');
        }

        return back()->with('success', $siguiente === 'edicion' ? 'Pasó a edición.' : 'En grabación.');
    }

    public function postEliminar(int $post): RedirectResponse
    {
        MarketingPost::findOrFail($post)->delete();
        return back()->with('success', 'Post eliminado.');
    }

    private function contenedores(): \Illuminate\Support\Collection
    {
        return Importacion::query()->orderByDesc('id')->limit(100)
            ->get(['id', 'numero', 'contenedor', 'estado'])
            ->map(fn (Importacion $i) => ['id' => $i->id, 'label' => $this->etiquetaContenedor($i).' · '.$i->estado->label()]);
    }

    private function etiquetaContenedor(Importacion $i): string
    {
        return $i->numero.($i->contenedor ? " · {$i->contenedor}" : '');
    }
}
