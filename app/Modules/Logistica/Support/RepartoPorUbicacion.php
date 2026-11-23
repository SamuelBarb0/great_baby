<?php

namespace App\Modules\Logistica\Support;

/**
 * DE DÓNDE SALE CADA UNIDAD DE UNA LÍNEA.
 *
 * Un renglón de la hoja de picking no siempre se resuelve en un solo estante:
 * si pide 50 y la primera ubicación tiene 12, el alistador tiene que seguir a
 * la segunda. Esta clase dice qué ubicaciones hay que visitar DE VERDAD y
 * cuántas unidades salen de cada una.
 *
 * Ese segundo número —`toma`— es el que importa y el que no se tenía:
 *
 *   • Para rotular la hoja. La zona del pedido se decidía por el stock PARADO
 *     en cada ubicación, así que un renglón que saca 48 de la zona 1 y 2 de la
 *     zona 3 —porque la 3 tiene mil guardadas— pesaba 48 contra 1000 y la hoja
 *     salía rotulada «zona 3», con el nombre de quien casi no trabaja en ese
 *     pedido.
 *
 *   • Para partir la hoja por zona sin duplicar renglones ni perder unidades.
 *     Si se partiera por «dónde hay stock» en vez de por «cuánto sale de acá»,
 *     el mismo renglón aparecería completo en dos secciones y entre las dos
 *     personas recogerían el doble.
 *
 * Vive aparte del controlador porque la hoja de un pedido y la de una oleada
 * tienen que repartir igual: dos copias de este bucle se desincronizan, y la
 * forma de notarlo es que el papel y el estante dejan de coincidir.
 */
final class RepartoPorUbicacion
{
    /**
     * Las paradas de una línea, en el orden en que hay que caminarlas.
     *
     * Entra un ítem ya armado por la hoja (`cantidad` + `ubicaciones`, que
     * vienen ordenadas con lo vendible primero y de mayor a menor stock) y
     * salen las ubicaciones que se visitan, cada una con su `toma`.
     *
     * @param  array{cantidad?: int|float, ubicaciones?: array<int, array>}  $item
     * @return array<int, array>
     */
    public static function paradas(array $item): array
    {
        $faltan = (int) ($item['cantidad'] ?? 0);
        $usadas = [];

        foreach (($item['ubicaciones'] ?? []) as $u) {
            /*
             * Exhibición no es una parada del recorrido: ir allá es una
             * excepción con traslado de por medio, no un estante más que
             * caminar. Si entrara, la hoja diría «compartido con zona» por
             * algo que no es una zona, y partiría una sección para mandar a
             * alguien a la vitrina.
             */
            if (! empty($u['es_respaldo'])) {
                continue;
            }

            // Lo que se saca de acá, que no es lo mismo que lo que hay.
            $u['toma'] = max(0, min($faltan, (int) $u['stock']));

            $usadas[] = $u;
            $faltan -= (int) $u['stock'];

            if ($faltan <= 0) {
                break;
            }
        }

        return $usadas;
    }

    /**
     * Cuántas unidades de la línea quedaron sin estante que las surta.
     *
     * Es lo que las paradas NO cubren: o la bodega no tiene tanto, o lo que
     * tiene está en exhibición. Ese sobrante hay que imprimirlo igual —en la
     * sección de «sin ubicación»— porque si se cae de la hoja partida, la suma
     * de las secciones deja de dar el total y nadie lo nota hasta que falta
     * mercancía en la caja.
     *
     * @param  array{cantidad?: int|float, ubicaciones?: array<int, array>}  $item
     */
    public static function sinCubrir(array $item): int
    {
        $cubierto = array_sum(array_map(
            fn (array $u) => (int) ($u['toma'] ?? 0),
            self::paradas($item)
        ));

        return max(0, (int) ($item['cantidad'] ?? 0) - $cubierto);
    }
}
