<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registra los asientos de partida doble en movimientos_contables usando
 * el helper atómico `MovimientoContable::registrarAsientoAtomico` que valida
 * ΣDebe = ΣHaber ANTES de commitear. Esto convierte cualquier factura mal
 * armada en excepción — nada de asientos silenciosamente descuadrados.
 *
 * ── Las cuentas salen de las REGLAS, no escritas acá ────────────────────────
 *
 * Estaban escritas a mano: '1305', '4135', '2408', '1105'/'1110'. Esta acción
 * es la que asienta TODA factura de venta y TODO pago de cliente, así que las
 * reglas `contable.cta_cxc_default`, `cta_ingreso_default`, `cta_iva_venta_default`
 * y `cta_caja/bancos_default` eran decorativas para el documento más importante
 * del sistema: Silvia las cambiaba en la pantalla y la factura seguía igual.
 *
 * Peor todavía: las notas crédito y débito SÍ leen `cta_cxc_default`. Con la
 * factura clavada en '1305' y su nota en la cuenta de la regla, una factura y
 * su propia nota caían en cuentas distintas y la CxC no se cerraba nunca.
 *
 * Cada concepto → su regla:
 *   CxC al cliente ......... contable.cta_cxc_default
 *   Ingreso por venta ...... contable.cta_ingreso_default
 *   IVA generado ........... contable.cta_iva_venta_default
 *   Caja / bancos .......... contable.cta_caja_default / cta_bancos_default
 *   Flete asumido .......... contable.cta_gasto_default
 *
 * Siguen escritas acá, a propósito, las que NO tienen regla porque son
 * decisiones puntuales de este asiento y no defaults de la empresa:
 *   1355 - Impuestos anticipos y saldos a favor (retenciones que nos practican)
 *   530510 - Descuentos comerciales condicionados
 *   2805 - Anticipos y avances recibidos (sobrepagos)
 */
class RegistrarAsientoContable
{
    use AsAction;

    public function factura(FacturaVenta $factura): int
    {
        return DB::transaction(function () use ($factura) {
            $this->limpiarPrevios($factura);
            $terceroTipo = 'App\\Models\\Contacto';
            $userId = auth()->id();

            $lineas = [];

            // CxC al cliente: lo que va a pagar, neto de lo que nos retiene.
            $retenido = (float) $factura->retenciones;
            $lineas[] = [
                'fecha' => $factura->fecha_emision,
                'cuenta_puc' => setting('contable.cta_cxc_default'), 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                'debe' => round((float) $factura->total - $retenido, 2), 'haber' => 0,
                'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                'descripcion' => "Factura {$factura->numero} — {$factura->contacto?->nombreDisplay()}",
                'user_id' => $userId,
            ];

            // Retenciones que nos practica el cliente: saldo a favor (1355xx).
            if ($retenido > 0) {
                foreach ($factura->retencionesAplicadas()->get() as $r) {
                    $lineas[] = [
                        'fecha' => $factura->fecha_emision,
                        'cuenta_puc' => $r->cuenta_puc, 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                        'debe' => (float) $r->valor, 'haber' => 0,
                        'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                        'descripcion' => ucfirst($r->tipo).' '.(float) $r->tarifa_pct."% factura {$factura->numero}",
                        'user_id' => $userId,
                    ];
                }
            }

            // Ingreso por venta
            $ingreso = (float) $factura->subtotal - (float) $factura->descuento;
            $lineas[] = [
                'fecha' => $factura->fecha_emision,
                'cuenta_puc' => setting('contable.cta_ingreso_default'), 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                'debe' => 0, 'haber' => $ingreso,
                'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                'descripcion' => "Venta {$factura->numero}",
                'user_id' => $userId,
            ];

            // IVA por pagar (si aplica)
            if ((float) $factura->impuestos > 0) {
                $lineas[] = [
                    'fecha' => $factura->fecha_emision,
                    'cuenta_puc' => setting('contable.cta_iva_venta_default'), 'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                    'debe' => 0, 'haber' => (float) $factura->impuestos,
                    'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                    'descripcion' => "IVA factura {$factura->numero}",
                    'user_id' => $userId,
                ];
            }

            // Autorretenciones de la empresa sobre esta venta.
            foreach ($this->lineasAutorretencion($factura, $ingreso, $terceroTipo, $userId) as $l) {
                $lineas[] = $l;
            }

            // Raíz C · valida partida doble antes de commit.
            return MovimientoContable::registrarAsientoAtomico(
                $this->repartirPorDestino($factura, $lineas)
            );
        });
    }

    /**
     * Marca cada línea con el destino fiscal que le toca.
     *
     * Con `fiscal` o `interno` es directo: todas las líneas llevan el destino de
     * la factura. El caso que importa es `reparto` — la venta real de $100 M de
     * la que sólo se declaran $20 M.
     *
     * Ahí cada línea se parte en dos, en la proporción que se declara:
     *
     *   1305 Clientes  $100 M  →  $20 M marcado `siigo` + $80 M marcado `interno`
     *
     * El ERP sigue teniendo los $100 M completos —que es la verdad de la
     * operación— y de ese mismo dato salen los dos balances:
     *
     *   Balance fiscal  = sólo `siigo`   → tiene que cuadrar contra SIIGO
     *   Balance real    = todo            → cuadra contra la operación
     *
     * Partir cada línea en vez de marcar la factura entera es lo que permite
     * sacar los dos sin volver a calcular nada. La alternativa —una sola marca
     * por factura— obliga a elegir uno de los dos balances y perder el otro.
     *
     * @param  array<int,array<string,mixed>>  $lineas
     * @return array<int,array<string,mixed>>
     */
    private function repartirPorDestino(FacturaVenta $factura, array $lineas): array
    {
        $destino = $factura->destino_fiscal ?? DestinoFiscal::Siigo;

        if ($destino !== DestinoFiscal::Reparto) {
            return array_map(fn ($l) => $l + ['destino_fiscal' => $destino], $lineas);
        }

        $total = (float) $factura->total;
        $fiscal = (float) ($factura->monto_fiscal ?? 0);

        // Sin una porción declarada válida no hay reparto que hacer: la factura
        // queda entera como interna, que es lo conservador. Si se dejara como
        // fiscal, se estaría declarando algo que nadie decidió declarar.
        if ($total <= 0 || $fiscal <= 0 || $fiscal >= $total) {
            return array_map(fn ($l) => $l + ['destino_fiscal' => DestinoFiscal::Interno], $lineas);
        }

        $proporcion = $fiscal / $total;
        $partidas = [];

        foreach ($lineas as $l) {
            $debe = round(((float) $l['debe']) * $proporcion, 2);
            $haber = round(((float) $l['haber']) * $proporcion, 2);

            // Parte declarada
            if ($debe > 0 || $haber > 0) {
                $partidas[] = array_merge($l, [
                    'debe' => $debe, 'haber' => $haber,
                    'destino_fiscal' => DestinoFiscal::Siigo,
                    'descripcion' => $l['descripcion'].' · parte declarada',
                ]);
            }

            // El resto. Se calcula por diferencia, no multiplicando por
            // (1 - proporción): así el redondeo nunca descuadra el asiento.
            $restoDebe = round(((float) $l['debe']) - $debe, 2);
            $restoHaber = round(((float) $l['haber']) - $haber, 2);

            if ($restoDebe > 0 || $restoHaber > 0) {
                $partidas[] = array_merge($l, [
                    'debe' => $restoDebe, 'haber' => $restoHaber,
                    'destino_fiscal' => DestinoFiscal::Interno,
                    'descripcion' => $l['descripcion'].' · parte interna',
                ]);
            }
        }

        return $partidas;
    }

    /**
     * Las autorretenciones que la empresa se practica a sí misma al facturar.
     *
     * La contadora lo explicó así en la reunión del 21-oct: «nosotros hacemos
     * una autorrenta por cada facturación que se emita; ese movimiento debe ir
     * amarrado ahí también, así como el IVA». No es algo que el cliente retiene
     * —eso ya se contabiliza arriba contra 1355— sino algo que Great Baby
     * declara y le paga a la DIAN mes a mes.
     *
     * El asiento son dos patas que se cancelan entre sí, así que agregarlas no
     * descuadra el resto de la factura:
     *
     *   DEBE   13xx   el anticipo que queda a favor de la empresa
     *   HABER  23xx   lo que se le debe a la DIAN
     *
     * Sólo entran las reglas ACTIVAS con sus dos cuentas puestas. El controller
     * ya no deja activar una autorretención a medias, pero se vuelve a verificar
     * acá: un asiento cojo en cada factura emitida no se nota hasta el cierre.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lineasAutorretencion(FacturaVenta $factura, float $base, string $terceroTipo, ?int $userId): array
    {
        if ($base <= 0) {
            return [];
        }

        $reglas = DB::table('retenciones_config')
            ->whereIn('tipo', ['autorrenta', 'autorreteica'])
            ->where('ambito', 'ventas')
            ->where('activa', true)
            ->whereNotNull('cuenta_puc')
            ->whereNotNull('cuenta_puc_contrapartida')
            ->get();

        $lineas = [];

        foreach ($reglas as $r) {
            // La base mínima manda: si la venta no la alcanza, no se practica.
            if ($base < (float) $r->base_minima) {
                continue;
            }

            $valor = round($base * ((float) $r->tarifa_pct) / 100, 2);
            if ($valor <= 0) {
                continue;
            }

            $texto = "{$r->concepto} ".((float) $r->tarifa_pct)."% factura {$factura->numero}";

            // Activo · el anticipo que queda a favor
            $lineas[] = [
                'fecha' => $factura->fecha_emision,
                'cuenta_puc' => $r->cuenta_puc_contrapartida,
                'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                'debe' => $valor, 'haber' => 0,
                'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                'descripcion' => $texto,
                'user_id' => $userId,
            ];

            // Pasivo · lo que se le paga a la DIAN
            $lineas[] = [
                'fecha' => $factura->fecha_emision,
                'cuenta_puc' => $r->cuenta_puc,
                'tercero_type' => $terceroTipo, 'tercero_id' => $factura->contacto_id,
                'debe' => 0, 'haber' => $valor,
                'origen_type' => FacturaVenta::class, 'origen_id' => $factura->id,
                'descripcion' => $texto,
                'user_id' => $userId,
            ];
        }

        return $lineas;
    }

    /**
     * El asiento de un pago.
     *
     * Los dos ejes NO se eligen acá: los hereda de la factura (ver los
     * accesores de `PagoVenta`). El destino se decidió una vez, al emitir.
     *
     * El reparto es el único caso con algo que resolver, y tampoco se
     * pregunta: **se cubre primero lo declarado**. En SIIGO hay una factura
     * por el monto declarado y el cliente la paga como cualquier otra; nadie
     * registra allá el 26,8 % de cada abono. Repartir el pago en proporción
     * sería contabilidad artificial que no se corresponde con ningún documento.
     */
    public function pago(PagoVenta $pago): int
    {
        return DB::transaction(function () use ($pago) {
            $this->limpiarPrevios($pago);
            $terceroTipo = 'App\\Models\\Contacto';
            $cuentaCaja = match ($pago->medio_pago) {
                'efectivo' => setting('contable.cta_caja_default'),
                // 9.3 · el pago de Dropi entra a la wallet, no al banco.
                'wallet_dropi' => \App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi::CUENTA_WALLET,
                default => setting('contable.cta_bancos_default'),
            };
            $userId = auth()->id();

            $recibido = (float) $pago->monto_recibido;
            $aplicado = (float) $pago->monto_aplicado;
            $dif = (float) $pago->diferencia;

            // Cuánto de ESTE pago corresponde a la parte declarada.
            $fiscal = $this->parteDeclaradaDelPago($pago);

            $linea = fn (string $cuenta, float $debe, float $haber, string $texto, DestinoFiscal $destino) => [
                'fecha' => $pago->fecha,
                'cuenta_puc' => $cuenta,
                'tercero_type' => $terceroTipo, 'tercero_id' => $pago->contacto_id,
                'debe' => $debe, 'haber' => $haber,
                'origen_type' => PagoVenta::class, 'origen_id' => $pago->id,
                'descripcion' => $texto,
                'destino_fiscal' => $destino,
                'user_id' => $userId,
            ];

            $numero = $pago->factura?->numero;
            $lineas = [];

            /*
             * Caja/banco y CxC se parten en dos pares: lo declarado y lo
             * interno. Cada par cuadra por su cuenta —misma plata del lado del
             * debe y del haber—, que es lo que hace que los dos balances
             * cierren solos.
             */
            if ($fiscal > 0.009) {
                $lineas[] = $linea($cuentaCaja, $fiscal, 0,
                    "Pago {$pago->medio_pago} — factura {$numero} · parte declarada", DestinoFiscal::Siigo);
                $lineas[] = $linea(setting('contable.cta_cxc_default'), 0, $fiscal,
                    "Aplicación pago factura {$numero} · parte declarada", DestinoFiscal::Siigo);
            }

            $cajaInterna = round($recibido - $fiscal, 2);
            $cxcInterna = round($aplicado - $fiscal, 2);
            $sufijo = $fiscal > 0.009 ? ' · parte interna' : '';

            if ($cajaInterna > 0.009) {
                $lineas[] = $linea($cuentaCaja, $cajaInterna, 0,
                    "Pago {$pago->medio_pago} — factura {$numero}{$sufijo}", DestinoFiscal::Interno);
            }
            if ($cxcInterna > 0.009) {
                $lineas[] = $linea(setting('contable.cta_cxc_default'), 0, $cxcInterna,
                    "Aplicación pago factura {$numero}{$sufijo}", DestinoFiscal::Interno);
            }

            // El descuento y el sobrepago no se declaran nunca: no existen en
            // la factura que viajó a SIIGO.
            if ($dif > 0 && $pago->clasificacion_diferencia) {
                $cuenta = match ($pago->clasificacion_diferencia->value) {
                    'descuento_pronto_pago', 'descuento_fuera_plazo' => '530510',
                    'flete_asumido_gb' => setting('contable.cta_gasto_default'),
                    default => null,
                };
                if ($cuenta) {
                    $lineas[] = $linea($cuenta, $dif, 0,
                        'Ajuste: '.$pago->clasificacion_diferencia->label(), DestinoFiscal::Interno);
                }
            }

            // SOBREPAGO → 2805 anticipos
            $sobrepago = round($recibido - $aplicado - max($dif, 0), 2);
            if ($sobrepago > 0.009) {
                $lineas[] = $linea('2805', 0, $sobrepago,
                    "Sobrepago cliente — anticipo a favor por \${$sobrepago}", DestinoFiscal::Interno);
            }

            // Raíz C · valida partida doble antes de commit.
            return MovimientoContable::registrarAsientoAtomico($lineas);
        });
    }

    /**
     * Cuánto de este pago cubre la parte declarada de la factura.
     *
     * Sólo tiene sentido en un reparto. Mira lo que YA cubrieron los pagos
     * anteriores: si cada pago mirara sólo su propio monto, una factura con
     * $20.000 declarados y dos abonos de $20.000 cobraría $40.000 en el libro
     * fiscal, contra una factura de SIIGO que vale $20.000.
     */
    /*
     * Publico a proposito: el recibo de caja que viaja a SIIGO necesita el
     * MISMO numero que el asiento. Mientras fue privado, el libro declaraba la
     * porcion y el voucher mandaba el pago entero contra una factura que alla
     * vale una fraccion — dos piezas del mismo reparto contradiciendose.
     */
    public function parteDeclaradaDelPago(PagoVenta $pago): float
    {
        $factura = $pago->factura;

        if (! $factura) {
            return 0.0;
        }

        $destino = $factura->destino_fiscal;

        if ($destino === DestinoFiscal::Siigo) {
            return (float) $pago->monto_aplicado;   // todo declarado
        }
        if ($destino !== DestinoFiscal::Reparto) {
            return 0.0;                              // nada declarado
        }

        $montoFiscal = (float) ($factura->monto_fiscal ?? 0);
        if ($montoFiscal <= 0) {
            // Un reparto sin monto válido es interno, igual que en la emisión.
            return 0.0;
        }

        $yaCubierto = (float) PagoVenta::query()
            ->where('factura_id', $factura->id)
            ->where('id', '<', $pago->id)
            ->sum('monto_aplicado');

        $pendienteDeclarado = max(0.0, $montoFiscal - $yaCubierto);

        return round(min((float) $pago->monto_aplicado, $pendienteDeclarado), 2);
    }

    protected function limpiarPrevios($modelo): void
    {
        // Re-audit RAÍZ Y (R3-1 datos) · forceDelete físico. Antes soft-borraba
        // → cada re-corrida acumulaba filas soft-deleted + vivas (shadow rows),
        // hinchaba `audits`, y `MovimientoContable::withTrashed()` mostraba
        // versiones contradictorias del mismo hecho. Un asiento previo a la
        // re-corrida es basura — el libro DIAN vive en NC/facturas emitidas.
        MovimientoContable::query()
            ->where('origen_type', get_class($modelo))
            ->where('origen_id', $modelo->id)
            ->forceDelete();
    }
}
