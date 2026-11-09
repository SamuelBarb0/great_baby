<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Services\CuentasPorPagar;
use App\Modules\Contabilidad\Support\FiltroEjes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que se le debe a los proveedores, con datos del ERP.
 *
 * Ya existía una pantalla de cuentas por pagar, pero lee de la API de SIIGO:
 * sin SIIGO conectado —producción está en sandbox— no muestra nada. Mientras
 * tanto, la lista con la que de verdad deciden a quién pagar es una hoja
 * impresa que se marca con resaltador y se firma a lápiz al margen.
 *
 * Esta es esa hoja, calculada. Es el insumo de la programación de pagos y de
 * la columna «proyección» del flujo de caja.
 */
class CuentasPorPagarController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'cuentas_por_pagar'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $r, CuentasPorPagar $servicio): Response
    {
        // `?linea=dropi` · el panel de Dropi abre la hoja ya filtrada. Aquí el
        //   resumen sí cambia: es la deuda de esa línea.
        $ejes = FiltroEjes::de($r->query('linea'), null);
        $filas = $servicio->pendientes($ejes);

        // Los filtros son los tres con los que se mira la hoja: todo, lo que
        // ya se pasó de fecha, y lo que está por caer.
        $filtro = (string) $r->query('estado', 'todas');
        $visibles = match ($filtro) {
            'vencidas' => $filas->where('dias_mora', '>', 0),
            'por_vencer' => $filas->where('dias_mora', '<=', 0),
            default => $filas,
        };

        if ($proveedor = trim((string) $r->query('q', ''))) {
            $visibles = $visibles->filter(
                fn ($f) => str_contains(mb_strtolower($f['proveedor']), mb_strtolower($proveedor))
                    || str_contains(mb_strtolower((string) $f['factura']), mb_strtolower($proveedor))
            );
        }

        return Inertia::render('Compras/CuentasPorPagar', [
            'filas' => $visibles->values(),
            // El resumen se calcula sobre TODO, no sobre lo filtrado: si al
            // filtrar «vencidas» el total bajara, el número de arriba dejaría
            // de ser la deuda y pasaría a ser otra cosa sin avisar.
            'resumen' => $servicio->resumen($filas),
            'porProveedor' => $filas->groupBy('proveedor')
                ->map(fn ($g, $nombre) => [
                    'proveedor' => $nombre,
                    'facturas' => $g->count(),
                    'saldo' => round($g->sum('saldo'), 2),
                    'vencido' => round($g->where('dias_mora', '>', 0)->sum('saldo'), 2),
                ])
                ->sortByDesc('saldo')->values(),
            'filtros' => ['estado' => $filtro, 'q' => $proveedor,
                'linea' => $ejes->linea === 'todas' ? null : $ejes->linea],
        ]);
    }
}
