<?php

namespace App\Modules\Dropi\Filament\Resources\TerceroDropiResource\Pages;

use App\Modules\Dropi\Filament\Resources\TerceroDropiResource;
use App\Modules\Dropi\Services\ImportadorTercerosDropi;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTercerosDropi extends ListRecords
{
    protected static string $resource = TerceroDropiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo cliente o proveedor'),
            // Carga masiva (23-nov-2026): muchos proveedores y clientes de una vez.
            Action::make('importar')->label('Importar desde Excel')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible(fn () => TerceroDropiResource::canCreate())
                ->modalHeading('Importar clientes y proveedores de Dropi')
                ->modalDescription('Una fila por tercero. Si el documento ya existe en el ERP, se agrega a Dropi sin duplicarlo ni cambiarle los datos. Una fila con error no frena las demás.')
                ->modalSubmitActionLabel('Importar')
                ->schema([
                    FileUpload::make('archivo')->label('Archivo (.xlsx)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'])
                        ->disk('local')->directory('imports')->required(),
                ])
                ->action(function (array $data) {
                    $rel = (string) ($data['archivo'] ?? '');
                    $ruta = collect([storage_path('app/private/'.$rel), storage_path('app/'.$rel)])
                        ->first(fn ($p) => $rel !== '' && is_file($p));
                    if (! $ruta) {
                        Notification::make()->title('No se encontró el archivo cargado.')->danger()->send();

                        return;
                    }
                    try {
                        $r = app(ImportadorTercerosDropi::class)->importar($ruta);
                    } finally {
                        @unlink($ruta);
                    }
                    $cuerpo = "Nuevos: {$r['creados']} · Ya existían y se agregaron a Dropi: {$r['compartidos']}.";
                    if ($r['errores']) {
                        $cuerpo .= "\n\nRevisar:\n· ".implode("\n· ", array_slice($r['errores'], 0, 15))
                            .(count($r['errores']) > 15 ? "\n· … y ".(count($r['errores']) - 15).' más.' : '');
                    }
                    Notification::make()->title(($r['creados'] + $r['compartidos']).' tercero(s) importado(s)')
                        ->body($cuerpo)->color($r['errores'] ? 'warning' : 'success')->persistent()->send();
                }),
            Action::make('plantilla')->label('Plantilla')->icon('heroicon-o-arrow-down-tray')->color('gray')->link()
                ->visible(fn () => TerceroDropiResource::canCreate())
                ->action(fn () => response()->streamDownload(
                    fn () => ImportadorTercerosDropi::escribirPlantilla('php://output'),
                    'plantilla-clientes-proveedores-dropi.xlsx',
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                )),
        ];
    }
}
