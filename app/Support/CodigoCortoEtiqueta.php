<?php

namespace App\Support;

/**
 * Código corto para la etiqueta de la térmica cuando el código real no cabe
 * (26-nov-2026): en el adhesivo de ~29 mm, Code 128 con rayas de un punto solo
 * aguanta unos 13 caracteres, y la mitad de las variantes tienen códigos de 16
 * a 24 («AND2507-94/800-05ELF-18M»). «GBV» + id de la variante o «GBP» + id
 * del producto agregado: letras y números, que cualquier pistola escribe igual
 * sin importar la distribución del teclado.
 */
class CodigoCortoEtiqueta
{
    public static function deVariante(int $id): string
    {
        return 'GBV' . $id;
    }

    public static function deProducto(int $id): string
    {
        return 'GBP' . $id;
    }

    /** @return array{tipo: 'variante'|'producto', id: int}|null */
    public static function leer(string $codigo): ?array
    {
        if (! preg_match('/^GB([VP])(\d{1,9})$/', strtoupper(trim($codigo)), $m)) {
            return null;
        }

        return ['tipo' => $m[1] === 'V' ? 'variante' : 'producto', 'id' => (int) $m[2]];
    }
}
