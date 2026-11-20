<?php

namespace App\Console\Commands;

use App\Modules\Cartera\Actions\ContabilizarDevolucionDropi;
use App\Modules\Cartera\Actions\RegistrarAsientoContable;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Actions\CobroWalletDeFactura;
use App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Dropi\Support\CuentasDropi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día la contabilidad de Dropi que se escribió con las reglas viejas
 * (19-nov-2026). Por defecto SÓLO muestra qué haría; con --aplicar lo hace.
 *
 *   1. Wallet · cada movimiento cuyo asiento vivo no es el que corresponde hoy
 *      (cuenta 111015 inexistente, grupos sin movimiento, signo al revés, pago
 *      sin factura abonado a la cartera) se reversa y se vuelve a asentar.
 *   2. Cobros · los pedidos facturados con pagos de wallet sin su recibo de
 *      caja (pago que llegó antes de la factura) se cruzan con la factura.
 *   3. Recibos · los recibos de la wallet asentados en la 111015 se rehacen
 *      contra la billetera del PUC.
 *   4. Facturas · las de Dropi con el IVA en la cuenta del ERP se rehacen
 *      con la de Dropi (24080501).
 *   5. Devoluciones · el asiento viejo reversaba la venta además de la nota
 *      crédito (doble), sin IVA y aunque no hubiera factura. Se reversa y queda
 *      sólo el inventario que volvió; la nota crédito queda con base, IVA y
 *      cartera en las cuentas de Dropi.
 *
 *   6. Compras · las recepciones de Dropi con inventario, IVA o proveedor en
 *      las cuentas del ERP se rehacen con las de Dropi (inventario por familia);
 *      los pagos a proveedor de esas compras pasan su deuda a la cuenta de
 *      proveedores de Dropi. Las devoluciones a proveedor se listan para
 *      revisarlas a mano.
 *
 * SÓLO DROPI: no toca ningún documento de Great Baby ni ninguna regla del ERP.
 *
 * Nunca borra: todo se corrige con contra-asiento. Nunca toca una línea que ya
 * está en SIIGO (`siigo_journal_id`): cambiarla sólo de este lado dejaría los
 * dos libros distintos — esas se listan para revisarlas a mano.
 */
class DropiRepararContabilidadCommand extends Command
{
    protected $signature = 'dropi:reparar-contabilidad
        {--aplicar : escribir los asientos (sin esto sólo muestra)}';

    protected $description = 'Corrige los asientos de Dropi hechos con las cuentas y reglas viejas (wallet, cobros, devoluciones).';

    private bool $aplicar = false;

    public function handle(): int
    {
        $this->aplicar = (bool) $this->option('aplicar');

        $this->wallet();
        $this->cobros();
        $this->recibos();
        $this->facturas();
        $this->devoluciones();
        $this->compras();

        $this->newLine();
        $this->aplicar
            ? $this->info('Listo. Los cambios de la wallet viajan solos a SIIGO si el push automático está prendido.')
            : $this->warn('Nada escrito. Corra con --aplicar para hacer los cambios.');

        return self::SUCCESS;
    }

    private function wallet(): void
    {
        $this->line('<options=bold>1 · Wallet</>');
        $listener = new RegistrarAsientoWalletDropi();
        $filas = [];
        $enSiigo = 0;

        DropiWalletMovimiento::with('pedido')->orderBy('fecha')->orderBy('id')
            ->chunk(200, function ($movs) use ($listener, &$filas, &$enSiigo) {
                foreach ($movs as $m) {
                    $vivas = MovimientoContable::where('origen_type', DropiWalletMovimiento::class)
                        ->where('origen_id', $m->id)->vivas()->orderBy('id')->get();

                    if ($this->firma($vivas) === $this->firmaEsperada($listener->lineasPara($m))) {
                        continue;
                    }
                    if ($vivas->contains(fn ($l) => filled($l->siigo_journal_id))) {
                        $enSiigo++;
                        continue;
                    }
                    $filas[] = [$m->id, $m->fecha?->toDateString(), $m->tipo?->value ?? $m->tipo, $m->monto,
                        $vivas->pluck('cuenta_puc')->unique()->implode(' / ') ?: '—'];
                    if ($this->aplicar) {
                        $listener->handle($m);
                    }
                }
            });

        $this->tabla(['mov', 'fecha', 'tipo', 'monto', 'cuentas de hoy'], $filas);
        if ($enSiigo) {
            $this->warn("  {$enSiigo} movimiento(s) ya están en SIIGO con otro asiento: revisarlos a mano.");
        }
    }

    private function cobros(): void
    {
        $this->line('<options=bold>2 · Pagos de wallet sin recibo de caja</>');
        $pedidos = DropiPedido::whereNotNull('factura_venta_id')
            ->whereIn('id', DropiWalletMovimiento::where('tipo', 'pago_guia')->where('monto', '>', 0)
                ->whereNotIn(DB::raw('SUBSTR(dropi_movimiento_id, 1, 60)'),
                    PagoVenta::withTrashed()->where('medio_pago', 'wallet_dropi')->whereNotNull('referencia')->select('referencia'))
                ->select('pedido_id'))
            ->get();

        $this->tabla(['pedido', 'guía', 'factura'], $pedidos->map(fn ($p) => [$p->id, $p->guia, $p->factura_venta_id])->all());
        if ($this->aplicar) {
            foreach ($pedidos as $p) {
                CobroWalletDeFactura::cruzarPrevios($p);
            }
        }
    }

    private function recibos(): void
    {
        $this->line('<options=bold>3 · Recibos de la wallet en una cuenta que no es la billetera</>');
        $wallet = RegistrarAsientoWalletDropi::cuentaWallet();
        $pagos = PagoVenta::where('medio_pago', 'wallet_dropi')
            ->whereIn('id', MovimientoContable::where('origen_type', PagoVenta::class)
                ->where('debe', '>', 0)->where('descripcion', 'not like', '[REVERSA%')
                ->where('cuenta_puc', 'like', '11%')->where('cuenta_puc', '!=', $wallet)
                ->whereNull('siigo_journal_id')->select('origen_id'))
            ->get();

        $this->tabla(['recibo', 'factura', 'monto'], $pagos->map(fn ($p) => [$p->id, $p->factura_id, $p->monto_recibido])->all());
        if ($this->aplicar) {
            // El recibo se regenera como lo hace el ERP al editar un pago: es
            // un asiento que nunca viajó como comprobante (SIIGO tiene el
            // recibo, y su cuenta la pone el tipo de pago).
            foreach ($pagos as $p) {
                (new RegistrarAsientoContable())->pago($p);
            }
        }
    }

    private function facturas(): void
    {
        $this->line('<options=bold>4 · Facturas de Dropi con el IVA en la cuenta del ERP</>');
        $ivaErp = (string) setting('contable.cta_iva_venta_default');
        $ivaDropi = (string) setting('contable.cta_dropi_iva_venta');
        $facturas = $ivaErp === $ivaDropi ? collect() : FacturaVenta::where('linea_negocio', LineaNegocio::Dropi->value)
            ->whereIn('id', MovimientoContable::where('origen_type', FacturaVenta::class)
                ->where('cuenta_puc', $ivaErp)->whereNull('siigo_journal_id')->select('origen_id'))
            ->get();

        $this->tabla(['factura', 'número', 'total'], $facturas->map(fn ($f) => [$f->id, $f->numero, $f->total])->all());
        if ($this->aplicar) {
            // El asiento de una factura se regenera como lo hace el ERP al
            // corregirla: nunca viajó como comprobante (SIIGO tiene la factura).
            foreach ($facturas as $f) {
                (new RegistrarAsientoContable())->factura($f);
            }
        }
    }

    private function devoluciones(): void
    {
        $this->line('<options=bold>5 · Devoluciones y notas crédito de Dropi</>');
        $filas = [];

        foreach (DropiDevolucion::with('pedido')->orderBy('id')->get() as $d) {
            $nc = NotaCredito::where('devolucion_dropi_id', $d->id)->whereNotIn('estado', ['rechazada', 'anulada'])->first();

            // a · Lo que el asiento VIEJO de la devolución escribió (venta,
            //     cartera e inventario con las cuentas viejas). El inventario
            //     nuevo se reconoce por su descripción y no se toca.
            $viejas = MovimientoContable::query()->vivas()
                ->where('origen_type', DropiDevolucion::class)->where('origen_id', $d->id)
                ->where('descripcion', 'not like', 'Reingreso inventario%')
                ->where('descripcion', 'not like', 'Reverso costo de venta%')
                ->get();

            // b · La nota crédito: tiene que quedar base a devoluciones, IVA y
            //     cartera del cliente de la factura, con las cuentas de Dropi.
            $vivasNc = $nc ? MovimientoContable::query()->vivas()
                ->where('origen_type', NotaCredito::class)->where('origen_id', $nc->id)->get() : collect();
            $esperadasNc = $nc?->factura ? $this->lineasDeNota($nc) : [];
            $ncMal = $nc?->factura && $this->firma($vivasNc) !== $this->firma($esperadasNc);

            if ($viejas->isEmpty() && ! $ncMal) {
                continue;
            }
            $tocadas = $ncMal ? $viejas->merge($vivasNc) : $viejas;
            if ($tocadas->contains(fn ($l) => filled($l->siigo_journal_id))) {
                $this->warn("  Devolución #{$d->id} tiene líneas en SIIGO: revisarla a mano.");
                continue;
            }
            $filas[] = [$d->id, $d->pedido?->guia, $nc?->numeroCompleto() ?? '—', $viejas->count(), $ncMal ? 'sí' : 'no'];

            if ($this->aplicar) {
                DB::transaction(function () use ($tocadas, $d, $ncMal, $esperadasNc) {
                    foreach ($tocadas as $l) {
                        $this->reversar($l);
                    }
                    ContabilizarDevolucionDropi::run($d->fresh());
                    if ($ncMal) {
                        MovimientoContable::registrarAsientoAtomico($esperadasNc);
                    }
                });
            }
        }

        $this->tabla(['devolución', 'guía', 'nota crédito', 'líneas viejas', 'NC a rehacer'], $filas);
    }

    /**
     * El asiento que le corresponde a una nota crédito de Dropi: el mismo que
     * escribe RegistrarAsientoNota (base, IVA en la proporción de la factura,
     * cartera del cliente), con las cuentas de Dropi.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lineasDeNota(NotaCredito $nc): array
    {
        $f = $nc->factura;
        $valor = round((float) $nc->valor, 2);
        $iva = (float) $f->total > 0 ? round($valor * (float) $f->impuestos / (float) $f->total, 2) : 0.0;
        $base = round($valor - $iva, 2);
        $comun = [
            'fecha' => now()->toDateString(),
            'tercero_type' => \App\Models\Contacto::class, 'tercero_id' => $f->contacto_id,
            'origen_type' => NotaCredito::class, 'origen_id' => $nc->id,
            'linea_negocio' => $f->linea_negocio, 'destino_fiscal' => $f->destino_fiscal,
            'descripcion' => "Nota crédito {$nc->numeroCompleto()} — factura {$f->numero}",
        ];
        $lineas = [[...$comun, 'cuenta_puc' => CuentasDropi::devolucion($f), 'debe' => $base, 'haber' => 0]];
        if ($iva > 0.009) {
            $lineas[] = [...$comun, 'cuenta_puc' => CuentasDropi::ivaVenta($f), 'debe' => $iva, 'haber' => 0, 'descripcion' => $comun['descripcion'].' · IVA'];
        }
        $lineas[] = [...$comun, 'cuenta_puc' => (string) setting('contable.cta_cxc_default'), 'debe' => 0, 'haber' => $valor];

        return $lineas;
    }

    private function reversar(MovimientoContable $l): void
    {
        MovimientoContable::create([
            'fecha' => now()->toDateString(), 'cuenta_puc' => $l->cuenta_puc,
            'tercero_type' => $l->tercero_type, 'tercero_id' => $l->tercero_id,
            'debe' => (float) $l->haber, 'haber' => (float) $l->debe,
            'origen_type' => $l->origen_type, 'origen_id' => $l->origen_id,
            'descripcion' => '[REVERSA #'.$l->id.'] '.$l->descripcion,
            'linea_negocio' => $l->linea_negocio, 'destino_fiscal' => $l->destino_fiscal,
        ]);
    }

    private function compras(): void
    {
        $this->line('<options=bold>6 · Compras de Dropi en cuentas del ERP</>');
        $delErp = array_values(array_filter(array_map('strval', [
            setting('contable.cta_inventario_default'), setting('contable.cta_iva_compra_default'),
            setting('contable.cta_proveedores_default'), setting('contable.cta_proveedores_exterior'),
        ])));
        $deDropi = [
            (string) setting('contable.cta_dropi_proveedores'), (string) setting('contable.cta_dropi_proveedores_exterior'),
            (string) setting('contable.cta_dropi_iva_compra'),
        ];
        $delErp = array_values(array_diff($delErp, $deDropi));

        // a · Recepciones: se regeneran (nunca viajaron como comprobante).
        $recepciones = \App\Modules\Compras\Models\RecepcionCompra::where('linea_negocio', LineaNegocio::Dropi->value)
            ->whereIn('id', MovimientoContable::where('origen_type', \App\Modules\Compras\Models\RecepcionCompra::class)
                ->whereIn('cuenta_puc', $delErp)->select('origen_id'))
            ->get()
            ->reject(fn ($r) => MovimientoContable::where('origen_type', $r::class)->where('origen_id', $r->id)->whereNotNull('siigo_journal_id')->exists());

        // b · Pagos a proveedor de compras de Dropi con la deuda en la cuenta del ERP.
        $pagos = \App\Modules\Cartera\Models\PagoProveedor::query()
            ->whereIn('id', MovimientoContable::where('origen_type', \App\Modules\Cartera\Models\PagoProveedor::class)
                ->whereIn('cuenta_puc', $delErp)->whereNull('siigo_journal_id')->select('origen_id'))
            ->get()
            ->filter(fn ($p) => CuentasDropi::esDropi(
                $p->recepcion_id ? \App\Modules\Compras\Models\RecepcionCompra::find($p->recepcion_id)
                    : ($p->orden_compra_id ? \App\Modules\Compras\Models\OrdenCompra::find($p->orden_compra_id) : null)));

        // c · Devoluciones a proveedor de Dropi: a mano.
        $devoluciones = \App\Modules\Compras\Models\DevolucionProveedor::with('recepcion')->get()
            ->filter(fn ($d) => CuentasDropi::esDropi($d->recepcion)
                && MovimientoContable::where('origen_type', $d::class)->where('origen_id', $d->id)->whereIn('cuenta_puc', $delErp)->exists());

        $this->tabla(['documento', 'número'], [
            ...$recepciones->map(fn ($r) => ['Recepción', $r->numero])->all(),
            ...$pagos->map(fn ($p) => ['Pago a proveedor', '#'.$p->id])->all(),
        ]);
        foreach ($devoluciones as $d) {
            $this->warn("  Devolución a proveedor {$d->numero} de Dropi con cuentas del ERP: revisarla a mano.");
        }

        if (! $this->aplicar) {
            return;
        }
        foreach ($recepciones as $r) {
            (new \App\Modules\Compras\Actions\RegistrarAsientoCompra())->recepcion($r);
        }
        foreach ($pagos as $p) {
            $recepcion = $p->recepcion_id ? \App\Modules\Compras\Models\RecepcionCompra::find($p->recepcion_id) : null;
            $destino = CuentasDropi::proveedores(
                $recepcion ?? \App\Modules\Compras\Models\OrdenCompra::find($p->orden_compra_id),
                (bool) $recepcion?->orden?->esImportacion(),
            );
            DB::transaction(function () use ($p, $delErp, $destino) {
                $lineas = MovimientoContable::query()->vivas()
                    ->where('origen_type', $p::class)->where('origen_id', $p->id)->whereIn('cuenta_puc', $delErp)->get();
                foreach ($lineas as $l) {
                    $this->reversar($l);
                    MovimientoContable::create([
                        'fecha' => now()->toDateString(), 'cuenta_puc' => $destino,
                        'tercero_type' => $l->tercero_type, 'tercero_id' => $l->tercero_id,
                        'debe' => (float) $l->debe, 'haber' => (float) $l->haber,
                        'origen_type' => $l->origen_type, 'origen_id' => $l->origen_id,
                        'descripcion' => $l->descripcion,
                        'linea_negocio' => $l->linea_negocio, 'destino_fiscal' => $l->destino_fiscal,
                    ]);
                }
            });
        }
    }

    /** @param  iterable<MovimientoContable|array>  $lineas */
    private function firma(iterable $lineas): string
    {
        return collect($lineas)->map(fn ($l) => implode('|', [
            data_get($l, 'cuenta_puc'),
            number_format((float) data_get($l, 'debe'), 2, '.', ''),
            number_format((float) data_get($l, 'haber'), 2, '.', ''),
            (string) data_get($l, 'tercero_id'),
        ]))->sort()->implode(';');
    }

    private function firmaEsperada(array $lineas): string
    {
        return $this->firma($lineas);
    }

    private function tabla(array $cab, array $filas): void
    {
        if (! $filas) {
            $this->line('  Nada que corregir.');

            return;
        }
        $this->table($cab, array_slice($filas, 0, 50));
        if (count($filas) > 50) {
            $this->line('  … y '.(count($filas) - 50).' más.');
        }
        $this->line('  Total: '.count($filas));
    }
}
