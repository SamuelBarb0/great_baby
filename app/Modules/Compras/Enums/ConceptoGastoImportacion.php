<?php

namespace App\Modules\Compras\Enums;

enum ConceptoGastoImportacion: string
{
    case Flete = 'flete';
    case Seguro = 'seguro';
    case Arancel = 'arancel';
    case IvaImportacion = 'iva_importacion';
    case AgenteAduana = 'agente_aduana';
    case Almacenaje = 'almacenaje';
    case TransporteInterno = 'transporte_interno';
    case Otros = 'otros';

    public function label(): string
    {
        return match ($this) {
            self::Flete => 'Flete internacional',
            self::Seguro => 'Seguro',
            self::Arancel => 'Arancel',
            self::IvaImportacion => 'IVA de importación',
            self::AgenteAduana => 'Agente aduanal',
            self::Almacenaje => 'Almacenaje',
            self::TransporteInterno => 'Transporte interno',
            self::Otros => 'Otros gastos',
        };
    }

    /**
     * ¿Este gasto es el IVA de importación?
     *
     * La regla vivia escrita a mano DENTRO de `LiquidarImportacion` y
     * `RegistrarAsientoCompra` no la tenia: el prorrateo excluia el IVA del
     * costo y el ASIENTO no, asi que el IVA entraba dos veces —una capitalizado
     * en 1435 y otra como credito fiscal en 1355— y el libro quedaba
     * descuadrado por el monto completo del IVA. El comentario de
     * `LiquidarImportacion:79` ya lo decia: «violacion partida doble».
     *
     * Vive aca para que las dos piezas pregunten lo mismo.
     */
    public function esIvaImportacion(): bool
    {
        return $this === self::IvaImportacion;
    }

    /**
     * Lo mismo, pero tolerante con lo que venga de la base: en las filas viejas
     * `concepto` puede ser texto suelto y no el enum.
     */
    public static function gastoEsIvaImportacion($gasto): bool
    {
        $c = $gasto->concepto ?? null;

        if ($c instanceof self) {
            return $c->esIvaImportacion();
        }

        $val = is_object($c) && property_exists($c, 'value') ? $c->value : (string) $c;

        return in_array(mb_strtolower(trim($val)), ['iva_importacion', 'iva importación', 'iva importacion', 'iva'], true);
    }

    public function capitalizablePorDefecto(): bool
    {
        return match ($this) {
            self::IvaImportacion => false,
            default => true,
        };
    }
}
