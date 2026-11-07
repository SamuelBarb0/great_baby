<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Actions\SiguienteConsecutivoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Modules\Siigo\Jobs\PushNotaCreditoASiigo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 4 · B.1 · CRUD Vue de notas crédito manuales.
 * Ruta: /app/cartera/notas-credito
 */
class NotasCreditoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $r): Response
    {
        // Una nota no tiene ejes propios: son los de su factura (`?linea=dropi` = las del e-commerce).
        $ejes = FiltroEjes::deRequest($r);
        $base = fn () => $ejes->aplicarPor(NotaCredito::query(), 'factura');

        $q = $base()->with(['factura:id,numero,total,contacto_id', 'factura.contacto:id,nombre_completo']);

        if ($busca = trim((string) $r->query('q', ''))) {
            $q->where(function ($qq) use ($busca) {
                $qq->where('numero', 'like', "%$busca%")
                   ->orWhereHas('factura', fn ($ff) => $ff->where('numero', 'like', "%$busca%"));
            });
        }
        if ($r->boolean('sin_siigo')) $q->whereNull('siigo_id');

        return Inertia::render('Cartera/NotasCredito', [
            'filtros' => ['q' => $r->query('q', ''), 'sin_siigo' => $r->boolean('sin_siigo')] + $ejes->aParametros(),
            'opcionesEjes' => FiltroEjes::opciones(),
            'notas' => $q->orderByDesc('id')->paginate(30)->through(fn ($n) => [
                'id' => $n->id,
                'numero' => $n->numeroCompleto(),
                'factura_numero' => $n->factura?->numero ?? '—',
                'cliente' => $n->factura?->contacto?->nombre_completo ?? '—',
                'valor' => (float) $n->valor,
                'motivo' => $n->motivo,
                'origen' => $n->devolucion_dropi_id ? 'Dropi (auto)' : 'Manual',
                'estado' => $n->estado,
                'siigo_id' => $n->siigo_id,
                'cufe' => $n->cufe,
                'emitida_at' => $n->emitida_at?->format('Y-m-d H:i'),
                'creada_at' => $n->created_at?->diffForHumans(),
            ]),
            'kpis' => [
                'total' => $base()->count(),
                'manuales' => $base()->whereNull('devolucion_dropi_id')->count(),
                'sin_siigo' => $base()->whereNull('siigo_id')->count(),
                'monto_mes' => (float) $base()->where('created_at', '>=', now()->startOfMonth())->sum('valor'),
            ],
        ]);
    }

    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'factura_id' => ['required', 'integer', 'exists:facturas_venta,id'],
            'valor' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $factura = FacturaVenta::findOrFail($data['factura_id']);

        // QA-CRIT-2 · comparar contra saldo pendiente MENOS NCs ya aplicadas.
        $totalNCPrevias = (float) NotaCredito::where('factura_id', $factura->id)->sum('valor');
        $disponible = (float) $factura->total - $totalNCPrevias;
        if ((float) $data['valor'] > $disponible) {
            return back()->with('flash', ['type' => 'error', 'message' =>
                'El valor NC ($' . number_format((float) $data['valor'], 2) . ') excede el disponible ($'
                . number_format($disponible, 2) . '). Total factura $' . number_format((float) $factura->total, 2)
                . ' - NCs previas $' . number_format($totalNCPrevias, 2) . '.']);
        }

        // Consecutivo tipo NC-#### usando setting empresa.prefijo_nc_dian.
        $prefijoBase = strtoupper(trim((setting('empresa.prefijo_nc_dian') ?: 'NC') . '-'));
        $rangoNc = (int) setting('empresa.rango_hasta_nc', 0) ?: null;
        $numeroCompleto = SiguienteConsecutivoFactura::run($prefijoBase, 4, $rangoNc);
        $numeroSecuencial = (int) preg_replace('/\D/', '', substr($numeroCompleto, strlen($prefijoBase)));

        $nc = NotaCredito::create([
            'prefijo' => rtrim($prefijoBase, '-'),
            'numero' => $numeroSecuencial,
            'factura_id' => $factura->id,
            'motivo' => $data['motivo'],
            'valor' => $data['valor'],
            'estado' => 'borrador',
        ]);
        // El Observer B.1 dispara PushNotaCreditoASiigo automáticamente vía afterCommit.
        return back()->with('flash', ['type' => 'success', 'message' => "NC {$nc->numeroCompleto()} creada · encolada a SIIGO."]);
    }

    public function reenviarSiigo(NotaCredito $notaCredito): RedirectResponse
    {
        PushNotaCreditoASiigo::dispatchManual($notaCredito->id);
        return back()->with('flash', ['type' => 'success', 'message' => "NC {$notaCredito->numeroCompleto()} encolada a SIIGO."]);
    }
}
