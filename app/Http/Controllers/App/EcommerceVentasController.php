<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Services\VentasEcommerce;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ventas del e-commerce, mes a mes: lo facturado y lo que pasó en Dropi.
 * El cálculo vive en App\Modules\Dropi\Services\VentasEcommerce.
 *
 * Es la pantalla de inicio del espacio e-commerce. Sólo LEE: las facturas por
 * su eje `linea_negocio = dropi` (el mismo que usan el libro y el balance, así
 * los totales de acá y los de contabilidad no pueden contar cosas distintas) y
 * los pedidos y el wallet de las tablas de Dropi. No asienta nada.
 */
class EcommerceVentasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Misma puerta que los reportes financieros de Dropi (DropiReportesController).
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esAracely(), 403);

                return $next($r);
            }),
        ];
    }

    public function index(Request $request, VentasEcommerce $ventas): Response
    {
        return Inertia::render('Ecommerce/Ventas', $ventas->delMes((string) $request->input('mes', '')));
    }
}
