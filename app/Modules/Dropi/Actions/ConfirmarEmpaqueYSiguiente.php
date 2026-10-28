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
     * @return array{duracion_seg:int, items:int, msg:string}
     */
    public function handle(int $pedidoId, int $operarioId, ?array $ubicacion = null): array
    {
        // Ubicación del equipo (ver Trazabilidad): obligatoria sólo si la regla
        // `empaque.gps_obligatorio` está prendida.
        Trazabilidad::exigir($ubicacion);

        return DB::transaction(function () use ($pedidoId, $operarioId, $ubicacion) {
            $pedido = DropiPedido::with('items')->lockForUpdate()->findOrFail($pedidoId);

            // Bloqueo: no confirmar si faltan unidades
            $itemsPendientes = $pedido->items->filter(function ($it) {
                $req = (int) ($it->cantidad ?? 1);
                $ya = (int) ($it->cantidad_pickeada ?? 0);
                return $ya < $req;
            });

            if ($itemsPendientes->isNotEmpty()) {
                $faltan = $itemsPendientes->sum(fn ($it) => ((int) ($it->cantidad ?? 1)) - ((int) ($it->cantidad_pickeada ?? 0)));
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

            $totalUnidades = (int) $pedido->items->sum(fn ($it) => (int) ($it->cantidad ?? 1));

            $registro->update([
                'fin_at' => $fin,
                'estado' => 'completado',
                'duracion_segundos' => $duracion,
                'items_escaneados' => $totalUnidades,
                'items_totales' => $totalUnidades,
            ]);

            $pedido->transicionar(
                EstadoPedidoDropi::Empacado,
                'manual',
                $operarioId,
                ['origen' => 'estacion_empaque', 'duracion_seg' => $duracion, 'items' => $totalUnidades]
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
                'msg' => "Pedido {$pedido->guia} empacado en " . gmdate('i:s', $duracion) . $aviso,
            ];
        });
    }
}
