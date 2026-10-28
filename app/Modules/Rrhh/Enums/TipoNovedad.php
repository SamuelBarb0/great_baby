<?php

namespace App\Modules\Rrhh\Enums;

/**
 * Los cuatro bloques del cuadro de novedades que se capturan.
 *
 * Los otros dos —ingresos y retiros— no están acá porque no se registran: se
 * derivan de `fecha_ingreso` y `fecha_retiro` del empleado al armar el corte.
 *
 * Las CLASES de cada tipo salen tal cual del Excel que Gestión Humana manda al
 * contador. Son lista cerrada y no texto libre por una razón práctica: el
 * ausentismo se cuenta agrupando por clase, y «CITA MEDICA», «Cita médica» y
 * «cita medica» se cuentan como tres cosas distintas.
 */
enum TipoNovedad: string
{
    case Incapacidad = 'incapacidad';
    case Permiso = 'permiso';
    case Ausencia = 'ausencia';
    case HorasExtra = 'horas_extra';

    public function label(): string
    {
        return match ($this) {
            self::Incapacidad => 'Incapacidades y licencias',
            self::Permiso => 'Permisos',
            self::Ausencia => 'Ausencias',
            self::HorasExtra => 'Horas extra',
        };
    }

    /** En singular, para los mensajes que hablan de una sola. */
    public function singular(): string
    {
        return match ($this) {
            self::Incapacidad => 'incapacidad',
            self::Permiso => 'permiso',
            self::Ausencia => 'ausencia',
            self::HorasExtra => 'horas extra',
        };
    }

    /**
     * Las clases válidas dentro del tipo.
     *
     * La separación entre «EG» (enfermedad general) y «AT» (accidente de
     * trabajo) no es cosmética: el accidente de trabajo lo paga la ARL desde
     * el primer día y la enfermedad general la EPS desde el tercero, y van a
     * reportes distintos ante el Ministerio.
     *
     * @return array<string,string>
     */
    public function clases(): array
    {
        return match ($this) {
            self::Incapacidad => [
                'EG' => 'Enfermedad general',
                'AT' => 'Accidente de trabajo',
                'EL' => 'Enfermedad laboral',
                'MATERNIDAD' => 'Licencia de maternidad',
                'PATERNIDAD' => 'Licencia de paternidad',
                'LUTO' => 'Licencia de luto',
            ],
            self::Permiso => [
                'CITA_MEDICA' => 'Cita médica',
                'DILIGENCIA' => 'Diligencia personal',
                'ESTUDIO' => 'Estudio',
                'CALAMIDAD' => 'Calamidad doméstica',
                'COMPENSATORIO' => 'Compensatorio',
                'OTRO' => 'Otro',
            ],
            self::Ausencia => [
                'SIN_JUSTIFICACION' => 'Inasistencia sin justificación',
                'ABANDONO' => 'Abandono del puesto',
                'SUSPENSION' => 'Suspensión disciplinaria',
            ],
            self::HorasExtra => [
                'ORDINARIA' => 'Hora extra ordinaria',
                'DOMINICAL' => 'Dominical o festivo',
            ],
        };
    }

    public function etiquetaDeClase(string $clase): string
    {
        return $this->clases()[$clase] ?? $clase;
    }

    /**
     * ¿Se mide en horas en vez de en días?
     *
     * Las horas extra siempre. Los permisos a veces: el Excel trae «2 horas»
     * en la misma columna donde otros traen «3» días, y por eso la pantalla
     * debe preguntar la unidad en vez de adivinarla.
     */
    public function enHoras(): bool
    {
        return $this === self::HorasExtra;
    }

    /** Las horas extra SUMAN al pago; las otras tres restan o no tocan nada. */
    public function esDescuento(): bool
    {
        return $this !== self::HorasExtra;
    }

    /**
     * ¿Cuenta como ausentismo?
     *
     * Las horas extra obviamente no. Las otras tres sí: el indicador que pidió
     * Elena mide días perdidos, sin importar si estaban justificados. Un mes
     * con mucha incapacidad justificada es igual de problemático para la
     * operación que uno con mucha inasistencia.
     */
    public function cuentaAusentismo(): bool
    {
        return $this !== self::HorasExtra;
    }

    public function color(): string
    {
        return match ($this) {
            self::Incapacidad => 'warning',
            self::Permiso => 'info',
            self::Ausencia => 'danger',
            self::HorasExtra => 'success',
        };
    }

    /** Los cuatro, en el orden en que los lleva el Excel. */
    public static function delCuadro(): array
    {
        return [self::Incapacidad, self::HorasExtra, self::Permiso, self::Ausencia];
    }

    /**
     * Para mandar a la pantalla: tipo → sus clases.
     *
     * En el orden de `delCuadro()` y no en el de `cases()`, para que la
     * pantalla y el Excel exportado lleven los bloques en el mismo orden. Si
     * no, quien compara los dos tiene que ir buscando.
     */
    public static function catalogo(): array
    {
        $out = [];

        foreach (self::delCuadro() as $t) {
            $out[$t->value] = [
                'label' => $t->label(),
                'en_horas' => $t->enHoras(),
                'clases' => $t->clases(),
            ];
        }

        return $out;
    }
}
