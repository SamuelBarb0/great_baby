<?php

namespace App\Http\Controllers\Cartera;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Cartera\Actions\CalcularAntiguedadCartera;
use App\Modules\Cartera\Actions\ConsultarCredito;
use App\Modules\Cartera\Enums\EstadoFactura;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-audit SEG C1 · IDOR total corregido. Antes cualquier Vendedor iteraba
 * IDs 1..N y descargaba PDF con PII+saldos de toda la base. Ahora:
 *   - Aracely/Gerente/Contador: acceso pleno.
 *   - Vendedor: SOLO estados de cuenta de contactos donde tiene ≥1 factura
 *     con `vendedor_id = auth()->id()`.
 *   - Rate-limit del router (20/1) montado en la ruta.
 */
class EstadoCuentaController extends Controller
{
    public function pdf(Contacto $contacto): Response
    {
        $u = auth()->user();
        abort_unless($u && ($u->esAracely() || $u->hasAnyRole(['Gerente', 'Contador', 'Vendedor'])), 403,
            'Solo roles financieros pueden descargar estados de cuenta.');
        abort_unless($contacto->es_cliente_b2b || $contacto->es_cliente, 403);

        // Re-audit SEG C1 · si NO es Aracely/Gerente/Contador, verifica que el
        // Vendedor tenga al menos una factura suya con este contacto.
        // Re-audit M5 R2 SEG-B2 · esContable() unificado.
        $accesoAdmin = $u->esContable();
        if (! $accesoAdmin) {
            $tieneFactura = $contacto->facturas()->where('vendedor_id', $u->id)->exists();
            abort_unless($tieneFactura, 403, 'Solo puedes ver estados de cuenta de tus propios clientes.');
        }

        /*
         * Los ABONOS de cada factura, que es lo que faltaba.
         *
         * Hasta hoy este informe mostraba sólo el saldo vigente, y eso lo
         * convertía en inútil para la conversación que de verdad ocurre: el
         * cliente dice «yo le pagué» y hay que poder responder con la fecha y
         * el valor de cada abono, factura por factura. Lo pidió la contadora
         * en la reunión del 21-oct con esas palabras: «ese es el informe que
         * tenemos vacío con el sistema actual».
         */
        $facturas = $contacto->facturas()
            ->whereNotIn('estado', [EstadoFactura::Anulada])
            ->with(['pagos' => fn ($q) => $q->orderBy('fecha')])
            ->orderBy('fecha_emision')
            ->get();

        $totalAbonado = $facturas->sum(fn ($f) => (float) $f->pagos->sum('monto_aplicado'));
        $credito = ConsultarCredito::run($contacto->id);
        $antig = CalcularAntiguedadCartera::run($contacto->id);

        $pdf = Pdf::loadView('cartera.pdf.estado-cuenta', [
            'contacto' => $contacto,
            'facturas' => $facturas,
            'credito' => $credito,
            'antig' => $antig,
            'totalAbonado' => $totalAbonado,
        ])->setPaper('letter', 'portrait');

        return $pdf->stream('estado-cuenta-' . $contacto->numero_documento . '.pdf');
    }
}
