<?php

namespace App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages;

use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ListDropiPedidos extends ListRecords
{
    protected static string $resource = DropiPedidoResource::class;

    /** Sin una vuelta buena en este tiempo, el botón avisa (el cron corre cada 15 s). */
    public const MINUTOS_SIN_SYNC = 2;

    protected function getHeaderActions(): array
    {
        // La tabla hace poll cada 15 s, así que la etiqueta se refresca sola.
        return [
            Action::make('sincronizar')
                ->label(fn () => self::estadoSync()['label'])
                ->icon(fn () => self::estadoSync()['icon'])
                ->color(fn () => self::estadoSync()['color'])
                ->tooltip(fn () => self::estadoSync()['tooltip'])
                ->action(function () {
                    $key = 'dropi-sync:' . auth()->id();
                    if (RateLimiter::tooManyAttempts($key, 3)) {
                        Notification::make()
                            ->title('Espera un momento')
                            ->body('Ya actualizaste hace poco. Reintenta en ' . RateLimiter::availableIn($key) . ' s.')
                            ->warning()->send();
                        return;
                    }
                    RateLimiter::hit($key, 60);

                    try {
                        $r = app(SincronizarDropiWeb::class)->handle('recientes');
                    } catch (DropiSesionNoDisponible $e) {
                        Notification::make()
                            ->title('Sin conexión con Dropi')
                            ->body($e->getMessage())
                            ->danger()->send();
                        return;
                    } catch (\Throwable $e) {
                        Log::warning('dropi.sync_manual_error', [
                            'msg' => $e->getMessage(),
                            'tipo' => $e::class,
                            'en' => $e->getFile() . ':' . $e->getLine(),
                            'traza' => array_slice(array_map(fn ($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'), $e->getTrace()), 0, 8),
                        ]);
                        Notification::make()
                            ->title('Dropi no respondió')
                            ->body('No se pudo actualizar ahora. Intenta de nuevo en un momento.')
                            ->danger()->send();
                        return;
                    }

                    Notification::make()
                        ->title('Pedidos al día')
                        ->body("Revisados: {$r['vistos']} · Nuevos: {$r['nuevos']} · Actualizados: {$r['actualizados']}")
                        ->success()
                        ->send();
                }),
            // "Crear Pedido" removido: los pedidos entran solo por Excel (ver canCreate()).
        ];
    }

    /**
     * Cómo se ve el botón según la última vuelta del sync.
     *
     * @return array{label: string, icon: string, color: string, tooltip: string}
     */
    public static function estadoSync(): array
    {
        // Sin memo: el mismo request que corre «Actualizar ahora» vuelve a pintar
        //   el botón y debe ver la hora nueva (son dos lecturas de caché).
        $estado = SincronizarDropiWeb::estado();
        $ultima = $estado['ultima'];
        $error = $estado['error'];
        $ayuda = 'Clic para actualizar ahora.';

        if (! $estado['activo']) {
            return [
                'label' => 'Sincronización automática apagada',
                'icon' => 'heroicon-o-pause-circle',
                'color' => 'warning',
                'tooltip' => 'El servidor no está trayendo pedidos solo. ' . $ayuda,
            ];
        }

        if (! $ultima) {
            return [
                'label' => 'Sin sincronizar todavía',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'tooltip' => ($error ? 'Último error: ' . $error['msg'] . '. ' : '') . $ayuda,
            ];
        }

        if ($ultima->lt(now()->subMinutes(self::MINUTOS_SIN_SYNC))) {
            $hora = $ultima->isToday() ? $ultima->format('H:i') : $ultima->format('d/m H:i');

            return [
                'label' => "Sin sincronizar desde {$hora}",
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'tooltip' => ($error ? 'Último error: ' . $error['msg'] . '. ' : '') . $ayuda,
            ];
        }

        return [
            'label' => 'Sincronizado ' . $ultima->locale('es')->diffForHumans(),
            'icon' => 'heroicon-o-check-circle',
            'color' => 'gray',
            'tooltip' => 'Los pedidos se sincronizan solos cada pocos segundos. ' . $ayuda,
        ];
    }
}
