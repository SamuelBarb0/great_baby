<?php

namespace App\Modules\Logistica\Support;

/**
 * La cuadrícula del piso donde se arman los pedidos.
 *
 * El procedimiento del área separa el picking del packing con un paso propio:
 * «Consolidación — trasladar al punto de consolidación y agrupar la totalidad
 * de ítems por cada orden», con entrada «artículos de distintas zonas».
 *
 * Varias personas recogen a la vez por zonas distintas, todo baja a un mismo
 * punto, y ahí se reparte por pedido. Cada pedido en curso necesita entonces un
 * sitio físico donde ir juntándose, y don Jorge mandó el plano: seis columnas
 * por tres filas, que él numera `C1FA` … `C6FC`.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO MANDA CUÁNTOS PEDIDOS CABEN EN UNA OLEADA
 *
 * Las casillas son el límite real: no se pueden armar más pedidos a la vez de
 * los que caben en el piso. Dieciocho casillas son dieciocho pedidos. Si el
 * área agranda la zona, se cambia acá —o en la regla— y el resto se acomoda.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ZonaDeAlistamiento
{
    /** La regla que manda. Se lee SIN default en la llamada, para que gane DEFAULTS. */
    public const REGLA = 'logistica.casillas_alistamiento';

    /** El plano que entregó el área: 6 columnas × 3 filas. */
    public const COLUMNAS_POR_DEFECTO = 6;

    public const FILAS_POR_DEFECTO = 3;

    /** Las filas se nombran con letras, como en el plano. */
    private const LETRAS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    /**
     * Todas las casillas, en el orden en que se recorren: C1FA, C1FB, C1FC, C2FA…
     *
     * Se lee del plano configurable para que agrandar la zona no sea un
     * despliegue. Los valores raros —cero, negativos, texto— caen al plano del
     * área en vez de dejar la bodega sin casillas.
     *
     * @return array<int, string>
     */
    public static function casillas(): array
    {
        [$columnas, $filas] = self::plano();

        $salida = [];

        for ($c = 1; $c <= $columnas; $c++) {
            for ($f = 0; $f < $filas; $f++) {
                $salida[] = "C{$c}F".self::LETRAS[$f];
            }
        }

        return $salida;
    }

    /** Cuántos pedidos caben a la vez: es el tope de una oleada. */
    public static function cuantasCaben(): int
    {
        return count(self::casillas());
    }

    /** ¿Existe esa casilla en el plano de hoy? */
    public static function existe(?string $casilla): bool
    {
        return $casilla !== null
            && in_array(mb_strtoupper(trim($casilla)), self::casillas(), true);
    }

    /**
     * Las casillas libres, en orden, dadas las que ya están ocupadas.
     *
     * @param  iterable<string>  $ocupadas
     * @return array<int, string>
     */
    public static function libres(iterable $ocupadas): array
    {
        $tomadas = [];

        foreach ($ocupadas as $o) {
            if ($o !== null && trim((string) $o) !== '') {
                $tomadas[] = mb_strtoupper(trim((string) $o));
            }
        }

        return array_values(array_filter(
            self::casillas(),
            fn ($c) => ! in_array($c, $tomadas, true),
        ));
    }

    /**
     * El plano efectivo: [columnas, filas].
     *
     * @return array{0:int,1:int}
     */
    private static function plano(): array
    {
        $crudo = setting(self::REGLA);

        if (is_string($crudo)) {
            $crudo = json_decode($crudo, true);
        }

        $columnas = is_array($crudo) ? (int) ($crudo['columnas'] ?? 0) : 0;
        $filas = is_array($crudo) ? (int) ($crudo['filas'] ?? 0) : 0;

        // Más filas que letras no se puede nombrar, así que ahí se corta.
        if ($columnas < 1 || $filas < 1 || $filas > count(self::LETRAS)) {
            return [self::COLUMNAS_POR_DEFECTO, self::FILAS_POR_DEFECTO];
        }

        return [$columnas, $filas];
    }
}
