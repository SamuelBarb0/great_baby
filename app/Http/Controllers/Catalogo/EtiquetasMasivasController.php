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
     * Rollo de la térmica SAT TT460 para las etiquetas de Dropi, en mm: papel
     * de 100 × 25 con 3 columnas de 32,49 y sin separación entre ellas (el
     * blanco que se ve entre etiquetas ya va dentro de cada columna); lo que
     * sobra, 1,27 por lado, es el margen. Leído en Configurar página de la
     * plantilla de BarTender de la bodega (GREATBABY.btw, 20-nov-2026). El
     * driver de la impresora debe tener el mismo papel de 100 × 25.
     */
    private const ROLLO_DROPI = ['ancho' => 100, 'alto' => 25, 'columnas' => 3, 'etiqueta' => 32.49, 'separacion' => 0];

    /** Alto máximo (mm) del código de barras: lo que dejan libre los 25 mm tras dos renglones de nombre. */
    private const BARRAS_ALTO_MAX = 14;

    /** Lo mismo, cuando la etiqueta lleva además el renglón del color (producto agrupado). */
    private const BARRAS_ALTO_CON_COLOR = 11;

    public function pdf(Request $request, CatalogoDropi $catalogo): Response
    {
        abort_unless(auth()->check(), 401);

        $productoIds = (array) $request->input('productos', []);
        $productos = Producto::query()
            ->whereIn('id', $productoIds)
            ->with(['variantes' => fn ($q) => $q->orderBy('codigo_barras')])
            ->orderBy('id')
            ->get();

        // Con variantes, una etiqueta por variante. Un producto agrupado no tiene
        //   variantes en el ERP (bodega, 23-nov-2026: «no puedo imprimir las
        //   variaciones»): sale una por cada color de Dropi, todas con su referencia.
        //   El nombre del color sale de la última lectura del catálogo de Dropi (la
        //   misma de «Mis productos en Dropi», sin llamar a Dropi), solo si hay agrupados.
        $nombrePorLlave = $productos->contains(fn (Producto $p) => $p->variantes->isEmpty())
            ? collect($catalogo->misProductos()['productos'])->flatMap(fn ($x) => $x['variaciones'])
                ->pluck('variacion', 'llave')->filter()->all()
            : [];

        $etiquetas = $productos->flatMap(fn (Producto $p) => $p->variantes->isNotEmpty()
            ? $p->variantes->map(fn (ProductoVariante $v) => self::deVariante($v->setRelation('producto', $p)))->all()
            : self::deAgrupado($p, $nombrePorLlave))->all();

        if ($etiquetas === []) {
            abort(404, 'Sin productos para imprimir.');
        }

        return $this->lote($etiquetas, 'etiquetas-lote');
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
            // Dos variaciones de Dropi pueden apuntar a la misma variante del ERP: una
            //   etiqueta basta. En un agrupado todas comparten la referencia, así que
            //   va una por color, con el color escrito: es lo único que las distingue.
            $llave = $etiqueta ? $etiqueta['codigo'] . '|' . ($etiqueta['color'] ?? '') : null;
            if ($llave && ! isset($etiquetas[$llave])) {
                $etiquetas[$llave] = $etiqueta;
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

    /**
     * Un producto agregado no tiene variantes: su código es la referencia. La
     * variación de Dropi (el color) va escrita en `color`: el código es el mismo
     * para todos los colores y solo así se sabe cuál es cuál.
     */
    private static function deAgregado(Producto $p, ?string $variacion): array
    {
        $variacion = filled($variacion) ? trim($variacion) : null;

        return [
            'nombre' => $p->nombre,
            'detalle' => (string) $variacion,
            'color' => $variacion,
            'precio' => (float) ($p->precio_proveedor ?? 0),
            'codigo' => $p->referencia,
        ];
    }

    /**
     * Las etiquetas de un producto agrupado en el catálogo: una por cada color
     * con que está vinculado en Dropi, o una sola si no tiene colores. El nombre
     * del color viene del catálogo de Dropi; si no está, el que guardó el vínculo.
     *
     * @param  array<string, string>  $nombrePorLlave  llave de Dropi => variación
     * @return list<array{nombre:?string, detalle:string, color:?string, precio:float, codigo:string}>
     */
    private static function deAgrupado(Producto $p, array $nombrePorLlave): array
    {
        $colores = DropiVinculoProducto::query()
            ->where('producto_id', $p->id)
            ->whereNull('variante_id')
            ->get(['sku_dropi', 'variacion'])
            ->map(fn (DropiVinculoProducto $v) => trim((string) ($nombrePorLlave[$v->sku_dropi] ?? $v->variacion)))
            ->sort()
            ->filter()
            ->unique(fn ($v) => mb_strtoupper($v))
            ->values();

        if ($colores->isEmpty()) {
            return [self::deAgregado($p, null)];
        }

        return $colores->map(fn (string $color) => self::deAgregado($p, $color))->all();
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
                // Con el renglón del color, las barras bajan de alto para caber en los 25 mm.
                $altoMax = filled($e['color'] ?? null) ? self::BARRAS_ALTO_CON_COLOR : self::BARRAS_ALTO_MAX;
                [$e['barras_ancho'], $e['barras_alto']] = self::encajarBarras($png, $altoMax);
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
    private static function encajarBarras(string $png, float $altoMax = self::BARRAS_ALTO_MAX): array
    {
        [$px, $py] = getimagesizefromstring($png);
        $ancho = self::ROLLO_DROPI['etiqueta'] - 2;
        $alto = $ancho * $py / $px;
        if ($alto > $altoMax) {
            $ancho *= $altoMax / $alto;
            $alto = $altoMax;
        }

        return [round($ancho, 2), round($alto, 2)];
    }
}
