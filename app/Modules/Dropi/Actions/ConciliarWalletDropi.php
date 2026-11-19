<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiClientInterface;
use App\Modules\Dropi\DTOs\PagoWalletDTO;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * §15 Diseño Dropi — Conciliación en 2 capas:
 *   Capa 1: guía ↔ wallet (match automático).
 *   Capa 2: wallet ↔ banco → NO se compara línea por línea (§17). Solo retiros consolidados.
 *
 * Comparamos lo recibido contra monto_esperado_proveedor del catálogo GB.
 * Si hay diferencia y viene sin categoría → registra sanción presunta (§19).
 * Si viene como "indemnizacion" → sanción confirmada por categoría explícita.
 */
class ConciliarWalletDropi
{
    use AsAction;

    /** @var array<int, true> pedidos con pago de guía en esta corrida */
    protected array $pedidosPagados = [];

    public function __construct(protected DropiClientInterface $client) {}

    /**
     * @return array{procesados:int, pagos_matcheados:int, sanciones_detectadas:int, gastos_registrados:int, errores:int}
     */
    public function handle(?CarbonImmutable $desde = null): array
    {
        $desde ??= CarbonImmutable::now()->subMonth();

        return $this->conciliar($this->client->walletDesde($desde));
    }

    /**
     * Concilia movimientos que ya se tienen en mano. Además del historial
     * completo (arriba), lo usa el sync de pedidos con los movimientos que trae
     * el detalle de cada pedido (`history_wallet`) por la API oficial, que no
     * expone la wallet completa. Mismos ids `web:{id}`: un movimiento que llegue
     * por las dos vías se guarda una sola vez.
     *
     * @param  iterable<PagoWalletDTO>  $movimientos
     * @return array{procesados:int, pagos_matcheados:int, sanciones_detectadas:int, gastos_registrados:int, errores:int}
     */
    public function conciliar(iterable $movimientos): array
    {
        $movimientos = collect($movimientos);
        $this->pedidosPagados = [];

        $pagos = 0;
        $sanciones = 0;
        $gastos = 0;

        $errores = 0;
        foreach ($movimientos as $mov) {
            // Un movimiento que falla (p. ej. un pedido que no puede cambiar de
            // estado) se anota y se sigue: antes abortaba el resto de la corrida.
            try {
                $resultado = DB::transaction(fn () => $this->procesar($mov));
            } catch (\Throwable $e) {
                $errores++;
                \Illuminate\Support\Facades\Log::error('dropi.wallet.movimiento_error', [
                    'movimiento' => $mov->dropiMovimientoId, 'orden' => $mov->ordenId, 'msg' => $e->getMessage(),
                ]);

                continue;
            }

            match ($resultado) {
                'pago' => $pagos++,
                'sancion' => $sanciones++,
                'gasto' => $gastos++,
                default => null,
            };
        }

        // Dropi parte el pago de una orden en varias líneas (una por producto).
        // La diferencia contra lo esperado se mide con la SUMA, al final: línea
        // por línea, la primera parecía un subpago y abría una sanción falsa.
        foreach (array_keys($this->pedidosPagados) as $pedidoId) {
            if (DB::transaction(fn () => $this->evaluarDiferencia($pedidoId))) {
                $sanciones++;
            }
        }

        return [
            'procesados' => $movimientos->count(),
            'pagos_matcheados' => $pagos,
            'sanciones_detectadas' => $sanciones,
            'gastos_registrados' => $gastos,
            'errores' => $errores,
        ];
    }

    protected function procesar(PagoWalletDTO $mov): string
    {
        // P7 · Idempotencia por ID único. Si el DTO no trae uno, sintetizar
        // determinístico para que la misma fila no se duplique en re-corridas.
        $movId = $mov->dropiMovimientoId ?: ('synth:' . hash('sha256', implode('|', [
            $mov->fecha->toDateString(), $mov->tipo, (string) $mov->monto,
            (string) ($mov->guia ?? ''), (string) ($mov->categoria ?? ''),
        ])));

        if (DropiWalletMovimiento::where('dropi_movimiento_id', $movId)->exists()) {
            return 'duplicado';
        }

        $pedido = $this->buscarPedido($mov->ordenId, $mov->guia);

        DropiWalletMovimiento::create([
            'dropi_movimiento_id' => $movId,
            'fecha' => $mov->fecha->toDateString(),
            'tipo' => $mov->tipo,
            'monto' => $mov->monto,
            'pedido_id' => $pedido?->id,
            'categoria' => $mov->categoria,
            'fuente' => $mov->raw,
        ]);

        return $this->aplicar($pedido, $mov->tipo, (float) $mov->monto, $mov->fecha, $movId);
    }

    /**
     * Movimiento ya guardado sin pedido (la wallet llegó antes que el pedido):
     * lo enlaza si el pedido ya existe y le aplica la misma conciliación. Lo usa
     * `dropi:reconciliar-huerfanos`.
     *
     * @return string|null resultado de aplicar(), o null si el pedido aún no está
     */
    public function reconciliarHuerfano(DropiWalletMovimiento $mov): ?string
    {
        $fuente = is_array($mov->fuente) ? $mov->fuente : [];
        preg_match('/GUIA:\s*\*?([A-Z0-9-]+)\*?/i', (string) ($fuente['description'] ?? ''), $enTexto);

        $pedido = $this->buscarPedido(
            isset($fuente['order_id']) ? (string) $fuente['order_id'] : null,
            $fuente['guia'] ?? ($enTexto[1] ?? null),
        );
        if (! $pedido) {
            return null;
        }

        $mov->update(['pedido_id' => $pedido->id]);
        $tipo = $mov->tipo instanceof \App\Modules\Dropi\Enums\TipoMovimientoWallet ? $mov->tipo->value : (string) $mov->tipo;
        $resultado = $this->aplicar($pedido, $tipo, (float) $mov->monto, CarbonImmutable::parse($mov->fecha), $mov->dropi_movimiento_id);

        if ($tipo === 'pago_guia') {
            $this->evaluarDiferencia($pedido->id);
        }

        return $resultado;
    }

    /**
     * 9.3 · El pago de Dropi cierra la factura del pedido: un recibo de caja
     * (PagoVenta, medio `wallet_dropi`) aplicado a ella. Su asiento es wallet
     * 111015 contra 1305, el mismo que antes hacía el movimiento de wallet; por
     * eso RegistrarAsientoWalletDropi ya no asienta los pagos de pedidos
     * facturados (si no, el 1305 se acreditaba dos veces). El observer del
     * pago baja el saldo de la factura y manda el recibo a SIIGO.
     */
    protected function registrarCobro(DropiPedido $pedido, float $monto, CarbonImmutable $fecha, ?string $movId): void
    {
        if (! $pedido->factura_venta_id) {
            return;
        }
        $factura = \App\Modules\Cartera\Models\FacturaVenta::find($pedido->factura_venta_id);
        if (! $factura) {
            return;
        }
        if ($movId && \App\Modules\Cartera\Models\PagoVenta::where('referencia', $movId)->exists()) {
            return;
        }

        $aplicado = min($monto, max(0, (float) $factura->saldo));
        \App\Modules\Cartera\Models\PagoVenta::create([
            'factura_id' => $factura->id,
            'contacto_id' => $factura->contacto_id,
            'fecha' => $fecha->toDateString(),
            'monto_recibido' => $monto,
            'monto_aplicado' => $aplicado,
            'diferencia' => round($aplicado - $monto, 2),
            'medio_pago' => 'wallet_dropi',
            'referencia' => $movId ? mb_substr($movId, 0, 60) : null,
            'banco' => 'Wallet Dropi',
            'notas' => "Pago Dropi guía {$pedido->guia}",
        ]);
    }

    /** Un reverso 1009 de Dropi deshace el recibo: la factura vuelve a quedar por cobrar. */
    protected function reversarCobro(DropiPedido $pedido, float $monto): void
    {
        if (! $pedido->factura_venta_id) {
            return;
        }
        $pago = \App\Modules\Cartera\Models\PagoVenta::where('factura_id', $pedido->factura_venta_id)
            ->where('medio_pago', 'wallet_dropi')
            ->orderByRaw('ABS(monto_recibido - ?) ASC', [$monto])
            ->orderByDesc('id')
            ->first();
        $pago?->delete();
    }

    /** Primero por la orden de Dropi (la wallet real la trae en cada movimiento); la guía, de respaldo. */
    protected function buscarPedido(?string $ordenId, ?string $guia): ?DropiPedido
    {
        $guia = $guia ? strtoupper(trim($guia)) : null;

        return ($ordenId ? DropiPedido::where('dropi_orden_id', $ordenId)->first() : null)
            ?? ($guia ? DropiPedido::where('guia', $guia)->first() : null);
    }

    protected function aplicar(?DropiPedido $pedido, string $tipo, float $monto, CarbonImmutable $fecha, ?string $movId = null): string
    {
        // §15 Capa 1: pago de guía → matchear + marcar pedido pagado
        if ($tipo === 'pago_guia' && $pedido) {
            // Un reverso (Dropi corrigió el estado de la guía) llega en negativo:
            // ya quedó guardado y resta en la suma del pedido; no mueve estados.
            if ($monto <= 0) {
                $this->pedidosPagados[$pedido->id] = true;
                $this->reversarCobro($pedido, abs($monto));

                return 'pago';
            }

            // A6 · si el pedido fue devuelto o cancelado, NO se marca Pagado.
            // En su lugar se registra sanción tipo pago_sobre_devuelto.
            if (in_array($pedido->estado, [
                EstadoPedidoDropi::Devuelto,
                EstadoPedidoDropi::CanceladoDropi,
                EstadoPedidoDropi::CanceladoGb,
            ], true)) {
                // H8 func / H2 datos · UNIQUE(pedido_id, tipo) + create desnudo
                // reventaba en re-conciliación → updateOrCreate mantiene idempotencia.
                DropiSancion::updateOrCreate(
                    ['pedido_id' => $pedido->id, 'tipo' => 'pago_sobre_devuelto'],
                    [
                        'monto_esperado' => 0,
                        'monto_recibido' => $monto,
                        'diferencia' => $monto,
                        'detectada_at' => now(),
                    ],
                );

                return 'sancion';
            }

            $pedido->transicionar(
                EstadoPedidoDropi::Pagado,
                'sistema',
                auth()->id(),
                ['conciliacion' => 'auto', 'monto' => $monto],
                ['pagado_at' => $fecha],
            );

            $this->registrarCobro($pedido, $monto, $fecha, $movId);

            // La diferencia de precio se mide al final de la corrida, con la
            // suma de todas las líneas del pedido (ver evaluarDiferencia).
            $this->pedidosPagados[$pedido->id] = true;

            return 'pago';
        }

        // §19 sanción por categoría explícita (indemnización con guía)
        if (in_array($tipo, ['indemnizacion'], true)) {
            if ($pedido) {
                DropiSancion::updateOrCreate(
                    ['pedido_id' => $pedido->id, 'tipo' => 'categoria_explicita'],
                    [
                        'monto_esperado' => 0,
                        'monto_recibido' => $monto,
                        'diferencia' => abs($monto),
                        'detectada_at' => now(),
                    ],
                );

                return 'sancion';
            }

            return 'gasto';
        }

        // §18 otros gastos (flete garantía, tarjeta) o retiros
        return 'gasto';
    }

    /**
     * §19 diferencia de precio (subpago O sobrepago) con la SUMA de los pagos
     * de guía del pedido, reversos incluidos. `diferencia` va firmada:
     *   + = subpago (esperado > recibido) — Dropi debe
     *   - = sobrepago (recibido > esperado) — GB debe devolver
     * Si la suma ya cuadra, se quita la sanción que hubiera dejado una corrida
     * anterior con el pago a medias.
     *
     * @return bool true si el pedido queda con diferencia
     */
    protected function evaluarDiferencia(int $pedidoId): bool
    {
        $pedido = DropiPedido::find($pedidoId);
        if (! $pedido) {
            return false;
        }

        $pagos = DropiWalletMovimiento::where('pedido_id', $pedidoId)->where('tipo', 'pago_guia');
        $recibido = (float) (clone $pagos)->sum('monto');
        $esperado = (float) $pedido->monto_esperado_proveedor;
        $delta = $esperado - $recibido;

        // No es una diferencia de precio:
        //  - un pedido devuelto o cancelado: Dropi reversa el pago (1009) y lo
        //    esperado deja de ser el precio. Si hubo pago encima de una
        //    devolución, eso ya lo marca `pago_sobre_devuelto`.
        //  - un pedido sin ningún pago positivo aquí: el pago original es anterior
        //    a DROPI_WALLET_DESDE y solo se ve su reverso. Sumado daba el doble
        //    del precio como «diferencia» (9 falsas en la primera importación).
        $devuelto = in_array($pedido->estado, [
            EstadoPedidoDropi::DevolucionEnCamino, EstadoPedidoDropi::Devuelto,
            EstadoPedidoDropi::CanceladoDropi, EstadoPedidoDropi::CanceladoGb,
        ], true);
        $sinPagoPropio = ! (clone $pagos)->where('monto', '>', 0)->exists();

        if (abs($delta) <= 0.01 || $devuelto || $sinPagoPropio) {
            DropiSancion::where('pedido_id', $pedidoId)->whereIn('tipo', ['diferencia_precio', 'sobrepago'])->delete();

            return false;
        }

        $tipo = $delta > 0 ? 'diferencia_precio' : 'sobrepago';
        DropiSancion::where('pedido_id', $pedidoId)
            ->where('tipo', $tipo === 'sobrepago' ? 'diferencia_precio' : 'sobrepago')
            ->delete();
        DropiSancion::updateOrCreate(
            ['pedido_id' => $pedidoId, 'tipo' => $tipo],
            [
                'monto_esperado' => $esperado,
                'monto_recibido' => $recibido,
                'diferencia' => $delta,
                'detectada_at' => now(),
            ],
        );

        return true;
    }
}
