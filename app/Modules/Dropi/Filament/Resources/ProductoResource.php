<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Filament\Resources\ProductoResource\Pages;
use App\Modules\Dropi\Models\Producto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductoResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = Producto::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 6;

    /**
     * Fuera del panel: el catálogo se maneja en Vue, en `/app/catalogo/productos`.
     *
     * Este Resource vive en la carpeta de Dropi por historia, pero es el
     * catálogo general del ERP, no una pantalla de Dropi. Ahora que `/admin`
     * quedó sólo para Dropi, tener las dos puertas significaba dos fichas de
     * producto con reglas distintas. No lo referencia ninguna pantalla Dropi.
     *
     * El permiso `productos` se sigue consultando desde Vue; acá queda a la
     * vista por si hace falta volver a encenderlo.
     */
    public static function canViewAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['referencia', 'nombre', 'categoria'];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return ['Ref' => $record->referencia, 'Precio' => '$' . number_format((float) $record->precio_proveedor, 0, ',', '.')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Tabs::make('tabs')->tabs([
                \Filament\Schemas\Components\Tabs\Tab::make('Información general')->icon('heroicon-o-information-circle')->schema([
                    TextInput::make('referencia')->required()->unique(ignoreRecord: true)->maxLength(100)
                        ->helperText('Código general del proveedor. Ej: AND2512-79/154'),
                    TextInput::make('nombre')->required(),
                    \Filament\Forms\Components\Select::make('marca_id')->relationship('marca', 'nombre')->searchable()->preload(),
                    \Filament\Forms\Components\Select::make('categoria_id')->label('Categoría')
                        ->relationship('categoriaMaestra', 'nombre')->searchable()->preload(),
                    \Filament\Forms\Components\Select::make('coleccion_id')->label('Colección')
                        ->relationship('coleccion', 'nombre')->searchable()->preload(),
                    \Filament\Forms\Components\Select::make('unidad_medida_id')->label('Unidad medida')
                        ->relationship('unidadMedida', 'nombre')->searchable()->preload(),
                    \Filament\Forms\Components\Select::make('impuesto_id')->label('Impuesto')
                        ->relationship('impuesto', 'nombre')->searchable()->preload(),
                    TextInput::make('precio_proveedor')->numeric()->prefix('$')->required(),
                    Textarea::make('descripcion')->columnSpanFull(),
                ])->columns(2),

                \Filament\Schemas\Components\Tabs\Tab::make('Reglas y dimensiones')->icon('heroicon-o-cog-6-tooth')->schema([
                    Toggle::make('activo')->default(true),
                    Toggle::make('requiere_talla')->label('Requiere talla'),
                    Toggle::make('es_set')->label('Es set/kit'),
                    Toggle::make('neto')->label('Neto (aplica descuentos y flete)')->default(true)
                        ->helperText('Si NO es neto, quedará protegido de descuentos comerciales'),
                    // C-F3 · Toggle desglose_stock
                    //   - Default true (comportamiento clásico).
                    //   - Deshabilitado si el producto ya tiene movimientos (política enforced
                    //     también en BD por trigger trg_bloqueo_toggle_desglose_stock).
                    //   - Cuando OFF, el campo `stock_directo` aparece y las variantes quedan
                    //     ocultas en pestaña aparte.
                    Toggle::make('desglose_stock')
                        ->label('Desglosar stock por variante')
                        ->helperText(fn ($record) => $record?->exists && $record->movimientos()->exists()
                            ? '🔒 Bloqueado: este producto ya tiene movimientos de kardex. El cambio quebraría el kardex append-only.'
                            : 'ON = stock granular por color/talla · OFF = un solo stock agregado (para productos que no diferencias por variación).')
                        ->default(true)
                        ->disabled(fn ($record) => $record?->exists && $record->movimientos()->exists())
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('stock_directo')
                        ->label('Stock inicial (agregado)')
                        ->numeric()->minValue(0)->default(0)
                        ->helperText('Solo aplica cuando NO desglosa por variante. Se registra un movimiento inicial en la primera bodega activa.')
                        ->visible(fn ($get) => $get('desglose_stock') === false)
                        ->columnSpanFull(),
                    TextInput::make('peso_gr')->numeric()->suffix('g')->label('Peso'),
                    TextInput::make('alto_cm')->numeric()->suffix('cm')->label('Alto'),
                    TextInput::make('ancho_cm')->numeric()->suffix('cm')->label('Ancho'),
                    TextInput::make('largo_cm')->numeric()->suffix('cm')->label('Largo'),
                ])->columns(2),

                \Filament\Schemas\Components\Tabs\Tab::make('Imágenes')->icon('heroicon-o-photo')->schema([
                    \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make('imagenes')
                        ->collection('imagenes')
                        ->multiple()->image()->imageEditor()->reorderable()->downloadable()
                        ->columnSpanFull(),
                ]),

                \Filament\Schemas\Components\Tabs\Tab::make('Contable')->icon('heroicon-o-calculator')->schema([
                    \Filament\Schemas\Components\Section::make('Cuentas contables del producto')
                        ->description('Si vacío, se usa el default global de Configuración → Reglas de negocio. Estas cuentas alimentan asientos, PDFs y sincronización SIIGO.')
                        ->schema([
                            TextInput::make('cta_ingreso')->label('Cta. ingreso')->placeholder(setting('contable.cta_ingreso_default'))->maxLength(30)->columnSpan(1),
                            TextInput::make('cta_iva_venta')->label('Cta. IVA venta')->placeholder(setting('contable.cta_iva_venta_default'))->maxLength(30)->columnSpan(1),
                            TextInput::make('cta_costo')->label('Cta. costo')->placeholder(setting('contable.cta_costo_default'))->maxLength(30)->columnSpan(1),
                            TextInput::make('cta_inventario')->label('Cta. inventario')->placeholder(setting('contable.cta_inventario_default'))->maxLength(30)->columnSpan(1),
                            TextInput::make('cta_devolucion')->label('Cta. devolución en ventas')->placeholder(setting('contable.cta_devolucion_default'))->maxLength(30)->columnSpan(1),
                            TextInput::make('cta_descuento')->label('Cta. descuento comercial')->placeholder(setting('contable.cta_descuento_default'))->maxLength(30)->columnSpan(1),
                            TextInput::make('centro_costo')->label('Centro de costo')->placeholder(setting('contable.centro_costo_default'))->maxLength(20)->columnSpan(1),
                            Textarea::make('notas_contables')->label('Notas contables')->rows(3)->columnSpanFull(),
                        ])->columns(2),
                ]),

                \Filament\Schemas\Components\Tabs\Tab::make('Sincronización SIIGO')->icon('heroicon-o-cloud')->schema([
                    TextInput::make('siigo_id')->label('ID en SIIGO')->disabled()->dehydrated(false),
                    TextInput::make('siigo_code')->label('Code SIIGO')->disabled()->dehydrated(false),
                    \Filament\Forms\Components\DateTimePicker::make('siigo_sync_at')->label('Última sync')->disabled()->dehydrated(false)->native(false),
                ])->columns(2),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\SpatieMediaLibraryImageColumn::make('imagen')
                    ->collection('imagenes')->label('')
                    ->circular()->size(40)->limit(1),
                TextColumn::make('referencia')->searchable()->weight('bold')->copyable(),
                TextColumn::make('nombre')->searchable()->limit(35),
                TextColumn::make('marca.nombre')->badge()->color('info')->toggleable(),
                TextColumn::make('categoriaMaestra.nombre')->label('Categoría')->badge()->toggleable(),
                TextColumn::make('precio_proveedor')->money('COP')->alignEnd()->sortable(),
                TextColumn::make('variantes_count')->counts('variantes')->label('Variantes')->badge()
                    ->formatStateUsing(fn ($state, $record) => $record->desglose_stock ? $state : '—')
                    ->tooltip(fn ($record) => $record->desglose_stock ? null : 'Producto sin desglose · stock agregado'),
                // C-F3 · badge dual: 🔵 Granular / 🟠 Agregado
                \Filament\Tables\Columns\IconColumn::make('desglose_stock')
                    ->label('Modo')
                    ->icon(fn ($state) => $state ? 'heroicon-o-squares-2x2' : 'heroicon-o-cube')
                    ->color(fn ($state) => $state ? 'info' : 'warning')
                    ->tooltip(fn ($state) => $state ? 'Granular · stock por variante' : 'Agregado · stock único'),
                IconColumn::make('siigo_id')
                    ->label('SIIGO')
                    ->getStateUsing(fn ($record) => ! empty($record->siigo_id))
                    ->boolean()
                    ->trueColor('info')->trueIcon('heroicon-o-cloud'),
                // F6 · badge de antigüedad de la última sincronización:
                //   verde  · <15 min  (fresco, dentro del ciclo de sync)
                //   amarillo · <1 h   (aceptable, próximo tick lo actualizará)
                //   rojo   · >1 h    (algo se atascó · revisar cola/logs)
                //   gris   · nunca   (producto nuevo aún sin sync)
                TextColumn::make('siigo_sync_at')
                    ->label('Última sync')
                    ->since()
                    ->placeholder('nunca')
                    ->badge()
                    ->color(function ($state) {
                        if (! $state) return 'gray';
                        $mins = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($state));
                        if ($mins < 15) return 'success';
                        if ($mins < 60) return 'warning';
                        return 'danger';
                    })
                    ->toggleable(),
                IconColumn::make('activo')->boolean(),
                IconColumn::make('neto')->boolean()->toggleable(),
                IconColumn::make('requiere_talla')->label('Talla')->boolean()->toggleable(),
            ])
            ->defaultSort('referencia')
            ->filters([
                \Filament\Tables\Filters\TernaryFilter::make('activo'),
                \Filament\Tables\Filters\SelectFilter::make('marca_id')->relationship('marca', 'nombre'),
                \Filament\Tables\Filters\SelectFilter::make('categoria_id')->relationship('categoriaMaestra', 'nombre')->label('Categoría'),
                \Filament\Tables\Filters\TernaryFilter::make('siigo_id')
                    ->label('Sincronizado con SIIGO')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('siigo_id'),
                        false: fn ($q) => $q->whereNull('siigo_id'),
                        blank: fn ($q) => $q,
                    ),
                // C-F3 · filtro por modo desglose_stock
                \Filament\Tables\Filters\TernaryFilter::make('desglose_stock')
                    ->label('Modo')
                    ->trueLabel('Solo granulares (por variante)')
                    ->falseLabel('Solo agregados (stock único)'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // F6 · botón manual «Sincronizar con SIIGO» (respaldo del push auto):
                //   - Decide 'crear' vs 'actualizar' según si el producto tiene siigo_id.
                //   - Usa dispatchManual() → bypasea kill-switch y debounce (es explícito
                //     y humano), pero respeta rate limit + lock del Job.
                //   - Requiere permiso 'productos.editar' (matriz oficial).
                \Filament\Actions\Action::make('sincronizar_siigo')
                    ->label('Sincronizar con SIIGO')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => $record->siigo_id
                        ? 'Actualizar en SIIGO'
                        : 'Crear en SIIGO')
                    ->modalDescription('Se encolará un job para empujar los cambios a SIIGO. Igual respeta el rate limit de la API (100 req/min).')
                    // B3-A5 · llave dedicada (más restrictiva que 'productos'):
                    // solo roles listados en 'productos.push_siigo' pueden gastar
                    // rate limit real hacia SIIGO. `authorize` doble-vía UI+backend.
                    ->visible(fn () => \App\Auth\Permisos::puede(auth()->user(), 'productos.push_siigo'))
                    ->authorize(fn () => \App\Auth\Permisos::puede(auth()->user(), 'productos.push_siigo'))
                    ->action(function ($record): void {
                        $accion = $record->siigo_id ? 'actualizar' : 'crear';
                        \App\Modules\Siigo\Jobs\PushProductoASiigo::dispatchManual($record->id, $accion);
                        \Filament\Notifications\Notification::make()
                            ->title('SIIGO · sync encolado')
                            ->body("Producto «{$record->referencia}» → acción {$accion}. Revisa la bandeja de sincronizaciones para el resultado.")
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('imprimir_etiquetas')
                        ->label('Imprimir etiquetas de variantes')
                        ->icon('heroicon-o-printer')
                        ->color('warning')
                        ->action(fn ($records) => redirect()->to(
                            route('catalogo.etiquetas.lote') . '?' . http_build_query(['productos' => $records->pluck('id')->toArray()])
                        )),
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make()
                        ->exports([
                            \pxlrbt\FilamentExcel\Exports\ExcelExport::make('catalogo')
                                ->fromTable()
                                ->withFilename('catalogo-productos-' . now()->format('Y-m-d')),
                        ]),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'view' => Pages\ViewProducto::route('/{record}'),
            'edit' => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
