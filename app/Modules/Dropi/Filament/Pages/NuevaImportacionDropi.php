<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Compras\Actions\CrearImportacion;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * «Nueva importación» de Dropi: abre el contenedor con la misma acción que /app
 * (CrearImportacion), en la línea Dropi. Su liquidación va al libro de Dropi.
 */
class NuevaImportacionDropi extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Nueva importación · Dropi';

    protected static ?string $slug = 'compras-nueva-importacion-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.nueva-importacion-dropi';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ComprasDropi::puedeImportar(auth()->user());
    }

    public function mount(): void
    {
        // Los mismos valores que propone /app.
        $this->form->fill([
            'puerto_destino' => 'Buenaventura',
            'incoterm' => 'FOB',
            'moneda_origen' => 'USD',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contenedor')->schema([
                TextInput::make('contenedor')->placeholder('Ej: MSKU1234567')->maxLength(50),
                TextInput::make('bl_awb')->label('BL / AWB')->placeholder('Ej: BL-45632')->maxLength(50)
                    ->helperText('Bill of Lading (marítimo) o Air Waybill (aéreo).'),
                TextInput::make('proveedor_pais')->label('País proveedor')->placeholder('Ej: China')->maxLength(100),
                TextInput::make('puerto_origen')->label('Puerto de origen')->maxLength(100),
                TextInput::make('puerto_destino')->label('Puerto de destino')->maxLength(100),
                TextInput::make('incoterm')->maxLength(10),
            ])->columns(3),
            Section::make('Moneda y fechas')->schema([
                Select::make('moneda_origen')->label('Moneda')->required()
                    ->options(['USD' => 'USD (dólar)', 'CNY' => 'CNY (yuan)']),
                TextInput::make('tasa_cambio_liquidacion')->label('Tasa de liquidación (pesos por 1)')
                    ->numeric()->minValue(0)
                    ->helperText('La primera orden que se le agregue fija la moneda. Si la tasa está vacía o es de otra moneda, también toma la de la orden.'),
                DatePicker::make('fecha_zarpe')->label('Zarpe'),
                DatePicker::make('eta')->label('ETA'),
            ])->columns(4),
        ])->statePath('data');
    }

    public function crear(): void
    {
        $datos = $this->form->getState();

        $imp = CrearImportacion::run([
            'contenedor' => $datos['contenedor'] ?? null,
            'bl_awb' => $datos['bl_awb'] ?? null,
            'proveedor_pais' => $datos['proveedor_pais'] ?? null,
            'puerto_origen' => $datos['puerto_origen'] ?? null,
            'puerto_destino' => $datos['puerto_destino'] ?? null,
            'incoterm' => $datos['incoterm'] ?? null,
            'moneda_origen' => $datos['moneda_origen'],
            'tasa_cambio_liquidacion' => isset($datos['tasa_cambio_liquidacion']) && $datos['tasa_cambio_liquidacion'] !== ''
                ? (float) $datos['tasa_cambio_liquidacion'] : null,
            'fecha_zarpe' => $datos['fecha_zarpe'] ?? null,
            'eta' => $datos['eta'] ?? null,
        ], LineaNegocio::Dropi);

        Notification::make()->title("Importación {$imp->numero} creada")
            ->body('Agréguele las órdenes de importación aprobadas y sus gastos.')->success()->send();

        $this->redirect(VerImportacionDropi::getUrl(['importacion' => $imp->id]));
    }
}
