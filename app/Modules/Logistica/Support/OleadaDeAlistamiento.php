<?php

namespace App\Modules\Logistica\Support;

use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Support\Collection;

/**
 * Una oleada: varios pedidos que se recogen de una sola pasada.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE
 *
 * Hasta acá el sistema sabía alistar UN pedido: una hoja, un recorrido. Con
 * cien pedidos en cola eso son cien recorridos, y si una referencia está en la
 * mitad de las facturas, cincuenta viajes al mismo estante.
 *
 * El procedimiento del área no trabaja así. Pone tres pasos: picking por zonas
 * («operarios por zona»), CONSOLIDACIÓN («trasladar al punto de consolidación y
 * agrupar la totalidad de ítems por cada orden», con entrada «artículos de
 * distintas zonas») y packing. Y el plano de la zona de alistamiento que mandó
 * don Jorge —seis columnas por tres filas— es ese punto de consolidación: cada
 * casilla es un pedido armándose.
 *
 * O sea que el área venía pidiendo esto desde el principio, sólo que lo llamó
 * «consolidación» y no «oleada».
 *
 * ───────────────────────────────────────────────────────────────────────────
 * CÓMO FUNCIONA
 *
 * Se recoge POR PRODUCTO, no por pedido: una fila por ubicación y referencia,
 * con el total de todos los pedidos juntos, y debajo a quién le va cada cuánto.
 * El recorrido va en el orden de la bodega —zona, rack, sección, nivel— para
 * no devolverse.
 *
 * Después, en la zona de alistamiento, ese montón se reparte en las casillas.
 * Por eso la hoja trae las dos caras: la de recoger y la de repartir.
 */
class OleadaDeAlistamiento
{
    /**
     * Arma la oleada de un conjunto de pedidos.
     *
     * @param  Collection<int, PedidoCliente>  $pedidos  con `items` cargados
     * @param  array<int, array<int, array>>  $ubicacionesPorPedido  [pedido_id => [clave => [ubicaciones]]]
     * @return array{recorrido: array, casillas: array, resumen: array}
     */
    public function armar(Collection $pedidos, array $ubicacionesPorPedido): array
    {
        $paradas = [];
        $porPedido = [];

        foreach ($pedidos as $p) {
            $porPedido[$p->id] = [
                'id' => $p->id,
                'numero' => $p->numero,
                'casilla' => $p->casilla_consolidacion,
                'cliente' => $p->contacto?->razon_social ?: $p->contacto?->nombre_completo,
                'ciudad' => $p->contacto?->ciudad,
                'items' => [],
                'unidades' => 0,
            ];

            foreach ($p->items as $it) {
                $cantidad = (int) $it->cantidad;

                if ($cantidad <= 0) {
                    continue;
                }

                $clave = $it->variante_id ? 'v:'.$it->variante_id : 'p:'.$it->producto_id;
                $ubicaciones = $ubicacionesPorPedido[$p->id][$clave] ?? [];

                $porPedido[$p->id]['items'][] = [
                    'sku' => $it->sku_snapshot,
                    'descripcion' => $it->descripcion_snapshot,
                    'cantidad' => $cantidad,
                ];
                $porPedido[$p->id]['unidades'] += $cantidad;

                /*
                 * De dónde se saca lo de ESTE pedido.
                 *
                 * Se reparte entre las ubicaciones que hagan falta para cubrir
                 * la cantidad, igual que lo hace la hoja de un solo pedido: la
                 * primera tiene prioridad, y si no alcanza se sigue con la que
                 * sigue. Lo que no se pudo cubrir queda anotado como faltante,
                 * que es justo lo que el área quiere ver antes de salir a
                 * caminar y no después.
                 */
                $faltan = $cantidad;

                foreach ($ubicaciones as $u) {
                    if ($faltan <= 0) {
                        break;
                    }

                    $toma = min($faltan, (int) $u['stock']);

                    if ($toma <= 0) {
                        continue;
                    }

                    $id = $u['codigo'];

                    $paradas[$id] ??= [
                        'codigo' => $u['codigo'],
                        'nombre' => $u['nombre'],
                        'zona' => $u['zona'] ?? null,
                        'rack' => $u['rack'] ?? null,
                        'es_respaldo' => (bool) ($u['es_respaldo'] ?? false),
                        'lineas' => [],
                    ];

                    $linea = $it->sku_snapshot ?: $it->descripcion_snapshot;

                    $paradas[$id]['lineas'][$linea] ??= [
                        'sku' => $it->sku_snapshot,
                        'descripcion' => $it->descripcion_snapshot,
                        'total' => 0,
                        'reparto' => [],
                    ];

                    $paradas[$id]['lineas'][$linea]['total'] += $toma;
                    $paradas[$id]['lineas'][$linea]['reparto'][] = [
                        'pedido' => $p->numero,
                        'casilla' => $p->casilla_consolidacion,
                        'cantidad' => $toma,
                    ];

                    $faltan -= $toma;
                }

                if ($faltan > 0) {
                    $paradas['SIN-STOCK'] ??= [
                        'codigo' => 'SIN UBICAR',
                        'nombre' => 'No se encontró dónde está',
                        'zona' => null,
                        'rack' => null,
                        'es_respaldo' => false,
                        'lineas' => [],
                    ];

                    $linea = ($it->sku_snapshot ?: $it->descripcion_snapshot).' · FALTANTE';

                    $paradas['SIN-STOCK']['lineas'][$linea] ??= [
                        'sku' => $it->sku_snapshot,
                        'descripcion' => $it->descripcion_snapshot,
                        'total' => 0,
                        'reparto' => [],
                    ];

                    $paradas['SIN-STOCK']['lineas'][$linea]['total'] += $faltan;
                    $paradas['SIN-STOCK']['lineas'][$linea]['reparto'][] = [
                        'pedido' => $p->numero,
                        'casilla' => $p->casilla_consolidacion,
                        'cantidad' => $faltan,
                    ];
                }
            }
        }

        $recorrido = $this->enOrdenDeBodega($paradas);

        /*
         * Las zonas, en el orden en que se caminan.
         *
         * Se sacan del recorrido YA ordenado y no del arreglo crudo: si no,
         * salen en el orden en que aparecieron los items —«C · A»— y el
         * encabezado contradice a la tabla que tiene debajo.
         */
        $zonas = [];

        foreach ($recorrido as $parada) {
            if (($parada['zona'] ?? null) && ! in_array($parada['zona'], $zonas, true)) {
                $zonas[] = $parada['zona'];
            }
        }

        return [
            'recorrido' => $recorrido,
            'casillas' => array_values($porPedido),
            'resumen' => [
                'pedidos' => $pedidos->count(),
                'paradas' => count($paradas),
                'unidades' => array_sum(array_column($porPedido, 'unidades')),
                'zonas' => $zonas,
                'hay_faltantes' => isset($paradas['SIN-STOCK']),
                'hay_respaldo' => (bool) array_filter($paradas, fn ($p) => $p['es_respaldo']),
            ],
        ];
    }

    /**
     * Las paradas en el orden en que se camina la bodega.
     *
     * Zona, después el PUESTO DEL RACK EN EL RECORRIDO —no su número— y
     * después el código, que ya trae sección y nivel en orden.
     *
     * Lo del puesto importa: el área pidió para la zona 3 un recorrido que no
     * es ascendente ni descendente —local, parqueadero, 15, 14, 13, arsutex,
     * 12, 11— porque se arranca por el fondo y se vuelve hacia la zona de
     * alistamiento, recogiendo las zonas sueltas al pasar. Ordenando por
     * número se caminaba al revés.
     *
     * El respaldo de surtido y lo que no se ubicó van al final: no son parte
     * del recorrido, son excepciones que se atienden aparte.
     */
    private function enOrdenDeBodega(array $paradas): array
    {
        $paradas = array_map(function ($p) {
            $p['lineas'] = array_values($p['lineas']);

            return $p;
        }, $paradas);

        uasort($paradas, function ($a, $b) {
            $peso = fn ($p) => [
                $p['codigo'] === 'SIN UBICAR' ? 2 : ($p['es_respaldo'] ? 1 : 0),
                $p['zona'] ?? 'ZZZ',
                \App\Modules\Logistica\Support\ZonaDeLaBodega::ordenEnElRecorrido($p['rack'] ?? null),
                (string) ($p['rack'] ?? ''),
                $p['codigo'],
            ];

            return $peso($a) <=> $peso($b);
        });

        return array_values($paradas);
    }
}
