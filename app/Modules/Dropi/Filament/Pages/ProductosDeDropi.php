<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Actions\CrearProductosNuevosDropi;
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
        // Antes era `esAracely()`: un rol de Dropi creado en Roles no podía
        //   destrabar los pedidos que esperan producto. La casilla manda.
        return Permisos::puede(auth()->user(), 'dropi_vincular_productos');
    }

    /** Ver qué llaves faltan es una cosa; vincularlas cambia pedidos e inventario. */
    public function getPuedeEditarProperty(): bool
    {
        return Permisos::puedeAccion(auth()->user(), 'dropi_vincular_productos', 'editar');
    }

    protected function exigirEdicion(): void
    {
        abort_unless($this->puedeEditar, 403, 'Vincular productos de Dropi necesita permiso de edición.');
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
                ->visible(fn () => $this->puedeEditar)
                ->requiresConfirmation()
                ->modalHeading('Vincular lo seguro')
                ->modalDescription('Se vinculan las llaves con la misma referencia o la misma variación en el ERP. Los pedidos que las esperaban toman el producto y, si hay stock, salen de «pendiente por inventario».')
                ->action(function () {
                    $this->exigirEdicion();

                    $r = app(CatalogoDropi::class)->vincular(auth()->id());
                    $n = Notification::make()->title("{$r['vinculos']} vínculos hechos")
                        ->body("{$r['items']} líneas de pedido ya tienen producto · {$r['pedidos_liberados']} pedidos salieron de «pendiente por inventario».");
                    ($r['errores'] ? $n->warning()->body('No se pudieron: ' . implode(' | ', array_slice($r['errores'], 0, 5))) : $n->success())->send();
                }),
            // Masivo (23-nov-2026): todos los «No están en el ERP» de una vez.
            Action::make('crearNuevos')
                ->label(fn () => 'Crear en el ERP ' . count(app(CrearProductosNuevosDropi::class)->porCrear(true)))
                ->icon('heroicon-o-plus-circle')
                ->color('gray')
                ->disabled(fn () => app(CrearProductosNuevosDropi::class)->porCrear(true) === [])
                ->visible(fn () => $this->puedeEditar)
                ->requiresConfirmation()
                ->modalHeading('Crear en el ERP los productos nuevos de Dropi')
                ->modalDescription('Se crea una ficha por producto de Dropi que no se parece a ninguno del ERP, ya vinculada, sin stock ni costo: el inventario entra con la compra. Los parecidos («Revisar a mano») no se tocan.')
                ->action(function () {
                    $this->exigirEdicion();
                    $r = app(CrearProductosNuevosDropi::class)->handle(todos: true);
                    $n = Notification::make()->title(count($r['creados']) . ' producto(s) creado(s) en el ERP')
                        ->body(collect($r['creados'])->pluck('referencia')->take(10)->implode(', ') ?: 'Ninguno.');
                    ($r['errores'] ? $n->warning()->body('No se pudieron: ' . implode(' | ', array_slice($r['errores'], 0, 5))) : $n->success())->send();
                }),
            // La regla, aquí mismo: que lo hagan solos o no.
            Action::make('automatico')
                ->label(fn () => CrearProductosNuevosDropi::todos() ? 'Crear solos: Sí' : 'Crear solos: No')
                ->icon(fn () => CrearProductosNuevosDropi::todos() ? 'heroicon-o-check-circle' : 'heroicon-o-pause-circle')
                ->color(fn () => CrearProductosNuevosDropi::todos() ? 'success' : 'gray')
                ->visible(fn () => $this->puedeEditar)
                ->requiresConfirmation()
                ->modalHeading(fn () => CrearProductosNuevosDropi::todos() ? '¿Dejar de crearlos solos?' : '¿Crearlos solos?')
                ->modalDescription(fn () => CrearProductosNuevosDropi::todos()
                    ? 'Sólo se crearán solos los que frenan pedidos. Los demás quedan aquí para crearlos a mano, uno por uno o todos.'
                    : 'Cada producto nuevo de Dropi que no se parezca a ninguno del ERP se creará y vinculará solo, cada pocos minutos.')
                ->action(function () {
                    $this->exigirEdicion();
                    \App\Support\Reglas::set('dropi.crear_productos_nuevos', CrearProductosNuevosDropi::todos() ? '0' : '1');
                    Notification::make()->title(CrearProductosNuevosDropi::todos() ? 'Se crearán solos' : 'Ya no se crearán solos')->success()->send();
                }),
        ];
    }

    /** «Crear en el ERP» de una fila «No están en el ERP»: ese producto de Dropi, con todas sus variaciones. */
    public function crearEnErpAction(): Action
    {
        return Action::make('crearEnErp')
            ->label('Crear en el ERP')
            ->size('sm')
            ->outlined()
            ->color('gray')
            ->visible(fn () => $this->puedeEditar)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Crear «' . ($arguments['nombre'] ?? '') . '» en el ERP')
            ->modalDescription('Queda una ficha nueva, vinculada a este producto de Dropi y a todas sus variaciones, sin stock ni costo: el inventario entra con la compra.')
            ->action(function (array $arguments) {
                $this->exigirEdicion();
                try {
                    $r = app(CrearProductosNuevosDropi::class)->crearUno((int) $arguments['dropi_id']);
                } catch (\DomainException $e) {
                    Notification::make()->title('No se creó')->body($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title("«{$r['nombre']}» creado en el ERP")
                    ->body("Referencia {$r['referencia']} · " . count($r['llaves']) . ' llave(s) vinculada(s).')->success()->send();
            });
    }

    /** «Vincular…» de una fila: producto agregado (`p:`) o variante (`v:`). */
    public function vincularLlaveAction(): Action
    {
        return Action::make('vincularLlave')
            ->label(fn (array $arguments) => ($arguments['vinculado'] ?? false) ? 'Cambiar…' : 'Vincular…')
            ->size('sm')
            ->outlined()
            ->visible(fn () => $this->puedeEditar)
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
                $this->exigirEdicion();

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
