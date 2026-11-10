<?php

namespace App\Modules\Portal\Actions;

use App\Models\Contacto;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Portal\Models\Cotizacion;
use App\Modules\Portal\Models\CotizacionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Arma una cotización con los precios de hoy, y los congela.
 *
 * Toma los precios de la lista del cliente igual que el pedido, pero a partir
 * de ahí son suyos: lo que se guarda acá es lo que se le prometió, y es lo que
 * se va a respetar al convertirla aunque la lista cambie mañana. Si se
 * recalculara al convertir, cotizar no serviría de nada.
 *
 * No toca inventario. Una cotización no reserva ni descuenta: la mercancía se
 * compromete al aprobar el pedido, no cuando alguien pide un precio.
 */
class CrearCotizacion
{
    /**
     * @param  list<array{variante_id?:int|null, producto_id?:int|null, cantidad:int, precio?:float|null}>  $items
     */
    public function ejecutar(
        Contacto $cliente,
        array $items,
        ?int $vendedorId = null,
        ?string $notas = null,
        ?string $vigenteHasta = null,
        ?int $empresaId = null,
    ): Cotizacion {
        $listaId = (int) ($cliente->lista_precios_id ?? 0);
        abort_if(! $listaId, 422, 'El cliente no tiene lista de precios asignada.');

        $dias = max(1, (int) setting('ventas.dias_vigencia_cotizacion'));
        $vence = $vigenteHasta ?: now()->addDays($dias)->toDateString();

        [$preciosVar, $preciosProd, $variantes, $productos] = $this->catalogo($items, $listaId);

        return DB::transaction(function () use (
            $cliente, $items, $vendedorId, $notas, $vence, $listaId, $empresaId,
            $preciosVar, $preciosProd, $variantes, $productos
        ) {
            $cot = Cotizacion::create([
                'numero' => $this->siguienteNumero(),
                'contacto_id' => $cliente->id,
                'vendedor_id' => $vendedorId,
                'lista_precios_id' => $listaId,
                'empresa_id' => $empresaId,
                'fecha' => now()->toDateString(),
                'vigente_hasta' => $vence,
                'estado' => 'borrador',
                'notas' => $notas,
                'creado_por' => auth()->id(),
                'subtotal' => 0, 'iva' => 0, 'total' => 0,
            ]);

            $subtotal = 0.0;
            $iva = 0.0;
            $validas = 0;

            foreach ($items as $it) {
                $cantidad = (int) ($it['cantidad'] ?? 0);
                if ($cantidad <= 0) {
                    continue;
                }

                $linea = $this->resolverLinea($it, $preciosVar, $preciosProd, $variantes, $productos);
                if (! $linea) {
                    continue;
                }

                /*
                 * El vendedor puede cotizar por debajo de la lista: negociar es
                 * media razón de existir de una cotización. Lo que no puede es
                 * cotizar en cero o en negativo, que no es negociar sino
                 * regalar, y eso se cae en la validación del formulario.
                 *
                 * El precio ya viene resuelto desde `resolverLinea`.
                 */
                $lineaSub = round($linea['precio'] * $cantidad, 2);
                $lineaIva = round($lineaSub * ($linea['iva_pct'] / 100), 2);
                $validas++;

                CotizacionItem::create([
                    'cotizacion_id' => $cot->id,
                    'variante_id' => $linea['variante_id'],
                    'producto_id' => $linea['producto_id'],
                    'sku_snapshot' => $linea['sku'],
                    'descripcion_snapshot' => $linea['descripcion'],
                    'cantidad' => $cantidad,
                    'precio_unitario' => $linea['precio'],
                    'iva_porcentaje' => $linea['iva_pct'],
                    'subtotal' => $lineaSub,
                    'iva_valor' => $lineaIva,
                    'total' => round($lineaSub + $lineaIva, 2),
                ]);

                $subtotal += $lineaSub;
                $iva += $lineaIva;
            }

            if ($validas === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Ninguno de los productos tiene precio en la lista del cliente.',
                ]);
            }

            $cot->update([
                'subtotal' => round($subtotal, 2),
                'iva' => round($iva, 2),
                'total' => round($subtotal + $iva, 2),
            ]);

            return $cot->fresh('items');
        });
    }

    /** Precios vigentes y catálogo de lo que se está cotizando. */
    private function catalogo(array $items, int $listaId): array
    {
        $idsVar = array_filter(array_column($items, 'variante_id'));
        $idsProd = array_filter(array_column($items, 'producto_id'));

        $preciosVar = PrecioVariante::where('lista_id', $listaId)->vigentes()
            ->whereIn('variante_id', $idsVar)->pluck('precio', 'variante_id');

        $preciosProd = PrecioProducto::where('lista_id', $listaId)->vigentes()
            ->whereIn('producto_id', $idsProd)->pluck('precio', 'producto_id');

        $variantes = ProductoVariante::whereIn('id', $idsVar)
            ->with(['producto' => fn ($q) => $q->select('id', 'referencia', 'nombre', 'impuesto_id')
                ->with('impuesto:id,porcentaje')])
            ->get()->keyBy('id');

        $productos = Producto::whereIn('id', $idsProd)
            ->with('impuesto:id,porcentaje')
            ->get(['id', 'referencia', 'nombre', 'impuesto_id'])->keyBy('id');

        return [$preciosVar, $preciosProd, $variantes, $productos];
    }

    private function resolverLinea(array $it, $preciosVar, $preciosProd, $variantes, $productos): ?array
    {
        /*
         * El precio que teclea el vendedor se decide ACÁ, no después.
         *
         * Antes se aplicaba al volver de este método, y entonces un producto
         * sin precio en la lista del cliente se descartaba acá adentro aunque
         * el vendedor hubiera escrito uno: la línea desaparecía de la
         * cotización sin decir nada. Y es un caso corriente —un producto nuevo
         * que todavía no está tarifado en esa lista, o uno que salió de ella—
         * donde justamente lo que el vendedor hace es ponerle precio él.
         *
         * Cotizar en cero o en negativo no es negociar sino regalar, así que
         * sólo entra un precio mayor que cero; el resto lo rechaza la
         * validación del formulario.
         */
        $propuesto = isset($it['precio']) ? round((float) $it['precio'], 2) : 0.0;

        if (! empty($it['variante_id'])) {
            $v = $variantes[$it['variante_id']] ?? null;
            $precio = $propuesto > 0 ? $propuesto : (float) ($preciosVar[$it['variante_id']] ?? 0);
            if (! $v || $precio <= 0) {
                return null;
            }

            return [
                'variante_id' => $v->id,
                'producto_id' => null,
                'sku' => $v->codigo_barras ?: ($v->producto?->referencia.'-'.$v->id),
                'descripcion' => trim(($v->producto?->nombre ?? '').' '
                    .($v->color_nombre ?? '').' '.($v->talla ? '· '.$v->talla : '')),
                'precio' => $precio,
                'iva_pct' => (float) ($v->producto?->impuesto?->porcentaje ?? 0),
            ];
        }

        if (! empty($it['producto_id'])) {
            $p = $productos[$it['producto_id']] ?? null;
            $precio = $propuesto > 0 ? $propuesto : (float) ($preciosProd[$it['producto_id']] ?? 0);
            if (! $p || $precio <= 0) {
                return null;
            }

            return [
                'variante_id' => null,
                'producto_id' => $p->id,
                'sku' => $p->referencia,
                'descripcion' => $p->nombre.' · colores surtidos',
                'precio' => $precio,
                'iva_pct' => (float) ($p->impuesto?->porcentaje ?? 0),
            ];
        }

        return null;
    }

    private function siguienteNumero(): string
    {
        $prefijo = 'COT-'.now()->format('ymd').'-';
        $ultimo = Cotizacion::withTrashed()
            ->where('numero', 'like', $prefijo.'%')
            ->lockForUpdate()
            ->orderByDesc('id')->value('numero');

        /*
         * El consecutivo se lee DESPUÉS del prefijo, no por los últimos cuatro.
         *
         * `str_pad` rellena pero no recorta: pasado el 9999 el número crece a
         * cinco dígitos y `substr(-4)` leía «0000», así que el siguiente volvía
         * a ser 0001 contra un `numero` que es único —y la cotización no se
         * creaba—. Cuatro dígitos alcanzan de sobra para un día, pero el que
         * los cuenta no tiene por qué saber cuántos son.
         */
        $sig = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $sig, 4, '0', STR_PAD_LEFT);
    }
}
