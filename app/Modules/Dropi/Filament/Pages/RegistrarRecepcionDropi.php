<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Compras\Actions\RegistrarRecepcion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use Filament\Forms\Components\Hidden;
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
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

/**
 * «Registrar recepción» de una OC de Dropi: lo que llegó, línea por línea.
 * Confirma con la misma acción que /app (RegistrarRecepcion → RecibirMercancia),
 * así sube el inventario, queda la deuda con el proveedor y sale el asiento.
 */
class RegistrarRecepcionDropi extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'compras-recibir-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.registrar-recepcion-dropi';

    #[Url]
    public ?int $orden = null;

    public ?array $data = [];

    public const CLASIFICACIONES = [
        'apto' => 'Apto (a la venta)',
        'averia' => 'Avería',
        'cuarentena' => 'Cuarentena',
        'revision' => 'En revisión',
        'faltante' => 'Faltante (no llegó)',
    ];

    public static function canAccess(): bool
    {
        return ComprasDropi::puedeRecibir(auth()->user());
    }

    public function getTitle(): string
    {
        return 'Registrar recepción · ' . $this->getOrden()->numero;
    }

    public function getOrden(): OrdenCompra
    {
        return ComprasDropi::orden((int) $this->orden)->load(['items', 'proveedor:id,nombre_completo,razon_social']);
    }

    public function mount(): void
    {
        abort_unless($this->orden, 404);
        $o = $this->getOrden();
        abort_unless(in_array($o->estado, [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Parcial, EstadoOrdenCompra::Enviada], true), 422,
            'Esta orden no está lista para recibir: tiene que estar aprobada.');

        $this->form->fill([
            'items' => $o->items
                ->map(fn ($i) => [
                    'orden_item_id' => $i->id,
                    'descripcion' => $i->descripcion,
                    'pendiente' => max(0, (float) $i->cantidad - (float) $i->cantidad_recibida),
                    'cantidad_recibida' => max(0, (float) $i->cantidad - (float) $i->cantidad_recibida),
                    'clasificacion' => 'apto',
                ])
                ->filter(fn ($i) => $i['pendiente'] > 0)
                ->values()->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Documentos del proveedor')->schema([
                TextInput::make('factura_proveedor')->label('Factura del proveedor')->maxLength(100)
                    ->helperText('Sin factura la deuda no aparece en Cuentas por pagar.'),
                TextInput::make('remision_proveedor')->label('Remisión')->maxLength(100),
                TextInput::make('transportista')->maxLength(100),
                Textarea::make('observaciones')->maxLength(1000)->columnSpanFull(),
            ])->columns(3),

            Section::make('Lo que llegó')->schema([
                Repeater::make('items')->label('')
                    ->schema([
                        Hidden::make('orden_item_id'),
                        TextInput::make('descripcion')->label('Producto')->disabled()->dehydrated(false)->columnSpan(3),
                        TextInput::make('pendiente')->label('Pendiente')->disabled()->dehydrated(false),
                        TextInput::make('cantidad_recibida')->label('Llegó')->numeric()->required()->minValue(0),
                        Select::make('clasificacion')->label('Estado')->options(self::CLASIFICACIONES)->default('apto')->required()->live(),
                        TextInput::make('lote')->maxLength(80),
                        TextInput::make('motivo_clasificacion')->label('Motivo')->maxLength(180)
                            ->required(fn (Get $get) => ! in_array($get('clasificacion'), ['apto', null], true))
                            ->visible(fn (Get $get) => $get('clasificacion') !== 'apto')
                            ->columnSpan(2),
                    ])
                    ->columns(6)
                    ->addable(false)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => $state['descripcion'] ?? null),
            ]),
        ])->statePath('data');
    }

    public function confirmar(): void
    {
        $datos = $this->form->getState();
        $items = collect($datos['items'] ?? [])
            ->filter(fn ($i) => (float) ($i['cantidad_recibida'] ?? 0) > 0)
            ->map(fn ($i) => [
                'orden_item_id' => (int) $i['orden_item_id'],
                'cantidad_recibida' => (float) $i['cantidad_recibida'],
                'lote' => $i['lote'] ?? null,
                'clasificacion' => $i['clasificacion'] ?? 'apto',
                'motivo_clasificacion' => $i['motivo_clasificacion'] ?? null,
            ])->values()->all();

        if ($items === []) {
            Notification::make()->title('No hay nada que recibir')->body('Escriba cuánto llegó en al menos un producto.')->warning()->send();

            return;
        }

        try {
            $rc = RegistrarRecepcion::run([
                'orden_id' => (int) $this->orden,
                'factura_proveedor' => $datos['factura_proveedor'] ?? null,
                'remision_proveedor' => $datos['remision_proveedor'] ?? null,
                'transportista' => $datos['transportista'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'items' => $items,
            ]);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('No se pudo recibir')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title("Recepción {$rc->numero} confirmada")
            ->body('El inventario ya subió.')->success()->send();
        $this->redirect(VerOrdenCompraDropi::getUrl(['orden' => $this->orden]));
    }
}
