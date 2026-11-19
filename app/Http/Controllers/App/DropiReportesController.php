<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DRP-C · Visibilidad y reportes Dropi (paridad Filament):
 *  - Inventario en vivo (variantes × ubicaciones)
 *  - Reportes Dropi (bloques operativos/finanzas/auditoría/ejecutivos)
 *  - Importar productos Excel
 */
class DropiReportesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // N1 · Reportes Dropi (KPIs financieros, top vendedores/ciudades) SOLO Aracely/Gerencia.
        //     Inventario en vivo (visibilidad de stock) sí es útil para Alistador → gate por método.
        return [
            new Middleware(function (Request $r, \Closure $next) {
                $u = $r->user();
                abort_unless($u, 403);

                $endpointsSoloAdmin = ['reportes', 'importarForm', 'importarProcesar'];
                $accion = $r->route()->getActionMethod();
                if (in_array($accion, $endpointsSoloAdmin, true) && ! $u->esAracely()) {
                    abort(403, 'Solo Aracely/Gerencia puede ver los reportes financieros.');
                }

                abort_unless($u->esAracely() || (method_exists($u, 'esAlistador') && $u->esAlistador()), 403);
                return $next($r);
            }),
        ];
    }

    // ---------- INVENTARIO EN VIVO ----------
    public function inventarioEnVivo(Request $request): Response
    {
        $busqueda = trim((string) $request->input('q', ''));
        $filtroCategoria = (string) $request->input('categoria', '');

        $variantes = ProductoVariante::with('producto:id,referencia,nombre')
            ->when($busqueda, function ($q) use ($busqueda) {
                $b = "%{$busqueda}%";
                $q->where(function ($qq) use ($b) {
                    $qq->where('codigo_barras', 'like', $b)
                        ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $b)->orWhere('referencia', 'like', $b));
                });
            })
            ->orderBy('producto_id')
            ->orderBy('codigo_barras')
            ->limit(200)
            ->get();

        /*
         * Una columna por BODEGA, no por posición.
         *
         * Esta pantalla pinta una matriz variante × ubicación. Con las 435
         * posiciones cargadas pasó de una docena de columnas a 491, o sea
         * ~98.000 celdas: deja de abrirse en un tiempo útil y hay que recorrer
         * 491 columnas de scroll horizontal para encontrar la bodega que tiene
         * algo. El plano de la bodega se mira en Ubicaciones; acá se mira
         * cuánto hay y dónde, al nivel de sede.
         */
        $ubicaciones = InventarioUbicacion::where('activa', true)
            ->whereNull('bodega_id')
            ->when($filtroCategoria, fn ($q) => $q->where('categoria', $filtroCategoria))
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'categoria']);

        // Saldos
        $variantIds = $variantes->pluck('id')->all();
        $ubicIds = $ubicaciones->pluck('id')->all();
        $saldosRaw = InventarioMovimiento::selectRaw('variante_id, ubicacion_id, SUM(cantidad) as saldo')
            ->whereIn('variante_id', $variantIds)
            ->whereIn('ubicacion_id', $ubicIds)
            ->groupBy('variante_id', 'ubicacion_id')
            ->having('saldo', '!=', 0)
            ->get();

        $mapa = [];
        foreach ($saldosRaw as $s) {
            $mapa[$s->variante_id][$s->ubicacion_id] = (int) $s->saldo;
        }

        return Inertia::render('Dropi/Inventario/EnVivo', [
            'filtros' => ['q' => $busqueda, 'categoria' => $filtroCategoria ?: null],
            'variantes' => $variantes->map(fn ($v) => [
                'id' => $v->id,
                'codigo' => $v->codigo_barras,
                'producto' => $v->producto?->nombre,
                'referencia' => $v->producto?->referencia,
                'detalle' => trim(($v->color_nombre ?? '') . ' ' . ($v->talla ?? '')),
                'saldos' => $mapa[$v->id] ?? [],
                'total' => array_sum($mapa[$v->id] ?? []),
            ])->values(),
            'ubicaciones' => $ubicaciones->map(fn ($u) => [
                'id' => $u->id, 'codigo' => $u->codigo, 'nombre' => $u->nombre,
                'categoria' => is_object($u->categoria) ? $u->categoria->value : $u->categoria,
            ])->values(),
            'categorias' => ['venta', 'reserva_proveedor', 'garantia', 'averia_reparar', 'averia_baja', 'produccion', 'general'],
        ]);
    }

    // ---------- REPORTES DROPI ----------
    public function reportes(): Response
    {
        $inicio = now('America/Bogota')->startOfMonth();
        $fin = now('America/Bogota')->endOfMonth();

        $ventas = (float) DropiPedido::where('estado', EstadoPedidoDropi::Pagado)
            ->whereBetween('pagado_at', [$inicio, $fin])->sum('monto_esperado_proveedor');
        $devoluciones = (float) DropiPedido::where('estado', EstadoPedidoDropi::Devuelto)
            ->whereBetween('devuelto_at', [$inicio, $fin])->sum('monto_esperado_proveedor');
        $retiros = abs((float) DropiWalletMovimiento::where('tipo', 'retiro_banco')
            ->whereBetween('fecha', [$inicio, $fin])->sum('monto'));
        $sanciones = 0.0;
        if (class_exists(\App\Modules\Dropi\Models\DropiSancion::class)) {
            $sanciones = (float) \App\Modules\Dropi\Models\DropiSancion::whereBetween('detectada_at', [$inicio, $fin])->sum('diferencia');
        }

        // Rankings de este mes
        $ventasPorTransportadora = DropiPedido::selectRaw('transportadora, COUNT(*) as pedidos, SUM(monto_esperado_proveedor) as total')
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->whereBetween('pagado_at', [$inicio, $fin])
            ->groupBy('transportadora')->orderByDesc('total')->limit(10)->get();

        $ventasPorCiudad = DropiPedido::selectRaw('cliente_ciudad, COUNT(*) as pedidos, SUM(monto_esperado_proveedor) as total')
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->whereBetween('pagado_at', [$inicio, $fin])
            ->groupBy('cliente_ciudad')->orderByDesc('total')->limit(15)->get();

        $ventasPorVendedor = DropiPedido::selectRaw('vendedor_nombre, COUNT(*) as pedidos, SUM(monto_esperado_proveedor) as total')
            ->where('estado', EstadoPedidoDropi::Pagado)
            ->whereBetween('pagado_at', [$inicio, $fin])
            ->whereNotNull('vendedor_nombre')
            ->groupBy('vendedor_nombre')->orderByDesc('total')->limit(15)->get();

        return Inertia::render('Dropi/Reportes/Index', [
            'periodo' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString(), 'label' => $inicio->format('F Y')],
            'kpis' => [
                'ventas' => $ventas,
                'devoluciones' => $devoluciones,
                'retiros' => $retiros,
                'sanciones' => $sanciones,
            ],
            'transportadoras' => $ventasPorTransportadora->map(fn ($r) => [
                'nombre' => $r->transportadora ?: '—', 'pedidos' => (int) $r->pedidos, 'total' => (float) $r->total,
            ])->all(),
            'ciudades' => $ventasPorCiudad->map(fn ($r) => [
                'nombre' => $r->cliente_ciudad ?: '—', 'pedidos' => (int) $r->pedidos, 'total' => (float) $r->total,
            ])->all(),
            'vendedores' => $ventasPorVendedor->map(fn ($r) => [
                'nombre' => $r->vendedor_nombre, 'pedidos' => (int) $r->pedidos, 'total' => (float) $r->total,
            ])->all(),
        ]);
    }

    // ---------- IMPORTAR PRODUCTOS ----------
    public function importarForm(): Response
    {
        return Inertia::render('Dropi/Productos/Importar');
    }

    public function importarProcesar(Request $request)
    {
        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ]);

        $file = $data['archivo'];
        $ruta = $file->storeAs('imports', 'productos_' . now()->format('YmdHis') . '.' . $file->getClientOriginalExtension(), 'local');
        $ext = strtolower($file->getClientOriginalExtension());

        $filas = $this->leerArchivo(storage_path('app/private/' . $ruta), $ext);

        // F20 · validar encabezado real. Si faltan columnas mínimas la
        // importación se detiene con mensaje claro — evita el falso "0 errores"
        // cuando venían filas silenciosamente sin `nombre` o `precio`.
        $requeridas = ['referencia', 'nombre', 'precio_proveedor'];
        $encabezadoFila0 = ! empty($filas) ? array_keys($filas[0]) : [];
        $faltan = array_diff($requeridas, $encabezadoFila0);
        if (! empty($faltan)) {
            return back()
                ->with('error', 'La plantilla no tiene las columnas: ' . implode(', ', $faltan)
                    . '. Descarga la plantilla oficial y vuelve a intentar.')
                ->withInput();
        }

        $creados = 0; $actualizados = 0; $errores = [];
        $tx = DB::transaction(function () use ($filas, &$creados, &$actualizados, &$errores) {
            foreach ($filas as $idx => $f) {
                try {
                    $ref = trim((string) ($f['referencia'] ?? ''));
                    $nombre = trim((string) ($f['nombre'] ?? ''));
                    $precio = (float) ($f['precio_proveedor'] ?? 0);
                    if (! $ref || ! $nombre) {
                        $errores[] = ['fila' => $idx + 2, 'motivo' => 'Falta referencia o nombre'];
                        continue;
                    }
                    $existia = Producto::where('referencia', $ref)->exists();
                    Producto::updateOrCreate(
                        ['referencia' => $ref],
                        ['nombre' => $nombre, 'precio_proveedor' => $precio, 'activo' => true],
                    );
                    $existia ? $actualizados++ : $creados++;
                } catch (\Throwable $e) {
                    $errores[] = ['fila' => $idx + 2, 'motivo' => $e->getMessage()];
                }
            }
        });

        return back()->with('success', "Importación: {$creados} creados, {$actualizados} actualizados, " . count($errores) . ' errores.')
            ->with('errores', $errores);
    }

    /** Lee CSV o XLSX simples con encabezado en fila 1. */
    private function leerArchivo(string $path, string $ext): array
    {
        $out = [];
        if ($ext === 'csv' || $ext === 'txt') {
            if (($h = fopen($path, 'r')) !== false) {
                $headers = fgetcsv($h);
                $headers = array_map('strtolower', array_map('trim', $headers ?: []));
                while (($row = fgetcsv($h)) !== false) {
                    $out[] = array_combine($headers, array_pad($row, count($headers), null));
                }
                fclose($h);
            }
        } else {
            // XLSX vía PhpSpreadsheet (Composer ya lo tiene por dompdf)
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
                $reader->setReadDataOnly(true);
                $sp = $reader->load($path);
                $sheet = $sp->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, false);
                if (empty($rows)) return [];
                $headers = array_map('strtolower', array_map(fn ($h) => trim((string) $h), $rows[0]));
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    if (! array_filter($row)) continue;
                    $out[] = array_combine($headers, array_pad($row, count($headers), null));
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('No se pudo leer XLSX: ' . $e->getMessage());
            }
        }
        return $out;
    }
}
