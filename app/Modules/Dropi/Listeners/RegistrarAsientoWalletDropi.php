<?php

namespace App\Modules\Dropi\Listeners;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Illuminate\Database\Eloquent\Events\Created;
use Illuminate\Support\Facades\DB;

/**
 * P4 · Cada movimiento wallet Dropi genera un asiento contable de partida doble.
 * Idempotente por (origen_type, origen_id): la limpieza previa borra asientos
 * pasados del mismo movimiento antes de recrearlos.
 *
 * Cuentas PUC usadas:
 *   111015 · Wallet Dropi (sub-analítica de bancos)
 *   1305   · CxC Dropi por guía cobrada
 *   1110   · Bancos GB (retiros)
 *   4295   · Otros ingresos (indemnizaciones)
 *   5195   · Gastos de flete/transporte
 *   530525 · Comisiones bancarias/tarjetas
 *   5395   · Otros gastos operacionales (sanciones)
 */
class RegistrarAsientoWalletDropi
{
    public const CUENTA_WALLET = '111015';

    /**
     * Usuario del ERP al que se atribuye el asiento: el del ajuste manual
     * (Aracely), o null = sistema. Solo se lee `fuente.user_id` en un ajuste
     * manual: en los movimientos de Dropi `fuente` es el payload de Dropi y su
     * `user_id` es un usuario DE DROPI (p. ej. un empleado suyo). Tomarlo
     * reventaba la llave foránea o, peor, si coincidía con un id del ERP,
     * atribuía el asiento a quien no era.
     */
    public static function usuarioDelAsiento(DropiWalletMovimiento $mov): ?int
    {
        $fuente = is_array($mov->fuente) ? $mov->fuente : [];

        return ($fuente['origen'] ?? null) === 'ajuste_manual' && isset($fuente['user_id'])
            ? (int) $fuente['user_id']
            : null;
    }

    public function handle(DropiWalletMovimiento $mov): void
    {
        DB::transaction(function () use ($mov) {
            // Re-audit DR-θ (FUNC-M2) · antes: HARD-DELETE de asientos previos
            //   → si el mov cambió tras cerrar contabilidad del periodo, el
            //   libro mayor se corrompía sin traza. Ahora: si ya existe
            //   asiento previo para este movimiento, generamos AJUSTE INVERSO
            //   (contra-partida) y luego el nuevo. Nunca borrar historial.
            $previos = MovimientoContable::query()
                ->where('origen_type', DropiWalletMovimiento::class)
                ->where('origen_id', $mov->id)
                ->orderBy('id')
                ->get();

            if ($previos->isNotEmpty()) {
                foreach ($previos as $p) {
                    // No re-invertir asientos que ya fueron reversados (marca en descripcion).
                    if (str_starts_with((string) $p->descripcion, '[REVERSA]')) continue;
                    // Chequeo idempotente: si ya existe la reversa de este id, saltar.
                    $yaReversado = MovimientoContable::query()
                        ->where('origen_type', DropiWalletMovimiento::class)
                        ->where('origen_id', $mov->id)
                        ->where('descripcion', 'LIKE', '[REVERSA #' . $p->id . ']%')
                        ->exists();
                    if ($yaReversado) continue;

                    MovimientoContable::create([
                        'fecha' => now(),
                        'cuenta_puc' => $p->cuenta_puc,
                        'debe' => (float) $p->haber,   // invertido
                        'haber' => (float) $p->debe,   // invertido
                        'origen_type' => DropiWalletMovimiento::class,
                        'origen_id' => $mov->id,
                        'descripcion' => '[REVERSA #' . $p->id . '] ' . ($p->descripcion ?? ''),
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            $tipo = $mov->tipo instanceof TipoMovimientoWallet
                ? $mov->tipo
                : TipoMovimientoWallet::tryFrom((string) $mov->tipo);
            if (! $tipo) return;

            $fecha = $mov->fecha;
            $monto = (float) $mov->monto;

            [$debe, $haber, $desc] = $this->cuentasPara($tipo, $monto, $mov);

            if (! $debe || ! $haber) return;

            // 9.2 · lo que cobra o paga Dropi (fletes de garantía, tarjeta,
            // sanciones, indemnizaciones) se asienta con Dropi como tercero.
            $dropi = in_array($tipo, [
                TipoMovimientoWallet::FleteGarantia, TipoMovimientoWallet::Tarjeta, TipoMovimientoWallet::Indemnizacion,
            ], true) ? self::terceroDropi() : null;
            $tercero = $dropi
                ? ['tercero_type' => \App\Models\Contacto::class, 'tercero_id' => $dropi->id]
                : ['tercero_type' => null, 'tercero_id' => null];

            MovimientoContable::create([
                'fecha' => $fecha,
                'cuenta_puc' => $debe,
                ...$tercero,
                'debe' => abs($monto), 'haber' => 0,
                'origen_type' => DropiWalletMovimiento::class,
                'origen_id' => $mov->id,
                'descripcion' => $desc,
                // N5 seg · en contexto de cron auth()->id() es null.
                'user_id' => self::usuarioDelAsiento($mov),
            ]);
            MovimientoContable::create([
                'fecha' => $fecha,
                'cuenta_puc' => $haber,
                ...$tercero,
                'debe' => 0, 'haber' => abs($monto),
                'origen_type' => DropiWalletMovimiento::class,
                'origen_id' => $mov->id,
                'descripcion' => $desc,
                // N5 seg · en contexto de cron auth()->id() es null.
                'user_id' => self::usuarioDelAsiento($mov),
            ]);
        });
    }

    /**
     * Dropi como tercero contable, con el NIT de la regla `dropi.nit_tercero`.
     * Sin NIT configurado no se inventa uno: el asiento sale sin tercero.
     */
    public static function terceroDropi(): ?\App\Models\Contacto
    {
        $nit = preg_replace('/\D/', '', (string) setting('dropi.nit_tercero', ''));
        if ($nit === '') {
            return null;
        }

        return \App\Models\Contacto::firstOrCreate(
            ['numero_documento' => $nit],
            ['tipo_documento' => 'NIT', 'nombre_completo' => 'DROPI S.A.S.', 'razon_social' => 'DROPI S.A.S.', 'activo' => true],
        );
    }

    /**
     * @return array{0:string,1:string,2:string}  [cuentaDebe, cuentaHaber, descripcion]
     */
    protected function cuentasPara(TipoMovimientoWallet $tipo, float $monto, DropiWalletMovimiento $mov): array
    {
        $ref = $mov->pedido?->guia ? "guía {$mov->pedido->guia}" : 'wallet Dropi';

        // 9.3 · el pago de un pedido facturado lo asienta su recibo de caja
        // (PagoVenta `wallet_dropi`, ver ConciliarWalletDropi::registrarCobro):
        // si también se asentara aquí, el 1305 se acreditaría dos veces.
        if ($tipo === TipoMovimientoWallet::PagoGuia && $mov->pedido?->factura_venta_id) {
            return ['', '', ''];
        }

        return match ($tipo) {
            // Ingreso del cliente final vía Dropi → aumenta wallet, cancela CxC.
            TipoMovimientoWallet::PagoGuia => [self::CUENTA_WALLET, '1305', "Pago wallet Dropi — {$ref}"],
            // Retiro a banco físico: sale de wallet, entra a bancos.
            TipoMovimientoWallet::RetiroBanco => ['1110', self::CUENTA_WALLET, 'Retiro wallet Dropi → banco GB'],
            // 9.2 · Casi todas las «indemnizaciones» de la wallet son SALIDAS
            // (1052 «salida por indemnización orden», 1041 «por incumplimiento»):
            // Dropi le cobra a Great Baby. Antes se asentaban siempre como
            // ingreso 4295 (con el monto en positivo): una sanción pagada
            // figuraba como plata ganada. Salida = gasto por sanción.
            TipoMovimientoWallet::Indemnizacion => $monto < 0
                ? ['5395', self::CUENTA_WALLET, "Sanción Dropi — {$ref}"]
                : [self::CUENTA_WALLET, '4295', "Indemnización a favor — {$ref}"],
            // Flete garantía: gasto operativo.
            TipoMovimientoWallet::FleteGarantia => ['5195', self::CUENTA_WALLET, "Flete garantía Dropi — {$ref}"],
            // Comisión tarjeta: gasto financiero.
            TipoMovimientoWallet::Tarjeta => ['530525', self::CUENTA_WALLET, "Comisión tarjeta Dropi — {$ref}"],
            // Otro: sin asiento por ahora (queda solo el registro wallet).
            TipoMovimientoWallet::Otro => ['', '', ''],
        };
    }
}
