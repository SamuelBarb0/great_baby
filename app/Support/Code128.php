<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Código de barras Code 128 (juego B), de verdad: con sus patrones y su dígito de
 * control, para que lo lea un lector.
 */
class Code128
{
    /** Anchos de barra y espacio de cada símbolo, del 0 al 106. */
    private const PATRONES = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const INICIO_B = 104;

    private const FIN = 106;

    /** @return list<int> símbolos: inicio, datos, control y fin */
    public static function simbolos(string $texto): array
    {
        if ($texto === '' || preg_match('/[^\x20-\x7E]/', $texto)) {
            throw new InvalidArgumentException("El código «{$texto}» tiene caracteres que Code 128 B no admite.");
        }

        $simbolos = [self::INICIO_B];
        $control = self::INICIO_B;
        foreach (str_split($texto) as $i => $c) {
            $valor = ord($c) - 32;
            $simbolos[] = $valor;
            $control += $valor * ($i + 1);
        }
        $simbolos[] = $control % 103;
        $simbolos[] = self::FIN;

        return $simbolos;
    }

    /** Barras y espacios en módulos: true = barra. */
    public static function modulos(string $texto): array
    {
        $modulos = [];
        foreach (self::simbolos($texto) as $s) {
            foreach (str_split(self::PATRONES[$s]) as $i => $ancho) {
                array_push($modulos, ...array_fill(0, (int) $ancho, $i % 2 === 0));
            }
        }

        return $modulos;
    }

    /** PNG con su texto debajo, para el PDF de etiquetas y para el Excel. */
    public static function png(string $texto, int $modulo = 2, int $alto = 70): string
    {
        $modulos = self::modulos($texto);
        $silencio = 10 * $modulo;
        $ancho = count($modulos) * $modulo + 2 * $silencio;
        $imagen = imagecreatetruecolor($ancho, $alto + 18);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $negro = imagecolorallocate($imagen, 0, 0, 0);
        imagefill($imagen, 0, 0, $blanco);

        foreach ($modulos as $i => $barra) {
            if ($barra) {
                $x = $silencio + $i * $modulo;
                imagefilledrectangle($imagen, $x, 0, $x + $modulo - 1, $alto - 1, $negro);
            }
        }
        $fuente = 3;
        imagestring($imagen, $fuente, (int) (($ancho - strlen($texto) * imagefontwidth($fuente)) / 2), $alto + 2, $texto, $negro);

        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        imagedestroy($imagen);

        return $png;
    }
}
