<?php

namespace App\Modules\Cartera\Actions;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\NotaDebito;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use Illuminate\Support\Facades\DB;

/**
 * El asiento de una nota crédito o débito manual.
 *
 * Esto NO EXISTÍA. El único sitio que escribía movimientos contra una
 * `NotaCredito` era `ContabilizarDevolucionDropi`, y exige una devolución de
 * Dropi: una NC creada a mano desde `/app/cartera/notas-credito` no generaba ni
 * una línea.
 *
 * Lo grave es que `FacturaVenta::recalcular()` **sí** resta las NC del saldo, y
 * su propio comentario afirma que «el asiento ya había reducido 1305 vía
 * observer NC» — cierto sólo para las de Dropi. Entonces, a la primera NC
 * manual: la cartera dice que el cliente ya no debe y el libro mantiene la CxC
 * completa. Se rompe la única cifra que hoy coincide exacto.
 *
 * La nota débito era peor: ni asiento ni saldo. `recalcular()` resta notas
 * crédito y no suma notas débito, así que una ND aumentaba la deuda ante la
 * DIAN y el ERP no se enteraba por ningún lado.
 *
 * Los asientos:
 *
 *   NOTA CRÉDITO (anula venta)        NOTA DÉBITO (aumenta la deuda)
 *     DEBE  4175 devoluciones           DEBE  1305 CxC
 *     DEBE  2408 IVA proporcional       HABER 4135 ingreso
 *     HABER 1305 CxC                    HABER 2408 IVA proporcional
 *
 * El IVA se reparte en la misma proporción que tenía la factura: una NC del
 * 30 % de una factura con IVA devuelve el 30 % de ese IVA. Si no, la cuenta de
 * IVA por pagar queda con un saldo que no corresponde a ninguna venta.
 */
class RegistrarAsientoNota
{
    /** Nota crédito: anula parte o toda la venta. */
    public function credito(NotaCredito $nc): int
    {
        $factura = $nc->factura;
        $valor = round((float) $nc->valor, 2);

        if (! $factura || $valor <= 0 || $this->yaAsentada(NotaCredito::class, $nc->id)) {
            return 0;
        }

        [$ivaNota, $baseNota] = $this->partirPorIva($factura, $valor);
        $destino = $this->destinoDe($factura);
        $tercero = ['tercero_type' => 'App\\Models\\Contacto', 'tercero_id' => $factura->contacto_id];

        $texto = "Nota crédito {$nc->numeroCompleto()} — factura {$factura->numero}";

        $lineas = [[
            'cuenta_puc' => setting('contable.cta_devolucion_default'),
            'debe' => $baseNota, 'haber' => 0,
            'descripcion' => $texto,
        ]];

        if ($ivaNota > 0.009) {
            $lineas[] = [
                'cuenta_puc' => setting('contable.cta_iva_venta_default'),
                'debe' => $ivaNota, 'haber' => 0,
                'descripcion' => $texto.' · IVA',
            ];
        }

        $lineas[] = [
            'cuenta_puc' => setting('contable.cta_cxc_default'),
            'debe' => 0, 'haber' => $valor,
            'descripcion' => $texto.' · menos CxC',
        ];

        return $this->asentar(NotaCredito::class, $nc->id, $this->fechaDe($nc), $lineas, $destino, $factura, $tercero);
    }

    /** Nota débito: le cobra más al cliente sobre una factura ya emitida. */
    public function debito(NotaDebito $nd): int
    {
        $factura = $nd->factura;
        $valor = round((float) $nd->valor, 2);

        if (! $factura || $valor <= 0 || $this->yaAsentada(NotaDebito::class, $nd->id)) {
            return 0;
        }

        [$ivaNota, $baseNota] = $this->partirPorIva($factura, $valor);
        $destino = $this->destinoDe($factura);
        $tercero = ['tercero_type' => 'App\\Models\\Contacto', 'tercero_id' => $factura->contacto_id];

        $texto = "Nota débito {$nd->numeroCompleto()} — factura {$factura->numero}";

        $lineas = [[
            'cuenta_puc' => setting('contable.cta_cxc_default'),
            'debe' => $valor, 'haber' => 0,
            'descripcion' => $texto.' · más CxC',
        ], [
            'cuenta_puc' => setting('contable.cta_ingreso_default'),
            'debe' => 0, 'haber' => $baseNota,
            'descripcion' => $texto,
        ]];

        if ($ivaNota > 0.009) {
            $lineas[] = [
                'cuenta_puc' => setting('contable.cta_iva_venta_default'),
                'debe' => 0, 'haber' => $ivaNota,
                'descripcion' => $texto.' · IVA',
            ];
        }

        return $this->asentar(NotaDebito::class, $nd->id, $this->fechaDe($nd), $lineas, $destino, $factura, $tercero);
    }

    /**
     * Cuánto de la nota es IVA y cuánto es base.
     *
     * Se usa la proporción de la FACTURA, no una tarifa fija: una factura
     * puede mezclar líneas gravadas y exentas, y una nota del 30 % de esa
     * factura devuelve el 30 % de su IVA. Con una tarifa fija, la cuenta de
     * IVA por pagar queda con saldo que no corresponde a ninguna venta.
     *
     * @return array{0: float, 1: float}  [iva, base]
     */
    private function partirPorIva(FacturaVenta $factura, float $valor): array
    {
        $total = (float) $factura->total;
        $impuestos = (float) $factura->impuestos;

        if ($total <= 0 || $impuestos <= 0) {
            return [0.0, $valor];
        }

        $iva = round($valor * ($impuestos / $total), 2);

        // La base sale por DIFERENCIA, no por su propia proporción: así los dos
        // pedazos suman exactamente el valor de la nota y el redondeo nunca
        // descuadra el asiento.
        return [$iva, round($valor - $iva, 2)];
    }

    /**
     * La nota hereda el destino de su factura.
     *
     * Un reparto manda su parte interna: anular parte de una venta declarada a
     * medias no se puede descontar entero del libro fiscal sin reabrir el
     * reparto, y declarar de menos es lo conservador.
     */
    private function destinoDe(FacturaVenta $factura): DestinoFiscal
    {
        $destino = $factura->destino_fiscal ?? DestinoFiscal::porDefecto();

        return $destino === DestinoFiscal::Reparto ? DestinoFiscal::Interno : $destino;
    }

    /**
     * Con qué fecha entra la nota al libro.
     *
     * Las notas no tienen columna `fecha`: la que vale es cuándo se emitió, y
     * si todavía no se emitió, cuándo se creó. Usar `now()` metería la nota en
     * el período equivocado si se asienta días después.
     */
    private function fechaDe($nota): string
    {
        return ($nota->emitida_at ?? $nota->created_at ?? now())->toDateString();
    }

    private function yaAsentada(string $tipo, int $id): bool
    {
        return MovimientoContable::where('origen_type', $tipo)->where('origen_id', $id)->exists();
    }

    /** @param array<int,array<string,mixed>> $lineas */
    private function asentar(
        string $tipo, int $id, string $fecha, array $lineas,
        DestinoFiscal $destino, FacturaVenta $factura, array $tercero
    ): int {
        return DB::transaction(function () use ($tipo, $id, $fecha, $lineas, $destino, $factura, $tercero) {
            $completas = array_map(fn (array $l) => array_merge($l, $tercero, [
                'fecha' => $fecha,
                'origen_type' => $tipo, 'origen_id' => $id,
                'linea_negocio' => $factura->linea_negocio,
                'destino_fiscal' => $destino,
                'user_id' => auth()->id(),
            ]), $lineas);

            return MovimientoContable::registrarAsientoAtomico($completas);
        });
    }
}
