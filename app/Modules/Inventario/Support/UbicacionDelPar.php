<?php

namespace App\Modules\Inventario\Support;

use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;

/**
 * Convierte el par (oficina, almacén) en la bodega concreta donde cae la
 * mercancía.
 *
 * La bodega razona con dos ejes —de quién es y en qué estado está— pero el
 * kardex sólo entiende de `inventario_ubicaciones`. Esta clase es la bisagra:
 * recibe «oficina 11, almacén 01» y devuelve la fila que representa a Dropi ·
 * Principal.
 *
 * Si el par todavía no tiene bodega, la crea. Se decidió así y no «fallar
 * pidiendo que la configuren» por una razón concreta: el contenedor ya está
 * descargado en el piso cuando alguien abre esta pantalla. Negarse a recibir
 * porque falta una fila de catálogo deja la mercancía sin registrar y a la
 * bodega trabajando en papel, que es peor que una bodega creada sola y bien
 * nombrada. Lo que SÍ se niega es inventar un par que el catálogo no admite
 * para ingreso directo: esa regla la puso el área y no se salta.
 */
class UbicacionDelPar
{
    /**
     * La bodega del par, creándola si hace falta.
     *
     * @param  bool  $esIngreso  true cuando viene de una recepción: entonces se
     *                           exige que el par admita ingreso directo.
     */
    public function resolver(
        Oficina $oficina,
        Almacen $almacen,
        bool $esIngreso = false,
        ?InventarioUbicacion $comoEsta = null,
    ): InventarioUbicacion {
        if ($esIngreso) {
            $this->exigirQueAdmitaIngreso($oficina, $almacen);
        }

        $ya = $this->buscar($oficina, $almacen);
        if ($ya) {
            return $ya;
        }

        /*
         * Si el par ya tiene bodega pero está desactivada, NO se crea otra.
         *
         * `buscar()` filtraba por activa, así que una bodega apagada dejaba el
         * par «libre» y la siguiente recepción creaba una gemela: dos filas
         * que significan lo mismo, con la mitad del stock cada una, y nadie se
         * entera hasta que el alistador no encuentra mercancía que el sistema
         * dice tener. Apagar una bodega es una decisión; se respeta y se
         * explica, con el nombre de la fila para que se pueda ir a arreglar.
         */
        $apagada = $this->buscarIncluyendoApagadas($oficina, $almacen);

        if ($apagada !== null) {
            abort(422, "«{$apagada->nombre}» ({$apagada->codigo}) es la bodega de "
                ."{$oficina->etiqueta()} / {$almacen->etiqueta()} y está desactivada. "
                .'Actívala en Bodegas, o quítale el par en el Catálogo, antes de recibir ahí. '
                .'Crear otra partiría el inventario en dos.');
        }

        return $this->crear($oficina, $almacen, $comoEsta);
    }

    /** La bodega ya mapeada a este par, si existe. Null si nadie la ha asociado. */
    public function buscar(Oficina $oficina, Almacen $almacen): ?InventarioUbicacion
    {
        return $this->consultaDelPar($oficina, $almacen)->where('activa', true)->first();
    }

    /** Lo mismo, pero mirando también las apagadas. */
    public function buscarIncluyendoApagadas(Oficina $oficina, Almacen $almacen): ?InventarioUbicacion
    {
        return $this->consultaDelPar($oficina, $almacen)->first();
    }

    private function consultaDelPar(Oficina $oficina, Almacen $almacen)
    {
        return InventarioUbicacion::query()
            ->where('oficina_id', $oficina->id)
            ->where('almacen_id', $almacen->id)
            // Una bodega es la que no cuelga de otra. Los racks heredan el par
            // de su bodega y no deben ganarle a ella en esta búsqueda.
            ->orderByRaw('bodega_id IS NULL DESC')
            ->orderBy('id');
    }

    /**
     * Por qué no se puede recibir contra este par.
     *
     * Devuelve el motivo en palabras, o null si sí se puede. Se usa para
     * pintar la pantalla antes de que alguien intente guardar.
     */
    public function motivoParaNoRecibir(Oficina $oficina, Almacen $almacen): ?string
    {
        if (! $oficina->permite_ingreso_directo) {
            return "A {$oficina->etiqueta()} no entra mercancía directo del contenedor: "
                .'sólo llega por traslado, después de que ya ingresó al sistema.';
        }

        if (! $almacen->permite_ingreso_directo) {
            return "Al almacén {$almacen->etiqueta()} no se ingresa directo: "
                .'la mercancía entra al 01 PRINCIPAL o al 07 GARANTÍA DE PROVEEDOR, '
                .'y de ahí se traslada.';
        }

        if (! $oficina->activa || ! $almacen->activo) {
            return 'Esa combinación está desactivada en el catálogo.';
        }

        return null;
    }

    private function exigirQueAdmitaIngreso(Oficina $oficina, Almacen $almacen): void
    {
        $motivo = $this->motivoParaNoRecibir($oficina, $almacen);
        abort_if($motivo !== null, 422, $motivo);
    }

    private function crear(
        Oficina $oficina,
        Almacen $almacen,
        ?InventarioUbicacion $comoEsta = null,
    ): InventarioUbicacion {
        $categoria = $almacen->categoria();

        /*
         * La bodega nueva hereda responsable y ciudad de la bodega contra la
         * que se estaba recibiendo.
         *
         * Naciendo sin responsable quedaba invisible: el alcance de bodegas se
         * calcula desde `responsable_user_id`, así que la mercancía que Jorge
         * acababa de recibir no le aparecía en su cola, ni en su kardex, ni en
         * su buscador —y tampoco la podía mover. Era una bodega que existía
         * sólo para el sistema. Heredar de la bodega de la orden es la
         * respuesta correcta en el único caso que la crea: el reparto de un
         * contenedor que estaba entrando a esa sede.
         *
         * La categoría la fija el almacén y `disponible_para_venta` sale de la
         * categoría —es el modelo el que lo garantiza, en su hook de guardado—,
         * así que el catálogo no deja guardar un almacén cuyo `comercial`
         * contradiga su categoría: si contradijera, este valor se perdería en
         * silencio.
         */
        return InventarioUbicacion::create([
            'codigo' => $this->codigoLibre($oficina, $almacen),
            'nombre' => "{$oficina->nombre} · {$almacen->nombre}",
            'oficina_id' => $oficina->id,
            'almacen_id' => $almacen->id,
            'categoria' => $categoria,
            'disponible_para_venta' => $categoria->disponibleParaVenta(),
            'responsable_user_id' => $comoEsta?->responsable_user_id,
            'ciudad' => $comoEsta?->ciudad,
            'activa' => true,
            'notas' => 'Creada automáticamente al recibir mercancía para '
                ."{$oficina->etiqueta()} / {$almacen->etiqueta()}."
                .($comoEsta ? " Responsable heredado de {$comoEsta->nombre}." : ''),
        ]);
    }

    /**
     * `10-01`, y si ya está tomado por otra cosa, `10-01-2`.
     *
     * `codigo` es único en la tabla y hay 43 filas importadas de SIIGO con
     * códigos que nadie controla. Chocar con una de ellas tumbaría la
     * recepción entera.
     */
    private function codigoLibre(Oficina $oficina, Almacen $almacen): string
    {
        $base = "{$oficina->codigo}-{$almacen->codigo}";
        $codigo = $base;
        $n = 1;

        while (InventarioUbicacion::where('codigo', $codigo)->exists()) {
            $codigo = $base.'-'.(++$n);
        }

        return $codigo;
    }
}
