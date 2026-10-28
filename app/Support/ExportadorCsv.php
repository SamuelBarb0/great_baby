<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F2c · Bajar a Excel lo que se está viendo, no «todo».
 *
 * El reclamo era concreto: se filtra un mes, se le da a exportar y sale el
 * archivo entero. Eso obliga a volver a filtrar en Excel —o peor, a trabajar
 * sobre el archivo equivocado sin notarlo—. Acá el export recibe la MISMA
 * consulta que pintó la tabla, así que no hay forma de que difieran.
 *
 * Dos decisiones que importan:
 *
 *   · Se escribe en streaming por tandas, no juntando todo en memoria. El
 *     libro de un año son decenas de miles de filas y un `->get()` las carga
 *     todas antes de mandar el primer byte: con suerte tarda, sin suerte tumba
 *     PHP.
 *
 *     Es `chunk()` y NO `chunkById()`, aunque el segundo sea el recomendado
 *     para recorrer tablas grandes. `chunkById` pagina con `WHERE id > último`
 *     pero NO borra el resto del ordenamiento: con un `ORDER BY fecha, id`
 *     —que es como se lee un libro contable— el «último id» de una tanda no es
 *     el id más alto de esa tanda, y la siguiente consulta se saltea todo lo
 *     que tenga id menor. O sea: filas que faltan en el archivo, en silencio.
 *     `chunk()` pagina por OFFSET y respeta el orden que pida quien exporta.
 *
 *   · Sale CSV con `;` y BOM. Excel en español abre así en columnas de una,
 *     sin el asistente de importación. Con `,` y sin BOM, el mismo archivo se
 *     ve como una sola columna con los acentos rotos, y la respuesta siempre
 *     es «el sistema exporta mal».
 */
final class ExportadorCsv
{
    /** Tope de filas, para que nadie se lleve la base entera por accidente. */
    public const MAX_FILAS = 50000;

    /**
     * @param  string                      $nombre    nombre del archivo, sin extensión
     * @param  array<int,string>           $columnas  encabezados
     * @param  BuilderContract             $consulta  la MISMA que pintó la tabla
     * @param  callable(object):array      $fila      cómo se convierte un registro en celdas
     * @param  array<int,string>           $pie       líneas al final (el filtro aplicado, totales…)
     */
    public static function descargar(
        string $nombre,
        array $columnas,
        BuilderContract $consulta,
        callable $fila,
        array $pie = [],
    ): StreamedResponse {
        $archivo = $nombre.'-'.now('America/Bogota')->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($columnas, $consulta, $fila, $pie) {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $columnas, ';');

            $escritas = 0;
            $consulta->chunk(500, function ($registros) use ($out, $fila, &$escritas) {
                foreach ($registros as $r) {
                    fputcsv($out, array_map([self::class, 'celda'], $fila($r)), ';');
                    $escritas++;
                }

                // Devolver false corta el recorrido: no se siguen trayendo
                // páginas de una consulta cuyo resultado ya no se va a escribir.
                return $escritas < self::MAX_FILAS;
            });

            if ($escritas >= self::MAX_FILAS) {
                fputcsv($out, [], ';');
                fputcsv($out, ['Se cortó en '.number_format(self::MAX_FILAS, 0, ',', '.')
                    .' filas. Afiná el filtro y volvé a exportar.'], ';');
            }

            foreach ($pie as $linea) {
                fputcsv($out, [$linea], ';');
            }

            fclose($out);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Un CSV de filas ya armadas, para lo que no es una sola consulta.
     *
     * El cuadro de novedades son SEIS bloques distintos —ingresos, retiros,
     * incapacidades, horas extra, permisos y ausencias—, cada uno con sus
     * propias columnas, en un mismo archivo. Eso no cabe en `descargar()`, que
     * recibe una consulta y un encabezado.
     *
     * No hay streaming acá a propósito: un corte mensual son decenas de filas,
     * no decenas de miles, y pedir que quepa en memoria es razonable. Si algún
     * día un cuadro creciera hasta doler, el que tiene que cambiar es este
     * método, no quien lo llama.
     *
     * Pasa por la misma `celda()` que el otro, así que hereda el separador, el
     * BOM, la coma decimal y la protección contra fórmulas.
     *
     * @param  array<int,array<int,mixed>>  $filas  una fila vacía deja un renglón en blanco
     */
    public static function deFilas(string $archivo, array $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($filas) {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));

            foreach ($filas as $fila) {
                fputcsv($out, array_map([self::class, 'celda'], $fila), ';');
            }

            fclose($out);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Una celda lista para Excel.
     *
     * Los números van con coma decimal porque Excel en es-CO los lee así; con
     * punto quedan como texto y no se pueden sumar, que es lo primero que hace
     * cualquiera con un export de contabilidad.
     *
     * El apóstrofo delante de un valor que empieza por `=`, `+`, `-` o `@` es a
     * propósito: sin él Excel lo interpreta como fórmula, y una descripción que
     * arranque así se ejecuta al abrir el archivo.
     */
    private static function celda(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        if (is_int($v)) {
            return (string) $v;
        }

        // Los importes salen SIEMPRE con dos decimales: con `(string) round()`
        // quedaba «11111» en una fila y «1234,56» en la otra, que en una
        // columna de plata se lee como si fueran cosas distintas.
        if (is_float($v)) {
            return number_format($v, 2, ',', '');
        }

        if (is_bool($v)) {
            return $v ? 'Sí' : 'No';
        }

        /*
         * Un enum castea mal a string y tumba la exportación ENTERA: el
         * encabezado ya salió, así que el navegador recibe medio CSV con la
         * página de error de Laravel pegada al final.
         *
         * Pasó de verdad con `clasificacion_diferencia` en el export de pagos.
         * Cualquier columna casteada puede traer uno sin que quien escribe el
         * export se entere, así que se resuelve acá y no en cada llamada: se
         * prefiere la etiqueta, que es lo que una persona entiende.
         */
        if ($v instanceof \BackedEnum) {
            return (string) (method_exists($v, 'label') ? $v->label() : $v->value);
        }
        if ($v instanceof \UnitEnum) {
            return $v->name;
        }

        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        // Lo que no sabe convertirse a texto sale vacío en lugar de tumbar el
        // archivo a medio escribir.
        if (is_array($v) || (is_object($v) && ! method_exists($v, '__toString'))) {
            return '';
        }

        $s = (string) $v;

        return $s !== '' && str_contains("=+-@", $s[0]) ? "'".$s : $s;
    }
}
