<?php

namespace App\Modules\Siigo\Exceptions;

use RuntimeException;

/**
 * El abono no toca la parte declarada de un reparto, así que no hay recibo que
 * mandar a SIIGO.
 *
 * En un reparto la factura de SIIGO vale sólo `monto_fiscal`. Los abonos cubren
 * primero esa porción; una vez cubierta, lo que siga cobrando es cartera
 * interna y en SIIGO ya no queda nada que abonar: la factura de allá está
 * pagada.
 *
 * No es un fallo ni algo que haya que reintentar: es que ese pago, para la
 * contabilidad oficial, no existe. Se registra como **omitido**, igual que un
 * pago contra una factura no electrónica.
 */
class PagoTotalmenteInterno extends RuntimeException
{
}
