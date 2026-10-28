<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Siigo\Models\SiigoSyncLog;
use App\Modules\Siigo\Services\SiigoEmisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Acciones SIIGO sobre facturas ya emitidas · 5 operaciones oficiales.
 *   • POST /v1/invoices/{id}/annul           → anular factura
 *   • POST /v1/invoices/{id}/mail            → reenviar mail al cliente
 *   • GET  /v1/invoices/{id}/pdf             → descargar PDF oficial SIIGO (con QR DIAN)
 *   • GET  /v1/invoices/{id}/xml             → descargar XML DIAN
 *   • GET  /v1/invoices/{id}/stamp/errors    → consultar errores DIAN
 *
 * Gate: esContable (Aracely/Gerencia/Gerente/Contador). Anular adicional
 * exige esRoot porque borra una factura ya emitida con CUFE DIAN.
 */
class FacturasSiigoAccionesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless($r->user()?->esContable(), 403,
                'Solo el equipo contable puede operar facturas en SIIGO.');
            return $next($r);
        })];
    }

    public function anular(Request $r, FacturaVenta $factura, SiigoEmisionService $svc): RedirectResponse
    {
        // Guard adicional: solo esRoot anula.
        abort_unless($r->user()?->esRoot(), 403,
            'Solo Aracely/Gerencia puede anular facturas DIAN.');

        try {
            $svc->anularFacturaEnSiigo($factura);
            $this->log('ok', "Factura {$factura->numero} anulada en SIIGO.", $factura);
            return back()->with('flash', ['type' => 'success',
                'message' => "Factura {$factura->numero} anulada en SIIGO."]);
        } catch (\Throwable $e) {
            $this->log('fallido', $e->getMessage(), $factura);
            return back()->with('flash', ['type' => 'error',
                'message' => "No se pudo anular: " . $e->getMessage()]);
        }
    }

    /**
     * Reencola la factura para que viaje a SIIGO.
     *
     * Existía para las otras siete cosas que viajan —órdenes de compra,
     * recepciones, devoluciones, pagos a proveedor, notas crédito, notas
     * débito y asientos manuales— y NO para la factura de venta, que es la
     * única con plazo legal. Si el envío automático fallaba, el único camino
     * era la consola.
     *
     * No reemite a lo bruto: `ReintentarEmisionDian` ya trae lock, backoff de
     * 1-5-30 minutos y 3 horas, y se devuelve solo si la factura ya está en
     * SIIGO y aceptada por la DIAN. Acá sólo se vuelve a poner en la cola.
     */
    public function reenviarSiigo(FacturaVenta $factura): RedirectResponse
    {
        if ($factura->estado === 'anulada') {
            return back()->with('flash', ['type' => 'error',
                'message' => "La factura {$factura->numero} está anulada: no se reenvía."]);
        }

        if ($factura->siigo_id && strtolower((string) $factura->stamp_status) === 'accepted') {
            return back()->with('flash', ['type' => 'info',
                'message' => "La factura {$factura->numero} ya está en SIIGO y aceptada por la DIAN."]);
        }

        \App\Jobs\ReintentarEmisionDian::dispatch($factura->id);
        $this->log('ok', "Factura {$factura->numero} reencolada a SIIGO a mano", $factura);

        return back()->with('flash', ['type' => 'success',
            'message' => "Factura {$factura->numero} encolada. Si la cola está corriendo, viaja en segundos."]);
    }

    public function reenviarMail(Request $r, FacturaVenta $factura, SiigoEmisionService $svc): RedirectResponse
    {
        $email = $r->input('email');
        if ($email) {
            $r->validate(['email' => 'required|email']);
        }
        try {
            $svc->reenviarMailFacturaSiigo($factura, $email);
            $this->log('ok', "Mail de {$factura->numero} reenviado a " . ($email ?: $factura->contacto?->email), $factura);
            return back()->with('flash', ['type' => 'success',
                'message' => "Mail reenviado a " . ($email ?: $factura->contacto?->email)]);
        } catch (\Throwable $e) {
            $this->log('fallido', $e->getMessage(), $factura);
            return back()->with('flash', ['type' => 'error',
                'message' => "No se pudo reenviar: " . $e->getMessage()]);
        }
    }

    public function pdf(FacturaVenta $factura, SiigoEmisionService $svc): HttpResponse
    {
        try {
            $bytes = $svc->pdfFacturaSiigo($factura);
            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$factura->numero}.pdf\"",
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $e) {
            abort(502, "SIIGO rechazó PDF: " . $e->getMessage());
        }
    }

    public function xml(FacturaVenta $factura, SiigoEmisionService $svc): HttpResponse
    {
        try {
            $bytes = $svc->xmlFacturaSiigo($factura);
            return response($bytes, 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=\"{$factura->numero}.xml\"",
            ]);
        } catch (\Throwable $e) {
            abort(502, "SIIGO rechazó XML: " . $e->getMessage());
        }
    }

    public function erroresDian(FacturaVenta $factura, SiigoEmisionService $svc): \Illuminate\Http\JsonResponse
    {
        try {
            $errores = $svc->erroresDianFacturaSiigo($factura);
            return response()->json(['ok' => true, 'errores' => $errores]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 502);
        }
    }

    private function log(string $estado, string $mensaje, FacturaVenta $f): void
    {
        try {
            SiigoSyncLog::create([
                'recurso' => 'facturas_venta',
                'estado' => $estado,
                'mensaje' => mb_substr($mensaje, 0, 500),
                'detalle' => ['factura_id' => $f->id, 'numero' => $f->numero, 'siigo_id' => $f->siigo_id],
            ]);
        } catch (\Throwable $e) { /* no bloqueamos la UI */ }
    }
}
