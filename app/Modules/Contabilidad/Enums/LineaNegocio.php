<?php

namespace App\Modules\Contabilidad\Enums;

/**
 * Primer eje de la contabilidad: DE QUIÉN es el movimiento.
 *
 * Great Baby y Dropi son dos negocios que comparten el mismo ERP y hasta hoy
 * asentaban en la misma pila sin nada que los separara: `movimientos_contables`
 * no tenía ninguna columna que dijera a cuál pertenecía cada asiento. Lo único
 * para distinguirlos era deducirlo del `origen_type`, que es justo lo que hace
 * `deOrigen()` acá abajo — pero ahora queda guardado en la fila, no deducido en
 * cada consulta.
 *
 * Es un eje APARTE del destino fiscal: Dropi también factura, también cobra y
 * también tiene cosas que van a la DIAN y cosas que no. Ver `DestinoFiscal`.
 */
enum LineaNegocio: string
{
    case GreatBaby = 'great_baby';
    case Dropi = 'dropi';

    public function label(): string
    {
        return match ($this) {
            self::GreatBaby => 'Great Baby',
            self::Dropi => 'Dropi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GreatBaby => 'info',
            self::Dropi => 'warning',
        };
    }

    /**
     * De qué línea es un asiento, según el documento que lo originó.
     *
     * Se mira el namespace del `origen_type` porque es el dato que SIEMPRE
     * viene: todo asiento nace de un documento. Si mañana hay una tercera
     * línea de negocio con su propio módulo, basta agregarla acá.
     */
    public static function deOrigen(?string $origenType): self
    {
        if ($origenType && str_starts_with($origenType, 'App\\Modules\\Dropi\\')) {
            return self::Dropi;
        }

        return self::GreatBaby;
    }

    /** Para pintar el filtro sin repetir el listado en cada pantalla. */
    public static function opciones(): array
    {
        return array_map(
            fn (self $c) => ['valor' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
