<?php

namespace App\Modules\Compras\Services;

use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Compras\Models\RecepcionCompra;
use Illuminate\Support\Collection;

/**
 * Lo que se le debe a los proveedores, calculado con datos propios.
 *
 * El ERP mostraba cuentas por pagar leyéndolas de la API de SIIGO. Eso
 * significa que sin SIIGO conectado —hoy en producción, que está en sandbox—
 * la pantalla aparece vacía, y que la lista que de verdad usan para decidir a
 * quién pagar vive en una hoja impresa con resaltador.
 *
 * Acá la cuenta por pagar se arma con lo que el ERP ya tiene:
 *
 *     recepción confirmada con factura de proveedor
 *       − los pagos registrados contra esa recepción
 *       = saldo
 *
 * y el vencimiento sale del `plazo_dias` de la condición de crédito del
 * proveedor, contado desde la fecha de recepción. Si no tiene condición
 * pactada, se asume de contado: vence el mismo día. Es deliberado — una
 * deuda sin plazo acordado no debería verse como si tuviera tiempo.
 *
 * No es una tabla nueva: inventar `cuentas_por_pagar` obligaría a mantenerla
 * en sincronía con recepciones y pagos, y a los dos meses diría otra cosa que
 * ellos. Se calcula.
 */
class CuentasPorPagar
{
    /**
     * @return Collection<int, array{
     *     recepcion_id:int, numero:string, proveedor:string, proveedor_id:int|null,
     *     factura:string|null, fecha:string, vencimiento:string, dias_mora:int,
     *     valor:float, abonado:float, saldo:float
     * }>
     */
    public function pendientes(): Collection
    {
        $recepciones = RecepcionCompra::query()
            ->with(['orden:id,proveedor_id,condicion_credito_id', 'orden.proveedor:id,nombre_completo,numero_documento'])
            ->where('estado', 'confirmada')
            ->whereNotNull('factura_proveedor')
            ->get();

        if ($recepciones->isEmpty()) {
            return collect();
        }

        // Un solo golpe a pagos: hacerlo por recepción son N consultas y la
        // pantalla se arrastra apenas hay historia.
        $abonos = PagoProveedor::query()
            ->whereIn('recepcion_id', $recepciones->pluck('id'))
            ->whereNull('anulado_at')
            ->selectRaw('recepcion_id, SUM(monto_neto) as total')
            ->groupBy('recepcion_id')
            ->pluck('total', 'recepcion_id');

        $hoy = now('America/Bogota')->startOfDay();

        return $recepciones->map(function (RecepcionCompra $r) use ($abonos, $hoy) {
            $valor = (float) $r->total_recibido;
            $abonado = (float) ($abonos[$r->id] ?? 0);
            $saldo = round($valor - $abonado, 2);

            $vence = $this->vencimientoDe($r);

            return [
                'recepcion_id' => $r->id,
                'numero' => $r->numero,
                'proveedor' => $r->orden?->proveedor?->nombre_completo ?? 'Sin proveedor',
                'proveedor_id' => $r->orden?->proveedor_id,
                'factura' => $r->factura_proveedor,
                'fecha' => $r->fecha_recepcion?->toDateString(),
                'vencimiento' => $vence->toDateString(),
                // Negativo = todavía no vence. Es el dato con el que se
                // decide a quién pagar primero.
                'dias_mora' => (int) $vence->diffInDays($hoy, false),
                'valor' => $valor,
                'abonado' => $abonado,
                'saldo' => $saldo,
            ];
        })
            ->filter(fn ($f) => $f['saldo'] > 0.01)
            ->sortBy('vencimiento')
            ->values();
    }

    /**
     * Cuándo vence.
     *
     * Sin condición de crédito activa, de contado. Suponer un plazo que nadie
     * pactó haría ver con tiempo una deuda que ya está vencida.
     */
    protected function vencimientoDe(RecepcionCompra $r): \Illuminate\Support\Carbon
    {
        $base = $r->fecha_recepcion?->copy() ?? now('America/Bogota');

        $plazo = (int) ($r->orden?->condicionCredito?->plazo_dias ?? 0);

        return $base->addDays($plazo)->startOfDay();
    }

    /** El total y el desglose que encabeza la pantalla. */
    public function resumen(Collection $filas): array
    {
        return [
            'total' => round($filas->sum('saldo'), 2),
            'vencido' => round($filas->where('dias_mora', '>', 0)->sum('saldo'), 2),
            'por_vencer' => round($filas->where('dias_mora', '<=', 0)->sum('saldo'), 2),
            'facturas' => $filas->count(),
            'proveedores' => $filas->pluck('proveedor')->unique()->count(),
        ];
    }
}
