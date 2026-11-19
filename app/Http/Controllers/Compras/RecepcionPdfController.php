<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\RecepcionCompra;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class RecepcionPdfController extends Controller
{
    public function pdf(RecepcionCompra $recepcion): Response
    {
        abort_unless(auth()->check(), 401);
        abort_unless(\App\Auth\Permisos::puede(auth()->user(), 'compras_recepcion'), 403);

        $recepcion->load(['items.producto', 'items.variante', 'orden.proveedor', 'bodega', 'receptor']);

        return Pdf::loadView('compras.pdf.recepcion', ['recepcion' => $recepcion])
            ->setPaper('letter', 'portrait')
            ->stream("REC-{$recepcion->numero}.pdf");
    }
}
