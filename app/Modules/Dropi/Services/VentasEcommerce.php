<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Support\EspacioTrabajo;
use Carbon\CarbonImmutable;

/**
 * Ventas del e-commerce de un mes: lo facturado (eje `linea_negocio = dropi`,
 * el mismo del libro y el balance) y lo que pasó con los pedidos y el wallet
 * de Dropi. Sólo lee. Lo usan la pantalla de /app y la de /admin, para que las
 * dos digan exactamente lo mismo.
 */
class VentasEcommerce
{
    /** @param  string|null  $mes  `YYYY-MM`; vacío o mal escrito = el mes en curso */
    public function delMes(?string $mes): array
    {
        $zona = 'America/Bogota';
        $mes = (string) $mes;
        $inicio = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)
            ? CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01', $zona)->startOfDay()
            : CarbonImmutable::now($zona)->startOfMonth();
        $fin = $inicio->endOfMonth();

        // ---- Facturado (eje contable, sin anuladas) ----
        $facturas = FacturaVenta::query()
            ->where('linea_negocio', LineaNegocio::Dropi->value)
            ->where('estado', '!=', EstadoFactura::Anulada->value)
            ->whereBetween('fecha_emision', [$inicio->toDateString(), $fin->toDateString()]);

        $totales = (clone $facturas)->selectRaw(
            'COUNT(*) as cantidad, COALESCE(SUM(subtotal),0) as subtotal, COALESCE(SUM(impuestos),0) as impuestos, '
            .'COALESCE(SUM(total),0) as total, COALESCE(SUM(saldo),0) as saldo'
        )->first();

        $porDia = (clone $facturas)
            ->selectRaw('fecha_emision as dia, COUNT(*) as cantidad, SUM(total) as total')
            ->groupBy('fecha_emision')->orderBy('fecha_emision')->get()
            ->map(fn ($f) => [
                'dia' => CarbonImmutable::parse($f->dia)->toDateString(),
                'cantidad' => (int) $f->cantidad,
                'total' => (float) $f->total,
            ])->all();

        $ultimas = (clone $facturas)->with('contacto:id,nombre_completo')
            ->orderByDesc('fecha_emision')->orderByDesc('id')->limit(10)->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'numero' => $f->numero,
                'cliente' => $f->contacto?->nombre_completo ?? '—',
                'fecha' => $f->fecha_emision?->toDateString(),
                'total' => (float) $f->total,
                'estado_label' => is_object($f->estado) && method_exists($f->estado, 'label') ? $f->estado->label() : (string) ($f->estado?->value ?? $f->estado),
            ])->all();

        // ---- Pedidos Dropi del mes, por la fecha de cada evento ----
        $entre = fn (string $col) => DropiPedido::whereBetween($col, [$inicio, $fin]);

        $pedidos = [
            'recibidos' => $entre('dropi_creado_at')->count(),
            'despachados' => $entre('despachado_at')->count(),
            'entregados' => $entre('entregado_at')->count(),
            'devueltos' => $entre('devuelto_at')->count(),
            'pagados' => $entre('pagado_at')->where('estado', EstadoPedidoDropi::Pagado)->count(),
            'pagado_monto' => (float) $entre('pagado_at')->where('estado', EstadoPedidoDropi::Pagado)->sum('monto_esperado_proveedor'),
            // Por la fecha de devolución, igual que el conteo: un pedido devuelto
            // que después cambió de estado sigue siendo una devolución del mes.
            // Con el filtro de estado salían «174 devueltos · $0» con datos reales.
            'devuelto_monto' => (float) $entre('devuelto_at')->sum('monto_esperado_proveedor'),
        ];

        /*
         * Saldo del wallet HOY, no del mes: es lo que hay para retirar.
         * Misma cuenta que la pantalla del wallet (DropiGestionController::walletIndex):
         * sólo se restan las sanciones que no pasaron por el wallet.
         */
        $saldoWallet = (float) DropiWalletMovimiento::sum('monto')
            - (float) DropiSancion::whereIn('tipo', ['pago_sobre_devuelto', 'categoria_explicita'])->sum('diferencia');

        $retiros = abs((float) DropiWalletMovimiento::where('tipo', 'retiro_banco')
            ->whereBetween('fecha', [$inicio, $fin])->sum('monto'));

        $empresa = EspacioTrabajo::empresaEcommerce();

        return [
            'periodo' => [
                'mes' => $inicio->format('Y-m'),
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
            ],
            'empresa' => $empresa ? [
                'nombre' => $empresa->nombreCorto(),
                'nit' => $empresa->nit,
                'activa' => (bool) $empresa->activa,
            ] : null,
            'facturado' => [
                'cantidad' => (int) ($totales->cantidad ?? 0),
                'subtotal' => (float) ($totales->subtotal ?? 0),
                'impuestos' => (float) ($totales->impuestos ?? 0),
                'total' => (float) ($totales->total ?? 0),
                'saldo' => (float) ($totales->saldo ?? 0),
            ],
            'por_dia' => $porDia,
            'ultimas' => $ultimas,
            'pedidos' => $pedidos,
            'wallet' => ['saldo' => $saldoWallet, 'retiros_mes' => $retiros],
        ];
    }
}
