<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Jobs\LeerCatalogoDropiJob;
use App\Modules\Dropi\Services\CatalogoDropi;
use App\Modules\Dropi\Support\DestinosVinculo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * «Productos de Dropi» en el panel Dropi (/admin): el catálogo propio leído por
 * la API, cruzado con el ERP, y el lugar donde se vincula cada llave.
 *
 * Toda la lógica está en CatalogoDropi;
 * esta página sólo la pinta y la dispara.
 */
class ProductosDeDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Vincular con el ERP';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Vincular con el ERP';

    protected static ?string $slug = 'productos-de-dropi';

    protected string $view = 'dropi.pages.productos-de-dropi';

    /** Estado que se está viendo; NULL = «con pedidos esperando». */
    public ?string $filtro = null;

    public string $buscar = '';

    public const LIMITE = 200;

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        // Se pinta en cada página del panel: se guarda un minuto en vez de
        // cruzar el catálogo entero en cada clic.
        $n = (int) Cache::remember(CatalogoDropi::CACHE_BADGE, 60,
            fn () => app(CatalogoDropi::class)->previsualizar()['resumen']['con_pedidos'] ?? 0);

        return $n > 0 ? (string) $n : null;
    }

    public function mount(): void
    {
        // Se arranca por lo que frena despachos; si no hay nada esperando, por lo vinculable.
        $r = $this->previa['resumen'];
        $this->filtro = ($r['con_pedidos'] ?? 0) > 0 ? null : (($r['enlazar'] ?? 0) > 0 ? 'enlazar' : 'revisar');
    }

    public function getPreviaProperty(): array
    {
        return app(CatalogoDropi::class)->previsualizar();
    }

    public function getLecturaProperty(): ?array
    {
        return Cache::get(LeerCatalogoDropiJob::ESTADO);
    }

    public function getLeyendoProperty(): bool
    {
        return in_array($this->lectura['estado'] ?? null, ['en_cola', 'leyendo'], true);
    }

    /** Las filas del grupo elegido, las que más pedidos frenan primero. */
    public function getFilasProperty(): array
    {
        $t = mb_strtolower(trim($this->buscar));

        return collect($this->previa['filas'])
            ->filter(fn ($f) => $this->filtro === null
                ? ($f['pedidos_esperando'] > 0 && $f['estado'] !== 'vinculado')
                : $f['estado'] === $this->filtro)
            ->filter(fn ($f) => $t === '' || collect([$f['nombre'], $f['sku'], $f['llave'], $f['variacion'], $f['destino_label']])
                ->contains(fn ($x) => str_contains(mb_strtolower((string) $x), $t)))
            ->sortByDesc('pedidos_esperando')
            ->values()
            ->all();
    }

    public function verGrupo(?string $estado): void
    {
        $this->filtro = $estado;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('leer')
                ->label(fn () => $this->leyendo ? 'Leyendo Dropi…' : 'Leer de Dropi')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->disabled(fn () => $this->leyendo)
                ->action(function () {
                    Cache::put(LeerCatalogoDropiJob::ESTADO, ['estado' => 'en_cola', 'desde' => now()->toIso8601String()], 900);
                    LeerCatalogoDropiJob::dispatch();
                    Notification::make()->title('Leyendo el catálogo de Dropi')
                        ->body('Tarda unos minutos: Dropi entrega de a 40 productos y pide esperar entre llamadas.')
                        ->info()->send();
                }),
            Action::make('vincularSeguros')
                ->label(fn () => 'Vincular ' . ($this->previa['resumen']['enlazar'] ?? 0))
                ->icon('heroicon-o-link')
                ->disabled(fn () => ($this->previa['resumen']['enlazar'] ?? 0) === 0)
                ->requiresConfirmation()
                ->modalHeading('Vincular lo seguro')
                ->modalDescription('Se vinculan las llaves con la misma referencia o la misma variación en el ERP. Los pedidos que las esperaban toman el producto y, si hay stock, salen de «pendiente por inventario».')
                ->action(function () {
                    $r = app(CatalogoDropi::class)->vincular(auth()->id());
                    $n = Notification::make()->title("{$r['vinculos']} vínculos hechos")
                        ->body("{$r['items']} líneas de pedido ya tienen producto · {$r['pedidos_liberados']} pedidos salieron de «pendiente por inventario».");
                    ($r['errores'] ? $n->warning()->body('No se pudieron: ' . implode(' | ', array_slice($r['errores'], 0, 5))) : $n->success())->send();
                }),
        ];
    }

    /** «Vincular…» de una fila: producto agregado (`p:`) o variante (`v:`). */
    public function vincularLlaveAction(): Action
    {
        return Action::make('vincularLlave')
            ->label(fn (array $arguments) => ($arguments['vinculado'] ?? false) ? 'Cambiar…' : 'Vincular…')
            ->size('sm')
            ->outlined()
            ->modalSubmitActionLabel('Vincular')
            ->modalHeading(fn (array $arguments) => 'Vincular ' . ($arguments['nombre'] ?? $arguments['llave']))
            ->modalDescription(fn (array $arguments) => 'Llave en Dropi: ' . $arguments['llave']
                . '. «Producto» lleva el stock en un solo total: todas las variaciones de Dropi descuentan de ahí.')
            ->schema([
                Select::make('destino')
                    ->label('Producto del ERP')
                    ->placeholder('Busca por nombre, referencia o código de barras')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search) => DestinosVinculo::buscar($search))
                    ->getOptionLabelUsing(fn ($value) => DestinosVinculo::etiqueta((string) $value)),
            ])
            ->action(function (array $data, array $arguments) {
                try {
                    $r = app(CatalogoDropi::class)->vincularLlave($arguments['llave'], (string) $data['destino'], auth()->id());
                } catch (\DomainException $e) {
                    Notification::make()->title('No se vinculó')->body($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title("{$arguments['llave']} vinculado")
                    ->body("{$r['items']} línea(s) de pedido con producto · {$r['pedidos_liberados']} pedido(s) salieron de «pendiente por inventario».")
                    ->success()->send();
            });
    }
}
