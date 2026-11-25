<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiCargaOperacion as Op;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * «Vincular con el ERP» en el otro sentido: productos que entraron al ERP en una
 * compra de Dropi y que Dropi no conoce (reunión con la señora Lahida,
 * 24-nov-2026: de los 16 productos del SA383, 15 no existían en Dropi y la
 * recepción no tenía a dónde mandar el stock).
 *
 * Nada se crea solo: bodega decide si el producto se CREA en Dropi o se ASOCIA
 * a uno que ya está allá. En los dos casos queda una carga DC- con lo que se
 * mandó, que se revisa y se confirma en «Cargar productos y stock a Dropi».
 */
class ProductosSinDropi
{
    public function __construct(
        protected DropiWebClient $dropi,
        protected PlanificarCargaDropi $planificador,
    ) {}

    /**
     * Lo recibido en compras de Dropi que no tiene id de Dropi ni vínculo.
     *
     * @return list<array{id:int, referencia:string, nombre:string, desglose:bool, stock:int, por_color:array<string,int>, oc:?string, recibido_at:?string}>
     */
    public function lista(): array
    {
        $recibidos = DB::table('compras_recepcion_items as i')
            ->join('compras_recepciones as r', 'r.id', '=', 'i.recepcion_id')
            ->join('compras_ordenes as o', 'o.id', '=', 'r.orden_id')
            ->where('r.linea_negocio', LineaNegocio::Dropi->value)
            ->where('r.estado', 'confirmada')
            ->whereNull('r.deleted_at')
            ->whereNotNull('i.producto_id')
            ->groupBy('i.producto_id')
            ->selectRaw('i.producto_id, MAX(o.numero) as oc, MAX(r.confirmada_at) as recibido_at')
            ->get()->keyBy('producto_id');

        if ($recibidos->isEmpty()) {
            return [];
        }

        $conVinculo = DropiVinculoProducto::query()
            ->leftJoin('producto_variantes as v', 'v.id', '=', 'dropi_vinculos_producto.variante_id')
            ->selectRaw('COALESCE(dropi_vinculos_producto.producto_id, v.producto_id) as pid')
            ->pluck('pid')->filter()->flip();

        return Producto::query()
            ->whereIn('id', $recibidos->keys())
            ->whereNull('dropi_sku')
            ->with('variantes')
            ->orderBy('nombre')
            ->get()
            ->reject(fn (Producto $p) => $conVinculo->has($p->id))
            ->map(function (Producto $p) use ($recibidos) {
                $s = self::stockErp($p);

                return [
                    'id' => $p->id,
                    'referencia' => (string) $p->referencia,
                    'nombre' => (string) $p->nombre,
                    'desglose' => (bool) $p->desglose_stock,
                    'stock' => $s['total'],
                    'por_color' => $s['por_color'],
                    'oc' => $recibidos[$p->id]->oc,
                    'recibido_at' => $recibidos[$p->id]->recibido_at,
                ];
            })->values()->all();
    }

    /**
     * Saldo vendible del ERP (ubicaciones activas y disponibles para la venta),
     * total y por color cuando el producto lleva el stock por variante.
     *
     * @return array{total:int, por_color:array<string,int>}
     */
    public static function stockErp(Producto $p): array
    {
        $ubicaciones = InventarioUbicacion::query()->where('disponible_para_venta', true)->where('activa', true)->pluck('id');
        if ($ubicaciones->isEmpty()) {
            return ['total' => 0, 'por_color' => []];
        }

        if (! $p->desglose_stock) {
            $total = (int) InventarioMovimiento::query()->where('producto_id', $p->id)->whereNull('variante_id')
                ->whereIn('ubicacion_id', $ubicaciones)->sum('cantidad');

            return ['total' => max(0, $total), 'por_color' => []];
        }

        $saldos = InventarioMovimiento::query()->whereIn('variante_id', $p->variantes->pluck('id'))
            ->whereIn('ubicacion_id', $ubicaciones)
            ->groupBy('variante_id')->selectRaw('variante_id, SUM(cantidad) as saldo')->pluck('saldo', 'variante_id');

        $porColor = [];
        foreach ($p->variantes as $v) {
            $n = (int) ($saldos[$v->id] ?? 0);
            if ($n > 0) {
                $color = trim((string) ($v->color_nombre ?: $v->codigo_barras));
                $porColor[$color] = ($porColor[$color] ?? 0) + $n;
            }
        }

        return ['total' => array_sum($porColor), 'por_color' => $porColor];
    }

    /**
     * «Crear en Dropi»: una carga individual con el producto armado desde el ERP
     * (nombre, referencia como SKU, un color por variante y el saldo como stock
     * inicial). Falta el precio, el sugerido, la categoría y las medidas: se
     * llenan en la carga antes de confirmar. Al crearse, EjecutarOperacionDropi
     * deja la ficha del ERP vinculada con cada variación.
     */
    public function cargaCrear(Producto $p, ?int $userId): Carga
    {
        $this->exigirSinDropi($p);

        // Un segundo clic no arma otra carga: dos cargas mandadas serían dos
        //   productos iguales en Dropi. Se reabre la que sigue abierta.
        $abierta = Carga::whereIn('estado', [Carga::BORRADOR, Carga::PROCESANDO])
            ->whereHas('operaciones', fn ($q) => $q->where('tipo', Op::CREAR)->where('detalle->erp_producto_id', $p->id))
            ->latest('id')->first();
        if ($abierta) {
            return $abierta;
        }

        $s = self::stockErp($p);

        $variaciones = [];
        foreach (array_keys($s['por_color']) as $k => $color) {
            $variaciones[] = ['valor' => mb_convert_case($color, MB_CASE_TITLE), 'sku' => "{$p->referencia}-{$k}", 'cantidad' => $s['por_color'][$color]];
        }

        return $this->nuevaCarga("Producto del ERP {$p->referencia}", $userId, [
            'fila' => 1, 'ref_excel' => $p->referencia, 'nombre_excel' => $p->nombre,
            'variaciones_excel' => self::textoColores($s['por_color']), 'cantidad_excel' => $s['total'],
            'tipo' => Op::CREAR, 'estado' => Op::REVISAR, 'motivo' => 'Falta el precio y el precio sugerido.',
            'detalle' => [
                'nombre' => $p->nombre, 'sku' => $p->referencia, 'descripcion' => (string) ($p->descripcion ?? ''),
                'precio' => null, 'sugerido' => null, 'cantidad' => $s['total'], 'variaciones' => $variaciones,
                'erp_producto_id' => $p->id,
            ],
        ]);
    }

    /**
     * «Asociar a uno de Dropi»: vincula cada variación del producto de Dropi con
     * el ERP y le guarda el id. Un producto que lleva el stock por variante
     * necesita que cada color de Dropi tenga su variante en el ERP; si falta
     * alguno no se vincula nada.
     *
     * @return array{vinculos:int, items:int, pedidos_liberados:int}
     */
    public function asociar(Producto $p, int $dropiId, ?int $userId): array
    {
        $this->exigirSinDropi($p);

        $dropi = collect(app(CatalogoDropi::class)->foto()['productos'] ?? [])->firstWhere('id', $dropiId);
        if (! $dropi) {
            throw new \DomainException("El producto {$dropiId} no está en la última lectura de Dropi: dale «Leer de Dropi» y vuelve a intentarlo.");
        }
        if ($ocupadas = DropiVinculoProducto::whereIn('sku_dropi', array_column($dropi['llaves'], 'llave'))->count()) {
            throw new \DomainException("«{$dropi['nombre']}» ya tiene {$ocupadas} variación(es) vinculada(s) a otro producto del ERP.");
        }

        // Primero se arma todo el plan; si un color no casa, no se escribe nada.
        $plan = [];
        foreach ($dropi['llaves'] as $ll) {
            $nombres = ['producto_nombre' => mb_substr($dropi['nombre'], 0, 150), 'variacion' => $ll['variacion'] ? mb_substr($ll['variacion'], 0, 80) : null];
            if (! $p->desglose_stock) {
                $plan[] = ['p', $ll['llave'], $p->id, $nombres];

                continue;
            }
            $variante = $p->variantes->first(fn ($v) => self::norm($v->color_nombre) !== '' && self::norm($v->color_nombre) === self::norm($ll['variacion']));
            if (! $variante) {
                $faltan[] = $ll['variacion'] ?: $ll['llave'];

                continue;
            }
            $plan[] = ['v', $ll['llave'], $variante->id, $nombres];
        }
        if (! empty($faltan)) {
            throw new \DomainException('En el ERP no hay variante para: ' . implode(', ', $faltan) . '. Vincúlalas una por una en el grupo de Dropi o crea esos colores en la ficha.');
        }

        $out = ['vinculos' => 0, 'items' => 0, 'pedidos_liberados' => 0];
        DB::transaction(function () use ($plan, $p, $dropiId, $userId, &$out) {
            $accion = VincularProductoDropi::make();
            foreach ($plan as [$tipo, $llave, $destino, $nombres]) {
                $r = $tipo === 'p' ? $accion->aProducto($llave, $destino, $userId, $nombres) : $accion->handle($llave, $destino, $userId, $nombres);
                $out['vinculos']++;
                $out['items'] += $r['items'];
                $out['pedidos_liberados'] += $r['pedidos_liberados'];
            }
            $p->forceFill(['dropi_sku' => (string) $dropiId])->save();
        });
        Cache::forget(CatalogoDropi::CACHE_BADGE);

        return $out;
    }

    /**
     * «Igualar Dropi al ERP»: una carga individual que deja en Dropi el saldo
     * vendible del ERP (modo «reemplazar»). Queda en borrador con el antes y el
     * después de cada variación, para confirmarla en la carga.
     */
    public function cargaIgualar(Producto $p, ?int $userId): Carga
    {
        abort_unless((bool) $p->dropi_sku, 422, "«{$p->nombre}» no está vinculado con Dropi.");
        $s = self::stockErp($p);
        $producto = $this->dropi->producto((int) $p->dropi_sku);
        $fila = ['fila' => 1, 'ref' => $p->referencia, 'nombre' => $p->nombre, 'cantidad' => $s['total'], 'variaciones' => self::textoColores($s['por_color'])];

        return $this->nuevaCarga("Igualar al ERP {$p->referencia}", $userId, [
            'fila' => 1, 'ref_excel' => $p->referencia, 'nombre_excel' => $p->nombre,
            'variaciones_excel' => $fila['variaciones'], 'cantidad_excel' => $s['total'],
        ] + $this->planificador->planFila($fila, $producto, (int) config('dropi.web.bodega_id'), 'vinculado desde el ERP'));
    }

    /** «5 ROJO - 3 AZUL», el formato de colores de las cargas. */
    public static function textoColores(array $porColor): ?string
    {
        return $porColor ? implode(' - ', array_map(fn ($c, $n) => "{$n} {$c}", array_keys($porColor), $porColor)) : null;
    }

    private function exigirSinDropi(Producto $p): void
    {
        if ($p->dropi_sku) {
            throw new \DomainException("«{$p->nombre}» ya está conectado con Dropi (id {$p->dropi_sku}).");
        }
    }

    private function nuevaCarga(string $concepto, ?int $userId, array $op): Carga
    {
        return DB::transaction(function () use ($concepto, $userId, $op) {
            $carga = Carga::create([
                'numero' => Carga::siguienteNumero(),
                'origen' => 'individual',
                'modo' => 'reemplazar',
                'concepto' => mb_substr($concepto, 0, 120),
                'bodega_dropi_id' => (int) config('dropi.web.bodega_id'),
                'defaults_crear' => ['categoria' => null, 'peso' => null, 'largo' => null, 'ancho' => null, 'alto' => null, 'privado' => false, 'garantia_dias' => 10, 'garantia_texto' => null],
                'estado' => Carga::BORRADOR,
                'user_id' => $userId,
            ]);
            $carga->operaciones()->create($op + ['candidato' => null]);

            return $carga;
        });
    }

    private static function norm(?string $s): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim(\Illuminate\Support\Str::ascii((string) $s))));
    }
}
