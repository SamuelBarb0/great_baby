<?php

namespace App\Modules\Logistica\Actions;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Inventario\Support\CostoPromedio;
use App\Modules\Inventario\Support\UbicacionDelPar;
use App\Modules\Logistica\Models\DevolucionCliente;
use DomainException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * CONFIRMAR UNA DEVOLUCIÓN: la mercancía entra al almacén 08.
 *
 * Hasta que alguien confirma, la devolución es una lista en borrador y el
 * inventario no se tocó. Al confirmar, cada línea escribe su entrada en el
 * kardex contra la ubicación del par (la oficina de la bodega donde se recibe
 * × el almacén 08), y el documento se cierra.
 *
 * El 08 no es vendible, así que esa mercancía queda contada —existe y se sabe
 * dónde está— pero el sistema no se la ofrece a nadie mientras no la revisen.
 * De ahí sale por traslado, que es el acto que cierra la inspección.
 */
class RegistrarDevolucionCliente
{
    use AsAction;

    /** El almacén donde entra todo lo devuelto, por procedimiento del área. */
    public const ALMACEN = '08';

    /**
     * El tipo que lleva el movimiento en el kardex.
     *
     * Propio y no uno reciclado. Los de ajuste —sobrante, merma, faltante,
     * ajuste_toma_fisica— están en la lista blanca del observer de SIIGO y
     * disparan un asiento de ajuste de inventario, que no es lo que significa
     * una devolución. Mide 26 caracteres y la columna admite 32.
     */
    public const TIPO = 'entrada_devolucion_cliente';

    public function handle(DevolucionCliente $devolucion): DevolucionCliente
    {
        return DB::transaction(function () use ($devolucion) {
            $d = DevolucionCliente::with(['items', 'origen'])
                ->lockForUpdate()
                ->findOrFail($devolucion->id);

            if ($d->estado !== 'borrador') {
                throw new DomainException("La devolución {$d->numero} ya está confirmada.");
            }

            if ($d->items->isEmpty()) {
                throw new DomainException('No se puede confirmar una devolución sin líneas.');
            }

            $destino = $this->ubicacionDeDevoluciones($d->origen);
            $ts = now();

            foreach ($d->items as $it) {
                $cantidad = round((float) $it->cantidad, 4);

                if ($cantidad <= 0) {
                    continue;
                }

                InventarioMovimiento::create([
                    'variante_id' => $it->variante_id,
                    // El hook `creating` puebla producto_id desde la variante,
                    // pero no al revés: en una línea agregada hay que darlo.
                    'producto_id' => $it->producto_id,
                    'ubicacion_id' => $destino->id,
                    'tipo' => self::TIPO,
                    // POSITIVO: el saldo de una ubicación es la suma de su
                    // columna, y el signo es lo único que distingue una
                    // entrada de una salida.
                    'cantidad' => $cantidad,
                    /*
                     * El costo importa más de lo que parece.
                     *
                     * El costo promedio de una ubicación se calcula al vuelo
                     * sobre las entradas de su kardex, y las que no traen
                     * costo quedan FUERA del promedio. Si la primera
                     * devolución entra sin costo, el 08 promedia cero, y
                     * cuando la mercancía salga de ahí por traslado ese cero
                     * viaja al almacén de destino y contamina el promedio del
                     * inventario bueno.
                     */
                    'costo_unit' => $it->costo_unit,
                    'referencia_tipo' => DevolucionCliente::class,
                    'referencia_id' => $d->id,
                    'user_id' => auth()->id(),
                    'notas' => "Devolución {$d->numero} · {$d->motivo}"
                        .($it->motivo_item ? " · {$it->motivo_item}" : ''),
                    'created_at' => $ts,
                ]);
            }

            $d->forceFill([
                'estado' => 'confirmada',
                'confirmada_at' => $ts,
                'ubicacion_destino_id' => $destino->id,
            ])->save();

            return $d->fresh(['items', 'destino']);
        });
    }

    /**
     * Dónde entra: el par (oficina de la bodega que recibe × almacén 08).
     *
     * `comoEsta` NO es opcional aunque la firma lo permita. El par nace con la
     * primera devolución, y una bodega sin `responsable_user_id` queda
     * invisible: el alcance de bodegas se calcula desde ese campo, así que lo
     * que acaban de recibir no les sale en el kardex, ni en el buscador, ni lo
     * pueden mover. Heredarlo de la bodega contra la que se recibe es la
     * respuesta correcta — es la misma sede.
     */
    private function ubicacionDeDevoluciones(InventarioUbicacion $origen): InventarioUbicacion
    {
        $oficina = $origen->oficina_id
            ? Oficina::find($origen->oficina_id)
            : null;

        // Una posición hereda la oficina de su sede: el rack no la lleva.
        $oficina ??= Oficina::find(
            InventarioUbicacion::find($origen->bodega_id)?->oficina_id
        );

        if (! $oficina) {
            throw new DomainException(
                "«{$origen->codigo}» no está asignada a ninguna oficina, así que no se sabe a qué "
                .'bodega de devoluciones entra lo que vuelve. Se asigna en el Catálogo de la bodega.'
            );
        }

        $almacen = Almacen::where('codigo', self::ALMACEN)->first();

        if (! $almacen) {
            throw new DomainException(
                'No existe el almacén '.self::ALMACEN.' (Devoluciones y garantías de clientes). '
                .'Se crea en el Catálogo de la bodega.'
            );
        }

        return app(UbicacionDelPar::class)->resolver(
            $oficina, $almacen, esIngreso: true, comoEsta: $origen
        );
    }

    /**
     * A qué costo entra una línea, cuando quien registra no lo sabe.
     *
     * El promedio de la bodega de la que salió: es lo que esa mercancía le
     * costó a la empresa. Si nunca pasó por ahí —una devolución de algo que se
     * vendió hace años— devuelve null y la pantalla pide que lo escriban, en
     * vez de inventar un cero que después contamina el promedio del almacén a
     * donde se traslade.
     */
    public static function costoSugerido(InventarioUbicacion $origen, ?int $varianteId, ?int $productoId): ?float
    {
        /*
         * La sede y sus estantes son el mismo sitio: con la mercancía bajada a
         * racks, la fila de la sede puede estar en cero y el costo vivir en
         * `R12-S1-N1`. Mirar sólo la sede devolvería null sobre una referencia
         * que sí tiene costo conocido.
         */
        $lugares = InventarioUbicacion::laSedeYSusPosiciones(
            (int) ($origen->bodega_id ?: $origen->id), sóloVendibles: false
        );

        $costos = CostoPromedio::porUbicaciones(
            [['variante_id' => $varianteId, 'producto_id' => $productoId]],
            $lugares
        );

        $costo = (float) ($costos[CostoPromedio::clave($varianteId, $productoId)] ?? 0);

        return $costo > 0 ? round($costo, 4) : null;
    }
}
