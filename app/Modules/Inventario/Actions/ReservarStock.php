<?php

namespace App\Modules\Inventario\Actions;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Models\ReservaInventario;
use App\Modules\Inventario\Services\StockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reserva stock para un pedido/factura. Valida disponible bajo lock.
 *
 * Re-audit M3 PATRÓN β + θ (SEG-A1 / DATOS-C2):
 *   - Antes: `$cantidad` sin validar → una reserva con cantidad=-999999 pasaba
 *     y como saldoDisponible = fisico - reservado, un reservado NEGATIVO
 *     inflaba el disponible (invento infinito).
 *   - Sin lockForUpdate → dos ventas concurrentes reservaban ambas la última
 *     unidad: saldo real -1.
 *   - Sin rate-limit → loop de reservas de 1u sobre miles de SKUs paralizaba
 *     ventas.
 *
 * C-F2 · Desglose dual — ahora es polimórfico:
 *   - handleSujeto(ProductoVariante) → reserva por variante (comportamiento clásico).
 *   - handleSujeto(Producto agregado) → reserva por producto (variante_id NULL).
 *   - handle(varianteId, ...) → wrapper legacy, resuelve la variante y delega.
 */
class ReservarStock
{
    use AsAction;

    public function __construct(protected StockService $stock)
    {
    }

    /**
     * API legacy · reserva por variante_id. Se mantiene por compatibilidad
     * con TODOS los callers actuales sin tocarlos. Delega en handleSujeto.
     */
    public function handle(
        int $varianteId,
        int $ubicacionId,
        int $cantidad,
        Model $origen,
        ?\DateTimeInterface $expiraAt = null,
    ): ReservaInventario {
        if ($varianteId <= 0) {
            throw new InvalidArgumentException('variante_id es obligatorio (> 0).');
        }
        $variante = ProductoVariante::findOrFail($varianteId);

        return $this->handleSujeto($variante, $ubicacionId, $cantidad, $origen, $expiraAt);
    }

    /**
     * C-F2 · API polimórfica · reserva por sujeto (Producto agregado O Variante granular).
     */
    public function handleSujeto(
        Producto|ProductoVariante $sujeto,
        int $ubicacionId,
        int $cantidad,
        Model $origen,
        ?\DateTimeInterface $expiraAt = null,
    ): ReservaInventario {
        // PATRÓN θ · guard estricto de cantidad.
        if ($cantidad <= 0) {
            throw new InvalidArgumentException("Cantidad de reserva debe ser > 0 (recibido {$cantidad}).");
        }
        if ($cantidad > 10000) {
            throw new InvalidArgumentException("Cantidad de reserva excede el máximo permitido (10.000 u).");
        }
        if ($ubicacionId <= 0) {
            throw new InvalidArgumentException('ubicacion_id es obligatorio.');
        }

        // Resolver ids del sujeto según su tipo.
        [$varianteId, $productoId, $etiquetaError] = $this->idsDelSujeto($sujeto);

        return DB::transaction(function () use ($sujeto, $varianteId, $productoId, $ubicacionId, $cantidad, $origen, $expiraAt, $etiquetaError) {
            // PATRÓN β · lock kardex + reservas activas del sujeto+ubicación.
            $this->lockearMovimientosYReservas($varianteId, $productoId, $ubicacionId);

            // PATRÓN ν · IDEMPOTENCIA por (origen, sujeto).
            $existente = $this->reservaActivaExistente($varianteId, $productoId, $ubicacionId, $origen);

            if ($existente) {
                $delta = $cantidad - (int) $existente->cantidad;
                if ($delta > 0) {
                    $disp = $this->disponibleDelSujeto($sujeto, $ubicacionId);
                    if ($disp < $delta) {
                        throw new InvalidArgumentException(
                            "Stock insuficiente para ampliar reserva · {$etiquetaError}: disponible {$disp}, delta requerido {$delta}."
                        );
                    }
                }
                $existente->cantidad = $cantidad;
                if ($expiraAt) $existente->expira_at = $expiraAt;
                $existente->save();
                return $existente;
            }

            $disp = $this->disponibleDelSujeto($sujeto, $ubicacionId);
            if ($disp < $cantidad) {
                throw new InvalidArgumentException(
                    "Stock insuficiente · {$etiquetaError} en ubicación {$ubicacionId}: disponible {$disp}, requerido {$cantidad}."
                );
            }

            return ReservaInventario::create([
                'variante_id' => $varianteId,
                'producto_id' => $productoId,
                'ubicacion_id' => $ubicacionId,
                'cantidad' => $cantidad,
                'origen_type' => get_class($origen),
                'origen_id' => $origen->getKey(),
                'expira_at' => $expiraAt,
                'activa' => true,
                'user_id' => auth()->id(),
            ]);
        });
    }

    /**
     * Extrae variante_id / producto_id según el tipo de sujeto, y una etiqueta
     * humana para mensajes de error.
     *
     * @return array{0: ?int, 1: ?int, 2: string} [varianteId, productoId, etiqueta]
     */
    private function idsDelSujeto(Producto|ProductoVariante $sujeto): array
    {
        if ($sujeto instanceof ProductoVariante) {
            /*
             * La variante también graba su `producto_id`, igual que el kardex.
             *
             * `inventario_movimientos` lo viene haciendo desde siempre —79 de
             * 79 filas con variante lo traen— y `reservas_inventario` lo
             * dejaba en nulo. La disponibilidad masiva del portal suma las dos
             * tablas con el MISMO filtro, `whereIn('producto_id', ...)`, así
             * que del lado del físico encontraba todo y del lado de lo
             * reservado no encontraba nada: el portal ofrecía mercancía que ya
             * estaba apartada para otro pedido.
             *
             * No cambia a quién pertenece la reserva —eso lo decide
             * `variante_id`, y los candados y la búsqueda de reserva existente
             * siguen filtrando por ahí—; es el mismo dato denormalizado para
             * poder agrupar por producto sin un join.
             */
            return [$sujeto->id, $sujeto->producto_id, "variante #{$sujeto->id}"];
        }
        if ($sujeto->esGranular()) {
            throw new InvalidArgumentException(
                "Producto #{$sujeto->id} ({$sujeto->referencia}) tiene desglose_stock=true. ".
                "Reserva por variante en su lugar."
            );
        }
        return [null, $sujeto->id, "producto agregado #{$sujeto->id} ({$sujeto->referencia})"];
    }

    private function lockearMovimientosYReservas(?int $varianteId, ?int $productoId, int $ubicacionId): void
    {
        $lock = fn ($q) => $varianteId !== null
            ? $q->where('variante_id', $varianteId)
            : $q->where('producto_id', $productoId)->whereNull('variante_id');

        $lock(InventarioMovimiento::query())
            ->where('ubicacion_id', $ubicacionId)
            ->lockForUpdate()->get();

        $lock(ReservaInventario::query())
            ->where('ubicacion_id', $ubicacionId)
            ->where('activa', true)
            ->lockForUpdate()->get();
    }

    private function reservaActivaExistente(?int $varianteId, ?int $productoId, int $ubicacionId, Model $origen): ?ReservaInventario
    {
        $q = ReservaInventario::query()
            ->where('origen_type', get_class($origen))
            ->where('origen_id', $origen->getKey())
            ->where('ubicacion_id', $ubicacionId)
            ->where('activa', true);

        if ($varianteId !== null) {
            $q->where('variante_id', $varianteId);
        } else {
            $q->where('producto_id', $productoId)->whereNull('variante_id');
        }

        return $q->first();
    }

    private function disponibleDelSujeto(Producto|ProductoVariante $sujeto, int $ubicacionId): int
    {
        return $this->stock->saldoDisponibleSujeto($sujeto, $ubicacionId);
    }
}
