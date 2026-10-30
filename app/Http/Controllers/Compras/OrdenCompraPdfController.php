<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\PreliquidarOrden;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class OrdenCompraPdfController extends Controller
{
    public function pdf(OrdenCompra $orden): Response
    {
        abort_unless(auth()->check(), 401);

        $orden->load(['items.producto', 'items.variante', 'proveedor', 'bodega', 'creador']);

        return Pdf::loadView('compras.pdf.orden-compra', ['orden' => $orden])
            ->setPaper('letter', 'portrait')
            ->stream("OC-{$orden->numero}.pdf");
    }

    /** La pre-liquidación de la OC de importación, para mandar o archivar. */
    public function preliquidacion(OrdenCompra $orden, PreliquidarOrden $preliquidar): Response
    {
        abort_unless(auth()->check(), 401);
        abort_unless($orden->esImportacion(), 404);

        $orden->load(['items', 'proveedor']);

        return Pdf::loadView('compras.pdf.preliquidacion', [
            'orden' => $orden,
            'pre' => $preliquidar->handle($orden),
        ])
            ->setPaper('letter', 'landscape')
            ->stream("Preliquidacion-{$orden->numero}.pdf");
    }
}
