<?php

namespace App\Modules\Contabilidad\Support;

use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;

/**
 * F2c · El filtro de los dos ejes, en un solo lugar.
 *
 * Línea de negocio y destino fiscal se filtran en varias pantallas —el libro,
 * los pagos, y lo que venga— y cada una lo resolvía por su cuenta o no lo
 * resolvía. Tres implementaciones del mismo filtro son tres oportunidades de
 * que una interprete «internas» distinto que las otras, y entonces los números
 * de dos pantallas del mismo mes no coinciden sin que nadie sepa por qué.
 *
 * Hay una decisión importante acá: la pestaña «internas» es todo lo que NO es
 * fiscal, no sólo lo marcado «interno». Un reparto tiene las dos mitades, así
 * que tiene que aparecer en las dos vistas; si «internas» fuera
 * `= interno` a secas, la parte interna de los repartos desaparecería del
 * balance interno y nadie lo notaría hasta el cierre.
 */
final class FiltroEjes
{
    private function __construct(
        public readonly string $linea,
        public readonly string $destino,
    ) {}

    public static function deRequest(Request $r): self
    {
        return new self(
            linea: self::normalizarLinea((string) $r->input('linea', 'todas')),
            destino: self::normalizarDestino((string) $r->input('destino', 'todas')),
        );
    }

    public static function de(?string $linea, ?string $destino): self
    {
        return new self(
            linea: self::normalizarLinea((string) $linea),
            destino: self::normalizarDestino((string) $destino),
        );
    }

    /** ¿Hay algo filtrado, o se está viendo todo? */
    public function activo(): bool
    {
        return $this->linea !== 'todas' || $this->destino !== 'todas';
    }

    /**
     * Aplica los dos ejes sobre columnas de la tabla que se está consultando.
     *
     * Para modelos que llevan las columnas encima: `movimientos_contables`,
     * `facturas_venta`.
     */
    public function aplicar(BuilderContract $q, string $tabla = ''): BuilderContract
    {
        $col = fn (string $c) => $tabla !== '' ? "{$tabla}.{$c}" : $c;

        if ($this->linea !== 'todas') {
            $q->where($col('linea_negocio'), $this->linea);
        }

        match ($this->destino) {
            'siigo' => $q->where($col('destino_fiscal'), DestinoFiscal::Siigo->value),
            // «Internas» = todo lo que no es fiscal. Incluye la parte interna
            // de los repartos, que si no se perdería del balance interno.
            'interna' => $q->where($col('destino_fiscal'), '!=', DestinoFiscal::Siigo->value),
            default => null,
        };

        return $q;
    }

    /**
     * Aplica los dos ejes a través de una relación.
     *
     * Un pago no tiene ejes propios y no debería tenerlos: son los de su
     * factura. Darle columnas propias abriría la puerta a un pago «fiscal» de
     * una factura interna, que no significa nada y descuadra el balance.
     */
    public function aplicarPor(BuilderContract $q, string $relacion): BuilderContract
    {
        if (! $this->activo()) {
            return $q;
        }

        return $q->whereHas($relacion, fn ($r) => $this->aplicar($r));
    }

    /** Lo que viaja a la pantalla para pintar el filtro y conservarlo. */
    public function aParametros(): array
    {
        return ['linea' => $this->linea, 'destino' => $this->destino];
    }

    /** Cómo se describe el filtro activo · para el pie del archivo exportado. */
    public function descripcion(): string
    {
        $partes = [];

        if ($this->linea !== 'todas') {
            $partes[] = LineaNegocio::from($this->linea)->label();
        }

        $partes[] = match ($this->destino) {
            'siigo' => 'sólo lo fiscal',
            'interna' => 'sólo lo que no va a SIIGO',
            default => 'todos los destinos',
        };

        return implode(' · ', $partes);
    }

    /** Las opciones del selector, para no repetir el listado en cada pantalla. */
    public static function opciones(): array
    {
        return [
            'lineas' => LineaNegocio::opciones(),
            'destinos' => [
                ['valor' => 'todas', 'label' => 'Todos los destinos'],
                ['valor' => 'siigo', 'label' => 'Sólo fiscal (a SIIGO)'],
                ['valor' => 'interna', 'label' => 'Sólo interno'],
            ],
        ];
    }

    private static function normalizarLinea(string $v): string
    {
        $v = trim($v);

        return LineaNegocio::tryFrom($v) ? $v : 'todas';
    }

    private static function normalizarDestino(string $v): string
    {
        return in_array(trim($v), ['siigo', 'interna'], true) ? trim($v) : 'todas';
    }
}
