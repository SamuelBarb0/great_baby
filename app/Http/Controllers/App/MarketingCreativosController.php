<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Marketing\Models\MarketingCreativo;
use App\Modules\Marketing\Models\MarketingPost;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * MKT-2 · Nube de creativos por producto y contenedor.
 *
 * Marketing sube cada pieza una vez (archivo liviano o enlace externo) y le
 * pone permiso; vendedores (mayorista) y dropship ven en la misma pantalla lo
 * compartido y bajan lo que tiene permiso de descarga.
 */
class MarketingCreativosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(MarketingCreativo::puedeGestionar($r->user())
                    || Permisos::puede($r->user(), 'marketing_material'), 403);
                return $next($r);
            }, only: ['index', 'ver', 'descargar']),
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(MarketingCreativo::puedeGestionar($r->user()), 403);
                return $next($r);
            }, only: ['subir', 'actualizar', 'eliminar']),
        ];
    }

    public function index(Request $r): Response
    {
        $u = $r->user();
        $productoId = (int) $r->input('producto', 0);
        $contenedorId = (int) $r->input('contenedor', 0);
        $postId = (int) $r->input('post', 0);
        $tipo = (string) $r->input('tipo', '');
        $q = trim((string) $r->input('q', ''));

        $creativos = MarketingCreativo::query()
            ->visiblesPara($u)
            ->with(['producto:id,nombre,referencia', 'importacion:id,numero,contenedor', 'autor:id,name'])
            ->when($productoId, fn ($qb) => $qb->where('producto_id', $productoId))
            ->when($contenedorId, fn ($qb) => $qb->where('importacion_id', $contenedorId))
            ->when($postId, fn ($qb) => $qb->where('post_id', $postId))
            ->when(in_array($tipo, MarketingCreativo::TIPOS, true), fn ($qb) => $qb->where('tipo', $tipo))
            ->when($q !== '', fn ($qb) => $qb->where(fn ($w) => $w->where('titulo', 'like', "%{$q}%")
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$q}%")->orWhere('referencia', 'like', "%{$q}%"))))
            ->latest()
            ->paginate(48)
            ->withQueryString();

        $gestiona = MarketingCreativo::puedeGestionar($u);
        $post = $postId ? MarketingPost::find($postId) : null;

        return Inertia::render('Marketing/Creativos', [
            'creativos' => $creativos->through(fn (MarketingCreativo $c) => $c->paraVista($u)),
            'filtros' => [
                'producto' => $productoId ?: null, 'contenedor' => $contenedorId ?: null,
                'post' => $postId ?: null, 'tipo' => $tipo, 'q' => $q,
            ],
            'gestiona' => $gestiona,
            // Para subir desde un post de la parrilla: el material queda ligado
            // a su producto y contenedor sin volver a elegirlos.
            'post' => $post ? [
                'id' => $post->id, 'titulo' => $post->titulo,
                'producto_id' => $post->producto_id, 'importacion_id' => $post->importacion_id,
            ] : null,
            'producto' => $productoId ? Producto::find($productoId, ['id', 'nombre', 'referencia']) : null,
            'productos' => $gestiona
                ? Producto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'referencia'])
                    ->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->nombre} ({$p->referencia})"])
                : [],
            'contenedores' => Importacion::query()->orderByDesc('id')->limit(100)->get(['id', 'numero', 'contenedor'])
                ->map(fn ($i) => ['id' => $i->id, 'label' => $i->numero.($i->contenedor ? " · {$i->contenedor}" : '')]),
            'max_kb' => MarketingCreativo::MAX_KB,
        ]);
    }

    /** Uno o varios archivos con los mismos datos, o un enlace externo. */
    public function subir(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', 'in:'.implode(',', MarketingCreativo::TIPOS)],
            'titulo' => ['nullable', 'string', 'max:200'],
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'importacion_id' => ['nullable', 'integer', 'exists:compras_importaciones,id'],
            'post_id' => ['nullable', 'integer', 'exists:marketing_posts,id'],
            'permiso' => ['required', 'in:'.implode(',', MarketingCreativo::PERMISOS)],
            'enlace_externo' => ['nullable', 'url', 'max:1000', 'required_without:archivos'],
            'archivos' => ['nullable', 'array', 'max:20', 'required_without:enlace_externo'],
            'archivos.*' => ['file', 'max:'.MarketingCreativo::MAX_KB,
                'mimes:jpg,jpeg,png,webp,gif,heic,mp4,mov,m4v,webm,pdf,zip'],
        ], [
            'archivos.*.max' => 'Cada archivo puede pesar hasta '.(MarketingCreativo::MAX_KB / 1024).' MB. Lo más pesado súbalo como enlace (Drive, Dropbox, YouTube).',
            'enlace_externo.required_without' => 'Suba un archivo o pegue un enlace.',
        ]);

        // Si viene de un post, hereda su producto y contenedor.
        if (! empty($data['post_id'])) {
            $post = MarketingPost::find($data['post_id']);
            $data['producto_id'] ??= $post?->producto_id;
            $data['importacion_id'] ??= $post?->importacion_id;
        }

        $base = [
            'tipo' => $data['tipo'],
            'producto_id' => $data['producto_id'] ?? null,
            'importacion_id' => $data['importacion_id'] ?? null,
            'post_id' => $data['post_id'] ?? null,
            'permiso' => $data['permiso'],
            'subido_por' => $r->user()->id,
        ];

        $n = 0;
        foreach ($r->file('archivos', []) as $archivo) {
            $nombre = $archivo->getClientOriginalName();
            $carpeta = 'marketing/creativos/'.now()->format('Y/m');
            $path = $archivo->storeAs($carpeta, Str::uuid().'.'.strtolower($archivo->getClientOriginalExtension()), MarketingCreativo::DISCO);
            MarketingCreativo::create([
                ...$base,
                'titulo' => ($data['titulo'] ?? null) ?: pathinfo($nombre, PATHINFO_FILENAME),
                'archivo_path' => $path,
                'archivo_nombre' => $nombre,
                'mime' => $archivo->getMimeType(),
                'tamano' => $archivo->getSize(),
            ]);
            $n++;
        }

        if (! empty($data['enlace_externo'])) {
            MarketingCreativo::create([
                ...$base,
                'titulo' => ($data['titulo'] ?? null) ?: parse_url($data['enlace_externo'], PHP_URL_HOST),
                'enlace_externo' => $data['enlace_externo'],
            ]);
            $n++;
        }

        return back()->with('success', $n === 1 ? 'Creativo subido.' : "{$n} creativos subidos.");
    }

    public function actualizar(Request $r, int $creativo): RedirectResponse
    {
        $data = $r->validate([
            'titulo' => ['sometimes', 'required', 'string', 'max:200'],
            'tipo' => ['sometimes', 'required', 'in:'.implode(',', MarketingCreativo::TIPOS)],
            'permiso' => ['sometimes', 'required', 'in:'.implode(',', MarketingCreativo::PERMISOS)],
        ]);
        MarketingCreativo::findOrFail($creativo)->update($data);

        return back()->with('success', 'Creativo actualizado.');
    }

    public function eliminar(int $creativo): RedirectResponse
    {
        MarketingCreativo::findOrFail($creativo)->delete();

        return back()->with('success', 'Creativo eliminado.');
    }

    /**
     * Para verlo dentro de la app (imagen o video). `response()->file` atiende
     * pedidos por rangos, que es lo que usa el reproductor de video para
     * adelantar; sin eso, en Safari el video ni arranca.
     */
    public function ver(Request $r, int $creativo): BinaryFileResponse
    {
        $c = $this->conArchivo($creativo);
        abort_unless($c->puedeVer($r->user()), 403);

        return response()->file(Storage::disk(MarketingCreativo::DISCO)->path($c->archivo_path), [
            'Content-Type' => $c->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($c->archivo_nombre).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function descargar(Request $r, int $creativo): BinaryFileResponse
    {
        $c = $this->conArchivo($creativo);
        abort_unless($c->puedeDescargar($r->user()), 403, 'Este creativo es solo para ver.');

        return response()->download(Storage::disk(MarketingCreativo::DISCO)->path($c->archivo_path), $c->archivo_nombre);
    }

    private function conArchivo(int $id): MarketingCreativo
    {
        $c = MarketingCreativo::findOrFail($id);
        abort_unless($c->archivo_path && Storage::disk(MarketingCreativo::DISCO)->exists($c->archivo_path), 404, 'El archivo ya no está.');

        return $c;
    }
}
