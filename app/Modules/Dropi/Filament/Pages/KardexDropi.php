<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * «Kardex» dentro de Dropi (28-nov-2026, la señora Lahida: «¿cómo se me descuenta
 * el inventario, dónde miro el kardex?»). Antes el kardex estaba en el ERP, solo
 * buscaba por código de barras o referencia exacta y hablaba de «egreso» y
 * «formato SIIGO»; el del panel ni aparecía en el menú de Dropi.
 *
 * Se busca como en «Buscar producto» (nombre, referencia, código de barras o
 * nombre en Dropi) y cada movimiento dice en palabras qué pasó, con su
 * documento: la guía abre el pedido, la recepción o la carga abren su documento.
 * El costo promedio y el saldo se calculan como el kardex del ERP.
 */
class KardexDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static ?string $navigationLabel = 'Kardex';

    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Kardex · entradas y salidas del inventario';

    protected static ?string $slug = 'kardex-dropi';

    protected string $view = 'dropi.pages.kardex-dropi';

    /** Cuántos movimientos se muestran de entrada; «Ver más» suma otros tantos. */
    public const POR_PAGINA = 100;

    /** Cuántos productos se ofrecen al buscar. */
    public const COINCIDENCIAS = 12;

    #[Url]
    public string $buscar = '';

    #[Url]
    public ?int $producto = null;

    /** Null = todas las variantes y lo registrado a nivel producto. */
    #[Url]
    public ?int $variante = null;

    public int $mostrar = self::POR_PAGINA;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return Permisos::puede($u, 'kardex') || Permisos::puede($u, 'contabilidad');
    }

    /** Al buscar se suelta el producto elegido; si sólo hay uno, se abre de una. */
    public function updatedBuscar(): void
    {
        $this->producto = null;
        $this->variante = null;
        $this->mostrar = self::POR_PAGINA;
        $c = $this->coincidencias;
        if ($c->count() === 1) {
            $this->producto = $c->first()->id;
        }
    }

    public function updatedVariante(): void
    {
        $this->mostrar = self::POR_PAGINA;
    }

    public function elegir(int $productoId): void
    {
        $this->producto = $productoId;
        $this->variante = null;
        $this->mostrar = self::POR_PAGINA;
    }

    public function verMas(): void
    {
        $this->mostrar += self::POR_PAGINA;
    }

    /** Los productos que coinciden con la búsqueda, como en «Buscar producto». */
    public function getCoincidenciasProperty(): Collection
    {
        $t = trim($this->buscar);
        if (mb_strlen($t) < 2) {
            return collect();
        }

        return Producto::query()
            ->where(fn ($q) => $q->where('nombre', 'like', "%{$t}%")
                ->orWhere('referencia', 'like', "%{$t}%")
                ->orWhereIn('id', CatalogoProductosDropi::idsPorNombreEnDropi($t))
                ->orWhereIn('id', CatalogoProductosDropi::idsPorCatalogoDropi($t))
                ->orWhereIn('id', CatalogoProductosDropi::idsPorCodigo($t)))
            ->orderBy('nombre')
            ->limit(self::COINCIDENCIAS)
            ->get(['id', 'referencia', 'nombre']);
    }

    public function getProductoElegidoProperty(): ?Producto
    {
        return $this->producto ? Producto::with('variantes')->find($this->producto) : null;
    }

    /** @return array<int, string> variante => «Rojo · 6M» */
    public function getVariantesProperty(): array
    {
        return $this->productoElegido?->variantes
            ->mapWithKeys(fn (ProductoVariante $v) => [$v->id => self::nombreVariante($v)])->all() ?? [];
    }

    /**
     * Todos los movimientos del producto (o de la variante), con el saldo y el
     * costo promedio que llevaban, el más reciente primero.
     *
     * @return array{movimientos: list<array>, total: int, saldo: int, costo_promedio: float, valor: float}
     */
    public function getKardexProperty(): array
    {
        $p = $this->productoElegido;
        if (! $p) {
            return ['movimientos' => [], 'total' => 0, 'saldo' => 0, 'costo_promedio' => 0.0, 'valor' => 0.0];
        }

        $q = InventarioMovimiento::query()->with(['ubicacion:id,codigo,nombre', 'variante']);
        if ($this->variante) {
            $q->where('variante_id', $this->variante);
        } else {
            $q->where(fn ($w) => $w->where(fn ($a) => $a->whereNull('variante_id')->where('producto_id', $p->id))
                ->orWhereIn('variante_id', $p->variantes->pluck('id')->all() ?: [0]));
        }

        // Cada quien ve sus bodegas, como en el kardex del ERP.
        $u = auth()->user();
        if (! $u->esAracely() && ! Permisos::esRoot($u)) {
            $bodegas = $u->bodegasQueVeIds();
            $bodegas ? $q->whereIn('ubicacion_id', $bodegas) : $q->whereRaw('1 = 0');
        }

        $saldo = 0;
        $costoProm = 0.0;
        $filas = $q->orderBy('created_at')->orderBy('id')->get()->map(function (InventarioMovimiento $m) use (&$saldo, &$costoProm) {
            $cantidad = (int) $m->cantidad;
            $costo = (float) ($m->costo_unit ?? 0);
            // Costo promedio ponderado, como el kardex del ERP: cambia sólo con
            //   las entradas que traen costo.
            if ($cantidad > 0 && $costo > 0) {
                $nuevoSaldo = $saldo + $cantidad;
                $costoProm = $nuevoSaldo > 0 ? round(($saldo * $costoProm + $cantidad * $costo) / $nuevoSaldo, 4) : 0.0;
            }
            $saldo += $cantidad;
            [$que, $documento, $url] = self::explicar($m);

            return [
                'fecha' => $m->created_at?->timezone('America/Bogota')->format('d/m/Y g:i a'),
                'que' => $que,
                'documento' => $documento,
                'url' => $url,
                'variante' => $m->variante ? self::nombreVariante($m->variante) : null,
                'bodega' => (string) ($m->ubicacion?->nombre ?: $m->ubicacion?->codigo),
                'entra' => $cantidad > 0 ? $cantidad : null,
                'sale' => $cantidad < 0 ? -$cantidad : null,
                'saldo' => $saldo,
                'costo' => $costo,
                'valor' => round(abs($cantidad) * $costo, 2),
                'nota' => $m->notas,
            ];
        });

        return [
            'movimientos' => $filas->reverse()->take($this->mostrar)->values()->all(),
            'total' => $filas->count(),
            'saldo' => $saldo,
            'costo_promedio' => $costoProm,
            'valor' => round(max($saldo, 0) * $costoProm, 2),
        ];
    }

    public static function nombreVariante(ProductoVariante $v): string
    {
        return collect([$v->color_nombre, $v->diseno_nombre, $v->talla])->filter()->implode(' · ') ?: ($v->codigo_barras ?: "Variante #{$v->id}");
    }

    /**
     * Qué pasó, en palabras, con su documento y a dónde lleva.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    public static function explicar(InventarioMovimiento $m): array
    {
        $id = $m->referencia_id;
        $guia = fn (?int $pedidoId) => $pedidoId ? (string) (\App\Modules\Dropi\Models\DropiPedido::whereKey($pedidoId)->value('guia') ?? "pedido #{$pedidoId}") : null;
        $verPedido = fn (?int $pedidoId) => $pedidoId ? DropiPedidoResource::getUrl('view', ['record' => $pedidoId]) : null;

        return match ($m->referencia_tipo ? class_basename($m->referencia_tipo) : null) {
            SalidaInventarioDropi::EGRESO => ['Salió por pedido Dropi', 'Guía ' . $guia($id), $verPedido($id)],
            SalidaInventarioDropi::REVERSO => ['Volvió: se desempacó el pedido', 'Guía ' . $guia($id), $verPedido($id)],
            'devolucion' => ['Devolución del cliente', 'Guía ' . $guia($id), $verPedido($id)],
            'DropiDevolucion' => (function () use ($id, $guia, $verPedido) {
                $pedido = \App\Modules\Dropi\Models\DropiDevolucion::whereKey($id)->value('pedido_id');

                return ['Devolución del cliente', 'Guía ' . $guia($pedido), $verPedido($pedido)];
            })(),
            'RecepcionCompra' => ['Entró por compra', \App\Modules\Compras\Models\RecepcionCompra::whereKey($id)->value('numero') ?? "Recepción #{$id}", "/app/compras/recepcion/{$id}"],
            'CargaInventario' => (function () use ($id) {
                $c = \App\Modules\Inventario\Models\CargaInventario::find($id);

                return [$c?->motivo?->label() ?? 'Carga de inventario', $c?->numero ?? "Carga #{$id}", "/app/inventario/cargas/{$id}"];
            })(),
            'Traslado' => [$m->cantidad > 0 ? 'Llegó por traslado' : 'Salió por traslado', "Traslado #{$id}", "/app/inventario/traslados/{$id}"],
            'TomaFisica' => ['Ajuste por conteo físico', "Conteo #{$id}", "/app/inventario/conteos/{$id}"],
            'FacturaVenta' => ['Venta facturada', "Factura #{$id}", "/app/cartera/facturas/{$id}"],
            \App\Modules\Inventario\Actions\DescontarStockPorDespacho::REFERENCIA_TIPO => ['Despacho de pedido mayorista', "Pedido #{$id}", null],
            'inventario_fisico' => ['Carga de inventario físico', null, null],
            default => [self::porTipo((string) $m->tipo, (int) $m->cantidad), $m->referencia_tipo && $id ? class_basename($m->referencia_tipo) . " #{$id}" : null, null],
        };
    }

    /** Sin documento conocido: lo que dice el tipo del movimiento. */
    private static function porTipo(string $tipo, int $cantidad): string
    {
        return match ($tipo) {
            'stock_inicial_form' => 'Inventario inicial (al crear el producto)',
            'carga_inicial_cliente' => 'Carga inicial de inventario',
            'entrada_compra', 'recepcion_ecommerce' => 'Entró por compra',
            'traslado_entrada', 'traslado_reversa_entrada' => 'Llegó por traslado',
            'traslado_salida', 'traslado_reversa_salida' => 'Salió por traslado',
            'ajuste_toma_fisica' => 'Ajuste por conteo físico',
            'merma' => 'Merma (dañado o perdido)',
            'faltante' => 'Faltante en conteo',
            'sobrante' => 'Sobrante en conteo',
            'salida_venta' => 'Venta',
            'salida_devolucion_proveedor' => 'Devuelto al proveedor',
            default => $cantidad >= 0 ? 'Entrada' : 'Salida',
        };
    }
}
