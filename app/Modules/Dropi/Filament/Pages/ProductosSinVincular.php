<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Actions\VincularProductoDropi;
use App\Modules\Dropi\Models\DropiPedidoItem;
use App\Modules\Dropi\Models\DropiVinculoProducto;
use App\Modules\Dropi\Models\ProductoVariante;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Productos que llegan de Dropi con una llave que el catálogo GB no conoce.
 * Se enlazan una vez con su variante y desde ahí los pedidos entran solos
 * (ver VincularProductoDropi). Mientras no estén enlazados, sus pedidos quedan
 * en «pendiente por inventario».
 */
class ProductosSinVincular extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link-slash';

    protected static ?string $navigationLabel = 'Productos sin vincular';

    protected static string|\UnitEnum|null $navigationGroup = 'Dropi';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Productos de Dropi sin vincular';

    protected string $view = 'dropi.pages.productos-sin-vincular';

    public string $buscar = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = DropiPedidoItem::whereNull('variante_id')->whereNotNull('sku_dropi')->distinct()->count('sku_dropi');

        return $n > 0 ? (string) $n : null;
    }

    public function getSinVincularProperty(): Collection
    {
        return DropiPedidoItem::query()
            ->join('dropi_pedidos', 'dropi_pedidos.id', '=', 'dropi_pedido_items.pedido_id')
            ->whereNull('dropi_pedido_items.variante_id')
            ->whereNotNull('dropi_pedido_items.sku_dropi')
            ->when(trim($this->buscar) !== '', function ($q) {
                $t = '%' . trim($this->buscar) . '%';
                $q->where(fn ($w) => $w->where('dropi_pedido_items.sku_dropi', 'like', $t)
                    ->orWhere('dropi_pedido_items.producto_nombre', 'like', $t));
            })
            ->groupBy('dropi_pedido_items.sku_dropi')
            ->selectRaw("dropi_pedido_items.sku_dropi,
                MAX(dropi_pedido_items.producto_nombre) AS producto_nombre,
                MAX(dropi_pedido_items.variacion) AS variacion,
                COUNT(DISTINCT dropi_pedido_items.pedido_id) AS pedidos,
                COUNT(DISTINCT CASE WHEN dropi_pedidos.estado = 'pendiente_inventario' THEN dropi_pedidos.id END) AS pendientes")
            ->orderByDesc('pendientes')
            ->orderByDesc('pedidos')
            ->limit(200)
            ->get();
    }

    public function getVinculadosProperty(): Collection
    {
        return DropiVinculoProducto::with('variante.producto')->latest('updated_at')->limit(20)->get();
    }

    public function vincularAction(): Action
    {
        return Action::make('vincular')
            ->label(fn (array $arguments) => isset($arguments['cambiar']) ? 'Cambiar' : 'Vincular')
            ->icon('heroicon-o-link')
            ->size('sm')
            ->modalHeading(fn (array $arguments) => 'Vincular ' . ($arguments['nombre'] ?? $arguments['sku']))
            ->modalDescription(fn (array $arguments) => 'Llave en Dropi: ' . $arguments['sku']
                . '. Desde ahora todo pedido con este producto usará la variante que elijas.')
            ->schema([
                Select::make('variante_id')
                    ->label('Producto del ERP')
                    ->placeholder('Busca por nombre, referencia o código de barras')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search) => self::buscarVariantes($search))
                    ->getOptionLabelUsing(fn ($value) => self::etiqueta(ProductoVariante::with('producto')->find($value))),
            ])
            ->action(function (array $data, array $arguments) {
                $r = VincularProductoDropi::run($arguments['sku'], (int) $data['variante_id'], auth()->id());

                Notification::make()
                    ->title('Producto vinculado')
                    ->body("{$r['items']} línea(s) de pedido actualizadas; {$r['pedidos_liberados']} pedido(s) salieron de «pendiente por inventario».")
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, string> */
    public static function buscarVariantes(string $search): array
    {
        $t = '%' . trim($search) . '%';

        return ProductoVariante::query()
            ->with('producto')
            ->where(fn ($q) => $q->where('codigo_barras', 'like', $t)
                ->orWhere('color_nombre', 'like', $t)
                ->orWhere('diseno_nombre', 'like', $t)
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $t)->orWhere('referencia', 'like', $t)))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductoVariante $v) => [$v->id => self::etiqueta($v)])
            ->all();
    }

    public static function etiqueta(?ProductoVariante $v): string
    {
        if (! $v) {
            return '';
        }

        $detalle = collect([$v->color_nombre, $v->diseno_nombre, $v->talla])->filter()->implode(' / ');

        return trim(($v->producto?->nombre ?? 'Sin producto') . ($detalle ? " · {$detalle}" : '') . " · {$v->codigo_barras}");
    }
}
