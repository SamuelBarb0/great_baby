<?php

namespace App\Modules\Cartera\Actions;

use App\Models\Contacto;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * §21 Diseño Dropi — Facturación híbrida B2B/consumidor final.
 * Al cerrar el corte, los pedidos con requiere_factura_b2b=true se agrupan por vendedor
 * y se emite UNA factura B2B por vendedor con los pedidos del corte.
 *
 * Re-audit fixes:
 *   FUNC C1 / DATOS C1 · asiento contable se dispara EXPLÍCITAMENTE tras crear
 *     items (antes el `saved` observer chequeaba items()->count() antes de que
 *     existieran → factura sin asiento → cierre mensual mentiroso).
 *   FUNC A2 · idempotencia por (corte_id, contacto_id) en lugar de por string
 *     `numero`. Re-cerrar el corte con contactos recreados NO duplica facturas.
 *   DATOS A9 · updateOrCreate NO pisa `activo` ni flags si el contacto ya existe;
 *     usa firstOrCreate con defaults conservadores.
 */
class GenerarFacturasB2BDeCorte
{
    use AsAction;

    public function handle(int $corteId): array
    {
        return DB::transaction(function () use ($corteId) {
            $corte = DropiCorte::findOrFail($corteId);

            $pedidos = DropiPedido::query()
                ->where('corte_id', $corteId)
                ->where('requiere_factura_b2b', true)
                ->whereIn('estado', ['despachado', 'entregado', 'pagado'])
                ->with('items')
                ->get()
                ->groupBy('vendedor_identificacion');

            $facturasCreadas = 0;
            $valorTotal = 0.0;

            foreach ($pedidos as $vendedorDoc => $pedidosVendedor) {
                if (! $vendedorDoc) continue;
                $primero = $pedidosVendedor->first();

                // A9 · firstOrCreate NO pisa flags manuales si el contacto ya existe.
                $contacto = Contacto::firstOrCreate(
                    ['numero_documento' => $vendedorDoc],
                    [
                        'tipo_documento' => 'CC',
                        'nombre_completo' => $primero->vendedor_nombre ?? 'Vendedor Dropi ' . $vendedorDoc,
                        'es_vendedor_dropi' => true,
                        'es_cliente_b2b' => true,
                        'es_cliente' => true,
                        'activo' => true,
                    ]
                );

                $total = (float) $pedidosVendedor->sum('monto_esperado_proveedor');
                if ($total <= 0) continue;

                // FUNC A2 · idempotencia por (corte_id, contacto_id) — sobrevive
                // a re-cierre del corte y a re-creación del contacto.
                $yaExiste = FacturaVenta::query()
                    ->where('origen_type', DropiCorte::class)
                    ->where('origen_id', $corte->id)
                    ->where('contacto_id', $contacto->id)
                    ->exists();
                if ($yaExiste) continue;

                $numero = 'FV-DP-' . $corte->fecha->format('ymd') . '-' . $corte->numero . '-' . str_pad((string) $contacto->id, 4, '0', STR_PAD_LEFT);
                $fechaEmision = $corte->cerrado_at?->toDateString() ?? now()->toDateString();
                $fechaVenc = (clone ($corte->cerrado_at ?? now()))->addDays(30)->toDateString();

                $factura = FacturaVenta::create([
                    'numero' => $numero,
                    'contacto_id' => $contacto->id,
                    'fecha_emision' => $fechaEmision,
                    'fecha_vencimiento' => $fechaVenc,
                    'estado' => 'pendiente',
                    'subtotal' => $total,
                    'descuento' => 0,
                    'impuestos' => 0,
                    'total' => $total,
                    'saldo' => $total,
                    'origen_type' => DropiCorte::class,
                    'origen_id' => $corte->id,
                    'observaciones' => "Facturación B2B del corte " . $corte->etiqueta() . " · " . $pedidosVendedor->count() . " pedidos",
                ]);

                foreach ($pedidosVendedor as $p) {
                    FacturaVentaItem::create([
                        'factura_id' => $factura->id,
                        'descripcion' => "Guía {$p->guia} · " . ($p->cliente_nombre ?? '—'),
                        'cantidad' => 1,
                        'precio_unit' => (float) $p->monto_esperado_proveedor,
                        'subtotal' => (float) $p->monto_esperado_proveedor,
                    ]);
                }

                app(AplicarRetencionesVenta::class)->ejecutar($factura);

                // Re-audit C1 · asiento contable EXPLÍCITO después de items.
                $factura->ensureAsiento();

                $facturasCreadas++;
                $valorTotal += $total;
            }

            return [
                'facturas_creadas' => $facturasCreadas,
                'valor_total' => $valorTotal,
                'vendedores' => $facturasCreadas,
            ];
        });
    }
}
