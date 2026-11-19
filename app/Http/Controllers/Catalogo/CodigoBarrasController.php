<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Support\Code128;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genera códigos de barras y QR de variantes:
 *  - /catalogo/variante/{v}/qr → SVG QR con el código de barras propio
 *  - /catalogo/variante/{v}/barcode → SVG Code128
 *  - /catalogo/variante/{v}/etiqueta → PDF etiqueta imprimible
 */
class CodigoBarrasController extends Controller
{
    public function qr(ProductoVariante $variante): Response
    {
        abort_unless(auth()->check(), 401);
        $opts = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 6,
        ]);
        $svg = (new QRCode($opts))->render($variante->codigo_barras);
        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function barcode(ProductoVariante $variante): Response
    {
        abort_unless(auth()->check(), 401);

        return response(Code128::svg($variante->codigo_barras), 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function etiqueta(ProductoVariante $variante): Response
    {
        abort_unless(auth()->check(), 401);
        $variante->load('producto');

        $qrOpts = new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_M, 'scale' => 8, 'imageBase64' => true]);
        $qrPng = (new QRCode($qrOpts))->render($variante->codigo_barras);

        return Pdf::loadView('catalogo.pdf.etiqueta', [
            'variante' => $variante,
            'qr' => $qrPng,
            'barras' => 'data:image/png;base64,' . base64_encode(Code128::png($variante->codigo_barras)),
        ])->setPaper([0, 0, 288, 145]) // 100x50mm aprox (4"x2"); con 'landscape' DomPDF la volteaba a 50 de ancho
          // Los códigos llevan «/» (AND2535-12/114-…) y un nombre de archivo no puede.
          ->stream('etiqueta-' . str_replace(['/', '\\'], '-', $variante->codigo_barras) . '.pdf');
    }
}
