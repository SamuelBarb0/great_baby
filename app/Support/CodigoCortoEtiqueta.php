<?php

namespace App\Support;

/**
 * Código corto para la etiqueta de la térmica cuando el código real no cabe
 * con rayas de 2 puntos en el adhesivo de ~29 mm.
 *
 * 27-nov-2026: el lector de mesa de la estación de empaque (Starpos) no leía
 * las rayas de 1 punto (0,125 mm), ni las de «AND2505-917» ni las del corto
 * «GBV…». Code 128 mete dos dígitos por símbolo, así que un código de 8
 * dígitos cabe con rayas de 2 puntos (0,25 mm): «1» + id de la variante o
 * «2» + id del producto agregado, rellenos a 7 cifras. Solo números, que
 * cualquier pistola escribe igual sin importar la distribución del teclado.
 *
 * Los cortos anteriores (GBV/GBP, 26-nov-2026) se siguen leyendo: ya hay
 * etiquetas impresas con ellos.
 */
class CodigoCortoEtiqueta
{
    public static function deVariante(int $id): string
    {
        return '1' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }

    public static function deProducto(int $id): string
    {
        return '2' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }

    /** @return array{tipo: 'variante'|'producto', id: int}|null */
    public static function leer(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));

        if (preg_match('/^([12])(\d{7,9})$/', $codigo, $m)) {
            $tipo = $m[1] === '1' ? 'variante' : 'producto';
        } elseif (preg_match('/^GB([VP])(\d{1,9})$/', $codigo, $m)) {
            $tipo = $m[1] === 'V' ? 'variante' : 'producto';
        } else {
            return null;
        }

        return (int) $m[2] > 0 ? ['tipo' => $tipo, 'id' => (int) $m[2]] : null;
    }
}
