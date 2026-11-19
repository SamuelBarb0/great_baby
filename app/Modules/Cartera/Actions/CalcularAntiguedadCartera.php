<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Enums\TramoAntiguedad;
use App\Modules\Cartera\Models\FacturaVenta;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * §7 TO-BE Cartera — Semáforo consolidado.
 * Devuelve totales por tramo y por contacto, listo para widget y reporte edad de saldos.
 */
class CalcularAntiguedadCartera
{
    use AsAction;

    /**
     * La antigüedad de la cartera, por tramo.
     *
     * Admite filtrar por cliente y por VENDEDOR. Lo segundo faltaba, y es lo
     * que el contrato pide —«semáforo de cartera por tramos y por vendedor»—:
     * sin eso no hay forma de que cada vendedor vea lo suyo ni de saber quién
     * está vendiendo bien pero cobrando mal.
     *
     * @return array{
     *   por_tramo: array<string, array{count:int, monto:float}>,
     *   total: float,
     *   count: int
     * }
     */
    /**
     * Los dos filtros conviven: miran cosas distintas.
     *
     * `$ejes` recorta por línea de negocio y destino fiscal —«sólo lo de
     * Dropi»— y `$vendedorId` por quién vende. El contrato pide el semáforo
     * por tramos Y POR VENDEDOR, que es lo que responde quién vende bien pero
     * cobra mal; los ejes responden otra pregunta, la contable.
     *
     * @param  \App\Modules\Contabilidad\Support\FiltroEjes|null  $ejes  línea de negocio / destino
     */
    public function handle(
        ?int $contactoId = null,
        ?\App\Modules\Contabilidad\Support\FiltroEjes $ejes = null,
        ?int $vendedorId = null,
    ): array
    {
        $facturas = FacturaVenta::query()
            ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->when($contactoId, fn ($q) => $q->where('contacto_id', $contactoId))
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->when($vendedorId, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->get();

        $porTramo = [];
        foreach (TramoAntiguedad::cases() as $t) {
            $porTramo[$t->value] = ['count' => 0, 'monto' => 0.0];
        }

        $total = 0.0;
        foreach ($facturas as $f) {
            $t = $f->tramo();
            $porTramo[$t->value]['count']++;
            $porTramo[$t->value]['monto'] += (float) $f->saldo;
            $total += (float) $f->saldo;
        }

        return [
            'por_tramo' => $porTramo,
            'total' => $total,
            'count' => $facturas->count(),
        ];
    }

    /**
     * La misma antigüedad, abierta por vendedor.
     *
     * Para que Gerencia vea de un vistazo quién vende bien y cobra mal, que es
     * la pregunta que no se podía responder. Los sin vendedor asignado salen
     * aparte en vez de repartirse: esconderlos haría cuadrar el total mintiendo.
     *
     * Y recorta por los mismos ejes que `handle()`.
     *
     * Este método se escribió antes de que existiera `FiltroEjes`, y al unir
     * con main quedó siendo el único bloque del tablero que no lo miraba: con
     * «Línea de negocio: Dropi» elegido, los KPIs, el semáforo y top morosos
     * daban $0 y esta tabla seguía mostrando la cartera entera. Dos números
     * distintos para la misma pregunta en la misma pantalla.
     *
     * @param  \App\Modules\Contabilidad\Support\FiltroEjes|null  $ejes  línea de negocio / destino
     * @return array<int,array{vendedor_id:?int,vendedor:string,por_tramo:array<string,array{count:int,monto:float}>,total:float,vencido:float}>
     */
    public function porVendedor(?\App\Modules\Contabilidad\Support\FiltroEjes $ejes = null): array
    {
        $facturas = FacturaVenta::query()
            ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->with('vendedor:id,name')
            ->get();

        $vacio = [];
        foreach (TramoAntiguedad::cases() as $t) {
            $vacio[$t->value] = ['count' => 0, 'monto' => 0.0];
        }

        $filas = [];
        foreach ($facturas as $f) {
            $clave = $f->vendedor_id ?: 0;
            $filas[$clave] ??= [
                'vendedor_id' => $f->vendedor_id,
                'vendedor' => $f->vendedor?->name ?? 'Sin vendedor asignado',
                'por_tramo' => $vacio,
                'total' => 0.0,
                'vencido' => 0.0,
            ];

            $t = $f->tramo();
            $saldo = (float) $f->saldo;
            $filas[$clave]['por_tramo'][$t->value]['count']++;
            $filas[$clave]['por_tramo'][$t->value]['monto'] += $saldo;
            $filas[$clave]['total'] += $saldo;
            if ($t !== TramoAntiguedad::AlDia) {
                $filas[$clave]['vencido'] += $saldo;
            }
        }

        // Lo más vencido arriba: es por donde hay que empezar a llamar.
        usort($filas, fn ($a, $b) => $b['vencido'] <=> $a['vencido']);

        return array_values($filas);
    }
}
