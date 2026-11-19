<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Support\IndicadoresRrhh;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F11 · Los indicadores que se le presentan a la Junta Directiva.
 *
 * «Cuando yo tenga reunión en junta directiva, yo realmente me voy a acercar
 * es a mostrarle a la junta directiva indicadores» (Elena, 19-oct).
 *
 * Los ve Gestión Humana y los ve la Junta. El líder de área no: son números de
 * toda la empresa, no de su equipo.
 */
class IndicadoresRrhhController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(
                Permisos::puede($r->user(), 'rrhh')
                || Permisos::puede($r->user(), 'rrhh_orden_contratacion'),
                403,
            );

            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        [$desde, $hasta] = $this->rango($r);

        return Inertia::render('Rrhh/Indicadores/Index', [
            'indicadores' => IndicadoresRrhh::entre($desde, $hasta)->todo(),
            'tipos_retiro' => collect(IndicadoresRrhh::TIPOS_RETIRO)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
        ]);
    }

    /** El mismo tablero, para llevarlo impreso a la junta. */
    public function pdf(Request $r)
    {
        [$desde, $hasta] = $this->rango($r);

        return Pdf::loadView('rrhh.pdf.indicadores', [
            'i' => IndicadoresRrhh::entre($desde, $hasta)->todo(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->download("indicadores-gestion-humana-{$desde}-a-{$hasta}.pdf");
    }

    /** El detalle de los retiros, que es lo que siempre piden desglosado. */
    public function retirosExcel(Request $r): StreamedResponse
    {
        [$desde, $hasta] = $this->rango($r);
        $datos = IndicadoresRrhh::entre($desde, $hasta)->motivosDeRetiro();

        return response()->streamDownload(function () use ($datos) {
            $out = fopen('php://output', 'w');
            // BOM y punto y coma: sin eso Excel en español rompe los acentos
            // y mete la fila entera en una celda.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'Cargo', 'Área', 'Motivo', 'Detalle', 'Fecha de retiro'], ';');
            foreach ($datos['detalle'] as $d) {
                fputcsv($out, [$d['nombre'], $d['cargo'], $d['area'], $d['tipo'], $d['motivo'], $d['fecha']], ';');
            }
            fclose($out);
        }, "retiros-{$desde}-a-{$hasta}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Registra el retiro de una persona.
     *
     * Sin esto, el indicador de rotación sólo podría contar a quien no superó
     * el período de prueba, y la pregunta de la Junta —«por qué se retira la
     * gente»— no tendría con qué responderse.
     */
    public function registrarRetiro(Request $r, int $empleado): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

        $e = Empleado::findOrFail($empleado);
        abort_unless($e->estado === 'activo', 422, 'Esta persona ya no está activa.');

        $data = $r->validate([
            'fecha_retiro' => ['required', 'date', 'after_or_equal:'.($e->fecha_ingreso?->toDateString() ?? '1900-01-01')],
            'tipo_retiro' => ['required', 'in:'.implode(',', array_keys(IndicadoresRrhh::TIPOS_RETIRO))],
            // El detalle en palabras: el tipo sirve para contar, esto para
            // entender. En la junta siempre preguntan por el caso concreto.
            'motivo_retiro' => ['required', 'string', 'min:5', 'max:500'],
        ], [], [
            'fecha_retiro' => 'fecha de retiro',
            'tipo_retiro' => 'motivo',
            'motivo_retiro' => 'detalle',
        ]);

        $e->update($data + [
            'estado' => 'retirado',
            'retirado_por_id' => $r->user()->id,
        ]);
        $e->anotar('retiro', IndicadoresRrhh::TIPOS_RETIRO[$data['tipo_retiro']].': '.$data['motivo_retiro']);

        return back()->with('success', "Se registró el retiro de {$e->nombre}.");
    }

    /**
     * El rango por defecto es el año corrido.
     *
     * La rotación de un mes no dice nada —con 20 personas, un retiro es un 5%
     * que parece una catástrofe—. Se mira sobre el año, que es como se compara
     * contra el sector.
     */
    private function rango(Request $r): array
    {
        $data = $r->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        return [
            $data['desde'] ?? now()->startOfYear()->toDateString(),
            $data['hasta'] ?? now()->toDateString(),
        ];
    }
}
