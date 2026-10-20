<?php

namespace App\Modules\Dropi\Concerns;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDropiTransicionado;
use App\Modules\Dropi\Models\DropiEstadoBitacora;
use App\Modules\Dropi\Support\Trazabilidad;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * State machine central de DropiPedido.
 *
 * Usarlo SIEMPRE que se cambie el estado — jamás $pedido->update(['estado' => ...])
 * directo. El trait:
 *   1) toma lock del pedido,
 *   2) valida la transición según EstadoPedidoDropi::puedeIrA($nuevo, $fuente),
 *   3) bloquea si el corte está cerrado y la fuente es 'manual',
 *   4) escribe DropiEstadoBitacora obligatoria,
 *   5) dispatcha PedidoDropiTransicionado para los side-effects (inventario,
 *      contabilidad, notificaciones) — nunca reimplementar side-effects fuera de
 *      un listener del evento.
 *
 * $fuente ∈ {manual, sistema, api}
 */
trait HasEstadoDropi
{
    public function transicionar(
        EstadoPedidoDropi $nuevo,
        string $fuente = 'manual',
        ?int $userId = null,
        array $payload = [],
        array $extraFields = [],
    ): self {
        return DB::transaction(function () use ($nuevo, $fuente, $userId, $payload, $extraFields) {
            /** @var \App\Modules\Dropi\Models\DropiPedido $pedido */
            $pedido = static::query()->lockForUpdate()->findOrFail($this->getKey());

            $actual = $pedido->estado instanceof EstadoPedidoDropi
                ? $pedido->estado
                : ($pedido->estado ? EstadoPedidoDropi::from((string) $pedido->estado) : null);

            if ($actual === $nuevo) {
                return $pedido; // no-op idempotente
            }

            // Guard corte cerrado — sólo sistema/api pueden mutar y sólo a estados legítimos.
            $corte = $pedido->corte;
            $corteCerrado = $corte && (
                ($corte->estado instanceof EstadoCorte && $corte->estado === EstadoCorte::Cerrado)
                || (is_string($corte->estado) && $corte->estado === EstadoCorte::Cerrado->value)
            );

            if ($corteCerrado) {
                if ($fuente === 'manual') {
                    throw new RuntimeException(
                        "Pedido {$pedido->guia}: no se puede mutar — el corte {$corte->numero} está cerrado."
                    );
                }
                if (! $nuevo->permitidoEnCorteCerrado()) {
                    throw new RuntimeException(
                        "Pedido {$pedido->guia}: transición a {$nuevo->value} bloqueada en corte cerrado."
                    );
                }
            }

            // Validar máquina de estados.
            if ($actual && ! $actual->puedeIrA($nuevo, $fuente)) {
                throw new RuntimeException(
                    "Pedido {$pedido->guia}: transición inválida {$actual->value} → {$nuevo->value} (fuente={$fuente})."
                );
            }

            // Aplicar.
            $pedido->estado = $nuevo;
            foreach ($extraFields as $k => $v) {
                $pedido->{$k} = $v;
            }
            $pedido->save();

            // Quién ya estaba; desde dónde (GPS) y con qué equipo (IP,
            // navegador) son las capas de seguridad del empaque/despacho.
            // El GPS va en columnas propias, no duplicado en el payload.
            DropiEstadoBitacora::create([
                'pedido_id' => $pedido->id,
                'estado_desde' => $actual?->value,
                'estado_hasta' => $nuevo->value,
                'fuente' => $fuente,
                'payload' => array_diff_key($payload, ['gps' => true]) ?: null,
                'user_id' => $userId,
            ] + Trazabilidad::columnas($payload, $fuente));

            // Side-effects van por listener del evento.
            PedidoDropiTransicionado::dispatch(
                $pedido->fresh(),
                $actual,
                $nuevo,
                $fuente,
                $userId,
                $payload,
            );

            // Refrescar la instancia actual para que quien llamó vea el cambio.
            $this->refresh();

            return $pedido;
        });
    }
}
