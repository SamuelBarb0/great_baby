<?php

namespace App\Modules\Contabilidad\Enums;

/**
 * Segundo eje de la contabilidad: A DÓNDE va el movimiento.
 *
 * Hasta hoy el sistema guardaba el RESULTADO (`siigo_id`, `siigo_journal_id`:
 * «ya se envió y con qué id») pero nunca la DECISIÓN («¿debe enviarse?»). Por
 * eso «pendiente de SIIGO» estaba definido en siete lugares como
 * `whereNull('siigo_id')`, y todo lo que alguien decidiera NO mandar aparecía
 * como pendiente para siempre.
 *
 * Con este eje:
 *   · Siigo    → va a la DIAN. Pendiente = destino Siigo + todavía sin enviar.
 *   · Interno  → no se declara nada.
 *   · Reparto  → una parte se declara y el resto no.
 *
 * Es un eje APARTE de la línea de negocio: tanto Great Baby como Dropi tienen
 * cosas de los tres destinos. Ver `LineaNegocio`.
 */
enum DestinoFiscal: string
{
    case Siigo = 'siigo';
    case Reparto = 'reparto';
    case Interno = 'interno';

    /**
     * «Bolsita» era el nombre de la reunión y no puede quedar en pantalla.
     * Los tres nombres de verdad, que además son tres casos distintos:
     *
     *   Fiscal   el 100 % se declara
     *   Reparto  una parte se declara y el resto no (la factura de $100 M de
     *            la que sólo se facturan $20 M)
     *   Interno  no se declara nada
     */
    public function label(): string
    {
        return match ($this) {
            self::Siigo => 'Fiscal',
            self::Reparto => 'Reparto',
            self::Interno => 'Interno',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Siigo => 'Se declara completo · va a SIIGO y a la DIAN',
            self::Reparto => 'Una parte se declara y el resto queda sólo en el ERP',
            self::Interno => 'No se declara · queda sólo en el ERP',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Siigo => 'success',
            self::Reparto => 'warning',
            self::Interno => 'info',
        };
    }

    /** ¿Este movimiento tiene que terminar en SIIGO, completo? */
    public function vaASiigo(): bool
    {
        return $this === self::Siigo;
    }

    /** ¿Hay algo que declarar? El reparto también manda su porción. */
    public function tieneParteFiscal(): bool
    {
        return $this === self::Siigo || $this === self::Reparto;
    }

    /**
     * El destino por defecto de un asiento nuevo.
     *
     * Es `Siigo` a propósito: hasta hoy TODO lo que se asentaba se consideraba
     * pendiente de enviar, así que este default deja el comportamiento
     * exactamente como estaba. Marcar algo como Interno o Reparto pasa a ser
     * una decisión explícita de alguien, no un efecto secundario de la
     * migración.
     */
    public static function porDefecto(): self
    {
        return self::Siigo;
    }

    /** Para pintar el filtro sin repetir el listado en cada pantalla. */
    /**
     * Los destinos que tiene sentido ofrecerle a una COMPRA.
     *
     * Sin «reparto»: declarar media compra significaria descontar IVA de una
     * factura que no existe por el resto, y eso no es una decision comercial
     * sino un problema. En compras es fiscal o interna.
     */
    public static function opcionesDeCompra(): array
    {
        return array_values(array_filter(
            self::opciones(),
            fn (array $o) => $o['valor'] !== self::Reparto->value,
        ));
    }

    public static function opciones(): array
    {
        return array_map(
            fn (self $c) => [
                'valor' => $c->value,
                'label' => $c->label(),
                'descripcion' => $c->descripcion(),
            ],
            self::cases(),
        );
    }
}
