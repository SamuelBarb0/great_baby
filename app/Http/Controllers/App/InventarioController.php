<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Inventario\Models\AlertaStockDisparada;
use App\Modules\Inventario\Models\ReservaInventario;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Inventario\Models\Traslado;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class InventarioController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // LOG · Inventario es la casa del AdminBodega. Antes solo Aracely
                //   entraba y Jorge recibía 403 en todo /app/inventario/*.
                abort_unless($r->user()?->esEquipoBodega(), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Inventario/Index', [
            'tab' => (string) $request->input('tab', 'stock'),
            'kpis' => $this->kpis(),
            'stock' => $this->stockPorBodega(),
            'traslados' => $this->traslados(),
            'tomas' => $this->tomas(),
            'alertas' => $this->alertas(),
        ]);
    }

    private function kpis(): array
    {
        // INV-A4 · stock total correcto · antes se hacía SUM(cantidad) sobre
        // TODA la tabla, lo que arrastraba traslados en tránsito (una salida
        // -X sin entrada +X todavía) e inflaba el número con reversas. Ahora
        // agrupamos por (sujeto, ubicacion) y descartamos buckets negativos:
        // el stock físico es la suma de todas las posiciones con saldo > 0.
        // INV-A5 · el número de arriba es lo VENDIBLE, no todo lo que hay.
        //   La ubicación dice para qué sirve la mercancía, no sólo dónde está:
        //   avería por reparar, baja total y lo reservado a garantías no se le
        //   puede vender a nadie. Sumarlo todo daba, en producción, 21.645
        //   unidades cuando lo vendible eran 13.091 — 8.554 de ellas dadas de
        //   baja y valoradas en $0. Gerencia leía un inventario 65% más grande
        //   del que existe.
        //   No se esconde: lo excluido viaja aparte y la tarjeta lo dice.
        //
        // INV-A6 · y un movimiento ANULADO no es stock.
        //   `InventarioMovimiento` fuerza borrado suave a propósito: el kardex
        //   no se borra, se anula. Pero estas consultas son `DB::table()`, que
        //   no sabe de `deleted_at`, así que seguían sumando lo anulado. Es lo
        //   que dejó sin efecto a `inventario:reparar-kardex`, que el 8-oct
        //   anuló 144 movimientos de productos borrados —8.958 unidades de
        //   stock fantasma— y el tablero siguió contándolas igual.
        $vendibles = array_map(
            fn (CategoriaUbicacion $c) => $c->value,
            array_filter(CategoriaUbicacion::cases(), fn ($c) => $c->disponibleParaVenta()),
        );

        $stockVendible = 0;
        $stockNoVendible = 0;
        if (Schema::hasTable('inventario_movimientos')) {
            // El join no pierde filas: `ubicacion_id` es NOT NULL con FK.
            $saldos = DB::table('inventario_movimientos as m')
                ->join('inventario_ubicaciones as u', 'u.id', '=', 'm.ubicacion_id')
                ->whereNull('m.deleted_at')
                ->selectRaw('u.categoria as categoria, COALESCE(m.variante_id, 0) as v, COALESCE(m.producto_id, 0) as p, m.ubicacion_id, SUM(m.cantidad) as saldo')
                ->groupBy('u.categoria', 'v', 'p', 'm.ubicacion_id')
                ->havingRaw('SUM(m.cantidad) > 0')
                ->get();

            $stockVendible = (int) $saldos->whereIn('categoria', $vendibles)->sum('saldo');
            $stockNoVendible = (int) $saldos->whereNotIn('categoria', $vendibles)->sum('saldo');
        }

        return [
            'unidades_stock' => $stockVendible,
            'unidades_no_vendibles' => $stockNoVendible,
            'alertas_activas' => AlertaStockDisparada::where('resuelta', false)->count(),
            'traslados_transito' => Traslado::where('estado', 'en_transito')->count(),
            'reservas_activas' => ReservaInventario::where('activa', true)->count(),
        ];
    }

    private function stockPorBodega(int $limit = 40): array
    {
        if (! Schema::hasTable('inventario_movimientos')) return [];

        // C-F3 FIX ALTO auditor · el reporte debe INCLUIR productos agregados.
        //   Antes: INNER JOIN a producto_variantes filtraba los agregados.
        //   Ahora: UNION de granulares (variante_id set) + agregados (variante_id NULL).
        $granulares = DB::table('inventario_movimientos as m')
            ->join('producto_variantes as v', 'v.id', '=', 'm.variante_id')
            ->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->join('inventario_ubicaciones as u', 'u.id', '=', 'm.ubicacion_id')
            ->whereNull('m.deleted_at')
            ->selectRaw('
                p.referencia,
                p.nombre,
                v.color_codigo as color,
                v.talla,
                v.codigo_barras as codigo,
                u.nombre as bodega,
                "granular" as modo,
                SUM(m.cantidad) as stock
            ')
            ->groupBy('p.referencia', 'p.nombre', 'v.color_codigo', 'v.talla', 'v.codigo_barras', 'u.nombre')
            ->havingRaw('SUM(m.cantidad) > 0');

        $agregados = DB::table('inventario_movimientos as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->join('inventario_ubicaciones as u', 'u.id', '=', 'm.ubicacion_id')
            ->whereNull('m.variante_id')
            ->whereNull('m.deleted_at')
            ->selectRaw('
                p.referencia,
                p.nombre,
                p.descripcion as color,
                NULL as talla,
                NULL as codigo,
                u.nombre as bodega,
                "agregado" as modo,
                SUM(m.cantidad) as stock
            ')
            ->groupBy('p.referencia', 'p.nombre', 'p.descripcion', 'u.nombre')
            ->havingRaw('SUM(m.cantidad) > 0');

        $rows = $granulares->union($agregados)
            ->orderByDesc('stock')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'referencia' => $r->referencia,
            'nombre' => $r->nombre,
            'color' => $r->color,
            'talla' => $r->talla,
            'codigo' => $r->codigo,
            'bodega' => $r->bodega,
            'modo' => $r->modo,
            'stock' => (int) $r->stock,
        ])->all();
    }

    private function traslados(): array
    {
        return Traslado::query()
            ->with(['origen:id,nombre', 'destino:id,nombre', 'solicitante:id,name'])
            ->orderByDesc('created_at')->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'origen' => $t->origen?->nombre,
                'destino' => $t->destino?->nombre,
                'motivo' => $t->motivo,
                'estado' => $t->estado,
                'fecha_solicitud' => $t->fecha_solicitud?->toDateString(),
                'fecha_ejecucion' => $t->fecha_ejecucion?->toDateString(),
                'creador' => $t->solicitante?->name,
            ])->all();
    }

    private function tomas(): array
    {
        return TomaFisica::query()
            ->with(['ubicacion:id,nombre', 'creador:id,name'])
            ->orderByDesc('created_at')->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'bodega' => $t->ubicacion?->nombre,
                'alcance' => $t->alcance,
                'fecha_conteo' => $t->fecha_conteo?->toDateString(),
                'estado' => $t->estado,
                'creador' => $t->creador?->name,
                'observaciones' => $t->observaciones,
            ])->all();
    }

    private function alertas(): array
    {
        return AlertaStockDisparada::query()
            // C-F-QA3 · eager-load producto agregado
            ->with(['variante.producto', 'producto', 'ubicacion:id,nombre', 'config'])
            ->where('resuelta', false)
            ->orderByDesc('created_at')->limit(30)
            ->get()
            ->map(function ($a) {
                $esAgg = $a->esAgregada();
                return [
                'id' => $a->id,
                // C-F-QA3 · fallback polimórfico: agregado usa producto directo
                'producto' => $esAgg
                    ? (($a->producto?->nombre ?? '—').' · AGREGADO')
                    : $a->variante?->producto?->nombre,
                'referencia' => $esAgg
                    ? $a->producto?->referencia
                    : $a->variante?->producto?->referencia,
                'talla' => $esAgg ? null : $a->variante?->talla,
                'bodega' => $a->ubicacion?->nombre,
                'tipo' => $a->tipo,
                'es_agregado' => $esAgg,
                'stock_actual' => (int) $a->saldo_al_disparar,
                // Re-audit M3 PATRÓN ζ (FUNC-A6 / DATOS-C1) · bug: la columna
                // real es `stock_minimo`, no `umbral_minimo`. Antes el KPI
                // siempre mostraba 0. Se agrega también `stock_maximo` +
                // `punto_reorden` según el tipo para el widget.
                'umbral' => (int) ($a->config?->stock_minimo ?? 0),
                'punto_reorden' => (int) ($a->config?->punto_reorden ?? 0),
                'stock_maximo' => (int) ($a->config?->stock_maximo ?? 0),
                'creada' => $a->created_at?->diffForHumans(),
                ];
            })->all();
    }
}
