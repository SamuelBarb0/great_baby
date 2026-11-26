<?php

namespace App\Support;

use Illuminate\Support\Str;
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

    private const INICIO_C = 105;

    /** Cambio de juego dentro del código: desde B a C, y desde C a B. */
    private const A_C = 99;

    private const A_B = 100;

    private const FIN = 106;

    /**
     * Símbolos: inicio, datos, control y fin. Las tiradas largas de dígitos van
     * en el juego C, de a dos por símbolo (26-nov-2026: el código quedaba tan
     * ancho que en el adhesivo de 29 mm solo cabía con rayas de un punto). El
     * lector devuelve el mismo texto: el juego es cosa de las barras.
     *
     * @return list<int>
     */
    public static function simbolos(string $texto): array
    {
        if ($texto === '' || preg_match('/[^\x20-\x7E]/', $texto)) {
            throw new InvalidArgumentException("El código «{$texto}» tiene caracteres que Code 128 B no admite.");
        }

        $simbolos = [];
        $juego = null;
        $enB = function (string $c) use (&$simbolos, &$juego): void {
            if ($juego !== 'B') {
                $simbolos[] = $juego === null ? self::INICIO_B : self::A_B;
                $juego = 'B';
            }
            $simbolos[] = ord($c) - 32;
        };

        $largo = strlen($texto);
        $i = 0;
        while ($i < $largo) {
            $digitos = strspn($texto, '0123456789', $i);
            // Cambiar de juego cuesta un símbolo: al inicio o al final del código
            // compensa desde 4 dígitos; en medio (ida y vuelta), desde 6.
            $minimo = ($i === 0 || $i + $digitos === $largo) ? 4 : 6;
            if ($digitos < $minimo) {
                $enB($texto[$i++]);

                continue;
            }
            if ($digitos % 2 === 1) {
                $enB($texto[$i++]);
                $digitos--;
            }
            if ($juego !== 'C') {
                $simbolos[] = $juego === null ? self::INICIO_C : self::A_C;
                $juego = 'C';
            }
            for ($fin = $i + $digitos; $i < $fin; $i += 2) {
                $simbolos[] = (int) substr($texto, $i, 2);
            }
        }

        $control = $simbolos[0];
        foreach (array_slice($simbolos, 1) as $posicion => $valor) {
            $control += $valor * ($posicion + 1);
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

    /**
     * Las barras y espacios seguidos, en módulos: [[true, 2], [false, 1], …].
     * Para dibujarlas como rectángulos en la etiqueta térmica (26-nov-2026): la
     * imagen reducida se imprimía punteada y la pistola de empaque no la leía.
     *
     * @return list<array{0: bool, 1: int}>
     */
    public static function tramos(string $texto): array
    {
        $tramos = [];
        foreach (self::modulos(self::codificable($texto)) as $barra) {
            $ultimo = array_key_last($tramos);
            if ($ultimo !== null && $tramos[$ultimo][0] === $barra) {
                $tramos[$ultimo][1]++;
            } else {
                $tramos[] = [$barra, 1];
            }
        }

        return $tramos;
    }

    /**
     * Lo que de verdad va en las barras: Code 128 B no tiene tildes ni eñes, así
     * que «LEÓ» se codifica «LEO». El lector lee «LEO» y la búsqueda por
     * codigo_barras igual encuentra la variante (la columna es utf8mb4_unicode_ci).
     */
    public static function codificable(string $texto): string
    {
        return Str::ascii($texto);
    }

    /** SVG con zona de silencio y el texto debajo; cada módulo mide 1 unidad del viewBox. */
    public static function svg(string $texto, int $alto = 50): string
    {
        $modulos = self::modulos(self::codificable($texto));
        $silencio = 10;
        $ancho = count($modulos) + 2 * $silencio;
        $altoTotal = $alto + 14;

        $barras = '';
        $inicio = null;
        foreach ([...$modulos, false] as $i => $barra) {
            if ($barra && $inicio === null) {
                $inicio = $i;
            } elseif (! $barra && $inicio !== null) {
                $barras .= '<rect x="'.($silencio + $inicio).'" y="0" width="'.($i - $inicio).'" height="'.$alto.'"/>';
                $inicio = null;
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$ancho.' '.$altoTotal.'" width="'.($ancho * 2).'" height="'.($altoTotal * 2).'" shape-rendering="crispEdges">'
            .'<rect width="100%" height="100%" fill="#fff"/>'
            .'<g fill="#000">'.$barras.'</g>'
            .'<text x="'.($ancho / 2).'" y="'.($alto + 11).'" font-family="monospace" font-size="10" text-anchor="middle" fill="#000">'.htmlspecialchars($texto, ENT_XML1).'</text>'
            .'</svg>';
    }

    /** PNG con su texto debajo, para el PDF de etiquetas y para el Excel. */
    public static function png(string $texto, int $modulo = 2, int $alto = 70): string
    {
        $texto = self::codificable($texto);
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
