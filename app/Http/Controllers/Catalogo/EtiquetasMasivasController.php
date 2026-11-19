<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Support\Code128;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EtiquetasMasivasController extends Controller
{
    public function pdf(Request $request): Response
    {
        abort_unless(auth()->check(), 401);

        $productoIds = (array) $request->input('productos', []);
        $variantes = ProductoVariante::query()
            ->whereIn('producto_id', $productoIds)
            ->with('producto')
            ->orderBy('producto_id')
            ->orderBy('codigo_barras')
            ->get();

        if ($variantes->isEmpty()) {
            abort(404, 'Sin variantes para imprimir.');
        }

        $qrOpts = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 8,
            'imageBase64' => true,
        ]);
        $qrCache = [];
        $barras = [];
        foreach ($variantes as $v) {
            $qrCache[$v->id] = (new QRCode($qrOpts))->render($v->codigo_barras);
            $barras[$v->id] = 'data:image/png;base64,' . base64_encode(Code128::png($v->codigo_barras));
        }

        return Pdf::loadView('catalogo.pdf.etiquetas-lote', [
            'variantes' => $variantes,
            'qrs' => $qrCache,
            'barras' => $barras,
        ])->setPaper('letter', 'portrait')
          ->stream('etiquetas-lote-' . now()->format('Ymd-His') . '.pdf');
    }
}
