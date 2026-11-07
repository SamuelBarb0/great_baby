<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Actions\ImportarPedidosDropiExcel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Importa el export "Órdenes (una orden por fila)" de Dropi (Mis Pedidos →
 * Acciones) hacia dropi_pedidos. Puente manual mientras se habilita el MCP/API.
 */
class ImportarPedidos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Importar pedidos (Excel)';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Importar pedidos de Dropi (Excel)';

    protected string $view = 'dropi.pages.importar-pedidos';

    public ?array $data = [];

    /** @var array<string,int>|null */
    public ?array $resultado = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('archivo')
                ->label('Archivo de órdenes (.xlsx o .csv)')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/csv', 'application/csv', 'application/octet-stream',
                ])
                ->disk('local')->directory('imports')
                ->required()
                ->helperText('Dropi → Mis Pedidos → Acciones → "Órdenes (una orden por fila)" o "Órdenes con Productos". Se detecta solo; el de Productos trae el detalle de ítems.'),
        ])->statePath('data');
    }

    public function procesar(): void
    {
        $data = $this->form->getState();
        $rel = $data['archivo'] ?? null;
        if (! $rel) {
            Notification::make()->title('Sube un archivo primero.')->danger()->send();
            return;
        }

        $ruta = collect([
            storage_path('app/private/' . $rel),
            storage_path('app/' . $rel),
        ])->first(fn ($p) => file_exists($p));

        if (! $ruta) {
            Notification::make()->title('No se encontró el archivo cargado.')->danger()->send();
            return;
        }

        try {
            $this->resultado = ImportarPedidosDropiExcel::run($ruta);
        } catch (\Throwable $e) {
            Notification::make()->title('Error al importar')->body($e->getMessage())->danger()->send();
            return;
        } finally {
            @unlink($ruta);
        }

        $r = $this->resultado;
        Notification::make()
            ->title('✅ Importación terminada')
            ->body("Nuevos: {$r['nuevos']} · Con cambios: {$r['actualizados']} · Sin cambios: {$r['sin_cambios']} · Pendientes sin guía: {$r['rechazados']}"
                . ($r['errores'] ? " · Errores: {$r['errores']}" : ''))
            ->color($r['errores'] ? 'warning' : 'success')
            ->persistent()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('procesar')
                ->label('Importar pedidos')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Importar pedidos de Dropi')
                ->modalDescription('Se cargan/actualizan los pedidos por guía. Las órdenes sin guía (pendientes) se omiten. ¿Continuar?')
                ->action('procesar'),
        ];
    }
}
