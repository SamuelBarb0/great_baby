<?php

namespace App\Modules\Logistica\Support;

/**
 * A qué zona operativa pertenece cada rack.
 *
 * El área reparte la bodega en zonas con un responsable cada una, y lo dijo
 * explícito: no se contempla más de un responsable por zona. En la lista de
 * picking que entregó, esas zonas se llaman A, B y C.
 *
 * Vive acá y no repetido en cada sitio porque lo usan tres: el sembrador que
 * creó las 435 posiciones, la hoja de picking —que tiene que decir por qué
 * zona se camina y quién responde— y el aviso de «compartido con zona» cuando
 * un pedido toca racks de dos.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EL REPARTO NO ESTÁ CLAVADO EN EL CÓDIGO
 *
 * Lo manda la regla `logistica.zonas_bodega`, editable en /app/reglas. Lo de
 * acá abajo es el valor por defecto —el levantamiento del área del
 * 17-nov-2026— para que el sistema arranque con algo sensato y para que no se
 * quede sin reparto si alguien borra la regla.
 *
 * Importa que sea configurable y no una constante: el área mueve racks de
 * zona, suma una cuarta o renombra las letras sin avisar, y cada vez que eso
 * pasa la hoja de picking manda a caminar por donde ya no es. Con la regla lo
 * cambia Aracely en un minuto; clavado, toca un despliegue.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ZonaDeLaBodega
{
    /** La regla que manda. Se lee SIN default en la llamada, para que gane DEFAULTS. */
    public const REGLA = 'logistica.zonas_bodega';

    /**
     * El reparto que entregó el área el 17-nov-2026.
     *
     * Es el valor por defecto de la regla, no la verdad: si la regla dice otra
     * cosa, manda la regla.
     */
    public const POR_DEFECTO = [
        'A' => ['1', '2', '3', '4', '5', '6'],
        'B' => ['6.5', '7', '8', '9', '10', '10.5'],
        'C' => ['11', '12', '13', '14', '15', 'PARQUEADERO', 'ARSUTEX', 'LOCAL'],
    ];

    /**
     * El reparto EFECTIVO: lo que diga la regla, o el de arriba.
     *
     * Se sanea a conciencia porque esto lo escribe una persona en un cuadro de
     * texto: una zona sin racks, un rack con espacios o un JSON que no es un
     * objeto de listas no puede dejar la bodega sin zonas.
     *
     * @return array<string, array<int, string>>
     */
    public static function mapa(): array
    {
        $crudo = setting(self::REGLA);

        if (is_string($crudo)) {
            $crudo = json_decode($crudo, true);
        }

        if (! is_array($crudo) || $crudo === []) {
            return self::POR_DEFECTO;
        }

        $mapa = [];

        foreach ($crudo as $letra => $racks) {
            $letra = mb_strtoupper(trim((string) $letra));

            if ($letra === '' || ! is_array($racks)) {
                continue;
            }

            $limpios = [];

            foreach ($racks as $rack) {
                if (is_array($rack) || is_object($rack)) {
                    continue;
                }

                $rack = mb_strtoupper(trim((string) $rack));

                if ($rack !== '' && ! in_array($rack, $limpios, true)) {
                    $limpios[] = $rack;
                }
            }

            if ($limpios !== []) {
                $mapa[$letra] = $limpios;
            }
        }

        return $mapa === [] ? self::POR_DEFECTO : $mapa;
    }

    /** Las letras de las zonas, en el orden en que las escribió el área. */
    public static function letras(): array
    {
        return array_keys(self::mapa());
    }

    /** La zona de un rack —«A»—, o null si ese rack no está en el reparto. */
    public static function deRack(?string $rack): ?string
    {
        if ($rack === null || trim($rack) === '') {
            return null;
        }

        $rack = mb_strtoupper(trim($rack));

        foreach (self::mapa() as $letra => $racks) {
            if (in_array($rack, $racks, true)) {
                return $letra;
            }
        }

        return null;
    }

    /**
     * La zona a la que pertenece una ubicación.
     *
     * Se decide por el RACK —la columna `pasillo`— y no por quién responde
     * por ella: el responsable se puede cambiar desde la pantalla, y el rack
     * es dónde está parada la mercancía. Si alguien reasigna un estante, la
     * zona del plano no cambia.
     */
    public static function deUbicacion(?object $ubicacion): ?string
    {
        return $ubicacion ? self::deRack($ubicacion->pasillo ?? null) : null;
    }

    /**
     * El número de la zona: «A» → 1.
     *
     * Es la POSICIÓN en el reparto, no una tabla aparte, para que agregar una
     * zona D no obligue a tocar código. Sirve para el nombre por defecto del
     * responsable («Responsable 1»), que es como los identifica el área
     * mientras no tengan nombre propio.
     */
    public static function numero(?string $letra): ?int
    {
        if ($letra === null) {
            return null;
        }

        $pos = array_search(mb_strtoupper(trim($letra)), self::letras(), true);

        return $pos === false ? null : $pos + 1;
    }

    /** Los racks de una zona. */
    public static function racksDe(?string $letra): array
    {
        return $letra ? (self::mapa()[mb_strtoupper(trim($letra))] ?? []) : [];
    }

    /**
     * Las zonas que toca un conjunto de racks, en el orden del reparto.
     *
     * Un pedido que toca dos zonas necesita a dos personas o un recorrido más
     * largo, y eso hay que decirlo ANTES de empezar a alistar: la lista del
     * área tiene una casilla «compartido con zona» justamente para eso.
     *
     * @param  iterable<string>  $racks
     * @return array<int, string>
     */
    public static function zonasDe(iterable $racks): array
    {
        $encontradas = [];

        foreach ($racks as $rack) {
            $z = self::deRack((string) $rack);

            if ($z !== null && ! in_array($z, $encontradas, true)) {
                $encontradas[] = $z;
            }
        }

        // En el orden del reparto, no en el que vinieron los ítems: la hoja se
        // lee igual siempre.
        return array_values(array_filter(self::letras(), fn ($l) => in_array($l, $encontradas, true)));
    }
}
