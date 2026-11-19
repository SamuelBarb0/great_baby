<?php

namespace App\Modules\Inventario\Enums;

/**
 * Por qué entra la mercancía de una carga masiva, y contra qué cuenta.
 *
 * La contadora lo pidió en la reunión del 2026-11-17: todo ingreso de
 * inventario tiene que decir de dónde viene. Si se compró, se le debe a un
 * proveedor; si lo pusieron los socios, es capital; si es el arranque del
 * sistema, es un saldo inicial. Sin ese porqué el débito al 1435 no tiene
 * contrapartida y el balance no cuadra.
 *
 * La cuenta no está fija en el código: cada motivo lee su regla `contable.*`
 * para que la contadora la ajuste desde el panel de Reglas.
 */
enum MotivoCargaInventario: string
{
    case Compra = 'compra';
    case AporteSocios = 'aporte_socios';
    case SaldoInicial = 'saldo_inicial';
    case Donacion = 'donacion';
    case Sobrante = 'sobrante';

    public function label(): string
    {
        return match ($this) {
            self::Compra => 'Compra de mercancía',
            self::AporteSocios => 'Aporte de socios / inversión',
            self::SaldoInicial => 'Inventario inicial (apertura de cuentas)',
            self::Donacion => 'Donación / mercancía recibida sin pago',
            self::Sobrante => 'Sobrante encontrado (ajuste)',
        };
    }

    /** Qué le explica la pantalla a quien elige el motivo. */
    public function ayuda(): string
    {
        return match ($this) {
            self::Compra => 'Queda una cuenta por pagar al proveedor que elijas.',
            self::AporteSocios => 'La mercancía entra como capital aportado por el socio que elijas.',
            self::SaldoInicial => 'Para arrancar el sistema con el inventario que ya existía.',
            self::Donacion => 'Entra como ingreso no operacional.',
            self::Sobrante => 'Mercancía que apareció en bodega sin documento.',
        };
    }

    /** Clave de la regla que guarda la cuenta de contrapartida. */
    public function reglaCuenta(): string
    {
        return 'contable.cta_carga_'.$this->value;
    }

    public function cuentaContrapartida(): string
    {
        $cuenta = setting($this->reglaCuenta());

        return ($cuenta !== null && $cuenta !== '')
            ? (string) $cuenta
            : (string) \App\Support\Reglas::DEFAULTS[$this->reglaCuenta()]['valor'];
    }

    /**
     * La compra y el aporte se le deben a alguien concreto: sin tercero el
     * mayor de proveedores o de socios no se puede conciliar.
     */
    public function requiereTercero(): bool
    {
        return in_array($this, [self::Compra, self::AporteSocios], true);
    }

    /** @return list<array{value:string,label:string,ayuda:string,cuenta:string,requiere_tercero:bool}> */
    public static function opciones(): array
    {
        return array_map(fn (self $m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'ayuda' => $m->ayuda(),
            'cuenta' => $m->cuentaContrapartida(),
            'requiere_tercero' => $m->requiereTercero(),
        ], self::cases());
    }
}
