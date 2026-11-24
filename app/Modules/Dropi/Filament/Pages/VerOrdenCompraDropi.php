<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Compras\Actions\AnularOrdenCompra;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\DuplicarOrdenCompra;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Detalle de una OC de Dropi: líneas, lo recibido y sus recepciones, con los
 * pasos que siguen (aprobar, recibir, duplicar, anular, reenviar a SIIGO).
 * Cada paso usa la misma acción que /app.
 */
class VerOrdenCompraDropi extends Page
{
    protected static ?string $slug = 'compras-oc-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.ver-orden-compra-dropi';

    #[Url]
    public ?int $orden = null;

    public static function canAccess(): bool
    {
        return ComprasDropi::puedeArmar(auth()->user()) || ComprasDropi::puedeRecibir(auth()->user());
    }

    public function mount(): void
    {
        abort_unless($this->orden, 404);
        ComprasDropi::orden($this->orden);
    }

    public function getTitle(): string
    {
        return 'Orden de compra ' . $this->getOrden()->numero;
    }

    public function getOrden(): OrdenCompra
    {
        return ComprasDropi::orden((int) $this->orden)
            ->load(['proveedor:id,nombre_completo,razon_social,numero_documento', 'bodega:id,nombre', 'items',
                'recepciones' => fn ($q) => $q->orderByDesc('id')]);
    }

    /**
     * «Stock en Dropi»: la constancia de lo que cada recepción subió a Dropi
     * (EnviarStockRecepcionADropiJob), con el antes y el después releído allá.
     *
     * @return \Illuminate\Support\Collection<int, \App\Modules\Dropi\Models\DropiCargaProducto>
     */
    public function getStockEnDropi(OrdenCompra $o): \Illuminate\Support\Collection
    {
        return \App\Modules\Dropi\Models\DropiCargaProducto::query()
            ->whereIn('recepcion_id', $o->recepciones->pluck('id'))
            ->with('operaciones')
            ->orderByDesc('id')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        $o = fn () => $this->getOrden();
        $u = auth()->user();

        return [
            Action::make('recibir')->label('Registrar recepción')->icon('heroicon-o-inbox-arrow-down')->color('success')
                ->visible(fn () => ComprasDropi::puedeRecibir($u)
                    && in_array($o()->estado, [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Parcial, EstadoOrdenCompra::Enviada], true))
                ->url(fn () => RegistrarRecepcionDropi::getUrl(['orden' => $this->orden])),

            Action::make('aprobar')->label('Aprobar')->icon('heroicon-o-check-badge')->color('primary')
                ->visible(fn () => ComprasDropi::puedeAprobar($u)
                    && in_array($o()->estado, [EstadoOrdenCompra::Borrador, EstadoOrdenCompra::Enviada], true))
                ->requiresConfirmation()
                ->modalDescription('Aprobar compromete la compra con el proveedor.')
                ->action(function () use ($o) {
                    try {
                        $orden = AprobarOrdenCompra::run($o());
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('No se pudo aprobar')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title("OC {$orden->numero} aprobada")->success()->send();
                }),

            Action::make('duplicar')->label('Duplicar')->icon('heroicon-o-document-duplicate')->color('gray')
                ->visible(fn () => ComprasDropi::puedeArmar($u))
                ->requiresConfirmation()
                ->action(function () use ($o) {
                    $nueva = DuplicarOrdenCompra::run($o());
                    Notification::make()->title("OC {$nueva->numero} creada como copia")->success()->send();
                    $this->redirect(self::getUrl(['orden' => $nueva->id]));
                }),

            Action::make('siigo')->label('Reenviar a SIIGO')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => ComprasDropi::puedeArmar($u) && $o()->recepciones->where('estado', 'confirmada')->isNotEmpty())
                ->requiresConfirmation()
                ->modalDescription('Envía otra vez a SIIGO la última recepción confirmada de esta orden.')
                ->action(function () use ($o) {
                    $rc = $o()->recepciones->where('estado', 'confirmada')->sortByDesc('id')->first();
                    \App\Modules\Siigo\Jobs\PushRecepcionASiigo::dispatchManual($rc->id);
                    Notification::make()->title("Recepción {$rc->numero} encolada para SIIGO")->success()->send();
                }),

            Action::make('anular')->label('Anular')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => ComprasDropi::puedeArmar($u)
                    && ! in_array($o()->estado, [EstadoOrdenCompra::Recibida, EstadoOrdenCompra::Cerrada, EstadoOrdenCompra::Anulada], true))
                ->schema([
                    Textarea::make('motivo')->label('¿Por qué se anula?')->required()->minLength(10)->maxLength(300),
                ])
                ->action(function (array $data) use ($o) {
                    $orden = AnularOrdenCompra::run($o(), $data['motivo']);
                    Notification::make()->title("OC {$orden->numero} anulada")->success()->send();
                }),
        ];
    }
}
