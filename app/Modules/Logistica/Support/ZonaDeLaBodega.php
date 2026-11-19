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

    /*
     * EL PLANO, Y POR QUÉ EL ORDEN DE LOS RACKS ES EL RECORRIDO
     *
     * Don Jorge mandó el plano con la entrada marcada (19-nov-2026). Leído por
     * coordenadas de la hoja, la bodega queda así:
     *
     *      ← fondo                                            entrada →
     *   R15 R14 R13 R12 R11 R10 R9 R8 R7 R6 R5 R4 R3 R2 R1   [ENTRADA]
     *         ↑R10.5 al fondo        ↑R6.5 al fondo
     *                      [ZONA DE ALISTAMIENTO]
     *                       debajo de R7, en el centro
     *
     * De ahí salen tres cosas que el sistema usa:
     *
     *   1. El recorrido eficiente es R1 → R2 → … → R15. Se entra por la
     *      derecha, junto al rack 1, y se camina hacia el fondo sin
     *      devolverse. Por eso la hoja de oleada ordena las paradas por rack
     *      ASCENDENTE: es el orden en que se pasa por delante de ellos.
     *
     *   2. Las zonas quedan por cercanía a la puerta: la A es la de la
     *      entrada, la B la del medio, la C el fondo. El responsable 1 es
     *      entonces el que está más cerca de la salida, que es donde se
     *      despacha.
     *
     *   3. La zona de alistamiento está en el CENTRO, no en una punta. Es
     *      coherente con el paso de consolidación del procedimiento: los tres
     *      operarios convergen ahí desde sus zonas con lo que recogieron.
     */

    /**
     * El reparto que entregó el área el 17-nov-2026, confirmado con el plano
     * del 19-nov: coincide con la cercanía a la puerta.
     *
     * Es el valor por defecto de la regla, no la verdad: si la regla dice otra
     * cosa, manda la regla.
     */
    public const POR_DEFECTO = [
        // Se entra por el rack 1 y se camina hacia el fondo.
        '1' => ['1', '2', '3', '4', '5', '6'],

        // El 6.5 está al fondo, justo detrás del 7: se recoge al pasar. El
        // 10.5 también está al fondo pero mucho más adentro —a la altura del
        // 12— así que queda de último. Este orden lo propusimos nosotros a
        // partir del plano; falta que el área lo confirme.
        '2' => ['7', '6.5', '8', '9', '10', '10.5'],

        // Tal cual lo pidió el área el 19-nov-2026: arranca por el local,
        // sigue por el parqueadero y vuelve desde el fondo hacia la zona de
        // alistamiento, con Arsutex entre el 13 y el 12.
        '3' => ['LOCAL', 'PARQUEADERO', '15', '14', '13', 'ARSUTEX', '12', '11'],
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

    /**
     * Los nombres de las zonas, en el orden en que las escribió el área.
     *
     * Van siempre como TEXTO. El área las llama «1», «2» y «3», y PHP
     * convierte solo las claves numéricas de un arreglo a entero: sin este
     * `strval` la comparación estricta de `numero()` no encontraba nunca la
     * zona y todos los responsables salían con el número cero.
     *
     * @return array<int, string>
     */
    public static function letras(): array
    {
        return array_map('strval', array_keys(self::mapa()));
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
                return (string) $letra;
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

    /**
     * El correo con el que nace la cuenta que etiqueta una zona.
     *
     * Vive acá porque lo usan dos sitios que no se pueden separar: el
     * sembrador que crea esas cuentas, y la Cola de alistamiento, que tiene
     * que EXCLUIRLAS de la lista de a quién asignarle un pedido. Si los dos
     * arman el correo por su cuenta y uno cambia, la cola vuelve a ofrecer
     * cuentas con las que nadie puede entrar.
     */
    public static function correoDeEtiqueta(int $numero): string
    {
        return "responsable{$numero}@greatbaby.com";
    }

    /**
     * Los correos de todas las cuentas-etiqueta del reparto de hoy.
     *
     * @return array<int, string>
     */
    public static function correosDeEtiqueta(): array
    {
        return array_map(
            fn ($i) => self::correoDeEtiqueta($i + 1),
            array_keys(self::letras()),
        );
    }

    /**
     * En qué puesto del recorrido va este rack dentro de su zona.
     *
     * ───────────────────────────────────────────────────────────────────
     * EL ORDEN DE LA LISTA *ES* EL RECORRIDO
     *
     * No se deduce del número del rack. El área lo pidió explícito para la
     * zona 3 (19-nov-2026) y no es ascendente ni descendente:
     *
     *     local → parqueadero → 15 → 14 → 13 → arsutex → 12 → 11
     *
     * Las zonas sueltas van intercaladas entre los racks, y el recorrido
     * arranca por el fondo y vuelve hacia la zona de alistamiento. Ordenar
     * por número —como se hacía— mandaba a caminar al revés y dejaba el
     * local de último, cuando el área pidió que fuera lo PRIMERO.
     *
     * Así que el orden se lee de la regla tal como está escrita. Para
     * cambiar el recorrido se reordena la lista en /app/reglas; no hay que
     * tocar código ni inventar una regla aparte.
     * ───────────────────────────────────────────────────────────────────
     */
    public static function ordenEnElRecorrido(?string $rack): int
    {
        if ($rack === null || trim($rack) === '') {
            return PHP_INT_MAX;
        }

        $rack = mb_strtoupper(trim($rack));

        foreach (self::mapa() as $racks) {
            $pos = array_search($rack, $racks, true);

            if ($pos !== false) {
                return $pos;
            }
        }

        // Un rack que nadie repartió se camina de último: no está en la ruta.
        return PHP_INT_MAX;
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
