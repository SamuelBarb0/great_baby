<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiCargaOperacion as Op;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Illuminate\Support\Facades\Log;

/**
 * Ejecuta UNA operación de una carga contra Dropi y la verifica releyendo.
 *
 *  - actualizar: relee el stock vivo de cada variación y manda la diferencia
 *    como ENTRADA o SALIDA (Dropi no fija stock, registra movimientos). En
 *    modo «sumar» la cantidad del Excel entra tal cual.
 *  - crear: crea el producto con sus variaciones y garantías, y carga el stock
 *    inicial como ENTRADA.
 *
 * Al final relee el producto y compara: si Dropi dijo «OK» pero el stock no
 * quedó como se pidió, la operación queda en error con lo que se encontró.
 * Un error no corta la carga: las demás operaciones siguen.
 */
class EjecutarOperacionDropi
{
    public function __construct(protected DropiWebClient $dropi) {}

    public function handle(Op $op): Op
    {
        if ($op->estado !== Op::LISTO) {
            return $op;
        }
        $carga = $op->carga;

        try {
            $op->tipo === Op::CREAR ? $this->crear($op, $carga) : $this->actualizar($op, $carga);
            $this->enlazar($op);
            $this->vincularFichaErp($op);
        } catch (\Throwable $e) {
            Log::warning('dropi.carga.operacion_fallida', ['op' => $op->id, 'error' => $e->getMessage()]);
            $op->forceFill(['estado' => Op::ERROR, 'error' => mb_substr($e->getMessage(), 0, 2000), 'procesado_at' => now()])->save();
        }

        return $op->refresh();
    }

    private function actualizar(Op $op, Carga $carga): void
    {
        $productoId = (int) $op->dropi_producto_id;
        $vivo = $this->dropi->producto($productoId);
        $detalle = $op->detalle;
        $movimientos = [];

        foreach ($detalle['lineas'] as &$l) {
            if ($l['cantidad'] === null || $l['cantidad'] === '') {
                $l['movimiento'] = null;
                continue;
            }
            $antes = self::stockDe($vivo, $l['variation_id'], (int) $carga->bodega_dropi_id);
            $objetivo = $carga->modo === 'sumar' ? $antes + (int) $l['cantidad'] : (int) $l['cantidad'];
            $diferencia = $objetivo - $antes;
            $l['antes'] = $antes;
            $l['objetivo'] = $objetivo;
            $l['movimiento'] = $diferencia === 0 ? 'NINGUNO' : ($diferencia > 0 ? 'ENTRADA' : 'SALIDA');
            $l['unidades'] = abs($diferencia);
            if ($diferencia !== 0) {
                $movimientos[] = [
                    'variation_id' => $l['variation_id'],
                    'warehouse_id' => (int) $carga->bodega_dropi_id,
                    'quantity' => abs($diferencia),
                    'type_movement' => $diferencia > 0 ? 'ENTRADA' : 'SALIDA',
                    'concept' => self::concepto($carga, $op),
                ];
            }
        }
        unset($l);

        if ($movimientos) {
            $this->dropi->moverStock($productoId, $movimientos);
        }

        $this->verificar($op, $detalle, $productoId, (int) $carga->bodega_dropi_id, $movimientos ? null : 'Ya tenía el stock pedido: no se movió nada.');
    }

    private function crear(Op $op, Carga $carga): void
    {
        $d = $op->detalle;
        $def = $carga->defaults_crear ?? [];
        $variable = ! empty($d['variaciones']);

        // Si una corrida anterior alcanzó a crearlo, no se crea dos veces.
        if (! $op->dropi_producto_id) {
            $creado = $this->dropi->crearProducto([
                'nombre' => $d['nombre'],
                'descripcion' => $d['descripcion'] ?? '',
                'sku' => $d['sku'],
                'privado' => (bool) ($def['privado'] ?? false),
                'peso' => (float) $def['peso'],
                'largo' => (float) $def['largo'],
                'ancho' => (float) $def['ancho'],
                'alto' => (float) $def['alto'],
                'categorias' => [$def['categoria']],
                'precio' => (float) $d['precio'],
                'sugerido' => (float) $d['sugerido'],
                'atributo' => 'COLOR',
                'variaciones' => array_map(fn ($v) => [
                    'valor' => $v['valor'], 'sku' => $v['sku'] ?? '', 'precio' => (float) $d['precio'], 'sugerido' => (float) $d['sugerido'],
                ], $d['variaciones'] ?? []),
                'garantia_dias' => (int) ($def['garantia_dias'] ?? 10),
                'garantia_texto' => $def['garantia_texto'],
            ]);
            $op->forceFill(['dropi_producto_id' => $creado['id'], 'dropi_nombre' => $d['nombre']])->save();
            $ids = $creado['variaciones'];
        } else {
            $ids = collect($this->dropi->producto((int) $op->dropi_producto_id)['variations'] ?? [])
                ->mapWithKeys(fn ($v) => [$v['attribute_values'][0]['value'] ?? '' => (int) $v['id']])->all();
        }

        $lineas = [];
        $movimientos = [];
        foreach ($variable ? $d['variaciones'] : [['valor' => null, 'cantidad' => $d['cantidad']]] as $v) {
            $variacionId = $v['valor'] === null ? null : ($ids[$v['valor']] ?? null);
            if ($v['valor'] !== null && ! $variacionId) {
                throw new \RuntimeException("Se creó el producto pero Dropi no devolvió la variación «{$v['valor']}».");
            }
            $cantidad = (int) ($v['cantidad'] ?? 0);
            $lineas[] = ['variation_id' => $variacionId, 'etiqueta' => $v['valor'] ?? 'Producto (sin variaciones)', 'cantidad' => $cantidad, 'antes' => 0, 'objetivo' => $cantidad, 'movimiento' => $cantidad > 0 ? 'ENTRADA' : 'NINGUNO', 'unidades' => $cantidad];
            if ($cantidad > 0) {
                $movimientos[] = ['variation_id' => $variacionId, 'warehouse_id' => (int) $carga->bodega_dropi_id, 'quantity' => $cantidad, 'type_movement' => 'ENTRADA', 'concept' => self::concepto($carga, $op)];
            }
        }
        if ($movimientos) {
            $this->dropi->moverStock((int) $op->dropi_producto_id, $movimientos);
        }

        $d['lineas'] = $lineas;
        $this->verificar($op, $d, (int) $op->dropi_producto_id, (int) $carga->bodega_dropi_id, null);
    }

    /** Relee el producto y compara lo que quedó con lo pedido. */
    private function verificar(Op $op, array $detalle, int $productoId, int $bodegaId, ?string $nota): void
    {
        $releido = $this->dropi->producto($productoId);
        $malas = [];
        foreach ($detalle['lineas'] as &$l) {
            if (($l['movimiento'] ?? null) === null) {
                continue;
            }
            $l['despues'] = self::stockDe($releido, $l['variation_id'], $bodegaId);
            if ($l['despues'] !== (int) $l['objetivo']) {
                $malas[] = "{$l['etiqueta']}: se pidió {$l['objetivo']} y quedó {$l['despues']}";
            }
        }
        unset($l);

        $op->forceFill([
            'detalle' => $detalle,
            'estado' => $malas ? Op::ERROR : Op::OK,
            'error' => $malas ? 'Dropi respondió OK pero el stock no quedó como se pidió: ' . implode('; ', $malas) . '. Puede ser una venta en el mismo momento: revisa el historial de stock en Dropi.' : null,
            'motivo' => $nota ?? $op->motivo,
            'razon_enviada' => mb_substr(self::razon($op->carga, $op), 0, 200),
            'procesado_at' => now(),
        ])->save();
    }

    /**
     * Si en el ERP hay un producto con la misma referencia y sin id de Dropi,
     * se le guarda: así el próximo cruce no depende de comparar nombres.
     */
    private function enlazar(Op $op): void
    {
        if ($op->estado !== Op::OK || ! $op->dropi_producto_id || ! $op->ref_excel) {
            return;
        }
        $id = (string) $op->dropi_producto_id;
        if (Producto::where('dropi_sku', $id)->exists()) {
            return;
        }
        Producto::where('referencia', $op->ref_excel)->whereNull('dropi_sku')->first()?->forceFill(['dropi_sku' => $id])->save();
    }

    /**
     * «Crear en Dropi» desde «Vincular con el ERP» (24-nov-2026): el producto
     * nace en Dropi ya vinculado con la ficha del ERP, variación por variación,
     * para que los pedidos que lleguen traigan producto. Si un vínculo falla el
     * producto igual quedó creado: se avisa en el log y se vincula a mano.
     */
    private function vincularFichaErp(Op $op): void
    {
        $erpId = $op->detalle['erp_producto_id'] ?? null;
        if ($op->tipo !== Op::CREAR || $op->estado !== Op::OK || ! $erpId || ! $op->dropi_producto_id) {
            return;
        }
        $producto = Producto::with('variantes')->find($erpId);
        if (! $producto) {
            return;
        }

        try {
            if (! $producto->dropi_sku) {
                $producto->forceFill(['dropi_sku' => (string) $op->dropi_producto_id])->save();
            }
            $accion = VincularProductoDropi::make();
            $norm = fn (?string $s) => mb_strtoupper(trim(\Illuminate\Support\Str::ascii((string) $s)));
            foreach ($op->detalle['lineas'] ?? [] as $l) {
                $llave = MapearPedidoDropiWeb::claveVinculo($l['variation_id'] ?? null, $op->detalle['sku'] ?? null, $op->dropi_producto_id);
                if (! $llave || DropiVinculoProducto::where('sku_dropi', $llave)->exists()) {
                    continue;
                }
                $nombres = ['producto_nombre' => mb_substr((string) $op->dropi_nombre, 0, 150), 'variacion' => ($l['variation_id'] ?? null) ? mb_substr((string) $l['etiqueta'], 0, 80) : null];
                if (! $producto->desglose_stock) {
                    $accion->aProducto($llave, $producto->id, null, $nombres);

                    continue;
                }
                $variante = $producto->variantes->first(fn ($v) => $norm($v->color_nombre) !== '' && $norm($v->color_nombre) === $norm($l['etiqueta'] ?? ''));
                if ($variante) {
                    $accion->handle($llave, $variante->id, null, $nombres);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('dropi.carga.vincular_ficha_erp', ['op' => $op->id, 'producto' => $erpId, 'error' => $e->getMessage()]);
        }
    }

    public static function stockDe(array $producto, ?int $variacionId, int $bodegaId): int
    {
        if (! $variacionId) {
            return (int) collect($producto['warehouse_product'] ?? [])->where('warehouse_id', $bodegaId)->sum('stock');
        }
        $v = collect($producto['variations'] ?? [])->firstWhere('id', $variacionId);

        return (int) collect($v['warehouse_product_variation'] ?? [])->where('warehouse_id', $bodegaId)->sum('stock');
    }

    /** Lo que se escribe en el historial de stock de Dropi: la razón de la fila (o el concepto de la carga) y el número. */
    public static function concepto(Carga $carga, ?Op $op = null): string
    {
        return mb_substr(self::razon($carga, $op) . " · {$carga->numero}", 0, 120);
    }

    public static function razon(Carga $carga, ?Op $op = null): string
    {
        $propia = trim((string) ($op?->razon ?? ''));

        return $propia !== '' ? $propia : (string) $carga->concepto;
    }
}
