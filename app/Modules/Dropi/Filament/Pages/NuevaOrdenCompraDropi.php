<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\Contacto;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\RegistrarProveedor;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * «Nueva OC» de Dropi. Arma la orden con la misma acción que /app
 * (CrearOrdenCompra) y la deja en la línea Dropi: así la recepción, la deuda
 * con el proveedor y el asiento contable salen como de Dropi.
 *
 * Nacional en COP o de importación en moneda extranjera (8-nov): la de
 * importación se mete después en un contenedor de Dropi para liquidarla.
 */
class NuevaOrdenCompraDropi extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'Nueva OC';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Nueva orden de compra · Dropi';

    protected static ?string $slug = 'compras-nueva-oc-dropi';

    protected string $view = 'dropi.pages.nueva-orden-compra-dropi';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ComprasDropi::puedeArmar(auth()->user());
    }

    public function mount(): void
    {
        $this->form->fill([
            'tipo' => 'nacional',
            'moneda' => 'COP',
            'tasa_cambio' => 1,
            'modelo_compra' => 'trading',
            'bodega_id' => ComprasDropi::bodegaPorDefecto(),
            'items' => [['cantidad' => 1, 'descuento_pct' => 0, 'iva_pct' => 19]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos de la orden')->schema([
                Select::make('proveedor_id')->label('Proveedor')->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Contacto::query()->deDropi()
                        ->where('es_proveedor', true)->where('activo', true)
                        ->where(fn ($q) => $q->where('nombre_completo', 'like', "%{$search}%")
                            ->orWhere('razon_social', 'like', "%{$search}%")
                            ->orWhere('numero_documento', 'like', "%{$search}%"))
                        ->orderBy('nombre_completo')->limit(30)->get()
                        ->mapWithKeys(fn (Contacto $c) => [$c->id => self::nombreProveedor($c)])->all())
                    ->getOptionLabelUsing(fn ($value) => ($c = Contacto::find($value)) ? self::nombreProveedor($c) : null)
                    ->createOptionForm([
                        TextInput::make('nombre')->label('Nombre o razón social')->required()->maxLength(180),
                        Select::make('tipo_documento')->options(['NIT' => 'NIT', 'CC' => 'CC', 'CE' => 'CE', 'PP' => 'Pasaporte'])
                            ->default('NIT')->required(),
                        TextInput::make('numero_documento')->label('Número de documento')->required()->maxLength(30),
                        TextInput::make('email')->email()->maxLength(120),
                        TextInput::make('telefono')->maxLength(30),
                    ])
                    ->createOptionUsing(fn (array $data) => \App\Modules\Dropi\Actions\RegistrarTerceroDropi::run($data + ['es_proveedor' => true])['contacto']->id),
                Select::make('bodega_id')->label('Bodega a donde llega')->required()
                    ->options(fn () => ComprasDropi::bodegas())->searchable(),
                DatePicker::make('fecha_esperada')->label('Fecha esperada'),
                Select::make('tipo')->label('Tipo de compra')->required()
                    ->options(['nacional' => 'Nacional', 'importacion' => 'Importación'])
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        // Como en /app: la importación se paga en otra moneda y su
                        // IVA se liquida en aduana, no en la línea.
                        if ($state === 'importacion') {
                            if ($get('moneda') === 'COP') {
                                $set('moneda', 'CNY');
                                $set('tasa_cambio', self::ultimaTasa('CNY'));
                            }
                            $set('items', collect($get('items') ?? [])->map(fn ($i) => [...$i, 'iva_pct' => 0])->all());
                        } else {
                            $set('moneda', 'COP');
                            $set('tasa_cambio', 1);
                        }
                    }),
                Select::make('moneda')->required()
                    ->options(['CNY' => 'CNY (yuan)', 'USD' => 'USD (dólar)'])
                    ->visible(fn (Get $get) => $get('tipo') === 'importacion')
                    ->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('tasa_cambio', self::ultimaTasa((string) $state))),
                TextInput::make('tasa_cambio')->label(fn (Get $get) => 'Pesos por 1 ' . $get('moneda'))
                    ->numeric()->required()
                    // Ninguna moneda extranjera vale un peso: con tasa 1, 3,50 RMB saldrían a 3,50 pesos.
                    ->rule(fn (Get $get) => $get('tipo') === 'importacion' ? 'gt:1' : 'gt:0')
                    ->visible(fn (Get $get) => $get('tipo') === 'importacion'),
                Select::make('modelo_compra')->label('Modelo de compra')
                    ->options(['trading' => 'Trading', 'fabrica' => 'Fábrica'])
                    ->visible(fn (Get $get) => $get('tipo') === 'importacion'),
                Textarea::make('observaciones')->maxLength(1000)->columnSpanFull(),
            ])->columns(3),

            Section::make('Productos')->schema([
                Repeater::make('items')->label('')
                    ->schema([
                        Select::make('producto_id')->label('Producto')->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Producto::query()->where('activo', true)
                                ->where(fn ($q) => $q->where('nombre', 'like', "%{$search}%")->orWhere('referencia', 'like', "%{$search}%"))
                                ->orderBy('nombre')->limit(30)->get()
                                ->mapWithKeys(fn (Producto $p) => [$p->id => "{$p->referencia} · {$p->nombre}"])->all())
                            ->getOptionLabelUsing(fn ($value) => ($p = Producto::find($value)) ? "{$p->referencia} · {$p->nombre}" : null)
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                $set('variante_id', null);
                                $p = $state ? Producto::find($state) : null;
                                if ($p) {
                                    $set('descripcion', $p->nombre);
                                    if ((float) $p->precio_proveedor > 0) {
                                        $set('precio_unit', (float) $p->precio_proveedor);
                                    }
                                }
                            })
                            ->columnSpan(3),
                        Select::make('variante_id')->label('Variante')
                            ->options(fn (Get $get) => $get('producto_id')
                                ? ProductoVariante::where('producto_id', $get('producto_id'))->get()
                                    ->mapWithKeys(fn (ProductoVariante $v) => [$v->id => self::nombreVariante($v)])->all()
                                : [])
                            ->visible(fn (Get $get) => $get('producto_id')
                                && ProductoVariante::where('producto_id', $get('producto_id'))->exists())
                            ->columnSpan(2),
                        TextInput::make('descripcion')->label('Descripción')->required()->maxLength(200)->columnSpan(3),
                        TextInput::make('cantidad')->numeric()->required()->minValue(0.001)->default(1),
                        TextInput::make('precio_unit')->label(fn (Get $get) => 'Costo unitario' . ($get('../../tipo') === 'importacion' ? ' (' . $get('../../moneda') . ')' : ''))
                            ->numeric()->required()->minValue(0)
                            ->prefix(fn (Get $get) => $get('../../tipo') === 'importacion' ? null : '$'),
                        TextInput::make('descuento_pct')->label('Desc.')->numeric()->default(0)->minValue(0)->maxValue(100)->suffix('%'),
                        TextInput::make('iva_pct')->label('IVA')->numeric()->default(19)->minValue(0)->maxValue(100)->suffix('%'),
                        // Las medidas de la caja reparten el flete por volumen o peso al liquidar.
                        TextInput::make('cajas')->numeric()->integer()->minValue(1)
                            ->visible(fn (Get $get) => $get('../../tipo') === 'importacion'),
                        TextInput::make('unidades_por_caja')->label('Unid./caja')->numeric()->integer()->minValue(1)
                            ->requiredWith('cajas')
                            ->visible(fn (Get $get) => $get('../../tipo') === 'importacion'),
                        TextInput::make('cbm_caja')->label('m³/caja')->numeric()->gt(0)
                            ->visible(fn (Get $get) => $get('../../tipo') === 'importacion'),
                        TextInput::make('peso_caja_kg')->label('kg/caja')->numeric()->gt(0)
                            ->visible(fn (Get $get) => $get('../../tipo') === 'importacion'),
                    ])
                    ->columns(6)
                    ->minItems(1)
                    ->addActionLabel('Agregar producto')
                    ->itemLabel(fn (array $state): ?string => trim(($state['descripcion'] ?? '') . ' × ' . ($state['cantidad'] ?? '')) ?: null),
            ]),
        ])->statePath('data');
    }

    public function crear(): void
    {
        $datos = $this->form->getState();

        $esImportacion = ($datos['tipo'] ?? 'nacional') === 'importacion';

        $orden = CrearOrdenCompra::run([
            'proveedor_id' => (int) $datos['proveedor_id'],
            'bodega_id' => (int) $datos['bodega_id'],
            'tipo' => $esImportacion ? 'importacion' : 'nacional',
            'moneda' => $esImportacion ? $datos['moneda'] : 'COP',
            'tasa_cambio' => $esImportacion ? (float) $datos['tasa_cambio'] : 1,
            ...($esImportacion ? ['modelo_compra' => $datos['modelo_compra'] ?? 'trading'] : []),
            'fecha_esperada' => $datos['fecha_esperada'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            'linea_negocio' => LineaNegocio::Dropi,
            'items' => collect($datos['items'])->map(fn (array $it) => [
                'producto_id' => (int) $it['producto_id'],
                'variante_id' => ! empty($it['variante_id']) ? (int) $it['variante_id'] : null,
                'descripcion' => $it['descripcion'],
                'cantidad' => (float) $it['cantidad'],
                'precio_unit' => (float) $it['precio_unit'],
                'descuento_pct' => (float) ($it['descuento_pct'] ?? 0),
                'iva_pct' => (float) ($it['iva_pct'] ?? 19),
                ...($esImportacion ? [
                    'cajas' => ! empty($it['cajas']) ? (int) $it['cajas'] : null,
                    'unidades_por_caja' => ! empty($it['unidades_por_caja']) ? (int) $it['unidades_por_caja'] : null,
                    'cbm_caja' => ! empty($it['cbm_caja']) ? (float) $it['cbm_caja'] : null,
                    'peso_caja_kg' => ! empty($it['peso_caja_kg']) ? (float) $it['peso_caja_kg'] : null,
                ] : []),
            ])->values()->all(),
        ]);

        Notification::make()->title("OC {$orden->numero} creada")
            ->body('Queda en borrador hasta que la aprueben.')->success()->send();

        $this->redirect(VerOrdenCompraDropi::getUrl(['orden' => $orden->id]));
    }

    /** La tasa de la última OC de importación en esa moneda, como propone /app (nunca la de otra moneda). */
    public static function ultimaTasa(string $moneda): ?float
    {
        $tasa = \App\Modules\Compras\Models\OrdenCompra::query()
            ->where('tipo', 'importacion')->where('moneda', $moneda)->where('tasa_cambio', '>', 1)
            ->orderByDesc('id')->value('tasa_cambio');

        return $tasa !== null ? (float) $tasa : null;
    }

    public static function nombreProveedor(Contacto $c): string
    {
        return ($c->razon_social ?: $c->nombre_completo) . ($c->numero_documento ? " · {$c->numero_documento}" : '');
    }

    public static function nombreVariante(ProductoVariante $v): string
    {
        $partes = array_filter([$v->color_nombre, $v->diseno_nombre, $v->talla]);

        return ($partes ? implode(' / ', $partes) : 'Variante') . ($v->codigo_barras ? " · {$v->codigo_barras}" : '');
    }
}
