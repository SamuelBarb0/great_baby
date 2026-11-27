<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use App\Modules\Dropi\Support\Trazabilidad;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class ConfirmarEmpaqueYSiguiente
{
    use AsAction;

    /**
     * Cierra el registro de empaque, cambia estado a Empacado y retorna las métricas.
     * Bloquea si aún faltan unidades por pickear, si quien confirma no es quien
     * empezó el empaque, o si la bodega no tiene saldo (lo frena el descuento).
     *
     * @param  bool  $completarFaltantes  «✅ Confirmar empaque» de la estación (27-nov-2026:
     *                                    «es como si ya hubieras empacado todo»): lo que
     *                                    falte por escanear se da por empacado, y queda
     *                                    contado en el registro y la bitácora.
     * @return array{duracion_seg:int, items:int, msg:string, a_mano:int}
     */
    public function handle(int $pedidoId, int $operarioId, ?array $ubicacion = null, bool $completarFaltantes = false): array
    {
        // Ubicación del equipo (ver Trazabilidad): obligatoria sólo si la regla
        // `empaque.gps_obligatorio` está prendida.
        Trazabilidad::exigir($ubicacion);

        return DB::transaction(function () use ($pedidoId, $operarioId, $ubicacion, $completarFaltantes) {
            $pedido = DropiPedido::with('items')->lockForUpdate()->findOrFail($pedidoId);

            // Bloqueo: no confirmar si faltan unidades (salvo que se pidan completar)
            $itemsPendientes = $pedido->items->filter(function ($it) {
                $req = $it->unidadesErp();
                $ya = (int) ($it->cantidad_pickeada ?? 0);
                return $ya < $req;
            });
            $faltan = (int) $itemsPendientes->sum(fn ($it) => $it->unidadesErp() - ((int) ($it->cantidad_pickeada ?? 0)));

            if ($faltan > 0 && ! $completarFaltantes) {
                throw new InvalidArgumentException("Aún faltan {$faltan} unidad(es) por escanear.");
            }

            // Sólo confirma quien empezó el empaque: es lo que deja la foto, el
            // escaneo y la ubicación a nombre de una sola persona.
            $registro = EmpaqueRegistro::where('pedido_id', $pedidoId)
                ->where('estado', 'en_curso')
                ->where('operario_id', $operarioId)
                ->latest('inicio_at')
                ->lockForUpdate()
                ->first();

            if (! $registro) {
                throw new InvalidArgumentException('No hay empaque en curso tuyo para este pedido.');
            }

            $fin = now();
            $duracion = (int) $registro->inicio_at->diffInSeconds($fin, absolute: true);

            // Lo que faltaba se da por empacado, a nombre de quien confirma.
            foreach ($itemsPendientes as $it) {
                $it->forceFill([
                    'cantidad_pickeada' => $it->unidadesErp(),
                    'pickeado_at' => $it->pickeado_at ?? $fin,
                    'pickeado_por' => $it->pickeado_por ?? $operarioId,
                ])->save();
            }

            $totalUnidades = (int) $pedido->items->sum(fn ($it) => $it->unidadesErp());

            $registro->update([
                'fin_at' => $fin,
                'estado' => 'completado',
                'duracion_segundos' => $duracion,
                // Lo escaneado de verdad: el resto se confirmó a mano.
                'items_escaneados' => $totalUnidades - $faltan,
                'items_totales' => $totalUnidades,
            ]);

            $pedido->transicionar(
                EstadoPedidoDropi::Empacado,
                'manual',
                $operarioId,
                ['origen' => 'estacion_empaque', 'duracion_seg' => $duracion, 'items' => $totalUnidades]
                    + ($faltan > 0 ? ['confirmado_a_mano' => $faltan] : [])
                    + Trazabilidad::payload($ubicacion),
            );

            // El empaque no se frena cuando una referencia no tiene existencias
            // en ninguna bodega (mismo criterio del despacho B2B), pero quien
            // empaca tiene que saberlo: ese inventario no bajó.
            $aviso = '';
            $sinDescontar = SalidaInventarioDropi::unidadesDescontables($pedido)
                - SalidaInventarioDropi::unidadesFuera($pedido->id);
            if ($sinDescontar > 0) {
                $aviso = " ⚠ {$sinDescontar} unidad(es) no bajaron del inventario: no hay existencias cargadas en bodega.";
            }

            return [
                'duracion_seg' => $duracion,
                'items' => $pedido->items->count(),
                'msg' => "Pedido {$pedido->guia} empacado en " . gmdate('i:s', $duracion)
                    . ($faltan > 0 ? " · {$faltan} unidad(es) confirmada(s) sin escanear" : '') . $aviso,
                'a_mano' => $faltan,
            ];
        });
    }
}
