<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Services\ColoresDeImportacion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registra y confirma la recepción de mercancía de una OC.
 *
 * Vivía dentro de `ComprasGestionController::recepcionCrear`; se sacó acá (7-nov)
 * para que el panel de Dropi reciba con exactamente las mismas reglas que /app.
 * Los datos llegan ya validados por quien llama.
 *
 * Lanza InvalidArgumentException con un mensaje para quien está contando:
 * sin tasa de cambio, recibiendo de más sin motivo, etc.
 *
 * @phpstan-type Linea array{orden_item_id:int, cantidad_recibida:float, lote?:?string,
 *     clasificacion?:?string, motivo_clasificacion?:?string, color_nombre?:?string}
 */
class RegistrarRecepcion
{
    use AsAction;

    /**
     * @param array{orden_id:int, remision_proveedor?:?string, factura_proveedor?:?string,
     *     transportista?:?string, observaciones?:?string, motivo_exceso?:?string, items:array<int, array>} $data
     */
    public function handle(array $data): RecepcionCompra
    {
        $orden = OrdenCompra::with('items')->findOrFail($data['orden_id']);
        abort_unless(in_array($orden->estado, [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Parcial, EstadoOrdenCompra::Enviada]), 422, 'La OC no está en estado receptable.');
        abort_if($orden->bodega_id === null, 422, 'La OC no tiene bodega destino asignada.');

        $itemsOc = $orden->items->pluck('id')->all();
        foreach ($data['items'] as $it) {
            abort_unless(in_array((int) $it['orden_item_id'], $itemsOc, true), 422, 'Item de recepción no pertenece a esta OC.');
        }

        $itemsFusionados = $this->fusionar($this->normalizarDestino($data['items'], $orden));

        $this->exigirQueElRepartoCuadre($orden, $itemsFusionados);

        // El conteo de Don Jorge es la verdad, así que contar de más no se bloquea:
        // sólo se le pide el motivo, para que quede dicho contra la orden.
        if ($orden->esImportacion()) {
            $excesos = app(ColoresDeImportacion::class)->excesos($orden, array_values($itemsFusionados));
            if ($excesos && blank($data['motivo_exceso'] ?? null)) {
                throw new InvalidArgumentException('Se contó más de lo esperado en '.implode(', ', array_keys($excesos)).': escriba el motivo.');
            }
            if ($excesos) {
                $data['observaciones'] = trim(($data['observaciones'] ?? '')."\nLlegó de más (".implode(', ', array_keys($excesos))."): {$data['motivo_exceso']}");
            }
        }

        // Re-audit M2 R3 PATRÓN Q (FUNC-C3) · TX UNIFICADA: la recepción y su
        //   confirmación van juntas. Si `RecibirMercancia` truena, no queda una
        //   recepción huérfana en borrador (y un reintento no crea otra).
        return DB::transaction(function () use ($data, $orden, $itemsFusionados) {
            $rc = RecepcionCompra::create([
                'numero' => RecepcionCompra::siguienteNumero(),
                'orden_id' => $orden->id,
                'bodega_id' => $orden->bodega_id,
                'estado' => 'borrador',
                'fecha_recepcion' => now(),
                'remision_proveedor' => $data['remision_proveedor'] ?? null,
                'factura_proveedor' => $data['factura_proveedor'] ?? null,
                'transportista' => $data['transportista'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                // Clave real del $fillable (antes 'creado_por' se descartaba).
                'recibido_por' => auth()->id(),
            ]);
            foreach ($itemsFusionados as $it) {
                $ocItem = $orden->items->firstWhere('id', $it['orden_item_id']);
                $costoUnit = (float) $ocItem->precio_unit;
                $cantidad = (float) $it['cantidad_recibida'];
                // Otro color del que se pidió en esta línea no es la variante de la línea.
                $color = $it['color_nombre'] ?? $ocItem->color_nombre;
                $mismoColor = mb_strtolower((string) $color) === mb_strtolower((string) $ocItem->color_nombre);
                $rc->items()->create([
                    'orden_item_id' => $ocItem->id,
                    // El destino de ESTA parte de la línea. Ya viene
                    // normalizado: null es «la bodega de la orden».
                    'ubicacion_id' => $it['ubicacion_id'] ?? null,
                    'color_nombre' => $color,
                    'variante_id' => $mismoColor ? $ocItem->variante_id : null,
                    // Los productos AGREGADOS no tienen variante: sin el producto
                    // `RecibirMercancia` se saltaba el kardex y el stock no subía.
                    'producto_id' => $ocItem->producto_id,
                    'cantidad_recibida' => $cantidad,
                    'costo_unit' => $costoUnit,
                    'subtotal' => round($costoUnit * $cantidad, 2),
                    'lote' => $it['lote'] ?? null,
                    // LOG-J8 · `RecibirMercancia` lee la clasificación para decidir a
                    // qué almacén lógico va (apto → vendible, el resto → no vendible).
                    'clasificacion' => $it['clasificacion'] ?? 'apto',
                    'motivo_clasificacion' => $it['motivo_clasificacion'] ?? null,
                ]);
            }

            return RecibirMercancia::run($rc);
        });
    }

    /**
     * Deja el destino escrito de una sola forma antes de fusionar.
     *
     * Dos cosas que parecen detalles y no lo son:
     *
     *  · Elegir en la pantalla la misma bodega de la orden es lo mismo que no
     *    elegir nada. Guardarlo como id abriría dos filas para un solo sitio
     *    y la recepción se vería repartida donde no lo hubo.
     *
     *  · Una línea que no llegó apta va a la ubicación no vendible, pase lo
     *    que pase —eso lo decide `RecibirMercancia` y pesa más que cualquier
     *    destino—. Si se guardara el destino elegido, la fila diría que la
     *    mercancía está en un sitio donde no está: una trampa para quien la
     *    lea el mes que viene.
     *
     * @param  array<int, array>  $items
     * @return array<int, array>
     */
    private function normalizarDestino(array $items, OrdenCompra $orden): array
    {
        return array_map(function (array $it) use ($orden) {
            $destino = (int) ($it['ubicacion_id'] ?? 0);
            $clas = $it['clasificacion'] ?? 'apto';

            $it['ubicacion_id'] = ($destino > 0 && $destino !== (int) $orden->bodega_id && $clas === 'apto')
                ? $destino
                : null;

            return $it;
        }, $items);
    }

    /**
     * Repartir no puede servir para recibir de más.
     *
     * El tope por línea ya existe y lo aplica `RecibirMercancia`, pero lo
     * aplica renglón por renglón: con dos de 30 sobre 50 pendientes se cae en
     * el segundo diciendo «esta recepción 30, pendiente 20», que no le explica
     * nada a quien está contando cajas con el contenedor abierto. Acá se mira
     * la línea COMPLETA y se dice la suma, que es lo que esa persona necesita
     * oír.
     *
     * En importación no se bloquea: el conteo de quien descarga es la verdad y
     * contar de más sólo pide motivo (ver `ColoresDeImportacion::excesos`).
     *
     * @param  array<string, array>  $itemsFusionados
     */
    private function exigirQueElRepartoCuadre(OrdenCompra $orden, array $itemsFusionados): void
    {
        if ($orden->esImportacion()) {
            return;
        }

        /*
         * Lo marcado «faltante» no cuenta contra el pendiente de la orden.
         *
         * Al confirmar, una línea faltante no entra al inventario y tampoco le
         * suma nada a `cantidad_recibida` de la orden: queda como constancia de
         * que eso no llegó. Sumarla acá bloqueaba recepciones legítimas —40
         * recibidas más 10 anotadas como faltantes contra un pendiente de 40
         * daban «está repartiendo 50»— y el mensaje mandaba a revisar una suma
         * que estaba bien.
         */
        $porLinea = [];
        foreach ($itemsFusionados as $it) {
            if (($it['clasificacion'] ?? 'apto') === 'faltante') {
                continue;
            }
            $id = (int) $it['orden_item_id'];
            $porLinea[$id] = ($porLinea[$id] ?? 0) + (float) $it['cantidad_recibida'];
        }

        foreach ($porLinea as $ordenItemId => $total) {
            $ocItem = $orden->items->firstWhere('id', $ordenItemId);
            if (! $ocItem) {
                continue;
            }

            $pendiente = (float) $ocItem->cantidad - (float) $ocItem->cantidad_recibida;

            if ($total > $pendiente + 0.0001) {
                throw new InvalidArgumentException(sprintf(
                    'En «%s» está repartiendo %s unidades y sólo quedan %s pendientes. '
                    .'Revise la suma de los destinos de esa línea.',
                    $ocItem->descripcion ?: 'la línea',
                    rtrim(rtrim(number_format($total, 3, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format(max(0, $pendiente), 3, ',', '.'), '0'), ','),
                ));
            }
        }
    }

    /**
     * Re-audit M2 R3 PATRÓN Q (FUNC-A3) · dos líneas con el mismo ítem, la misma
     * clasificación y el mismo color se suman; con clasificaciones distintas
     * quedan separadas (parte del lote puede llegar apta y parte con avería).
     *
     * Desde el reparto, el destino también separa: la misma línea puede abrir
     * un renglón por cada bodega a la que se mandó mercancía.
     */
    private function fusionar(array $items): array
    {
        $fusionados = [];
        foreach ($items as $it) {
            $clas = $it['clasificacion'] ?? 'apto';
            $color = trim((string) ($it['color_nombre'] ?? '')) ?: null;
            /*
             * El destino entra en la clave o el reparto se pierde.
             *
             * Con la clave vieja —ítem, clasificación y color— «30 a Great
             * Baby y 20 a Dropi» se fusionaban en una sola fila de 50 que
             * entraba entera a la bodega de la orden, sin que nadie se
             * enterara: la pantalla decía que se repartió y el kardex decía
             * que no.
             */
            $destino = $it['ubicacion_id'] ?? null;
            $key = (int) $it['orden_item_id'].':'.$clas.':'.mb_strtolower((string) $color).':'.($destino ?: '-');
            if (isset($fusionados[$key])) {
                $fusionados[$key]['cantidad_recibida'] += (float) $it['cantidad_recibida'];
                $fusionados[$key]['lote'] = $fusionados[$key]['lote'] ?: ($it['lote'] ?? null);
                // Dos novedades del mismo producto: se concatenan para no perder contexto.
                if (! empty($it['motivo_clasificacion']) && $fusionados[$key]['motivo_clasificacion'] !== $it['motivo_clasificacion']) {
                    $fusionados[$key]['motivo_clasificacion'] = trim(($fusionados[$key]['motivo_clasificacion'] ?? '').' · '.$it['motivo_clasificacion']);
                }
            } else {
                $fusionados[$key] = [
                    'orden_item_id' => (int) $it['orden_item_id'],
                    'cantidad_recibida' => (float) $it['cantidad_recibida'],
                    'lote' => $it['lote'] ?? null,
                    'clasificacion' => $clas,
                    'motivo_clasificacion' => $it['motivo_clasificacion'] ?? null,
                    'color_nombre' => $color,
                    'ubicacion_id' => $destino,
                ];
            }
        }

        return $fusionados;
    }
}
