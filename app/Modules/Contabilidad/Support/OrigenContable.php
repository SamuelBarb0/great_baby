<?php

namespace App\Modules\Contabilidad\Support;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Compras\Models\DevolucionProveedor;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Dropi\Models\DropiCorte;
use App\Modules\Dropi\Models\DropiDevolucion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Inventario\Models\CargaInventario;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Database\Eloquent\Model;

/**
 * De qué operación salió cada línea del libro, dicho en palabras.
 *
 * `movimientos_contables` guarda el origen como nombre de clase PHP. La
 * contadora necesita leer «Carga de inventario CI-2026-000003», no
 * «CargaInventario#3», y poder abrir el documento. Antes cada pantalla tenía
 * su propio mapa a medias: el panel conocía 4 orígenes, el libro diario
 * ninguno, y el detalle sólo abría facturas, pagos y notas crédito, así que un
 * asiento de wallet Dropi o de un despacho no se podía rastrear.
 *
 * Este es el único mapa. Para que un origen nuevo se vea y se pueda abrir,
 * basta con agregarlo acá.
 */
final class OrigenContable
{
    /**
     * clave URL => [clase, etiqueta, ¿lo contabiliza también SIIGO?, ruta del documento|null]
     *
     * @var array<string, array{0:class-string<Model>, 1:string, 2:bool, 3:?string}>
     */
    public const TIPOS = [
        'factura' => [FacturaVenta::class, 'Factura de venta', true, '/app/cartera/facturas/{id}'],
        'pago' => [PagoVenta::class, 'Recibo de caja', true, null],
        'nota_credito' => [NotaCredito::class, 'Nota crédito', true, null],
        'carga_inventario' => [CargaInventario::class, 'Carga de inventario', false, '/app/inventario/cargas/{id}'],
        'compra' => [RecepcionCompra::class, 'Recepción de compra', true, '/app/compras/recepcion/{id}'],
        'importacion' => [Importacion::class, 'Importación', true, '/app/compras/importacion/{id}'],
        'devolucion_proveedor' => [DevolucionProveedor::class, 'Devolución a proveedor', true, null],
        'despacho_b2b' => [PedidoCliente::class, 'Despacho de pedido B2B', false, null],
        'toma_fisica' => [TomaFisica::class, 'Toma física', false, '/app/inventario/conteos/{id}'],
        'asiento_manual' => [AsientoManual::class, 'Asiento manual', true, null],
        'wallet_dropi' => [DropiWalletMovimiento::class, 'Movimiento wallet Dropi', false, null],
        'devolucion_dropi' => [DropiDevolucion::class, 'Devolución Dropi', false, null],
        'corte_dropi' => [DropiCorte::class, 'Corte Dropi', false, null],
    ];

    public static function clave(?string $fqcn): ?string
    {
        foreach (self::TIPOS as $clave => [$clase]) {
            if ($clase === $fqcn) return $clave;
        }

        return null;
    }

    /** @return ?class-string<Model> */
    public static function clase(string $clave): ?string
    {
        return self::TIPOS[$clave][0] ?? null;
    }

    public static function etiqueta(?string $fqcn): string
    {
        $clave = self::clave($fqcn);

        return $clave ? self::TIPOS[$clave][1] : ($fqcn ? class_basename($fqcn) : 'Sin origen');
    }

    public static function vaASiigo(string $clave): bool
    {
        return (bool) (self::TIPOS[$clave][2] ?? false);
    }

    /** Link al detalle del asiento de ese documento (o null si el origen no se conoce). */
    public static function urlAsiento(?string $fqcn, $id): ?string
    {
        $clave = self::clave($fqcn);

        return ($clave && $id) ? "/app/contabilidad/reporte-detalle?tipo={$clave}&id={$id}" : null;
    }

    public static function urlDocumento(string $clave, int $id): ?string
    {
        $ruta = self::TIPOS[$clave][3] ?? null;

        return $ruta ? str_replace('{id}', (string) $id, $ruta) : null;
    }

    /** Para los selects: [{value, label}]. */
    public static function opciones(): array
    {
        return array_map(
            fn ($clave) => ['value' => $clave, 'label' => self::TIPOS[$clave][1]],
            array_keys(self::TIPOS)
        );
    }

    /**
     * Encabezado legible del documento origen para el detalle del asiento.
     *
     * Los documentos no comparten columnas —una factura tiene `numero` y
     * `total`, un movimiento de wallet `monto` y `tipo`, una devolución sólo su
     * pedido—, así que se toma el primer campo que exista y cada tipo agrega lo
     * que lo explica.
     */
    public static function resumen(string $clave, Model $o): array
    {
        $primero = function (array $campos) use ($o) {
            foreach ($campos as $c) {
                $v = $o->getAttribute($c);
                if ($v !== null && $v !== '') return $v;
            }

            return null;
        };

        $fecha = $primero(['fecha_emision', 'fecha', 'fecha_recepcion', 'fecha_conteo', 'recibido_at', 'created_at']);
        $tercero = $o->contacto ?? $o->tercero ?? $o->proveedor ?? null;
        $tercero = $tercero instanceof Model
            ? ($tercero->razon_social ?: ($tercero->nombre_completo ?? null))
            : null;

        $detalle = match ($clave) {
            'carga_inventario' => $o->motivo?->label().' · '.$o->observacion
                .($o->soporte ? " · soporte {$o->soporte}" : ''),
            'wallet_dropi' => trim(($o->tipo?->label() ?? '').' · '.($o->categoria ?? '')
                .($o->pedido?->guia ? " · guía {$o->pedido->guia}" : ''), ' ·'),
            'devolucion_dropi' => $o->pedido?->guia ? "Guía {$o->pedido->guia} · {$o->destino_inventario}" : null,
            'asiento_manual' => $o->glosa ?? null,
            'devolucion_proveedor' => $o->motivo ?? null,
            default => null,
        };

        $total = $primero(['total', 'valor_total', 'total_valor', 'monto_recibido', 'valor']);
        if ($total === null && $clave === 'wallet_dropi') $total = abs((float) $o->monto);

        return [
            'numero' => $primero(['numero', 'referencia']) ?? ('#'.$o->getKey()),
            'origen' => self::TIPOS[$clave][1],
            'fecha' => $fecha instanceof \DateTimeInterface ? $fecha->format('Y-m-d') : ($fecha ? (string) $fecha : null),
            'tercero' => $tercero ?? '—',
            'total' => (float) ($total ?? 0),
            'detalle' => $detalle,
            'url_documento' => self::urlDocumento($clave, (int) $o->getKey()),
            'va_siigo' => self::vaASiigo($clave),
            'anulado' => $o->getAttribute('deleted_at') !== null,
        ];
    }
}
