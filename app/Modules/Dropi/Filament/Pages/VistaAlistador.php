<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDespachado;
use App\Modules\Dropi\Jobs\NotificarClienteDespachoJob;
use App\Modules\Dropi\Models\DropiAlistadorLock;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiEstadoBitacora;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiRecoleccionMarca;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

/**
 * §5, §6, §11 Diseño Dropi — Vista operativa del alistador.
 * Simplificada, distinta a la vista de gestión de Aracely.
 *
 * Va DESPUÉS de Aracely (25-nov-2026): ella escoge los pedidos y le da
 * «Generar corte»; de ahí sale el reporte (hoja + stickers) y la encargada de
 * bodega trabaja ese corte con la hoja en la mano. Sin un corte generado no hay
 * nada que hacer aquí.
 *
 * Dos modos secuenciales dentro del corte:
 *   RECOLECCIÓN — agrupado por producto, la misma suma de la hoja, para recorrer bodega una vez.
 *   EMPAQUE     — por pedido, la misma cola de la Estación de Empaque, con bloqueo al abrir.
 */
class VistaAlistador extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Vista del Alistador';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Vista del Alistador';

    protected string $view = 'dropi.pages.vista-alistador';

    public string $modo = 'recoleccion'; // recoleccion | empaque

    /** Por cantidad (lo de más unidades primero) o por el recorrido de la bodega (26-nov-2026). */
    public string $ordenRecoleccion = RecoleccionDelCorte::POR_CANTIDAD;

    public ?int $corteId = null;

    // §24 · Solo Alistador/Aracely acceden. SAC ve pedidos pero no esta vista operativa.
    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u ? ($u->esAlistador() || $u->esAracely()) : false;
    }

    public function mount(): void
    {
        // El corte generado más viejo que todavía tiene algo por empacar.
        $this->corteId = app(RecoleccionDelCorte::class)->cortesEnTrabajo()->first()?->id;
    }

    public function escogerCorte(int $corteId): void
    {
        $this->corteId = $this->cortes->contains('id', $corteId) ? $corteId : $this->corteId;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, DropiCorte> */
    public function getCortesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return app(RecoleccionDelCorte::class)->cortesEnTrabajo();
    }

    public function getCorteProperty(): ?DropiCorte
    {
        return $this->corteId ? DropiCorte::find($this->corteId) : null;
    }

    /** Los reportes del corte (hoja + stickers), uno por cada vez que Aracely generó en la franja. */
    public function getReportesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return DropiLoteImpresion::query()->where('corte_id', $this->corteId)->oldest('id')->get();
    }

    /** @return array{total: int, por_empacar: int, empacados: int} */
    public function getCifrasProperty(): array
    {
        if (! $this->corteId) {
            return ['total' => 0, 'por_empacar' => 0, 'empacados' => 0];
        }
        $q = fn () => DropiPedido::query()->where('corte_id', $this->corteId)->whereNotNull('lote_impresion_id');

        return [
            'total' => $q()->count(),
            'por_empacar' => $q()->whereIn('estado', DropiPedido::estadosDespachables())->count(),
            'empacados' => $q()->whereIn('estado', [EstadoPedidoDropi::Empacado, EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado])->count(),
        ];
    }

    public function cambiarModo(string $modo): void
    {
        $this->modo = in_array($modo, ['recoleccion', 'empaque'], true) ? $modo : 'recoleccion';
    }

    /**
     * §5, §6 modo recolección — totales por producto de lo impreso del corte
     * (la hoja del reporte), en el orden del recorrido de la bodega y con el
     * «chulito» de quien ya recogió cada referencia (ver RecoleccionDelCorte).
     */
    public function getRecoleccionProperty(): array
    {
        return $this->corteId ? app(RecoleccionDelCorte::class)->conMarcasImpresos($this->corteId, $this->ordenRecoleccion) : [];
    }

    /** «Por cantidad» o «Por recorrido»: el alistador escoge (la señora Lahida, 26-nov-2026). */
    public function ordenarRecoleccion(string $orden): void
    {
        $this->ordenRecoleccion = RecoleccionDelCorte::ordenValido($orden);
    }

    /**
     * Marca o desmarca una referencia del corte como recogida. La marca guarda
     * el total que pedía el corte en ese momento; es compartida entre tablets.
     */
    public function marcarRecogido(string $sku): void
    {
        if (! static::canAccess() || ! $this->corteId) {
            return;
        }

        $total = collect(app(RecoleccionDelCorte::class)->conMarcasImpresos($this->corteId))->firstWhere('sku', $sku)['total_unidades'] ?? null;
        if ($total === null) {
            Notification::make()->title('Esa referencia ya no está por recoger en este corte.')->warning()->send();

            return;
        }

        $marca = DropiRecoleccionMarca::query()
            ->where('corte_id', $this->corteId)
            ->where('sku_dropi', $sku)
            ->first();

        if ($marca && $marca->cantidad >= $total) {
            $marca->delete();

            return;
        }

        DropiRecoleccionMarca::query()->updateOrCreate(
            ['corte_id' => $this->corteId, 'sku_dropi' => $sku],
            ['cantidad' => $total, 'marcado_por' => auth()->id(), 'marcado_at' => now()],
        );
    }

    /**
     * §6 modo empaque — la misma cola de la Estación de Empaque (lo impreso, en
     * el orden de la pila), sólo la de este corte. No incluye los que están
     * bloqueados por otro alistador (excepto por mí, que puedo continuar los que ya tomé).
     */
    public function getEmpaqueProperty(): Collection
    {
        if (! $this->corteId) {
            return DropiPedido::query()->whereRaw('1=0')->get();
        }

        // Locks vencidos (>10 min) los limpia el scheduler `dropi:liberar-locks`.
        // Aquí solo filtramos los activos, sin escribir en cada render.
        $bloqueadosPorOtros = DropiAlistadorLock::query()
            ->where('alistador_id', '!=', auth()->id())
            ->where('heartbeat_at', '>=', now()->subMinutes(10))
            ->pluck('pedido_id')->all();

        $cola = app(\App\Modules\Dropi\Support\ColaDeEmpaque::class)->delCorte($this->corteId, (int) auth()->id(), 20 + count($bloqueadosPorOtros))
            ->reject(fn (DropiPedido $p) => in_array($p->id, $bloqueadosPorOtros, true))
            ->take(20);

        return new Collection($cola->values()->all());
    }

    /**
     * §6 — tomar un pedido para empacar. Adquiere el lock; si ya está tomado por otro, avisa.
     *
     * Re-audit DR-β/γ (SEG-M1) · usa `transicionar()` en vez de `->update(['estado'=>...])`.
     *   El update directo era NO-OP porque `estado` no está en $fillable, pero
     *   igual escribía la bitácora con la transición mentida → auditoría fantasma.
     *   Ahora la bitácora la escribe el propio Action sólo si la transición pasó.
     */
    public function tomarPedido(int $pedidoId): void
    {
        $userId = auth()->id();

        $lock = DropiAlistadorLock::firstOrCreate(
            ['pedido_id' => $pedidoId],
            ['alistador_id' => $userId],
        );

        if ($lock->alistador_id !== $userId) {
            Notification::make()
                ->title('Pedido ocupado')
                ->body('Otro alistador ya está empacando este pedido.')
                ->warning()->send();

            return;
        }

        $lock->update(['heartbeat_at' => now()]);

        $pedido = DropiPedido::find($pedidoId);
        if ($pedido && $pedido->estado !== EstadoPedidoDropi::Alistando) {
            try {
                $pedido->transicionar(EstadoPedidoDropi::Alistando, 'manual', $userId);
            } catch (\Throwable $e) {
                Notification::make()->title('No se pudo tomar')->body($e->getMessage())->danger()->send();
                return;
            }
        }

        Notification::make()->title('Pedido tomado')->success()->send();
    }

    /**
     * §6 — marcar pedido como empacado y liberar el lock.
     * Solo el alistador dueño del lock puede empacar.
     *
     * Re-audit DR-β (SEG-M1) · vía transicionar().
     */
    public function empacarPedido(int $pedidoId): void
    {
        $userId = auth()->id();

        $miLock = DropiAlistadorLock::where('pedido_id', $pedidoId)
            ->where('alistador_id', $userId)
            ->exists();

        if (! $miLock) {
            Notification::make()
                ->title('No puedes empacar este pedido')
                ->body('El pedido lo tomó otro alistador o el lock ya expiró — vuelve a tomarlo.')
                ->danger()->send();

            return;
        }

        $pedido = DropiPedido::find($pedidoId);
        if (! $pedido) {
            return;
        }

        try {
            $pedido->transicionar(EstadoPedidoDropi::Empacado, 'manual', $userId);
        } catch (\Throwable $e) {
            Notification::make()->title('No se pudo empacar')->body($e->getMessage())->danger()->send();
            return;
        }

        DropiAlistadorLock::where('pedido_id', $pedidoId)->delete();

        Notification::make()->title('Pedido empacado')->success()->send();
    }

    /**
     * Re-audit DR-γ (SEG-C1) · GRAVÍSIMO previo: cualquier Alistador podía
     *   invocar `wire:call="despacharPedido(<id>)"` sobre CUALQUIER pedido,
     *   escribir `despachado_at`, disparar evento y encolar WhatsApp masivo
     *   al cliente final. Ahora:
     *
     *   - Requiere ser dueño del EmpaqueRegistro `en_curso` para ese pedido,
     *     como hace `EscanerController::despachar` en la vía web.
     *   - Transiciona vía `transicionar()` — respeta state-machine y corte
     *     cerrado. Si la transición falla, NO se despacha ni notifica.
     *   - Aracely/Gerencia pueden despachar sin registro (root).
     */
    public function despacharPedido(int $pedidoId): void
    {
        $userId = auth()->id();
        $u = auth()->user();

        $pedido = DropiPedido::find($pedidoId);
        if (! $pedido) {
            return;
        }

        $esRoot = $u && $u->esAracely();
        if (! $esRoot) {
            // Alistador debe haber empacado él este pedido — verificar EmpaqueRegistro suyo.
            $tieneRegistro = \App\Modules\Dropi\Models\EmpaqueRegistro::query()
                ->where('pedido_id', $pedidoId)
                ->where('operario_id', $userId)
                ->exists();
            if (! $tieneRegistro) {
                Notification::make()
                    ->title('No autorizado')
                    ->body('Sólo puedes despachar pedidos que TÚ empacaste.')
                    ->danger()->send();
                return;
            }
        }

        try {
            $pedido->transicionar(
                EstadoPedidoDropi::Despachado,
                'manual',
                $userId,
                extraFields: ['despachado_at' => now()],
            );
        } catch (\Throwable $e) {
            Notification::make()->title('No se pudo despachar')->body($e->getMessage())->danger()->send();
            return;
        }

        // Broadcast + WhatsApp SÓLO tras transición exitosa.
        event(new PedidoDespachado($pedido->fresh()));
        NotificarClienteDespachoJob::dispatch($pedido->id);

        Notification::make()
            ->title('✅ Despachado')
            ->body("Guía {$pedido->guia} · Notificación WhatsApp encolada")
            ->success()
            ->send();
    }
}
