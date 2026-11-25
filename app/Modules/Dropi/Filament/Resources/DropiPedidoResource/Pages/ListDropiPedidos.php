<?php

namespace App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages;

use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Models\DropiPedido;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ListDropiPedidos extends ListRecords
{
    protected static string $resource = DropiPedidoResource::class;

    /** Sin una vuelta buena en este tiempo, el botón avisa (el cron corre cada 15 s). */
    public const MINUTOS_SIN_SYNC = 2;

    /** El avance de «Generar guías» y de los lotes de «Imprimir guías» (se refresca con el sondeo de 15 s de la tabla). */
    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $lineas = [];

        $a = \App\Modules\Dropi\Jobs\GenerarGuiasEnBloqueJob::avance(auth()->id());
        if ($a) {
            $listas = $a['hechas'] + $a['saltadas'] + count($a['errores']);
            $texto = $listas < $a['total']
                ? "Generando guías: {$listas} de {$a['total']}"
                : "Guías generadas: {$a['hechas']} de {$a['total']}";
            if ($a['saltadas']) {
                $texto .= " · {$a['saltadas']} saltadas (no estaban PENDIENTE en Dropi)";
            }
            if ($a['errores']) {
                $texto .= ' · ' . count($a['errores']) . ' con error: ' . implode(' | ', array_slice($a['errores'], 0, 3));
            }
            $lineas[] = e($texto);
        }

        // Los lotes de «Imprimir guías» de hoy: el avance y, listos, sus partes.
        $lotes = \App\Modules\Dropi\Models\DropiLoteImpresion::query()
            ->where('created_at', '>=', now('America/Bogota')->startOfDay())
            ->latest('id')->limit(3)->get();
        foreach ($lotes as $l) {
            $linea = e($l->titulo()) . ': ';
            $linea .= match ($l->estado) {
                \App\Modules\Dropi\Models\DropiLoteImpresion::DESCARGANDO => e("bajando stickers, {$l->descargados} de {$l->total}…"),
                \App\Modules\Dropi\Models\DropiLoteImpresion::ARMANDO => e('armando el PDF, parte ' . (count($l->partes ?? []) + 1) . '…'),
                \App\Modules\Dropi\Models\DropiLoteImpresion::ERROR => e('falló: ' . $l->error),
                default => collect($l->partes ?? [])->keys()->map(fn ($i) => '<a href="' . e(route('dropi.guias.parte', [$l, $i + 1])) . '" target="_blank" rel="noopener" style="text-decoration:underline;font-weight:600">🖨 Parte ' . ($i + 1) . '</a>')->implode(' · ')
                    . ($l->sin_sticker ? e(' · ' . count($l->sin_sticker) . ' sin sticker') : ''),
            };
            $lineas[] = $linea;
        }

        return $lineas ? new \Illuminate\Support\HtmlString(implode('<br>', $lineas)) : null;
    }

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
     * Pestañas por etapa de la bodega (reunión del 19-nov-2026: «quiero ver los
     * pendientes»). «Todos» queda primero y por defecto, como estaba la lista.
     */
    public function getTabs(): array
    {
        $etapa = fn (string $nombre, array $estados) => Tab::make($nombre)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('estado', $estados))
            ->badge(fn () => DropiPedido::query()->whereIn('estado', $estados)->count());

        return [
            'todos' => Tab::make('Todos'),
            'nuevos' => $etapa('Nuevos', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario]),
            'por_empacar' => $etapa('Por empacar', [EstadoPedidoDropi::Alistando]),
            'empacados' => $etapa('Empacados', [EstadoPedidoDropi::Empacado]),
            'despachados' => $etapa('Despachados', [EstadoPedidoDropi::Despachado]),
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
