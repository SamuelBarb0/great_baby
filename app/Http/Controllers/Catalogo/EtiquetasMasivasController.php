<?php

namespace App\Http\Controllers\Catalogo;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\CatalogoDropi;
use App\Support\Code128;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EtiquetasMasivasController extends Controller
{
    /**
     * Rollo de la térmica SAT TT460 para las etiquetas de Dropi, en mm: 3
     * etiquetas de 32,5 × 25 por fila, a 3 mm entre columnas. Son las medidas
     * de la plantilla de BarTender con la que ya imprime la bodega
     * (GREATBABY.btw, 20-nov-2026). El ancho del papel (104) es el de las tres
     * columnas y sus separaciones; si la impresión se corre, se ajusta aquí.
     */
    private const ROLLO_DROPI = ['ancho' => 104, 'alto' => 25, 'columnas' => 3, 'etiqueta' => 32.5, 'separacion' => 3];

    /** Alto máximo (mm) del código de barras: lo que dejan libre los 25 mm tras dos renglones de nombre. */
    private const BARRAS_ALTO_MAX = 14;

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

        return $this->lote($variantes->map(fn (ProductoVariante $v) => self::deVariante($v))->all(), 'etiquetas-lote');
    }

    /**
     * Etiquetas de un producto de «Mis productos en Dropi». Cada variación lleva
     * el código del ERP con el que está vinculada —el de la variante o la
     * referencia del producto agregado—, porque eso es lo que reconoce la pistola
     * de empaque (ProcesarEscaneoEmpaque); el SKU de Dropi no lo reconocería.
     * Las variaciones sin vínculo no tienen código que imprimir y se omiten.
     */
    public function dropi(int $dropiId, CatalogoDropi $catalogo): Response
    {
        abort_unless(Permisos::puede(auth()->user(), 'dropi_mis_productos'), 403);

        $producto = collect($catalogo->misProductos()['productos'])->firstWhere('id', $dropiId);
        abort_unless($producto, 404, 'Ese producto no está en la última lectura de Dropi.');

        $variacionPorLlave = collect($producto['variaciones'])->keyBy('llave');
        $vinculos = DropiVinculoProducto::query()
            ->whereIn('sku_dropi', $variacionPorLlave->keys())
            ->with(['variante.producto', 'producto'])
            ->get();

        $etiquetas = [];
        foreach ($vinculos as $vinculo) {
            $variacion = $variacionPorLlave->get($vinculo->sku_dropi)['variacion'] ?? null;
            $etiqueta = match (true) {
                $vinculo->variante !== null => self::deVariante($vinculo->variante),
                $vinculo->producto !== null => self::deAgregado($vinculo->producto, $variacion),
                default => null,
            };
            // Dos variaciones de Dropi pueden apuntar al mismo código del ERP: una etiqueta basta.
            if ($etiqueta && ! isset($etiquetas[$etiqueta['codigo']])) {
                $etiquetas[$etiqueta['codigo']] = $etiqueta;
            }
        }

        abort_if($etiquetas === [], 404, 'Este producto no está vinculado al ERP: vincúlalo para poder imprimir sus etiquetas.');

        return $this->lote(array_values($etiquetas), 'etiquetas-dropi-' . $dropiId, soloBarras: true);
    }

    /** @return array{nombre:?string, detalle:string, precio:float, codigo:string} */
    private static function deVariante(ProductoVariante $v): array
    {
        return [
            'nombre' => $v->producto?->nombre,
            'detalle' => trim(implode(' · ', array_filter([
                $v->color_nombre ? '🎨 ' . $v->color_nombre : null,
                $v->diseno_nombre,
                $v->talla ? 'T' . $v->talla : null,
            ]))),
            'precio' => (float) ($v->producto?->precio_proveedor ?? 0),
            'codigo' => $v->codigo_barras,
        ];
    }

    /** Un producto agregado no tiene variantes: su código es la referencia. */
    private static function deAgregado(Producto $p, ?string $variacion): array
    {
        return [
            'nombre' => $p->nombre,
            'detalle' => (string) $variacion,
            'precio' => (float) ($p->precio_proveedor ?? 0),
            'codigo' => $p->referencia,
        ];
    }

    /**
     * @param  list<array{nombre:?string, detalle:string, precio:float, codigo:string}>  $etiquetas
     * @param  bool  $soloBarras  las de «Mis productos en Dropi» llevan el nombre y el
     *                            código de barras, como la plantilla de BarTender de la
     *                            bodega (20-nov-2026); las del catálogo, completas.
     */
    private function lote(array $etiquetas, string $archivo, bool $soloBarras = false): Response
    {
        $qrOpts = $soloBarras ? null : new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 8,
            'imageBase64' => true,
        ]);
        foreach ($etiquetas as &$e) {
            if ($qrOpts) {
                $e['qr'] = (new QRCode($qrOpts))->render($e['codigo']);
            }
            $png = Code128::png($e['codigo']);
            $e['barras'] = 'data:image/png;base64,' . base64_encode($png);
            if ($soloBarras) {
                [$e['barras_ancho'], $e['barras_alto']] = self::encajarBarras($png);
            }
        }
        unset($e);

        if ($soloBarras) {
            // Cada página es una fila completa del rollo de la SAT TT460 (mm → pt).
            $mm = 72 / 25.4;

            return Pdf::loadView('catalogo.pdf.etiquetas-dropi', ['etiquetas' => $etiquetas, 'rollo' => self::ROLLO_DROPI])
                ->setPaper([0, 0, self::ROLLO_DROPI['ancho'] * $mm, self::ROLLO_DROPI['alto'] * $mm], 'portrait')
                ->stream($archivo . '-' . now()->format('Ymd-His') . '.pdf');
        }

        return Pdf::loadView('catalogo.pdf.etiquetas-lote', ['etiquetas' => $etiquetas])
            ->setPaper('letter', 'portrait')
            ->stream($archivo . '-' . now()->format('Ymd-His') . '.pdf');
    }

    /**
     * Medidas (mm) del código de barras dentro de la etiqueta de Dropi, sin
     * deformarlo: tan ancho como la etiqueta, salvo que así quede más alto que
     * el espacio que deja el nombre. Un código corto («899») estirado a lo ancho
     * salía de 13 mm de alto y, con el nombre encima, la fila no cabía en los
     * 25 mm y DomPDF la partía en páginas en blanco.
     *
     * @return array{0: float, 1: float}
     */
    private static function encajarBarras(string $png): array
    {
        [$px, $py] = getimagesizefromstring($png);
        $ancho = self::ROLLO_DROPI['etiqueta'] - 2;
        $alto = $ancho * $py / $px;
        if ($alto > self::BARRAS_ALTO_MAX) {
            $ancho *= self::BARRAS_ALTO_MAX / $alto;
            $alto = self::BARRAS_ALTO_MAX;
        }

        return [round($ancho, 2), round($alto, 2)];
    }
}
