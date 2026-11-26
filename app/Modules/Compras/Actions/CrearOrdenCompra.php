<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraItem;
use App\Modules\Compras\Services\PreliquidarOrden;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class CrearOrdenCompra
{
    use AsAction;

    /**
     * @param array{proveedor_id:int, bodega_id?:int|null, tipo?:string, moneda?:string,
     *              tasa_cambio?:float, fecha_esperada?:string|null, observaciones?:string|null,
     *              items:array<int,array{producto_id?:int|null,variante_id?:int|null,
     *                    descripcion:string,cantidad:float,precio_unit:float,
     *                    descuento_pct?:float,iva_pct?:float}>, lleva_iva?:bool} $data
     *
     * `lleva_iva` en false (compra sin IVA, reunión 25-nov) deja todas las
     * líneas en 0 %, diga lo que diga cada una.
     */
    public function handle(array $data): OrdenCompra
    {
        return DB::transaction(function () use ($data) {
            $esImportacion = ($data['tipo'] ?? 'nacional') === 'importacion';
            $modelo = $data['modelo_compra'] ?? PreliquidarOrden::TRADING;

            $orden = OrdenCompra::create([
                'numero' => OrdenCompra::siguienteNumero(),
                'proveedor_id' => $data['proveedor_id'],
                'bodega_id' => $data['bodega_id'] ?? null,
                'condicion_credito_id' => $data['condicion_credito_id'] ?? null,
                'tipo' => $data['tipo'] ?? 'nacional',
                'modelo_compra' => $esImportacion ? $modelo : null,
                'moneda' => $data['moneda'] ?? 'COP',
                'tasa_cambio' => $data['tasa_cambio'] ?? 1,
                'fecha_emision' => now(),
                'fecha_esperada' => $data['fecha_esperada'] ?? null,
                'estado' => EstadoOrdenCompra::Borrador,
                'creado_por' => auth()->id(),
                'observaciones' => $data['observaciones'] ?? null,
                // Sin línea explícita la columna queda en su valor por defecto
                // (great_baby). El panel de Dropi la manda en `dropi` (7-nov).
                ...(isset($data['linea_negocio']) ? ['linea_negocio' => $data['linea_negocio']] : []),
                // Sólo las importaciones se pre-liquidan; nacen con los de la última.
                'parametros_preliquidacion' => $esImportacion
                    ? PreliquidarOrden::normalizar($data['parametros_preliquidacion'] ?? PreliquidarOrden::parametrosIniciales($modelo, $data['moneda'] ?? 'CNY'))
                    : null,
            ]);

            foreach ($data['items'] as $it) {
                // Un componente no tiene producto propio: así no sube al inventario.
                $esComponente = ($it['tipo_linea'] ?? null) === OrdenCompraItem::TIPO_COMPONENTE;
                OrdenCompraItem::create([
                    'orden_id' => $orden->id,
                    'producto_id' => $esComponente ? null : ($it['producto_id'] ?? null),
                    'variante_id' => $esComponente ? null : ($it['variante_id'] ?? null),
                    'tipo_linea' => $esComponente ? OrdenCompraItem::TIPO_COMPONENTE : OrdenCompraItem::TIPO_PRODUCTO,
                    'color_nombre' => $esComponente ? null : (trim((string) ($it['color_nombre'] ?? '')) ?: null),
                    'descripcion' => $it['descripcion'],
                    'cantidad' => $it['cantidad'],
                    'precio_unit' => $it['precio_unit'],
                    'descuento_pct' => $it['descuento_pct'] ?? 0,
                    'iva_pct' => ($data['lleva_iva'] ?? true) ? ($it['iva_pct'] ?? 19) : 0,
                    'cajas' => $it['cajas'] ?? null,
                    'unidades_por_caja' => $it['unidades_por_caja'] ?? null,
                    'caja_largo_cm' => $it['caja_largo_cm'] ?? null,
                    'caja_ancho_cm' => $it['caja_ancho_cm'] ?? null,
                    'caja_alto_cm' => $it['caja_alto_cm'] ?? null,
                    'cbm_caja' => $it['cbm_caja'] ?? null,
                    'peso_caja_kg' => $it['peso_caja_kg'] ?? null,
                    'empresa_destino_id' => $it['empresa_destino_id'] ?? null,
                ]);
            }

            $this->ligarComponentes($orden);

            RecalcularTotalesOC::make()->handle($orden);

            return $orden->fresh(['items', 'proveedor']);
        });
    }

    /** Cada componente pertenece a la primera línea normal con su misma referencia. */
    private function ligarComponentes(OrdenCompra $orden): void
    {
        $items = $orden->items()->orderBy('id')->get();
        $principales = $items->reject->esComponente()
            ->filter(fn (OrdenCompraItem $i) => CrearProductosDeOrden::referenciaDe($i))
            ->unique(fn (OrdenCompraItem $i) => CrearProductosDeOrden::referenciaDe($i))
            ->keyBy(fn (OrdenCompraItem $i) => CrearProductosDeOrden::referenciaDe($i));

        $items->filter->esComponente()->each(function (OrdenCompraItem $c) use ($principales) {
            $principal = $principales[CrearProductosDeOrden::referenciaDe($c)] ?? null;
            if ($principal) {
                $c->componente_de_item_id = $principal->id;
                $c->save();
            }
        });
    }
}
