<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * La guía de un pedido Dropi, pedida desde el ERP al terminar de empacarlo.
 *
 * Por qué al empacar (Samuel y bodega, 23-nov-2026): generar la guía antes deja
 * guías de pedidos que al final no salen (sin stock, cancelados) y hay que
 * anularlas; generarlas todas al cerrar el corte deja cientos de stickers sin
 * saber a qué caja va cada uno. Al confirmar el empaque, el sticker sale para
 * la caja que se acaba de cerrar.
 *
 * Es lo mismo que «Actualizar estatus → GUIA_GENERADA» de la web de Dropi
 * (ActualizarEstatusEnDropi, con su auditoría). Si el pedido ya tiene guía, no
 * pide otra: sólo busca el sticker. El sticker es un PDF público de Dropi cuya
 * ruta sólo trae la lista de pedidos (`guia_urls3`); si Dropi todavía no lo
 * publicó, queda la guía sin sticker y se puede volver a pedir.
 *
 * Detrás de la regla `dropi.guia_al_empacar` (apagada): la guía es real, la
 * transportadora la registra.
 */
class GenerarGuiaDropi
{
    use AsAction;

    public function __construct(protected DropiWebClient $client) {}

    public static function activa(): bool
    {
        return (bool) setting('dropi.guia_al_empacar', false);
    }

    /**
     * Al confirmar el empaque, en cualquiera de las dos estaciones (/app y la
     * del panel): la guía si la regla está prendida, o el sticker si el pedido
     * ya la tenía. Un fallo de Dropi no tumba el empaque: queda para reintentar
     * en «Mis stickers de hoy».
     *
     * @return array{texto: string, sticker_url: ?string, error: bool}|null  null = no aplica
     */
    public static function alEmpacar(int $pedidoId, int $userId): ?array
    {
        $pedido = DropiPedido::find($pedidoId);
        if (! $pedido?->dropi_orden_id || (! $pedido->tieneGuiaReal() && ! self::activa())) {
            return null;
        }

        try {
            $g = self::make()->handle($pedido, $userId);
        } catch (\Throwable $e) {
            Log::warning('dropi.guia.al_empacar_fallo', ['pedido_id' => $pedidoId, 'msg' => $e->getMessage()]);

            return ['texto' => ' ⚠ Sin guía: Dropi respondió «'.mb_substr($e->getMessage(), 0, 160).'». Reintenta abajo.', 'sticker_url' => null, 'error' => true];
        }

        return [
            'texto' => $g['sticker_url']
                ? " 🏷 Guía {$g['guia']}: imprime el sticker."
                : " 🏷 Guía {$g['guia']}: el sticker aún no está, reintenta abajo.",
            'sticker_url' => $g['sticker_url'],
            'error' => false,
        ];
    }

    /**
     * @return array{guia: ?string, transportadora: ?string, sticker_url: ?string, generada: bool}
     */
    public function handle(DropiPedido $pedido, ?int $userId = null): array
    {
        if (! $pedido->dropi_orden_id) {
            throw new \DomainException('Este pedido no tiene número de orden de Dropi.');
        }

        // Un doble clic, o la estación y el reintento del corte a la vez, no
        //   pueden pedir dos guías para el mismo pedido.
        $lock = Cache::lock("dropi:guia:{$pedido->id}", 90);
        if (! $lock->get()) {
            throw new \DomainException('La guía de este pedido ya se está generando.');
        }

        try {
            $pedido->refresh();
            $generada = false;

            if (! $pedido->tieneGuiaReal()) {
                $r = ActualizarEstatusEnDropi::make()->handle($pedido, 'GUIA_GENERADA', null, $userId);
                $generada = true;
                $pedido->refresh();

                // Si volver a traer el pedido falló, la guía igual quedó en
                //   Dropi: se toma la de su respuesta (el sync la confirma).
                $guia = strtoupper(trim((string) ($r['shipping_guide'] ?? '')));
                if (! $pedido->tieneGuiaReal() && $guia !== ''
                    && ! DropiPedido::withTrashed()->where('guia', $guia)->exists()) {
                    $pedido->forceFill(array_filter([
                        'guia' => $guia,
                        'transportadora' => $r['shipping_company'] ?? null,
                    ]))->save();
                }
            }

            if (! $pedido->sticker_path) {
                $this->buscarSticker($pedido);
                $pedido->refresh();
            }

            return [
                'guia' => $pedido->tieneGuiaReal() ? $pedido->guia : null,
                'transportadora' => $pedido->transportadora,
                'sticker_url' => $pedido->stickerUrl(),
                'generada' => $generada,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Las guías de los pedidos ya empacados del corte que quedaron sin guía
     * (Dropi la rechazó o no respondió al empacar).
     *
     * @return array{generadas: int, errores: list<string>}
     */
    public function pendientesDelCorte(DropiCorte $corte, ?int $userId = null): array
    {
        $out = ['generadas' => 0, 'errores' => []];

        foreach (self::sinGuia($corte)->get() as $pedido) {
            try {
                $this->handle($pedido, $userId);
                $out['generadas']++;
            } catch (\Throwable $e) {
                $out['errores'][] = "Orden {$pedido->dropi_orden_id}: {$e->getMessage()}";
                Log::warning('dropi.guia.pendiente_fallo', ['pedido_id' => $pedido->id, 'msg' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** Empacados del corte, de Dropi, todavía con la guía provisional. */
    public static function sinGuia(DropiCorte $corte)
    {
        return $corte->pedidos()
            ->where('estado', EstadoPedidoDropi::Empacado)
            ->whereNotNull('dropi_orden_id')
            ->where('guia', 'like', \App\Modules\Dropi\Services\MapearPedidoDropiWeb::PREFIJO_SIN_GUIA . '%');
    }

    private function buscarSticker(DropiPedido $pedido): void
    {
        try {
            $lista = $this->client->pedidosCrudos(['textToSearch' => (string) $pedido->dropi_orden_id], 0, 5)['objects'];
            SincronizarDropiWeb::guardarStickers(array_values(array_filter(
                $lista,
                fn ($o) => (string) ($o['id'] ?? '') === (string) $pedido->dropi_orden_id,
            )));
        } catch (\Throwable $e) {
            // Sin sticker todavía: la guía ya quedó, se puede volver a pedir.
            Log::warning('dropi.guia.sticker_no_disponible', ['pedido_id' => $pedido->id, 'msg' => $e->getMessage()]);
        }
    }
}
