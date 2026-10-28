<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiCorte;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Cierre formal de corte:
 *  - Congela el corte (estado=cerrado, cerrado_at, cerrado_por).
 *  - Genera snapshot JSON con totales del cierre.
 *  - Hash SHA-256 del snapshot → bloquea edición retroactiva (auditable).
 *  - Genera manifiesto PDF con QR de verificación.
 *  - Dispara GenerarRemisionesCorte (§21) para la exportación a ARI.
 */
class CerrarCorte
{
    use AsAction;

    public function __construct(protected GenerarRemisionesCorte $generarRemisiones) {}

    /**
     * @return array{corte_id:int, hash:string, pdf_url:string, remisiones:int}
     */
    public function handle(int $corteId, int $userId): array
    {
        return DB::transaction(function () use ($corteId, $userId) {
            $corte = DropiCorte::lockForUpdate()->findOrFail($corteId);

            if ($corte->estado === EstadoCorte::Cerrado) {
                throw new RuntimeException("El corte ya está cerrado (hash: {$corte->manifiesto_hash}).");
            }

            // Re-audit DR-δ (FUNC-M7) · lock los pedidos del corte para
            //   snapshot consistente + validar terminales manuales.
            $pedidos = $corte->pedidos()->with('items')->orderBy('guia')->lockForUpdate()->get();

            // Guard: no cerrar con pedidos en Pending/PendienteInventario/Alistando
            //   (fuente manual quedaría atrapada — corte cerrado sólo admite
            //   transiciones a Despachado/Entregado/Pagado/Devuelto/etc por
            //   fuente api|sistema; Alistando y Pending no están en la lista).
            $atrapados = $pedidos->filter(fn ($p) => in_array($p->estado, [
                EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario,
                EstadoPedidoDropi::Alistando,
            ], true));
            if ($atrapados->isNotEmpty()) {
                throw new RuntimeException(sprintf(
                    'No se puede cerrar: %d pedidos aún en Pending/PendienteInv/Alistando quedarían atrapados. Empácalos, cancélalos o muévelos a otro corte antes de cerrar. Guías: %s',
                    $atrapados->count(),
                    $atrapados->pluck('guia')->take(5)->implode(', ')
                ));
            }

            $snapshot = [
                'corte' => [
                    'id' => $corte->id,
                    'fecha' => $corte->fecha->toDateString(),
                    'numero' => $corte->numero,
                ],
                'cerrado_at' => now()->toIso8601String(),
                'cerrado_por' => $userId,
                'totales' => [
                    'pedidos_totales' => $pedidos->count(),
                    'pedidos_despachados' => $pedidos->where('estado', EstadoPedidoDropi::Despachado)->count(),
                    'pedidos_pagados' => $pedidos->where('estado', EstadoPedidoDropi::Pagado)->count(),
                    'pedidos_devueltos' => $pedidos->where('estado', EstadoPedidoDropi::Devuelto)->count(),
                    'pedidos_pendientes_inv' => $pedidos->where('estado', EstadoPedidoDropi::PendienteInventario)->count(),
                    'monto_esperado_total' => (float) $pedidos->sum('monto_esperado_proveedor'),
                ],
                'guias' => $pedidos->pluck('guia')->all(),
            ];

            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $json);

            // Generar PDF del manifiesto
            $pdfDir = storage_path('app/manifiestos');
            File::ensureDirectoryExists($pdfDir);
            $pdfPath = "manifiestos/corte-{$corte->id}-{$hash}.pdf";
            $absPath = storage_path('app/' . $pdfPath);

            // QR con el hash de verificación (opcional; se ignora si la librería no se instaló)
            $qrSvg = '';
            try {
                if (class_exists(Builder::class)) {
                    $qr = Builder::create()
                        ->writer(new SvgWriter())
                        ->data($hash)
                        ->size(110)
                        ->margin(0)
                        ->build();
                    $qrSvg = $qr->getString();
                }
            } catch (\Throwable) {
                // Sin QR, seguimos.
            }

            Pdf::loadView('dropi.pdf.manifiesto', [
                'corte' => $corte,
                'pedidos' => $pedidos,
                'snapshot' => $snapshot,
                'hash' => $hash,
                'qrSvg' => $qrSvg,
            ])
                ->setPaper('letter', 'portrait')
                ->save($absPath);

            // Re-audit DR-β · con $guarded, `estado/cerrado_por/cerrado_at/
            //   manifiesto_hash` no pasan por fill. Se asignan por propiedad.
            $corte->estado = EstadoCorte::Cerrado;
            $corte->cerrado_por = $userId;
            $corte->cerrado_at = now();
            $corte->manifiesto_hash = $hash;
            $corte->manifiesto_pdf_path = $pdfPath;
            $corte->snapshot_json = $snapshot;
            $corte->pedidos_totales = $snapshot['totales']['pedidos_totales'];
            $corte->pedidos_despachados = $snapshot['totales']['pedidos_despachados'];
            $corte->pedidos_pendientes_inv = $snapshot['totales']['pedidos_pendientes_inv'];
            $corte->save();

            // Generar remisiones 1:1 y prepararlas para ARI (§21)
            $r = $this->generarRemisiones->handle($corteId);

            // La factura ya no sale del corte: cada pedido se factura a
            // consumidor final al despacharse (FacturarAlDespachar, decisión
            // del 2026-10-20). Facturar aquí también por vendedor duplicaba la
            // venta ante la DIAN. GenerarFacturasB2BDeCorte queda para un
            // vendedor que pida factura a su nombre, a mano.
            $b2b = ['facturas_creadas' => 0, 'valor_total' => 0.0];

            return [
                'corte_id' => $corte->id,
                'hash' => $hash,
                'pdf_url' => route('dropi.manifiesto.descargar', ['corte' => $corte->id]),
                'remisiones' => $r['remisiones_creadas'],
                'facturas_b2b' => $b2b['facturas_creadas'],
                'valor_b2b' => $b2b['valor_total'],
            ];
        });
    }
}
