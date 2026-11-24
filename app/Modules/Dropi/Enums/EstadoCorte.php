<?php

namespace App\Modules\Dropi\Enums;

/**
 * Estados del corte diario Dropi:
 *  - Abierto    : puede recibir pedidos.
 *  - EnDespacho : «Cerrar corte» (24-nov-2026). Ya no recibe pedidos —los
 *                 nuevos van al siguiente corte— pero sus pedidos se siguen
 *                 recolectando, empacando y despachando. Es la hora de corte de
 *                 la bodega: primero se cierra la entrada y después se empaca.
 *  - Cerrado    : «Finalizar corte»: congelado (hash + manifiesto DIAN +
 *                 remisiones ARI). No se puede editar.
 *
 * P6 · Los estados intermedios que existían antes (alistando/empacando/despachado)
 * nunca se seteaban en el flujo — código muerto que confundía a los widgets. Eliminados.
 * EnDespacho sí lo pone una acción (CortarEntradaCorte).
 */
enum EstadoCorte: string
{
    case Abierto = 'abierto';
    case EnDespacho = 'en_despacho';
    case Cerrado = 'cerrado';

    public function label(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::EnDespacho => 'Cerrado · empacando',
            self::Cerrado => 'Finalizado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierto => 'gray',
            self::EnDespacho => 'warning',
            self::Cerrado => 'success',
        };
    }

    /** Sólo un corte abierto recibe pedidos nuevos. */
    public function recibePedidos(): bool
    {
        return $this === self::Abierto;
    }

    /** Tolera el valor crudo de la columna (string) además del enum. */
    public static function de(self|string|null $estado): ?self
    {
        return $estado instanceof self ? $estado : self::tryFrom((string) $estado);
    }
}
