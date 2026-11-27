<?php

namespace App\Modules\Dropi\Filament\Resources\DropiPedidoResource\Pages;

use App\Modules\Dropi\Actions\SincronizarDropiWeb;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Support\EstadoEnDropi;
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

    /**
     * La etapa del ERP (pagado, devuelto, pendiente por inventario…) con que
     * abren esta tabla los enlaces de Reportes y del tablero. Desde el
     * 25-nov-2026 la tabla sólo muestra el estado de Dropi, así que esto no es
     * un filtro de la barra: llega por la URL (`?etapa=pagado`) y se quita arriba.
     */
    #[\Livewire\Attributes\Url]
    public ?string $etapa = null;

    public static function urlEtapa(\App\Modules\Dropi\Enums\EstadoPedidoDropi $e): string
    {
        return DropiPedidoResource::getUrl('index', ['etapa' => $e->value]);
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->when(\App\Modules\Dropi\Enums\EstadoPedidoDropi::tryFrom((string) $this->etapa), fn (Builder $q, $e) => $q->where('estado', $e));
    }

    public function quitarEtapa(): void
    {
        $this->etapa = null;
        $this->resetTable();
    }

    /**
     * El filtro por etapa que viene de un enlace, con cómo quitarlo. Sin él, el
     * camino del día en una línea (27-nov-2026: «se pierden, no hay opciones
     * claras»), y a quien no genera cortes, adónde ir a trabajar.
     */
    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        if ($e = \App\Modules\Dropi\Enums\EstadoPedidoDropi::tryFrom((string) $this->etapa)) {
            return new \Illuminate\Support\HtmlString(e("Mostrando sólo: {$e->label()} (en el ERP) · ") . '<a href="#" wire:click.prevent="quitarEtapa" style="text-decoration:underline">quitar</a>');
        }

        if (DropiPedidoResource::puedeCambiarEnDropi()) {
            return '1. Revisa «Pendiente»  →  2. «Generar corte de hoy»  →  3. Imprime la hoja y los stickers del reporte  →  4. Resuelve los que piden tu acción.';
        }

        return new \Illuminate\Support\HtmlString(e('Para alistar o empacar, entra a ')
            . '<a href="' . e(\App\Modules\Dropi\Filament\Pages\VistaAlistador::getUrl()) . '" style="text-decoration:underline">Vista del Alistador</a>'
            . e(' o a la ')
            . '<a href="' . e(\App\Modules\Dropi\Filament\Pages\EstacionEmpaque::getUrl()) . '" style="text-decoration:underline">Estación de Empaque</a>.');
    }

    /** Quien genera cortes entra a «Pendiente», que es su trabajo del día. */
    public function getDefaultActiveTab(): string|int|null
    {
        return DropiPedidoResource::puedeCambiarEnDropi() ? 'pendiente' : 'todos';
    }

    /** Los reportes de los cortes de hoy y el avance de las guías, en tarjetas (26-nov-2026). */
    protected function getHeaderWidgets(): array
    {
        return [\App\Modules\Dropi\Filament\Widgets\ReporteGuiasWidget::class];
    }

    protected function getHeaderActions(): array
    {
        // La tabla hace poll cada 5 s, así que la etiqueta se refresca sola.
        return [
            // El trabajo del día, siempre a la vista (27-nov-2026: sólo aparecía al
            //   seleccionar pedidos). Lleva lo pendiente sin corte; para escoger a
            //   mano sigue «Generar corte con los seleccionados» de la tabla.
            Action::make('generarCorteDeHoy')
                ->label('Generar corte de hoy')
                ->icon('heroicon-o-scissors')
                ->color('primary')
                ->visible(fn () => DropiPedidoResource::puedeCambiarEnDropi())
                ->badge(fn () => DropiPedidoResource::corteEnCurso() ? null : (DropiPedidoResource::candidatosCorteDeHoy()->count() ?: null))
                ->tooltip(fn () => ($l = DropiPedidoResource::corteEnCurso()) ? 'Hay un corte generándose: ' . $l->titulo() : 'Todo lo pendiente que aún no está en un corte')
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Generar el ' . DropiPedidoResource::corteDeAhora())
                ->modalDescription(fn () => DropiPedidoResource::descripcionCorte(DropiPedidoResource::candidatosCorteDeHoy()))
                ->modalSubmitActionLabel('Sí, generar el corte')
                ->action(fn () => DropiPedidoResource::ejecutarCorte(DropiPedidoResource::candidatosCorteDeHoy())),
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
        // Los estados de Dropi, como en «Mis pedidos» de la plataforma (25-nov-2026).
        //   Un solo conteo agrupado para todas: la tabla se refresca cada 5 s.
        $conteo = null;
        $porEstado = function () use (&$conteo) {
            return $conteo ??= DropiPedido::query()
                ->join('dropi_web_huellas as h', 'h.dropi_orden_id', '=', 'dropi_pedidos.dropi_orden_id')
                ->selectRaw('h.estatus_dropi as estado, count(*) as n')->groupBy('h.estatus_dropi')
                ->pluck('n', 'estado');
        };

        $tabs = ['todos' => Tab::make('Todos')];
        foreach (EstadoEnDropi::PESTANAS as $clave => [$nombre, $estados]) {
            $tabs[$clave] = Tab::make($nombre)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('huella', fn ($h) => $h->whereIn('estatus_dropi', $estados)))
                ->badge(fn () => (int) collect($estados)->sum(fn ($e) => $porEstado()[$e] ?? 0) ?: null)
                ->badgeColor(EstadoEnDropi::color($estados[0]));
        }

        return $tabs;
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
