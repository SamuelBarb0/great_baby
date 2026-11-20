<?php

namespace App\Http\Controllers\Dropi;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Filament\Pages\VistaAlistador;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * La recolección del corte en papel (reunión del 19-nov-2026: «PDF del
 * alistador sobre la recolección»). Misma lista y mismo orden de recorrido que
 * la tablet, con una casilla por referencia para marcar a mano, y lo que ya
 * se marcó en el sistema impreso como recogido.
 *
 * Sin datos del comprador: sólo referencias y unidades, así que lo descarga
 * quien entra a la Vista del Alistador, no sólo el equipo administrativo.
 */
class RecoleccionPdfController extends Controller
{
    public function descargar(DropiCorte $corte): Response
    {
        abort_unless(VistaAlistador::canAccess(), 403);

        $filas = app(RecoleccionDelCorte::class)->conMarcas($corte->id);

        return Pdf::loadView('dropi.pdf.recoleccion', [
            'corte' => $corte,
            'filas' => $filas,
            'unidades' => array_sum(array_column($filas, 'total_unidades')),
            'generado' => now(),
        ])
            ->setPaper('letter')
            ->stream('recoleccion-corte-' . $corte->id . '.pdf');
    }
}
