<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cruce de la wallet de Dropi con los pedidos del ERP, desde DROPI_WALLET_DESDE
 * (lo anterior se pagó antes y su pago no está importado).
 *
 * Separa lo que es normal de lo que hay que reclamar: un pedido en camino sin
 * pago es normal; uno ENTREGADO hace más de PLAZO_PAGO_DIAS sin pago es un
 * atraso. La pantalla de Discrepancias mezclaba los dos (165 en camino + 1
 * atraso real al 28-sep-2026).
 */
class CruceWalletPedidos
{
    /** Dropi paga al proveedor 1,1 días después de entregar en promedio, 3 como máximo (medido el 28-sep-2026). */
    public const PLAZO_PAGO_DIAS = 3;

    public function desde(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('dropi.web.wallet_desde'), config('app.timezone'))->startOfDay();
    }

    public function resumen(): array
    {
        $pagos = DropiWalletMovimiento::query()->where('tipo', 'pago_guia')->where('fecha', '>=', $this->desde()->toDateString());

        return [
            'desde' => $this->desde(),
            'ultima_sync' => DropiWalletMovimiento::where('dropi_movimiento_id', 'like', 'web:%')->max('created_at'),
            'pagados' => [
                'n' => DropiPedido::where('estado', EstadoPedidoDropi::Pagado)->whereHas('pagoWallet')->count(),
                'monto' => (float) (clone $pagos)->whereNotNull('pedido_id')->sum('monto'),
            ],
            'atrasos' => $this->cifra($this->atrasos()),
            'en_plazo' => $this->cifra($this->entregadosEnPlazo()),
            'en_camino' => $this->cifra($this->enCamino()),
            'devoluciones' => $this->cifra($this->sinPago()->where('estado', EstadoPedidoDropi::DevolucionEnCamino)),
            'sin_pedido' => [
                'n' => (clone $pagos)->whereNull('pedido_id')->count(),
                'monto' => (float) (clone $pagos)->whereNull('pedido_id')->sum('monto'),
            ],
            'diferencias' => [
                'n' => $this->diferencias()->count(),
                'monto' => (float) $this->diferencias()->sum('diferencia'),
            ],
            'recibo_pendiente' => [
                'n' => $this->pagosSinRecibo()->count(),
                'monto' => (float) $this->pagosSinRecibo()->sum('monto'),
            ],
        ];
    }

    /**
     * Pagos de guía enlazados a su pedido, con su estado contable
     * (`estado_contable`): lo que conecta el cruce con la contabilidad.
     */
    public function pagosDePedidos(): Builder
    {
        return DropiWalletMovimiento::query()->conEstadoContable()->with('pedido')
            ->where('tipo', 'pago_guia')->whereNotNull('pedido_id')
            ->where('fecha', '>=', $this->desde()->toDateString());
    }

    /** Pagos de pedidos facturados que no tienen recibo de caja: no han llegado al libro. */
    public function pagosSinRecibo(): Builder
    {
        return DropiWalletMovimiento::query()->estadoContable(DropiWalletMovimiento::CONTABLE_RECIBO_PENDIENTE)
            ->where('fecha', '>=', $this->desde()->toDateString());
    }

    /**
     * @param  Collection<int, DropiWalletMovimiento>  $pagos
     * @return array<int, string> factura_id => número
     */
    public function numerosDeFactura(Collection $pagos): array
    {
        $ids = $pagos->pluck('pedido.factura_venta_id')->filter()->unique()->values();

        return $ids->isEmpty() ? [] : FacturaVenta::whereIn('id', $ids)->pluck('numero', 'id')->all();
    }

    /** Entregados hace más del plazo y sin pago: lo que hay que reclamar a Dropi. */
    public function atrasos(): Builder
    {
        return $this->sinPago()->where('estado', EstadoPedidoDropi::Entregado)
            ->where('entregado_at', '<', now()->subDays(self::PLAZO_PAGO_DIAS));
    }

    /** Entregados hace poco: Dropi todavía está en plazo de pagarlos. */
    public function entregadosEnPlazo(): Builder
    {
        return $this->sinPago()->where('estado', EstadoPedidoDropi::Entregado)
            ->where(fn ($q) => $q->whereNull('entregado_at')->orWhere('entregado_at', '>=', now()->subDays(self::PLAZO_PAGO_DIAS)));
    }

    /** Despachados que Dropi aún no marca como entregados. Normal; se muestran del más viejo al más nuevo. */
    public function enCamino(): Builder
    {
        return $this->sinPago()->where('estado', EstadoPedidoDropi::Despachado);
    }

    /** @return Collection<int, DropiWalletMovimiento> pagos de Dropi cuyo pedido no está en el ERP */
    public function pagosSinPedido(): Collection
    {
        return DropiWalletMovimiento::query()->conEstadoContable()->where('tipo', 'pago_guia')->whereNull('pedido_id')
            ->where('fecha', '>=', $this->desde()->toDateString())
            ->orderByDesc('fecha')->limit(100)->get();
    }

    public function diferencias(): Builder
    {
        return DropiSancion::query()->with('pedido')->whereIn('tipo', ['diferencia_precio', 'sobrepago']);
    }

    /** Pedidos despachados desde el corte sin ningún pago de guía en la wallet. */
    protected function sinPago(): Builder
    {
        return DropiPedido::query()
            ->where('despachado_at', '>=', $this->desde())
            ->whereDoesntHave('pagoWallet');
    }

    /** @return array{n:int, monto:float} */
    protected function cifra(Builder $q): array
    {
        return ['n' => (clone $q)->count(), 'monto' => (float) (clone $q)->sum('monto_esperado_proveedor')];
    }
}
