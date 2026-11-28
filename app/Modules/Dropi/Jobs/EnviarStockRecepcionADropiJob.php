<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Actions\EjecutarOperacionDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiCargaOperacion as Op;
use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\PlanificarCargaDropi;
use App\Modules\Dropi\Services\ProductosSinDropi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Lo que entró apto en una recepción de Dropi se SUMA al stock de Dropi
 * (reunión con la señora Lahida, 24-nov-2026: «ya queda alimentado» tenía que
 * ser verdad también en Dropi, sin que bodega cargue nada a mano).
 *
 * Queda una carga DC- ligada a la recepción como constancia: qué se mandó, el
 * stock de antes y el de después de cada variación, releído de Dropi. Lo que
 * Dropi no conoce queda «omitido» en la carga y aparece en «Vincular con el
 * ERP» → «Del ERP, no están en Dropi». Lo que no se puede repartir solo (Dropi
 * tiene colores y la recepción no) queda para revisar en la carga.
 */
class EnviarStockRecepcionADropiJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $recepcionId) {}

    public function uniqueId(): string
    {
        return (string) $this->recepcionId;
    }

    public function handle(DropiWebClient $dropi, PlanificarCargaDropi $planificador, EjecutarOperacionDropi $ejecutar): void
    {
        if (! (bool) setting('dropi.stock_al_recibir', true)) {
            return;
        }
        $rec = RecepcionCompra::with(['items.variante', 'orden'])->find($this->recepcionId);
        $linea = $rec?->linea_negocio instanceof LineaNegocio ? $rec->linea_negocio->value : $rec?->linea_negocio;
        if (! $rec || $linea !== LineaNegocio::Dropi->value || $rec->estado !== 'confirmada') {
            return;
        }
        // Una sola constancia por recepción: si el job se repite no suma dos veces.
        if (Carga::where('recepcion_id', $rec->id)->exists()) {
            return;
        }

        $porProducto = [];
        foreach ($rec->items as $i) {
            if (($i->clasificacion ?? 'apto') !== 'apto' || (float) $i->cantidad_recibida <= 0 || ! $i->producto_id) {
                continue;
            }
            $color = trim((string) ($i->variante?->color_nombre ?: $i->color_nombre));
            $porProducto[$i->producto_id]['total'] = ($porProducto[$i->producto_id]['total'] ?? 0) + (int) $i->cantidad_recibida;
            if ($color !== '') {
                $porProducto[$i->producto_id]['colores'][$color] = ($porProducto[$i->producto_id]['colores'][$color] ?? 0) + (int) $i->cantidad_recibida;
            }
        }
        if (! $porProducto) {
            return;
        }

        $bodega = (int) config('dropi.web.bodega_id');
        $ops = [];
        foreach (Producto::whereIn('id', array_keys($porProducto))->get() as $k => $p) {
            $r = $porProducto[$p->id];
            $f = ['fila' => $k + 1, 'ref' => $p->referencia, 'nombre' => $p->nombre, 'cantidad' => $r['total'], 'variaciones' => ProductosSinDropi::textoColores($r['colores'] ?? [])];
            $base = ['fila' => $f['fila'], 'ref_excel' => $p->referencia, 'nombre_excel' => $p->nombre, 'variaciones_excel' => $f['variaciones'], 'cantidad_excel' => $r['total']];

            if (! $p->dropi_sku) {
                $ops[] = $base + ['tipo' => Op::ACTUALIZAR, 'estado' => Op::OMITIDO, 'detalle' => ['lineas' => []],
                    'motivo' => 'No está en Dropi: créalo o asócialo en «Vincular productos» → «Del ERP, no están en Dropi».'];

                continue;
            }
            try {
                $ops[] = $base + $planificador->planFila($f, $dropi->producto((int) $p->dropi_sku), $bodega, "recepción {$rec->numero}");
            } catch (\Throwable $e) {
                report($e);
                $ops[] = $base + ['tipo' => Op::ACTUALIZAR, 'estado' => Op::ERROR, 'detalle' => ['lineas' => []], 'dropi_producto_id' => (int) $p->dropi_sku,
                    'error' => mb_substr('No se pudo leer el producto en Dropi: ' . $e->getMessage(), 0, 2000)];
            }
        }

        $carga = DB::transaction(function () use ($rec, $ops, $bodega) {
            $carga = Carga::create([
                'numero' => Carga::siguienteNumero(),
                'origen' => 'recepcion',
                'recepcion_id' => $rec->id,
                'modo' => 'sumar',
                'concepto' => mb_substr("Recepción {$rec->numero} · {$rec->orden?->numero}", 0, 120),
                'bodega_dropi_id' => $bodega,
                'defaults_crear' => null,
                'estado' => Carga::PROCESANDO,
                'user_id' => $rec->recibido_por,
                'confirmado_at' => now(),
            ]);
            foreach ($ops as $op) {
                $carga->operaciones()->create($op + ['candidato' => null]);
            }

            return $carga;
        });

        foreach ($carga->operaciones()->where('estado', Op::LISTO)->get() as $op) {
            $ejecutar->handle($op);
        }

        // Lo que quedó para revisar se arregla y se confirma en la carga.
        $carga->forceFill($carga->operaciones()->where('estado', Op::REVISAR)->exists()
            ? ['estado' => Carga::BORRADOR, 'confirmado_at' => null]
            : ['estado' => Carga::TERMINADO, 'terminado_at' => now()])->save();
    }
}
