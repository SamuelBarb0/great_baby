<?php

namespace App\Modules\Rrhh\Enums;

/**
 * El tipo de contrato que se pide y con el que se abre la vacante.
 *
 * Existe porque había TRES verdades distintas sobre lo mismo y ninguna sabía
 * de las otras:
 *
 *   · el formulario de requisición las traía escritas a mano en el Vue
 *     —indefinido, fijo, obra_labor, aprendiz, prestacion—;
 *   · `rrhh_requisiciones.tipo_contrato` es un `string(60)` validado sólo como
 *     «texto de hasta 60», así que aceptaba cualquier cosa;
 *   · `rrhh_vacantes.tipo_contrato` es un ENUM estricto con OTRAS cinco:
 *     indefinido, obra_labor, prestacion_servicios, aprendizaje, temporal.
 *
 * Sólo dos coincidían. `abrirVacante()` copiaba el valor tal cual, MySQL
 * rechazaba el enum y el servidor respondía 500 — en silencio, porque la
 * pantalla cerraba el modal y dejaba todo igual. Una requisición a término
 * fijo, de aprendiz SENA o de prestación de servicios quedaba aprobada para
 * siempre sin poder convertirse en vacante, que son justo los tipos que más
 * se usan en el país.
 *
 * Los nombres de acá son los que habla el área y los que YA están guardados
 * en las requisiciones; los de la tabla de vacantes se alcanzan con
 * `paraVacante()`. Agregar un tipo nuevo es tocar este archivo y nada más.
 */
enum TipoContrato: string
{
    case Indefinido = 'indefinido';
    case Fijo = 'fijo';
    case ObraLabor = 'obra_labor';
    case Aprendiz = 'aprendiz';
    case Prestacion = 'prestacion';

    public function label(): string
    {
        return match ($this) {
            self::Indefinido => 'Indefinido',
            self::Fijo => 'Término fijo',
            self::ObraLabor => 'Obra o labor',
            self::Aprendiz => 'Aprendiz SENA',
            self::Prestacion => 'Prestación de servicios',
        };
    }

    /**
     * Cómo se llama este mismo contrato en `rrhh_vacantes`.
     *
     * Dos de los cinco se escriben distinto —`aprendiz`/`aprendizaje` y
     * `prestacion`/`prestacion_servicios`— y no es más que ortografía: es el
     * mismo contrato y se traduce. `fijo` SÍ se agregó a la tabla, porque no
     * tenía equivalente: el `temporal` que ya estaba ahí es otra cosa —el de
     * empresa de servicios temporales— y mapear uno al otro habría rotulado
     * mal el contrato de la persona en su propia ficha.
     */
    public function paraVacante(): string
    {
        return match ($this) {
            self::Aprendiz => 'aprendizaje',
            self::Prestacion => 'prestacion_servicios',
            default => $this->value,
        };
    }

    /**
     * El valor guardado, traducido a enum, tolerando lo viejo.
     *
     * Las requisiciones anteriores a este archivo guardaron texto libre: puede
     * haber un `prestacion_servicios` escrito por alguien que copió el nombre
     * de la otra tabla. Se reconoce y se devuelve el caso correcto en vez de
     * reventar.
     */
    public static function desdeGuardado(?string $v): ?self
    {
        $v = trim((string) $v);

        if ($v === '') {
            return null;
        }

        return self::tryFrom($v) ?? match ($v) {
            'aprendizaje' => self::Aprendiz,
            'prestacion_servicios' => self::Prestacion,
            'temporal' => self::Fijo,
            default => null,
        };
    }

    /** Para el selector de la pantalla, sin que el Vue repita los textos. */
    public static function catalogo(): array
    {
        return array_map(fn (self $t) => [
            'valor' => $t->value,
            'label' => $t->label(),
        ], self::cases());
    }
}
