<?php

namespace App\Console\Commands;

use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\NotaDebito;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pone en la línea Dropi lo del e-commerce que nació marcado Great Baby.
 *
 * Hasta el 6-nov FacturarPedidoDropi creaba la factura sin `linea_negocio` y la
 * columna tomaba su default (`great_baby`): la factura, su asiento y el cobro
 * desde la wallet quedaban en los libros de Great Baby. El código ya está
 * arreglado; esto corrige lo que se alcanzó a crear.
 *
 * Igual que `contabilidad:reestampar-ejes`: no inventa nada y no toca montos.
 * Sólo cambia de qué libro es cada movimiento, así que la partida doble sigue
 * cuadrando por construcción. Sin --aplicar sólo muestra lo que haría.
 */
class DropiReestamparLineaCommand extends Command
{
    protected $signature = 'dropi:reestampar-linea {--aplicar : Escribe los cambios. Sin esto sólo muestra qué haría.}';

    protected $description = 'Mueve a la línea Dropi las facturas del e-commerce (y sus asientos, cobros y notas) que quedaron en Great Baby';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        if (! $aplicar) {
            $this->warn('MODO REVISIÓN · no se escribe nada. Agregue --aplicar para corregir.');
        }

        $ecommerce = EmpresaConfig::porCodigo((string) setting('dropi.empresa', 'great_baby'));

        // Facturas del e-commerce: las que nacen de un pedido Dropi o las de su
        // razón social. Desde el 7-nov Dropi factura con Great Baby: «todo lo de
        // su razón social» sería todo Great Baby, así que entonces sólo cuenta
        // el origen.
        $facturas = FacturaVenta::query()
            ->where(fn ($q) => $q->where('origen_type', DropiPedido::class)
                ->when($ecommerce && ! $ecommerce->es_principal, fn ($w) => $w->orWhere('empresa_id', $ecommerce->id)))
            ->get();

        $documentos = [
            FacturaVenta::class => $facturas->pluck('id'),
            PagoVenta::class => PagoVenta::whereIn('factura_id', $facturas->pluck('id'))->pluck('id'),
            NotaCredito::class => NotaCredito::whereIn('factura_id', $facturas->pluck('id'))->pluck('id'),
            NotaDebito::class => NotaDebito::whereIn('factura_id', $facturas->pluck('id'))->pluck('id'),
        ];

        $facturasMal = $facturas->filter(fn ($f) => $f->linea_negocio !== LineaNegocio::Dropi);
        foreach ($facturasMal as $f) {
            $this->line(sprintf('  factura %-18s %s → dropi   %s', $f->numero, $f->linea_negocio?->value ?? '—', number_format((float) $f->total, 0, ',', '.')));
        }

        $movs = MovimientoContable::query()
            ->where(function ($q) use ($documentos) {
                foreach ($documentos as $tipo => $ids) {
                    $q->orWhere(fn ($w) => $w->where('origen_type', $tipo)->whereIn('origen_id', $ids));
                }
            })
            ->where('linea_negocio', '!=', LineaNegocio::Dropi->value)
            ->get();

        foreach ($movs->groupBy('origen_type') as $tipo => $grupo) {
            $this->line(sprintf('  %-12s %3d movimiento(s) → dropi   débitos %s',
                class_basename($tipo), $grupo->count(), number_format((float) $grupo->sum('debe'), 0, ',', '.')));
        }

        if ($aplicar && ($facturasMal->isNotEmpty() || $movs->isNotEmpty())) {
            DB::transaction(function () use ($facturasMal, $movs) {
                FacturaVenta::whereIn('id', $facturasMal->pluck('id'))->update(['linea_negocio' => LineaNegocio::Dropi->value]);
                MovimientoContable::whereIn('id', $movs->pluck('id'))->update(['linea_negocio' => LineaNegocio::Dropi->value]);
            });
        }

        $this->info(sprintf('%d factura(s) y %d movimiento(s) %s.',
            $facturasMal->count(), $movs->count(), $aplicar ? 'corregidos' : 'quedarían corregidos'));

        return self::SUCCESS;
    }
}
