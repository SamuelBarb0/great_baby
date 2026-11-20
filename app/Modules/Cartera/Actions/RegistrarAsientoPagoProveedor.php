<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Models\RetencionAplicada;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use Illuminate\Support\Facades\DB;

/**
 * El asiento de un pago a proveedor.
 *
 * Esto NO EXISTÍA. `RegistrarPagoProveedor` creaba el pago, calculaba y
 * guardaba las retenciones, y no escribía ni un movimiento contable. Tampoco
 * había observer, ni listener, ni job que lo hiciera.
 *
 * Dos consecuencias, las dos silenciosas:
 *
 *   1) La cuenta de proveedores (2205/2210) **sólo crecía**: la recepción la
 *      acreditaba y el pago nunca la debitaba. El pasivo con proveedores del
 *      balance iba subiendo para siempre, aunque se les pagara.
 *
 *   2) Las retenciones practicadas (2365 retefuente, 2367 reteiva, 2368
 *      reteica) **nunca entraban al libro**. El reporte de retenciones del hub
 *      apunta a esas cuentas y salía siempre vacío del lado de compras — y eso
 *      es la base de la declaración mensual ante la DIAN.
 *
 * El asiento:
 *
 *     DEBE  2205  proveedores        monto bruto (se cancela el pasivo)
 *     HABER 1105/1110  caja o banco  monto neto (lo que de verdad salió)
 *     HABER 2365/2367/2368           cada retención practicada
 *
 * Cuadra porque bruto = neto + retenciones: lo retenido no sale de la caja,
 * queda como deuda con la DIAN hasta que se declare.
 */
class RegistrarAsientoPagoProveedor
{
    public function handle(PagoProveedor $pago): int
    {
        if ($this->yaAsentado($pago)) {
            return 0;
        }

        $bruto = round((float) $pago->monto_bruto, 2);
        $neto = round((float) $pago->monto_neto, 2);

        if ($bruto <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($pago, $bruto, $neto) {
            $destino = $this->destinoDe($pago);
            $linea = $this->lineaDe($pago);
            $tercero = ['tercero_type' => 'App\\Models\\Contacto', 'tercero_id' => $pago->contacto_id];
            $texto = "Pago a proveedor #{$pago->id}".($pago->observaciones ? " · {$pago->observaciones}" : '');

            $lineas = [[
                // Se cancela el pasivo por el BRUTO: es lo que se le debía.
                'cuenta_puc' => $this->cuentaDelProveedor($pago),
                'debe' => $bruto, 'haber' => 0,
                'descripcion' => $texto,
            ], [
                // De la caja sale sólo el NETO.
                'cuenta_puc' => $pago->metodo === 'efectivo'
                    ? setting('contable.cta_caja_default')
                    : setting('contable.cta_bancos_default'),
                'debe' => 0, 'haber' => $neto,
                'descripcion' => $texto.' · salida de caja',
            ]];

            foreach ($this->retencionesDe($pago) as $cuenta => $valor) {
                $lineas[] = [
                    'cuenta_puc' => $cuenta,
                    'debe' => 0, 'haber' => round($valor, 2),
                    'descripcion' => $texto.' · retención practicada',
                ];
            }

            $completas = array_map(fn (array $l) => array_merge($l, $tercero, [
                'fecha' => $pago->fecha,
                'origen_type' => PagoProveedor::class, 'origen_id' => $pago->id,
                'linea_negocio' => $linea,
                'destino_fiscal' => $destino,
                'user_id' => auth()->id(),
            ]), $lineas);

            return MovimientoContable::registrarAsientoAtomico($completas);
        });
    }

    /**
     * Las retenciones practicadas, por cuenta PUC.
     *
     * Salen de `retenciones_aplicadas`, que es donde `CalculadorRetenciones`
     * las persiste con su cuenta. Si por alguna razón no quedaron detalladas,
     * se usa el total del pago contra la cuenta de retefuente — mejor una
     * línea agregada que perder la retención.
     *
     * Ese respaldo usaba `cuenta_puc_egreso` del pago, y estaba mal:
     * `cuenta_puc_egreso` es la cuenta de la que SALE la plata —el banco—, no
     * la de la retención. Así lo llena `RegistrarPagoProveedor`, así lo manda
     * el comprobante de egreso a SIIGO y así lo dice la propia pantalla, que
     * sugiere «1110 Bancos». Sólo acá se leía como si fuera otra cosa.
     *
     * El efecto era silencioso: el asiento CUADRABA igual —debe proveedores
     * por el bruto, haber banco por el neto, haber banco otra vez por la
     * retención— así que la validación de partida doble no se quejaba. Pero la
     * retención quedaba acreditada en el banco en vez de en 2365, y el reporte
     * de retenciones que sostiene la declaración mensual salía corto.
     *
     * @return array<string,float>
     */
    private function retencionesDe(PagoProveedor $pago): array
    {
        $porCuenta = RetencionAplicada::query()
            ->where('origen_type', PagoProveedor::class)
            ->where('origen_id', $pago->id)
            ->get()
            ->groupBy('cuenta_puc')
            ->map(fn ($g) => (float) $g->sum('valor'))
            ->filter(fn ($v) => $v > 0.009)
            ->all();

        if ($porCuenta !== []) {
            return $porCuenta;
        }

        $total = round((float) $pago->monto_retenciones, 2);

        return $total > 0.009
            ? [(string) setting('contable.cta_retefuente_default') => $total]
            : [];
    }

    /**
     * Contra qué cuenta se cancela la deuda.
     *
     * Una importación debe en 2210 (proveedores del exterior) y una compra
     * nacional en 2205. Se mira la recepción que originó el pago; si no la
     * trae, se asume nacional, que es el caso normal.
     */
    private function cuentaDelProveedor(PagoProveedor $pago): string
    {
        $recepcion = $pago->recepcion_id ? RecepcionCompra::find($pago->recepcion_id) : null;

        // La compra de Dropi se cancela en la misma cuenta de Dropi en que la
        // abonó su recepción; para Great Baby, la regla de siempre.
        return \App\Modules\Dropi\Support\CuentasDropi::proveedores(
            $recepcion ?? ($pago->orden_compra_id ? \App\Modules\Compras\Models\OrdenCompra::find($pago->orden_compra_id) : null),
            (bool) $recepcion?->orden?->esImportacion(),
        );
    }

    /** Los dos ejes salen de la compra que se está pagando. */
    private function destinoDe(PagoProveedor $pago): DestinoFiscal
    {
        $recepcion = $pago->recepcion_id ? RecepcionCompra::find($pago->recepcion_id) : null;
        $destino = $recepcion?->destino_fiscal ?? DestinoFiscal::porDefecto();

        return $destino === DestinoFiscal::Reparto ? DestinoFiscal::Interno : $destino;
    }

    private function lineaDe(PagoProveedor $pago)
    {
        $recepcion = $pago->recepcion_id ? RecepcionCompra::find($pago->recepcion_id) : null;

        return $recepcion?->linea_negocio;
    }

    private function yaAsentado(PagoProveedor $pago): bool
    {
        return MovimientoContable::where('origen_type', PagoProveedor::class)
            ->where('origen_id', $pago->id)
            ->exists();
    }
}
