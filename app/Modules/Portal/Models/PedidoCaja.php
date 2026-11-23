<?php

namespace App\Modules\Portal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una caja de un pedido, con su guía.
 *
 * El área lo describió el 20-nov-2026: un pedido puede salir en varias cajas,
 * e Interrapidísimo da una guía POR CAJA; las demás transportadoras dan una
 * sola para todo el pedido.
 *
 * Antes sólo cabía una guía en `pedidos_cliente.guia_transportadora`, así que
 * de un pedido de tres cajas se guardaba una y las otras dos se perdían. Y la
 * guía es con lo que se reclama cuando una caja no llega.
 *
 * `guia` es nullable a propósito: con las transportadoras de guía única, las
 * cajas se registran igual —hay que saber cuántos bultos salieron— y la guía
 * la lleva sólo la primera.
 */
class PedidoCaja extends Model
{
    protected $table = 'pedido_cajas';

    protected $guarded = ['id'];

    protected $casts = [
        'numero' => 'integer',
        'viaje' => 'integer',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCliente::class, 'pedido_id');
    }

    /**
     * Lo que va escrito con marcador en la caja.
     *
     * Decía «2 de 3» contra el total del pedido, y con el despacho parcial eso
     * se volvió una promesa que no se puede cumplir: el total no se sabe cuando
     * se rotula. Si el pedido sale en dos viajes, la caja que se marcó «2 de 2»
     * el lunes resulta ser la 2 de 4 el miércoles, y el rótulo escrito a mano
     * en una caja no se puede corregir después.
     *
     * Por eso se rotula contra el VIAJE, que sí está cerrado en el momento de
     * escribirlo: las cajas de un viaje salen juntas en el mismo camión.
     */
    public function rotulo(int $cajasDeSuViaje): string
    {
        return $cajasDeSuViaje > 1
            ? "{$this->numero} · {$this->viaje}.º envío, caja ".$this->enSuViaje()." de {$cajasDeSuViaje}"
            : "caja {$this->numero}";
    }

    /** Qué número hace esta caja DENTRO de su viaje. */
    public function enSuViaje(): int
    {
        return (int) static::where('pedido_id', $this->pedido_id)
            ->where('viaje', $this->viaje)
            ->where('numero', '<=', $this->numero)
            ->count();
    }
}
