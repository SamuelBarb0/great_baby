<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** «Órdenes de compra» de la línea Dropi. Se crean, aprueban y reciben desde este panel. */
class OrdenesCompraDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Órdenes de compra';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Órdenes de compra · Dropi';

    protected static ?string $slug = 'compras-ordenes-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/compras';
    }

    protected static function puedeVer(User $u): bool
    {
        // ComprasController.
        return $u->puedeArmarOrdenes();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('nueva')->label('Nueva OC')->icon('heroicon-o-plus')
                ->visible(fn () => \App\Modules\Dropi\Filament\Support\ComprasDropi::puedeArmar(auth()->user()))
                ->url(fn () => NuevaOrdenCompraDropi::getUrl()),
            // Carga masiva (23-nov-2026): muchas compras de muchos proveedores.
            \Filament\Actions\Action::make('importar')->label('Importar desde Excel')->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => \App\Modules\Dropi\Filament\Support\ComprasDropi::puedeArmar(auth()->user()))
                ->modalHeading('Importar órdenes de compra de Dropi')
                ->modalDescription('Cada fila es un producto; las filas con la misma «referencia_oc» forman una orden. Si el producto tiene variantes, escriba en «color» cuál llega (una fila por color), tal como está en el ERP. Antes de crear nada, cada orden se abre en «Nueva OC» para revisarla y escoger lo que falte.')
                ->modalSubmitActionLabel('Revisar')
                ->schema([
                    \Filament\Forms\Components\FileUpload::make('archivo')->label('Archivo (.xlsx)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'])
                        ->disk('local')->directory('imports')->storeFileNamesIn('archivo_nombre')->required(),
                ])
                // Vista previa (24-nov-2026): el Excel no crea nada al subirlo. Se lee
                //   (analizar) y cada orden se abre llena en «Nueva OC», donde se
                //   revisa, se escoge el producto o la variante que no casó y se crea.
                ->action(function (array $data) {
                    $rel = (string) ($data['archivo'] ?? '');
                    $ruta = collect([storage_path('app/private/'.$rel), storage_path('app/'.$rel)])
                        ->first(fn ($p) => $rel !== '' && is_file($p));
                    if (! $ruta) {
                        \Filament\Notifications\Notification::make()->title('No se encontró el archivo cargado.')->danger()->send();

                        return;
                    }
                    try {
                        $r = app(\App\Modules\Dropi\Services\ImportadorOrdenesCompraDropi::class)->analizar($ruta);
                    } finally {
                        @unlink($ruta);
                    }
                    if ($r['errores']) {
                        \Filament\Notifications\Notification::make()->title('No se pudo leer el archivo')
                            ->body(implode("\n", $r['errores']))->danger()->persistent()->send();

                        return;
                    }
                    NuevaOrdenCompraDropi::empezarImportacion($r['ordenes'], (string) ($data['archivo_nombre'] ?? basename($rel)));
                    $this->redirect(NuevaOrdenCompraDropi::getUrl(['desde' => 'excel']));
                }),
            \Filament\Actions\Action::make('plantilla')->label('Plantilla')->icon('heroicon-o-arrow-down-tray')
                ->color('gray')->link()
                ->visible(fn () => \App\Modules\Dropi\Filament\Support\ComprasDropi::puedeArmar(auth()->user()))
                ->action(fn () => response()->streamDownload(
                    fn () => \App\Modules\Dropi\Services\ImportadorOrdenesCompraDropi::escribirPlantilla('php://output'),
                    'plantilla-compras-dropi.xlsx',
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                )),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::ejesDropi()->aplicar(OrdenCompra::query()->with('proveedor:id,nombre_completo')))
            ->columns([
                TextColumn::make('numero')->label('Orden')->searchable()->sortable(),
                TextColumn::make('proveedor.nombre_completo')->label('Proveedor')->searchable(),
                TextColumn::make('fecha_emision')->label('Fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('tipo')->label('Tipo')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('total')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('estado')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof EstadoOrdenCompra ? $state->label() : (string) $state),
            ])
            ->defaultSort('fecha_emision', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(collect(EstadoOrdenCompra::cases())
                    ->mapWithKeys(fn (EstadoOrdenCompra $e) => [$e->value => $e->label()])->all()),
            ])
            ->recordUrl(fn (OrdenCompra $record) => VerOrdenCompraDropi::getUrl(['orden' => $record->id]))
            ->emptyStateHeading('No hay órdenes de compra de Dropi');
    }
}
