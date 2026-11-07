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
     * @return array{
     *   por_tramo: array<string, array{count:int, monto:float}>,
     *   total: float,
     *   count: int
     * }
     */
    /** @param  \App\Modules\Contabilidad\Support\FiltroEjes|null  $ejes  línea de negocio / destino (p. ej. sólo Dropi) */
    public function handle(?int $contactoId = null, ?\App\Modules\Contabilidad\Support\FiltroEjes $ejes = null): array
    {
        $facturas = FacturaVenta::query()
            ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->when($contactoId, fn ($q) => $q->where('contacto_id', $contactoId))
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
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
}
