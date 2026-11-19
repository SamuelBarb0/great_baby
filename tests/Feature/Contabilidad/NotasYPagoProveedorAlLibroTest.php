<?php

/**
 * Las notas y los pagos a proveedor también llegan al libro.
 *
 * Tres documentos no generaban NI UN movimiento contable:
 *
 *   · **Nota crédito manual** — pero `recalcular()` sí bajaba el saldo de la
 *     factura. A la primera NC: cartera decía que el cliente ya no debe y el
 *     libro mantenía la CxC completa. Se rompía la única cifra que coincide
 *     exacto. (Las NC de Dropi sí asentaban, por otro camino.)
 *
 *   · **Nota débito** — ni asiento ni saldo. Aumentaba la deuda ante la DIAN y
 *     el ERP no se enteraba por ningún lado.
 *
 *   · **Pago a proveedor** — la cuenta de proveedores sólo crecía, y las
 *     retenciones practicadas nunca entraban al libro. Eso es la base de la
 *     declaración mensual.
 */

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Actions\RegistrarAsientoNota;
use App\Modules\Cartera\Actions\RegistrarAsientoPagoProveedor;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\NotaDebito;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->cliente = Contacto::create([
        'numero_documento' => '900222111', 'nombre_completo' => 'Cliente Notas SAS', 'es_cliente' => true,
    ]);
    $this->proveedor = Contacto::create([
        'numero_documento' => '901333222', 'nombre_completo' => 'Proveedor Notas SAS', 'es_proveedor' => true,
    ]);
    $this->producto = Producto::create([
        'referencia' => 'REF-N', 'nombre' => 'Producto', 'activo' => true,
    ]);
});

function facturaConIva(float $precio, float $iva = 19, ?DestinoFiscal $destino = null): FacturaVenta
{
    $d = $destino ?? DestinoFiscal::Siigo;

    return CrearFacturaManual::run([
        'contacto_id' => test()->cliente->id,
        'fecha_emision' => now()->toDateString(),
        'destino_fiscal' => $d->value,
        'motivo_destino' => $d === DestinoFiscal::Siigo ? null : 'Prueba',
        'items' => [[
            'producto_id' => test()->producto->id,
            'descripcion' => 'Venta', 'cantidad' => 1,
            'precio_unit' => $precio, 'impuesto_pct' => $iva,
        ]],
    ]);
}

function movs(string $tipo, int $id)
{
    return MovimientoContable::where('origen_type', $tipo)->where('origen_id', $id);
}

// ── Nota crédito ─────────────────────────────────────────────────────────────

it('una nota crédito manual genera su asiento', function () {
    $f = facturaConIva(1_000_000);           // 1.190.000 con IVA

    $nc = NotaCredito::create([
        'factura_id' => $f->id, 'prefijo' => 'NC', 'numero' => 1,
        'valor' => 595_000, 'motivo' => 'Devolución', 'estado' => 'borrador',
    ]);

    (new RegistrarAsientoNota())->credito($nc->fresh()->load('factura'));

    // La CxC baja por el valor de la nota…
    expect((float) movs(NotaCredito::class, $nc->id)->where('cuenta_puc', cta('cta_cxc_default'))->sum('haber'))
        ->toEqual(595_000.0)
        // …y el asiento cuadra.
        ->and(round((float) movs(NotaCredito::class, $nc->id)->sum('debe')
            - (float) movs(NotaCredito::class, $nc->id)->sum('haber'), 2))->toEqual(0.0);
});

it('la nota crédito devuelve el IVA en la proporción de su factura', function () {
    /*
     * Con tarifa fija, la cuenta de IVA por pagar queda con saldo que no
     * corresponde a ninguna venta. La mitad de una factura con IVA devuelve la
     * mitad de ese IVA.
     */
    $f = facturaConIva(1_000_000);           // base 1.000.000 · IVA 190.000 · total 1.190.000

    $nc = NotaCredito::create([
        'factura_id' => $f->id, 'prefijo' => 'NC', 'numero' => 2,
        'valor' => 595_000, 'motivo' => 'Mitad', 'estado' => 'borrador',
    ]);

    (new RegistrarAsientoNota())->credito($nc->fresh()->load('factura'));

    expect((float) movs(NotaCredito::class, $nc->id)->where('cuenta_puc', cta('cta_iva_venta_default'))->sum('debe'))
        ->toEqual(95_000.0)   // la mitad de 190.000
        ->and((float) movs(NotaCredito::class, $nc->id)->where('cuenta_puc', cta('cta_devolucion_default'))->sum('debe'))
        ->toEqual(500_000.0);
});

it('una nota crédito sin IVA no toca la cuenta de IVA', function () {
    $f = facturaConIva(1_000_000, 0);

    $nc = NotaCredito::create([
        'factura_id' => $f->id, 'prefijo' => 'NC', 'numero' => 3,
        'valor' => 400_000, 'motivo' => 'Sin IVA', 'estado' => 'borrador',
    ]);

    (new RegistrarAsientoNota())->credito($nc->fresh()->load('factura'));

    expect(movs(NotaCredito::class, $nc->id)->where('cuenta_puc', cta('cta_iva_venta_default'))->count())->toBe(0);
});

it('la nota crédito hereda el destino de su factura', function () {
    $f = facturaConIva(1_000_000, 0, DestinoFiscal::Interno);

    $nc = NotaCredito::create([
        'factura_id' => $f->id, 'prefijo' => 'NC', 'numero' => 4,
        'valor' => 300_000, 'motivo' => 'Interna', 'estado' => 'borrador',
    ]);

    (new RegistrarAsientoNota())->credito($nc->fresh()->load('factura'));

    expect(movs(NotaCredito::class, $nc->id)->where('destino_fiscal', DestinoFiscal::Siigo->value)->count())
        ->toBe(0);
});

it('no se asienta dos veces la misma nota', function () {
    $f = facturaConIva(1_000_000, 0);

    $nc = NotaCredito::create([
        'factura_id' => $f->id, 'prefijo' => 'NC', 'numero' => 5,
        'valor' => 200_000, 'motivo' => 'Doble', 'estado' => 'borrador',
    ]);

    $accion = new RegistrarAsientoNota();
    $accion->credito($nc->fresh()->load('factura'));
    $accion->credito($nc->fresh()->load('factura'));

    expect((float) movs(NotaCredito::class, $nc->id)->where('cuenta_puc', cta('cta_cxc_default'))->sum('haber'))
        ->toEqual(200_000.0);
});

// ── Nota débito ──────────────────────────────────────────────────────────────

it('una nota débito aumenta la CxC en el libro', function () {
    $f = facturaConIva(1_000_000, 0);

    $nd = NotaDebito::create([
        'factura_id' => $f->id, 'prefijo' => 'ND', 'numero' => 1,
        'valor' => 150_000, 'motivo' => 'Flete no facturado', 'estado' => 'borrador',
    ]);

    (new RegistrarAsientoNota())->debito($nd->fresh()->load('factura'));

    expect((float) movs(NotaDebito::class, $nd->id)->where('cuenta_puc', cta('cta_cxc_default'))->sum('debe'))
        ->toEqual(150_000.0)
        ->and((float) movs(NotaDebito::class, $nd->id)->where('cuenta_puc', cta('cta_ingreso_default'))->sum('haber'))
        ->toEqual(150_000.0);
});

it('la nota débito también sube el saldo de la factura', function () {
    // Antes `recalcular()` restaba las NC y no sumaba las ND: el cliente
    // quedaba debiendo menos de lo que debía.
    $f = facturaConIva(1_000_000, 0);
    $saldoAntes = (float) $f->saldo;

    NotaDebito::create([
        'factura_id' => $f->id, 'prefijo' => 'ND', 'numero' => 2,
        'valor' => 150_000, 'motivo' => 'Flete', 'estado' => 'borrador',
    ]);

    $f->fresh()->recalcular();

    expect((float) $f->fresh()->saldo)->toEqual($saldoAntes + 150_000);
});

// ── Pago a proveedor ─────────────────────────────────────────────────────────

it('un pago a proveedor cancela el pasivo y registra la retención', function () {
    /*
     * Lo que estaba roto: la cuenta de proveedores SOLO crecía, y las
     * retenciones practicadas nunca entraban al libro — que es la base de la
     * declaración mensual ante la DIAN.
     */
    $p = PagoProveedor::create([
        'fecha' => now()->toDateString(),
        'contacto_id' => $this->proveedor->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'monto_bruto' => 1_000_000,
        'monto_retenciones' => 25_000,
        'monto_neto' => 975_000,
        'metodo' => 'transferencia',
        'estado' => 'confirmado',
    ]);

    (new RegistrarAsientoPagoProveedor())->handle($p->fresh());

    $m = movs(PagoProveedor::class, $p->id);

    expect((float) (clone $m)->where('cuenta_puc', cta('cta_proveedores_default'))->sum('debe'))->toEqual(1_000_000.0)
        ->and((float) (clone $m)->where('cuenta_puc', cta('cta_bancos_default'))->sum('haber'))->toEqual(975_000.0)
        ->and((float) (clone $m)->where('cuenta_puc', cta('cta_retefuente_default'))->sum('haber'))->toEqual(25_000.0)
        // Cuadra: bruto = neto + retenciones.
        ->and(round((float) (clone $m)->sum('debe') - (float) (clone $m)->sum('haber'), 2))->toEqual(0.0);
});

it('la retención va a retefuente aunque el pago traiga cuenta de egreso', function () {
    /*
     * El respaldo de `retencionesDe()` usaba `cuenta_puc_egreso` como si fuera
     * la cuenta de la retención. No lo es: es de dónde SALE la plata —el
     * banco—, y así la llena `RegistrarPagoProveedor`, así la manda el
     * comprobante de egreso a SIIGO y así la rotula la pantalla.
     *
     * El efecto era invisible: el asiento cuadraba igual (debe proveedores por
     * el bruto, haber banco por el neto, haber banco OTRA VEZ por la
     * retención), así que la validación de partida doble no decía nada. Pero
     * la retención quedaba acreditada en el banco y el reporte que sostiene la
     * declaración mensual salía corto.
     *
     * Esta prueba manda la cuenta de egreso a propósito y exige que la
     * retención igual aterrice en retefuente.
     */
    $p = PagoProveedor::create([
        'fecha' => now()->toDateString(),
        'contacto_id' => $this->proveedor->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'monto_bruto' => 1_000_000, 'monto_retenciones' => 25_000, 'monto_neto' => 975_000,
        'metodo' => 'transferencia', 'estado' => 'confirmado',
        'cuenta_puc_egreso' => cta('cta_bancos_default'),
    ]);

    (new RegistrarAsientoPagoProveedor())->handle($p->fresh());

    $m = movs(PagoProveedor::class, $p->id);

    expect((float) (clone $m)->where('cuenta_puc', cta('cta_retefuente_default'))->sum('haber'))
        ->toEqual(25_000.0)
        // Del banco sale SÓLO el neto. Antes salían 1.000.000: el neto más la
        // retención, las dos contra la misma cuenta.
        ->and((float) (clone $m)->where('cuenta_puc', cta('cta_bancos_default'))->sum('haber'))
        ->toEqual(975_000.0);
});

it('un pago en efectivo sale de caja', function () {
    $p = PagoProveedor::create([
        'fecha' => now()->toDateString(),
        'contacto_id' => $this->proveedor->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'monto_bruto' => 200_000, 'monto_retenciones' => 0, 'monto_neto' => 200_000,
        'metodo' => 'efectivo', 'estado' => 'confirmado',
    ]);

    (new RegistrarAsientoPagoProveedor())->handle($p->fresh());

    expect((float) movs(PagoProveedor::class, $p->id)->where('cuenta_puc', cta('cta_caja_default'))->sum('haber'))
        ->toEqual(200_000.0);
});

it('no se asienta dos veces el mismo pago', function () {
    $p = PagoProveedor::create([
        'fecha' => now()->toDateString(),
        'contacto_id' => $this->proveedor->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'monto_bruto' => 500_000, 'monto_retenciones' => 0, 'monto_neto' => 500_000,
        'metodo' => 'transferencia', 'estado' => 'confirmado',
    ]);

    $accion = new RegistrarAsientoPagoProveedor();
    $accion->handle($p->fresh());
    $accion->handle($p->fresh());

    expect((float) movs(PagoProveedor::class, $p->id)->where('cuenta_puc', cta('cta_proveedores_default'))->sum('debe'))
        ->toEqual(500_000.0);
});
