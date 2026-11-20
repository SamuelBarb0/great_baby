<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * MEJORAS-A · ⌘K: búsqueda global instantánea desde cualquier página.
 * Busca en facturas, contactos, pedidos Dropi, pedidos B2B, variantes por código.
 */
class BusquedaGlobalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(['auth', 'throttle:60,1'])];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['resultados' => []]);
        }

        $out = [];
        $like = "%{$q}%";
        $u = $request->user();

        // El buscador no puede ser una puerta trasera: antes bastaba estar
        // autenticado para enumerar la cartera y el libro de clientes letra por
        // letra, sin importar los permisos del rol. Cada bloque pide el mismo
        // permiso que la pantalla a la que lleva.
        /*
         * El permiso que pide el DESTINO, no uno que se le parezca.
         *
         * Antes este bloque miraba `facturas` y `contactos`, que son dos
         * casillas de la pantalla de roles que ningún controlador consulta:
         * `/app/facturas/{id}` y `/app/contactos/{id}` piden `contabilidad`.
         * El Vendedor tiene `facturas` por matriz, así que el buscador le
         * devolvía la factura y la pantalla se la negaba — el mismo defecto
         * que ya habíamos corregido cuatro veces en logística, acá otra vez.
         *
         * Se piden los dos: el del buscador (que es lo que el admin marcó) y
         * el de la pantalla (que es lo que de verdad se va a exigir).
         */
        $puedeFacturas = \App\Auth\Permisos::puede($u, 'facturas')
            && \App\Auth\Permisos::puede($u, 'contabilidad');
        $puedeContactos = \App\Auth\Permisos::puede($u, 'contactos')
            && \App\Auth\Permisos::puede($u, 'contabilidad');

        // Facturas por número
        if ($puedeFacturas) {
            foreach (FacturaVenta::where('numero', 'like', $like)->limit(5)->get(['id', 'numero', 'total', 'estado']) as $f) {
                $out[] = [
                    'tipo' => 'Factura',
                    'label' => $f->numero,
                    'sub' => '$' . number_format($f->total, 0, ',', '.') . ' · ' . (is_object($f->estado) ? $f->estado->value : $f->estado),
                    'url' => '/app/facturas/' . $f->id,
                    'icon' => 'FileText',
                ];
            }
        }

        // Contactos
        if ($puedeContactos) {
        foreach (Contacto::where('activo', true)->where(function ($w) use ($like) {
            $w->where('nombre_completo', 'like', $like)
                ->orWhere('razon_social', 'like', $like)
                ->orWhere('numero_documento', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('telefono', 'like', $like);
        })->limit(5)->get(['id', 'nombre_completo', 'razon_social', 'telefono']) as $c) {
            $out[] = [
                'tipo' => 'Contacto',
                'label' => $c->razon_social ?: $c->nombre_completo,
                'sub' => $c->telefono ?? '',
                'url' => '/app/contactos/' . $c->id,
                'icon' => 'User',
            ];
        }
        }

        // Pedidos Dropi por guía o Dropi ID
        if (\App\Auth\Permisos::puede($u, 'dropi_pedidos')) {
            foreach (DropiPedido::where(function ($w) use ($like) {
                $w->where('guia', 'like', $like)->orWhere('dropi_orden_id', 'like', $like);
            })->limit(5)->get(['id', 'guia', 'cliente_nombre', 'estado']) as $p) {
                $out[] = [
                    'tipo' => 'Dropi',
                    'label' => $p->guia,
                    'sub' => $p->cliente_nombre . ' · ' . (is_object($p->estado) ? $p->estado->value : $p->estado),
                    'url' => '/app/dropi',
                    'icon' => 'Truck',
                ];
            }
        }

        // Pedidos B2B · los ve quien trabaja el pedido: comercial o logística.
        /*
         * `/app/pedidos-b2b/{id}` pide `esEquipoBodega()` o el rol
         * Despachador. `logistica` no alcanza: el Alistador la tiene y la
         * pantalla igual lo rechaza.
         */
        if ($u?->esEquipoBodega() || $u?->hasRole('Despachador') || \App\Auth\Permisos::esRoot($u)) {
            foreach (PedidoCliente::where('numero', 'like', $like)->limit(5)->get(['id', 'numero', 'total', 'estado']) as $p) {
                $out[] = [
                    'tipo' => 'Pedido B2B',
                    'label' => $p->numero,
                    'sub' => '$' . number_format($p->total, 0, ',', '.') . ' · ' . $p->estado,
                    'url' => '/app/pedidos-b2b/' . $p->id,
                    'icon' => 'Package',
                ];
            }
        }

        /*
         * Variantes por código de barras.
         *
         * Buscar un producto por su código no revela nada sensible, pero el
         * resultado LLEVA AL KARDEX, y el kardex sólo lo abren Aracely,
         * alistadores y admins de bodega. Ofrecérselo al Contador o al
         * Gerente era mandarlos a un 403: escriben el código para ver de
         * dónde salió un movimiento y el sistema les cierra la puerta que
         * él mismo les mostró.
         */
        $puedeKardex = \App\Auth\Permisos::esRoot($u)
            || $u?->esAlistador() || $u?->esAdminBodega();

        foreach ($puedeKardex ? ProductoVariante::with('producto:id,nombre,referencia')
            ->where('codigo_barras', 'like', $like)->limit(5)->get(['id', 'producto_id', 'codigo_barras'])
            : collect() as $v) {
            $out[] = [
                'tipo' => 'Producto',
                'label' => $v->codigo_barras,
                'sub' => $v->producto?->nombre,
                'url' => '/app/inventario/kardex?codigo=' . urlencode($v->codigo_barras),
                'icon' => 'Boxes',
            ];
        }

        /*
         * Accesos rápidos.
         *
         * Cada uno lleva la condición de SU destino. Antes salían todos para
         * todo el mundo: al Colaborador le ofrecía la estación de empaque y
         * al Vendedor la nueva orden de compra.
         */
        $shortcuts = [
            ['nombre' => 'Nueva OC', 'url' => '/app/compras/oc/nueva', 'kw' => ['oc', 'compra', 'orden'],
                'puede' => fn () => $u?->esEquipoBodega() || $u?->esContable()],
            ['nombre' => 'Registrar devolución Dropi', 'url' => '/app/dropi/devolucion/registrar', 'kw' => ['devol', 'dropi'],
                'puede' => fn () => \App\Auth\Permisos::puede($u, 'dropi_devoluciones')],
            ['nombre' => 'Nueva garantía', 'url' => '/app/garantias/nueva', 'kw' => ['garan', 'ticket'],
                'puede' => fn () => \App\Auth\Permisos::puede($u, 'garantias')],
            ['nombre' => 'Estación de empaque', 'url' => '/app/estacion-empaque', 'kw' => ['empaque', 'estacion'],
                'puede' => fn () => \App\Auth\Permisos::puede($u, 'estacion_empaque')],
            ['nombre' => 'Torre de Control', 'url' => '/app', 'kw' => ['torre', 'control'],
                'puede' => fn () => true],   // `/app` redirige a lo de cada quien
        ];
        $qLower = mb_strtolower($q);
        foreach ($shortcuts as $s) {
            if (! ($s['puede'])()) {
                continue;
            }

            foreach ($s['kw'] as $kw) {
                if (str_contains($qLower, $kw)) {
                    $out[] = ['tipo' => 'Acción', 'label' => $s['nombre'], 'sub' => '', 'url' => $s['url'], 'icon' => 'Zap'];
                    break;
                }
            }
        }

        return response()->json(['resultados' => array_slice($out, 0, 20)]);
    }
}
