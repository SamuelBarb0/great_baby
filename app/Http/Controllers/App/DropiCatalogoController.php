<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Jobs\LeerCatalogoDropiJob;
use App\Modules\Dropi\Services\CatalogoDropi;
use App\Modules\Dropi\Support\DestinosVinculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Traer productos de Dropi»: foto del catálogo propio por la API, vista previa
 * contra el ERP y vínculos de lo seguro. Ver App\Modules\Dropi\Services\CatalogoDropi.
 */
class DropiCatalogoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Misma puerta que importar productos y los reportes de Dropi.
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esAracely(), 403);

                return $next($r);
            }),
        ];
    }

    public function index(CatalogoDropi $catalogo): Response
    {
        $previa = $catalogo->previsualizar();

        return Inertia::render('Dropi/Catalogo', [
            'leido_at' => $previa['leido_at'],
            'resumen' => $previa['resumen'],
            'filas' => $previa['filas'],
            'lectura' => Cache::get(LeerCatalogoDropiJob::ESTADO),
        ]);
    }

    public function leer(): RedirectResponse
    {
        Cache::put(LeerCatalogoDropiJob::ESTADO, ['estado' => 'en_cola', 'desde' => now()->toIso8601String()], 900);
        LeerCatalogoDropiJob::dispatch();

        return back()->with('info', 'Leyendo el catálogo de Dropi. Tarda unos minutos: Dropi entrega de a 40 productos y pide esperar entre llamadas.');
    }

    /**
     * Buscador del modal «Vincular…»: productos agregados (`p:<id>`) y variantes
     * (`v:<id>`). Es la misma búsqueda de «Productos de Dropi» en /admin (DestinosVinculo), para
     * que las dos puertas ofrezcan exactamente lo mismo.
     */
    public function buscar(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $opciones = DestinosVinculo::buscar($q);

        return response()->json(collect($opciones)
            ->map(fn ($label, $valor) => ['valor' => $valor, 'label' => $label, 'agregado' => str_starts_with($valor, 'p:')])
            ->values());
    }

    /** Vincula una llave desde la fila de la pantalla. */
    public function vincularLlave(Request $r, CatalogoDropi $catalogo): RedirectResponse
    {
        $data = $r->validate([
            'llave' => ['required', 'string', 'max:60'],
            'destino' => ['required', 'string', 'regex:/^[vp]:\d+$/'],
        ]);

        try {
            $res = $catalogo->vincularLlave($data['llave'], $data['destino'], $r->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$data['llave']} vinculado · {$res['items']} línea(s) de pedido con producto · "
            ."{$res['pedidos_liberados']} pedido(s) salieron de «pendiente por inventario».");
    }

    public function vincular(Request $r, CatalogoDropi $catalogo): RedirectResponse
    {
        $res = $catalogo->vincular($r->user()->id);

        $msg = "{$res['vinculos']} vínculos hechos · {$res['items']} líneas de pedido ya tienen producto · "
            ."{$res['pedidos_liberados']} pedidos salieron de «pendiente por inventario».";

        return $res['errores']
            ? back()->with('warning', $msg.' No se pudieron: '.implode(' | ', array_slice($res['errores'], 0, 5)))
            : back()->with('success', $msg);
    }
}
