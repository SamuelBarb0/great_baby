<?php

namespace App\Modules\Compras\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una factura dentro de una programación de pagos.
 *
 * `monto` puede ser menor que el saldo: en la hoja hay abonos parciales
 * —«ARSUTEX tiene un abono de 20.000.000»— y pagar a medias es lo normal
 * cuando la plata no alcanza para todos.
 */
class ProgramacionPagoItem extends Model
{
    protected $table = 'programacion_pago_items';

    protected $guarded = ['id'];

    protected $casts = ['monto' => 'decimal:2'];

    public function programacion(): BelongsTo
    {
        return $this->belongsTo(ProgramacionPago::class, 'programacion_id');
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionCompra::class, 'recepcion_id');
    }
}
