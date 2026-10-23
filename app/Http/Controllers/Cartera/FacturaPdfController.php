<?php

namespace App\Http\Controllers\Cartera;

use App\Http\Controllers\Controller;
use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Siigo\Services\QrDianService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class FacturaPdfController extends Controller
{
    public function pdf(FacturaVenta $factura): Response
    {
        // Re-audit M5 R4 SEG-A2 · REGRESIÓN de ronda 3 corregida.
        // Antes de R3: la matriz incluía Vendedor y SAC (con scope ownership),
        // y `esContable()` los excluía → un vendedor no podía reimprimir su
        // propia factura, un SAC no podía adjuntar el PDF a un ticket.
        // Restauramos la matriz correcta:
        //   - esContable (Aracely/Gerencia/Gerente/Contador): TODAS
        //   - Vendedor: solo las suyas (vendedor_id)
        //   - ServicioCliente: todas (atiende clientes)
        $u = auth()->user();
        abort_unless(
            $u && (
                $u->esContable()
                || ($u->hasRole('Vendedor') && $factura->vendedor_id === $u->id)
                || $u->hasRole('ServicioCliente')
            ),
            403,
        );
        return $this->render($factura);
    }

    /**
     * Descarga pública por token (no requiere auth).
     * URL de ejemplo: /factura/publica/{token}
     */
    public function publica(string $token, \Illuminate\Http\Request $request): Response
    {
        // Re-audit R3-07 · rate-limit por IP sobre tokens inválidos + log de seguridad.
        // Antes: un scanner podía probar tokens ilimitadamente sin fricción; los 404
        // del try/catch iban al log genérico sin canal 'security'. Ahora: bloqueo
        // a 60 rechazos/min por IP con abort(429) y Log::channel('security').
        $ip = $request->ip();
        $key = "factura.token_reject:{$ip}";
        if (RateLimiter::tooManyAttempts($key, 60)) {
            abort(429, 'Demasiadas solicitudes.');
        }

        // Re-audit SEG N1-B · el firstOrFail() lanzaba ModelNotFoundException con
        // stack trace / body distinto al abort(404) genérico usado abajo → mismo
        // oracle. Se uniforma respuesta con abort(404) tras log.
        try {
            $factura = FacturaVenta::where('token_publico', $token)->firstOrFail();
        } catch (ModelNotFoundException) {
            RateLimiter::hit($key, 60);
            \Illuminate\Support\Facades\Log::channel(config('logging.channels.security') ? 'security' : 'stack')
                ->info('factura.publica.token_inexistente', [
                    'ip' => $ip,
                    'token_prefijo' => substr($token, 0, 6) . '…',
                ]);
            abort(404, 'Documento no disponible.');
        }

        // Re-audit SEG A1 + SEG N1 · info-leak. Antes: 410 con mensaje distinto
        // según motivo (anulada / pagada / expirada) permitía oracle-diferenciar
        // token válido de inválido y descubrir estado. Ahora: 404 genérico
        // uniforme en TODAS las ramas. Log server-side conserva trazabilidad.
        $razonesInvalidez = [];
        if ($factura->estado === \App\Modules\Cartera\Enums\EstadoFactura::Anulada) {
            $razonesInvalidez[] = 'anulada';
        }
        if ($factura->estado === \App\Modules\Cartera\Enums\EstadoFactura::Pagada) {
            $razonesInvalidez[] = 'pagada';
        }
        $diasVida = (int) setting('cartera.token_publico_dias_vida', 90);
        $emitidaAt = $factura->emitida_at ?? $factura->created_at;
        if ($emitidaAt && $emitidaAt->diffInDays(now()) > $diasVida) {
            $razonesInvalidez[] = 'expirada';
        }
        if (! empty($razonesInvalidez)) {
            RateLimiter::hit($key, 60);
            \Illuminate\Support\Facades\Log::channel(config('logging.channels.security') ? 'security' : 'stack')
                ->info('factura.publica.token_rechazado', [
                    'ip' => $ip,
                    'factura_id' => $factura->id,
                    'razones' => $razonesInvalidez,
                ]);
            abort(404, 'Documento no disponible.');
        }

        return $this->render($factura, publica: true);
    }

    /**
     * Re-audit UX#1 · Render que el Portal invoca (cliente autenticado con guard `cliente`).
     * Idéntico a `publica()` pero sin depender de token — el ownership ya se
     * validó en PortalFacturasController::pdf.
     */
    public function publicaComoCliente(FacturaVenta $factura): Response
    {
        if ($factura->estado === \App\Modules\Cartera\Enums\EstadoFactura::Anulada) {
            abort(410, 'Esta factura fue anulada.');
        }
        return $this->render($factura, publica: true);
    }

    private function render(FacturaVenta $factura, bool $publica = false): Response
    {
        $factura->load(['contacto', 'items.variante.producto', 'pagos']);
        // Multiempresa · el encabezado es el de la razón social que facturó.
        $empresa = EmpresaConfig::porId($factura->empresa_id);

        $qrSvg = '';
        if ($factura->cufe) {
            $qrSvg = app(QrDianService::class)->generarSvg($factura, 130);
        }

        // Task #46: usar plantilla WYSIWYG si hay una configurada; sino fallback a la clásica.
        $plantilla = \App\Modules\Plantillas\Models\PlantillaDocumento::paraTipo('factura');
        $vista = $plantilla ? 'cartera.pdf.factura-plantilla' : 'cartera.pdf.factura';
        $cfg = $plantilla ? $plantilla->configEfectiva() : \App\Modules\Plantillas\Models\PlantillaDocumento::defaults();

        return Pdf::loadView($vista, [
            'factura' => $factura,
            'empresa' => $empresa,
            'qrSvg' => $qrSvg,
            'publica' => $publica,
            'cfg' => $cfg,
        ])->setPaper('letter', 'portrait')
          ->stream('factura-' . $factura->numero . '.pdf');
    }
}
