<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Services\CatalogoDropi;
use BackedEnum;
use Filament\Pages\Page;

/**
 * «Mis productos en Dropi»: el catálogo propio tal como está en Dropi (foto,
 * precios, stock, variaciones) y si cada uno ya está vinculado al ERP.
 *
 * Sólo lee la foto que deja «Leer de Dropi» (en «Vincular con el ERP»): no
 * llama a Dropi al abrirse, así que carga al instante.
 */
class MisProductosDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Mis productos en Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Mis productos en Dropi';

    protected static ?string $slug = 'mis-productos-dropi';

    protected string $view = 'dropi.pages.mis-productos-dropi';

    public string $buscar = '';

    /** todos | con_stock | sin_stock | variables | sin_vincular */
    public string $filtro = 'todos';

    public const POR_PAGINA = 48;

    public int $mostrar = self::POR_PAGINA;

    public const FILTROS = [
        'todos' => 'Todos',
        'con_stock' => 'Con stock',
        'sin_stock' => 'Sin stock',
        'variables' => 'Con variaciones',
        'sin_vincular' => 'Sin vincular al ERP',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function updatedBuscar(): void
    {
        $this->mostrar = self::POR_PAGINA;
    }

    public function updatedFiltro(): void
    {
        $this->mostrar = self::POR_PAGINA;
    }

    public function verMas(): void
    {
        $this->mostrar += self::POR_PAGINA;
    }

    public function getCatalogoProperty(): array
    {
        return app(CatalogoDropi::class)->misProductos();
    }

    public function getProductosProperty(): array
    {
        $t = mb_strtolower(trim($this->buscar));

        return array_values(array_filter($this->catalogo['productos'], function (array $p) use ($t) {
            $pasa = match ($this->filtro) {
                'con_stock' => $p['stock'] > 0,
                'sin_stock' => $p['stock'] <= 0,
                'variables' => count($p['variaciones']) > 1 || $p['tipo'] === 'VARIABLE',
                'sin_vincular' => $p['vinculo'] !== 'si',
                default => true,
            };
            if (! $pasa) {
                return false;
            }
            if ($t === '') {
                return true;
            }

            return str_contains(mb_strtolower($p['nombre'] . ' ' . $p['sku'] . ' ' . $p['id'] . ' ' . implode(' ', $p['categorias'])), $t)
                || collect($p['variaciones'])->contains(fn ($v) => str_contains(mb_strtolower((string) $v['variacion']), $t));
        }));
    }
}
