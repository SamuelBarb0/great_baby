<?php

namespace App\Modules\Dropi\Listeners;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Illuminate\Support\Facades\DB;

/**
 * Cada movimiento de la wallet de Dropi genera su asiento de partida doble.
 *
 * ── La regla, en una línea ─────────────────────────────────────────────────
 *
 *   La wallet es una cuenta de banco más (11100501). Lo que ENTRA la debita,
 *   lo que SALE la acredita, y la otra pata va a la cuenta del concepto.
 *
 *   | Movimiento                       | Contrapartida (regla)             |
 *   |----------------------------------|-----------------------------------|
 *   | Pago de guía SIN factura         | contable.cta_dropi_anticipo   28050501     |
 *   | Pago de guía CON factura         | (lo asienta su recibo de caja)    |
 *   | Retiro a banco / retiro negado   | contable.cta_dropi_banco_retiro 11200501   |
 *   | Flete de garantía                | contable.cta_dropi_flete      51355001     |
 *   | Comisión de tarjeta              | contable.cta_dropi_comision_tarjeta 53051501 |
 *   | Indemnización que sale (sanción) | contable.cta_dropi_sancion    53952001     |
 *   | Indemnización que entra          | contable.cta_dropi_indemnizacion 42950502  |
 *   | Otro que sale / que entra        | contable.cta_dropi_otro_salida / _entrada  |
 *
 * ── Por qué el SIGNO manda (19-nov-2026) ───────────────────────────────────
 *
 * Antes cada tipo tenía la dirección fija y se asentaba `abs($monto)`. Dropi
 * manda reversos con el mismo tipo y el signo opuesto, así que:
 *   · un «retiro negado» (1021, la plata VUELVE a la wallet) se asentaba como
 *     OTRO retiro: la wallet bajaba dos veces y el banco subía por plata que
 *     nunca llegó;
 *   · el reverso de un flete o de una comisión se asentaba como otro cobro;
 *   · un reverso de pago (1009) de un pedido sin factura se asentaba como otro
 *     pago.
 * Con la dirección tomada del signo, un reverso deshace exactamente lo que
 * hizo el movimiento original.
 *
 * ── Por qué el pago sin factura va a ANTICIPO y no a la cartera ────────────
 *
 * Abonarlo a 13050501 sin una factura que la hubiera cargado dejaba la cartera
 * de Dropi en NEGATIVO. La plata que Dropi paga por un pedido que todavía no se
 * facturó es un anticipo del cliente: al facturar, FacturarPedidoDropi cruza
 * esos pagos con la factura (recibo de caja) y este mismo listener reversa el
 * anticipo — la 28050501 vuelve a cero y la factura queda pagada.
 *
 * ── Historia ───────────────────────────────────────────────────────────────
 *
 * Nunca borra: si el movimiento ya tenía asiento, primero escribe la reversa
 * (marcada `[REVERSA #id]`) y después el nuevo. Las cuentas salen de las reglas
 * `contable.cta_dropi_*` (pantalla de Reglas), no de literales: antes la wallet iba a la
 * 111015, que no existe en el PUC de Great Baby.
 *
 * Lo que este listener escribe viaja a SIIGO como comprobante contable
 * (PushAsientoDropiASiigo), con Dropi como tercero.
 */
class RegistrarAsientoWalletDropi
{
    /** La cuenta de la billetera, según la regla. */
    public static function cuentaWallet(): string
    {
        return (string) setting('contable.cta_dropi_wallet');
    }

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
        $escribio = DB::transaction(function () use ($mov) {
            $escribio = $this->reversarPrevios($mov);

            $lineas = $this->lineasPara($mov);
            if ($lineas) {
                MovimientoContable::registrarAsientoAtomico($lineas);
                $escribio = true;
            }

            return $escribio;
        });

        if ($escribio) {
            $id = $mov->id;
            DB::afterCommit(fn () => \App\Modules\Siigo\Jobs\PushAsientoDropiASiigo::dispatch(DropiWalletMovimiento::class, $id));
        }
    }

    /**
     * Las dos líneas del asiento, o [] si este movimiento no se asienta acá.
     *
     * @return array<int,array<string,mixed>>
     */
    public function lineasPara(DropiWalletMovimiento $mov): array
    {
        $tipo = $mov->tipo instanceof TipoMovimientoWallet
            ? $mov->tipo
            : TipoMovimientoWallet::tryFrom((string) $mov->tipo);
        $monto = round((float) $mov->monto, 2);

        if (! $tipo || abs($monto) < 0.01) {
            return [];
        }

        [$contra, $desc] = $this->contrapartida($tipo, $monto, $mov);
        if (! $contra) {
            return [];
        }

        $entra = $monto > 0;
        $valor = abs($monto);
        $wallet = self::cuentaWallet();
        $dropi = self::terceroDropi();
        $base = [
            'fecha' => $mov->fecha,
            'tercero_type' => $dropi ? \App\Models\Contacto::class : null,
            'tercero_id' => $dropi?->id,
            'origen_type' => DropiWalletMovimiento::class,
            'origen_id' => $mov->id,
            'descripcion' => $desc,
            'user_id' => self::usuarioDelAsiento($mov),
        ];

        return [
            [...$base, 'cuenta_puc' => $entra ? $wallet : $contra, 'debe' => $valor, 'haber' => 0],
            [...$base, 'cuenta_puc' => $entra ? $contra : $wallet, 'debe' => 0, 'haber' => $valor],
        ];
    }

    /**
     * La cuenta del otro lado de la wallet y la descripción del asiento.
     *
     * @return array{0:string,1:string}  ['' , ''] = no se asienta acá
     */
    protected function contrapartida(TipoMovimientoWallet $tipo, float $monto, DropiWalletMovimiento $mov): array
    {
        $ref = $mov->pedido?->guia ? "guía {$mov->pedido->guia}" : 'wallet Dropi';
        $entra = $monto > 0;

        // El pago de un pedido facturado lo asienta su recibo de caja (PagoVenta
        // `wallet_dropi`, ConciliarWalletDropi::registrarCobro). Si también se
        // asentara acá, la wallet se debitaría dos veces.
        if ($tipo === TipoMovimientoWallet::PagoGuia && $mov->pedido?->factura_venta_id) {
            return ['', ''];
        }

        $cta = fn (string $clave) => (string) setting('contable.cta_dropi_'.$clave);

        return match ($tipo) {
            TipoMovimientoWallet::PagoGuia => [$cta('anticipo'), $entra
                ? "Pago Dropi sin factura (anticipo) — {$ref}"
                : "Reverso de pago Dropi sin factura — {$ref}"],
            TipoMovimientoWallet::RetiroBanco => [$cta('banco_retiro'), $entra
                ? 'Retiro de la wallet Dropi negado: vuelve a la wallet'
                : 'Retiro de la wallet Dropi al banco'],
            TipoMovimientoWallet::Indemnizacion => $entra
                ? [$cta('indemnizacion'), "Indemnización a favor — {$ref}"]
                : [$cta('sancion'), "Sanción / indemnización cobrada por Dropi — {$ref}"],
            TipoMovimientoWallet::FleteGarantia => [$cta('flete'), $entra
                ? "Reverso de flete de garantía Dropi — {$ref}"
                : "Flete de garantía Dropi — {$ref}"],
            TipoMovimientoWallet::Tarjeta => [$cta('comision_tarjeta'), $entra
                ? "Reverso de comisión de tarjeta Dropi — {$ref}"
                : "Comisión de tarjeta Dropi — {$ref}"],
            TipoMovimientoWallet::Otro => $entra
                ? [$cta('otro_entrada'), 'Abono Dropi sin clasificar · '.($mov->categoria ?: 'sin código')]
                : [$cta('otro_salida'), 'Cobro Dropi sin clasificar · '.($mov->categoria ?: 'sin código')],
        };
    }

    /**
     * Escribe la reversa de las líneas vivas del movimiento. Nunca borra.
     *
     * @return bool true si escribió alguna reversa
     */
    protected function reversarPrevios(DropiWalletMovimiento $mov): bool
    {
        $previos = MovimientoContable::query()
            ->where('origen_type', DropiWalletMovimiento::class)
            ->where('origen_id', $mov->id)
            ->orderBy('id')
            ->get();

        $escribio = false;
        foreach ($previos as $p) {
            // Una reversa no se vuelve a reversar, ni se reversa dos veces una línea.
            if (str_starts_with((string) $p->descripcion, '[REVERSA')) {
                continue;
            }
            $yaReversado = $previos->contains(
                fn ($o) => str_starts_with((string) $o->descripcion, '[REVERSA #'.$p->id.']')
            );
            if ($yaReversado) {
                continue;
            }

            MovimientoContable::create([
                'fecha' => now()->toDateString(),
                'cuenta_puc' => $p->cuenta_puc,
                'tercero_type' => $p->tercero_type,
                'tercero_id' => $p->tercero_id,
                'debe' => (float) $p->haber,
                'haber' => (float) $p->debe,
                'origen_type' => DropiWalletMovimiento::class,
                'origen_id' => $mov->id,
                'descripcion' => '[REVERSA #'.$p->id.'] '.($p->descripcion ?? ''),
                'linea_negocio' => $p->linea_negocio,
                'destino_fiscal' => $p->destino_fiscal,
                'user_id' => auth()->id(),
            ]);
            $escribio = true;
        }

        return $escribio;
    }

    /**
     * Dropi como tercero contable, con el NIT de la regla `dropi.nit_tercero`.
     * Sin NIT configurado no se inventa uno: el asiento sale sin tercero (y no
     * puede viajar a SIIGO, que exige tercero por línea en un comprobante).
     */
    public static function terceroDropi(): ?\App\Models\Contacto
    {
        $nit = preg_replace('/\D/', '', (string) setting('dropi.nit_tercero'));
        if ($nit === '') {
            return null;
        }

        return \App\Models\Contacto::firstOrCreate(
            ['numero_documento' => $nit],
            ['tipo_documento' => 'NIT', 'nombre_completo' => 'DROPI S.A.S.', 'razon_social' => 'DROPI S.A.S.', 'activo' => true],
        );
    }
}
