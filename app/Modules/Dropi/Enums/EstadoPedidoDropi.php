<?php

namespace App\Modules\Dropi\Enums;

enum EstadoPedidoDropi: string
{
    case Pending = 'pending';
    case PendienteInventario = 'pendiente_inventario';
    case Alistando = 'alistando';
    case Empacado = 'empacado';
    case Despachado = 'despachado';
    case Entregado = 'entregado';
    case DevolucionEnCamino = 'devolucion_en_camino';
    case Devuelto = 'devuelto';
    case Pagado = 'pagado';
    case CanceladoDropi = 'cancelado_dropi';
    case CanceladoGb = 'cancelado_gb';
    /**
     * Arranque del ERP (24-nov-2026): pedidos que Aracely ya trabajó en Dropi
     * con el flujo anterior (guía generada allá) o que Dropi ya no muestra.
     * No entran a ninguna cola del ERP; la sincronización los sigue moviendo
     * (despachado, entregado, cancelado…) cuando Dropi los reporte.
     */
    case GestionadoEnDropi = 'gestionado_en_dropi';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Nuevo',
            self::PendienteInventario => 'Pendiente por inventario',
            self::Alistando => 'Alistando',
            self::Empacado => 'Empacado',
            self::Despachado => 'Despachado',
            self::Entregado => 'Entregado',
            self::DevolucionEnCamino => 'Devolución en camino',
            self::Devuelto => 'Devuelto',
            self::Pagado => 'Pagado',
            self::CanceladoDropi => 'Cancelado por Dropi',
            self::CanceladoGb => 'Cancelado por GB',
            self::GestionadoEnDropi => 'Gestionado en Dropi (flujo anterior)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Alistando, self::Empacado => 'warning',
            self::PendienteInventario => 'danger',
            self::Despachado, self::Entregado, self::Pagado => 'success',
            self::DevolucionEnCamino, self::Devuelto, self::GestionadoEnDropi => 'gray',
            self::CanceladoDropi, self::CanceladoGb => 'danger',
        };
    }

    /**
     * Estados terminales (no admiten más transiciones desde fuente manual).
     */
    public function esTerminal(): bool
    {
        return in_array($this, [
            self::Pagado, self::CanceladoDropi, self::CanceladoGb,
        ], true);
    }

    /**
     * Cuando el pedido pertenece a un corte cerrado, sólo estas transiciones son
     * legales — y sólo desde fuente 'api' o 'sistema' (conciliación auto).
     * Un operador humano NUNCA muta pedidos de corte cerrado.
     *
     * Re-audit DR-δ (FUNC-C2) · agregado Despachado: es la transición operativa
     *   natural post-cierre (pedido Empacado atrapado en corte cerrado necesita
     *   pasar a Despachado cuando físicamente sale). Antes RuntimeException
     *   bloqueaba incluso a la fuente `sistema`.
     */
    public function permitidoEnCorteCerrado(): bool
    {
        return in_array($this, [
            self::Despachado, self::Entregado, self::Pagado, self::DevolucionEnCamino,
            self::Devuelto, self::CanceladoDropi, self::GestionadoEnDropi,
        ], true);
    }

    /**
     * Máquina de estados oficial. `fuente` puede aflojar la regla:
     *  - manual   → sólo las transiciones abajo listadas.
     *  - sistema  → cualquiera (conciliación wallet, jobs internos).
     *  - api      → cualquiera (sync desde Dropi puede saltar estados legítimamente).
     *
     * Devuelve true si es válida; false si es un salto ilegal.
     */
    public function puedeIrA(?self $nuevo, string $fuente = 'manual'): bool
    {
        if ($nuevo === null || $nuevo === $this) {
            return false;
        }

        if ($fuente === 'sistema' || $fuente === 'api') {
            return true; // El sync/conciliación tiene autoridad.
        }

        $transiciones = [
            self::Pending->value => [
                self::PendienteInventario, self::Alistando,
                self::CanceladoDropi, self::CanceladoGb,
            ],
            self::PendienteInventario->value => [
                self::Pending, self::Alistando,
                self::CanceladoDropi, self::CanceladoGb,
            ],
            self::Alistando->value => [
                self::Empacado, self::Pending, // revert por lock expirado
                self::CanceladoGb,
            ],
            self::Empacado->value => [
                self::Despachado, self::Alistando, // rework si detecta error
                self::CanceladoGb,
            ],
            self::Despachado->value => [
                self::Entregado, self::DevolucionEnCamino,
            ],
            self::Entregado->value => [
                self::Pagado, self::DevolucionEnCamino,
            ],
            self::DevolucionEnCamino->value => [
                self::Devuelto,
            ],
            self::Devuelto->value => [],       // terminal manualmente
            self::Pagado->value => [],         // terminal manualmente
            self::CanceladoDropi->value => [],
            self::CanceladoGb->value => [],
            self::GestionadoEnDropi->value => [self::CanceladoGb],
        ];

        return in_array($nuevo, $transiciones[$this->value] ?? [], true);
    }
}
