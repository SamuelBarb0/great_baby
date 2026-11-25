<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Services\CatalogoDropi;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * «Mis productos en Dropi»: el catálogo propio tal como está en Dropi (precios,
 * stock, variaciones; sin fotos: Great Baby no puede subir las suyas a Dropi) y si cada uno ya está vinculado al ERP.
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

    /** Formulario de Dropi para crear un producto (la API oficial no crea productos). */
    public const URL_CREAR_EN_DROPI = 'https://app.dropi.co/dashboard/products/add';

    /**
     * «Crear producto en Dropi» arriba de la página. Es un enlace: el script de
     * AdminPanelProvider lo abre en una ventana al lado del panel.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('crearEnDropi')
                ->label('Crear producto en Dropi')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(self::URL_CREAR_EN_DROPI, shouldOpenInNewTab: true)
                ->visible(fn (): bool => (bool) (\App\Auth\MenuApp::permisos(auth()->user())['dropi'] ?? false)),
            // Antes abría la «Carga masiva» de Dropi (plantilla de Excel), que no
            // aplica los cambios: ahora la carga del ERP, que sí, y los verifica.
            Action::make('cargaMasivaEnDropi')
                ->label('Cargar productos y stock (Excel)')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->tooltip('Sube el Excel de inventario: vista previa, crea los productos que faltan y carga el stock en Dropi, verificando cada fila.')
                ->url('/app/dropi/carga-productos')
                ->visible(fn (): bool => \App\Auth\Permisos::puede(auth()->user(), 'dropi_productos')),
        ];
    }

    /**
     * Formulario de edición de Dropi para un producto. Es la misma ruta a la que
     * manda su propia lista de productos (`/dashboard/products/edit?id=`, leído
     * del bundle de app.dropi.co el 7-nov); la API oficial no edita productos.
     */
    public static function urlEditarEnDropi(int $id): string
    {
        return 'https://app.dropi.co/dashboard/products/edit?id=' . $id;
    }

    /** El selector de etiquetas, para el producto del ERP con que está vinculado (`producto`). */
    public function imprimirEtiquetasAction(): Action
    {
        return \App\Modules\Dropi\Filament\Support\ImprimirEtiquetasAccion::make();
    }

    /**
     * El producto del ERP con que está vinculado uno de Dropi, si es uno solo.
     * Si sus variaciones apuntan a varios, el botón imprime como antes: una
     * etiqueta por variación vinculada.
     */
    public static function productoErp(array $p): ?int
    {
        $ids = array_values(array_unique(array_filter(array_column($p['variaciones'], 'producto_erp'))));

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    public static function canAccess(): bool
    {
        // Antes era `esAracely()`: un rol de Dropi creado en Roles no podía
        //   ver su propio catálogo. La casilla manda.
        return Permisos::puede(auth()->user(), 'dropi_mis_productos');
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
