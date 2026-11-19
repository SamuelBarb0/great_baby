<?php

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Actions\CrearProductosDeOrden;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Compara lo que dice el proveedor que mandó con lo que se le pidió. La orden nunca
 * coincide con la lista final: acá se ve qué cambió y queda escrito por qué.
 *
 * No toca la orden ni el inventario: lo que manda al final es lo que Jorge cuente.
 */
class ComparadorEmpaque
{
    /** @return array{resumen: array<string,float|int>, filas: list<array<string,mixed>>, avisos: list<string>, hoja: string} */
    public function comparar(Importacion $imp, ImportacionDocumento $doc): array
    {
        if (strtolower(pathinfo((string) $doc->nombre, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('Sólo se pueden comparar archivos Excel .xlsx. Este queda guardado, pero hay que revisarlo a mano.');
        }

        $ruta = Storage::disk(ImportacionDocumento::DISCO)->path($doc->ruta);
        $lector = app(LectorListaEmpaque::class);
        try {
            $candidatas = $lector->detectar($ruta);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('No se pudo leer el archivo: '.$e->getMessage());
        }
        if (! $candidatas) {
            throw new InvalidArgumentException('No encontré en este archivo una fila de encabezados con referencia y cajas o cantidad.');
        }

        $mejor = $candidatas[0];
        $leido = $lector->leer($ruta, $mejor['hoja'], $mejor['fila'], $mejor['mapa']);

        $pedido = $this->pedido($imp);
        $porProducto = collect($pedido)->filter(fn ($p) => $p['producto_id'])->keyBy('producto_id');
        $motivos = DB::table('compras_diferencias_empaque')->where('importacion_id', $imp->id)->pluck('motivo', 'referencia');

        $lista = [];
        foreach ($leido['lineas'] as $l) {
            $ref = (string) $l['referencia'];
            // Una referencia que cambió es el mismo producto: se cuenta sobre la línea de la orden.
            $clave = isset($pedido[$ref]) ? $ref : (($p = Producto::buscarPorReferencia($ref)) ? ($porProducto[$p->id]['referencia'] ?? $ref) : $ref);
            $lista[$clave] ??= ['referencia' => $clave, 'como_llego' => $ref, 'cajas' => 0, 'cantidad' => 0.0, 'descripcion' => $l['descripcion'] ?? null];
            $lista[$clave]['cajas'] += (int) ($l['cajas'] ?? 0);
            $lista[$clave]['cantidad'] += (float) ($l['cantidad'] ?? 0);
        }

        $filas = [];
        foreach ($pedido as $ref => $p) {
            $l = $lista[$ref] ?? null;
            $cantidad = (float) ($l['cantidad'] ?? 0);
            $filas[] = [
                'referencia' => $ref,
                'como_llego' => $l['como_llego'] ?? null,
                'descripcion' => $p['descripcion'],
                'orden_item_id' => $p['item_id'],
                'cajas_pedidas' => $p['cajas'],
                'cajas_lista' => (int) ($l['cajas'] ?? 0),
                'cantidad_pedida' => $p['cantidad'],
                'cantidad_lista' => $cantidad,
                'estado' => ! $l ? 'quitada' : (abs($cantidad - $p['cantidad']) < 0.001 ? 'igual' : 'cambia'),
                'motivo' => $motivos[$ref] ?? null,
            ];
        }
        foreach ($lista as $ref => $l) {
            if (! isset($pedido[$ref])) {
                $filas[] = [
                    'referencia' => $ref,
                    'como_llego' => $ref,
                    'descripcion' => $l['descripcion'],
                    'orden_item_id' => null,
                    'cajas_pedidas' => 0,
                    'cajas_lista' => $l['cajas'],
                    'cantidad_pedida' => 0.0,
                    'cantidad_lista' => $l['cantidad'],
                    'estado' => 'nueva',
                    'motivo' => $motivos[$ref] ?? null,
                ];
            }
        }

        $orden = ['quitada' => 0, 'nueva' => 1, 'cambia' => 2, 'igual' => 3];
        usort($filas, fn ($a, $b) => $orden[$a['estado']] <=> $orden[$b['estado']]);

        return [
            'hoja' => $mejor['hoja'],
            'avisos' => $leido['avisos'],
            'resumen' => [
                'cajas_pedidas' => (int) array_sum(array_column($filas, 'cajas_pedidas')),
                'cajas_lista' => (int) array_sum(array_column($filas, 'cajas_lista')),
                'cbm_lista' => (float) ($leido['totales']['cbm'] ?? 0),
                'peso_lista' => (float) ($leido['totales']['peso'] ?? 0),
                'diferencias' => count(array_filter($filas, fn ($f) => $f['estado'] !== 'igual')),
                'sin_motivo' => count(array_filter($filas, fn ($f) => $f['estado'] !== 'igual' && ! $f['motivo'])),
            ],
            'filas' => $filas,
        ];
    }

    public function guardarMotivo(Importacion $imp, string $referencia, string $motivo, ?int $userId): void
    {
        DB::table('compras_diferencias_empaque')->updateOrInsert(
            ['importacion_id' => $imp->id, 'referencia' => $referencia],
            ['motivo' => $motivo, 'registrado_por' => $userId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** «Esta referencia de la lista es esta otra de la orden»: en adelante, el mismo producto. */
    public function vincular(Importacion $imp, string $referenciaLista, int $ordenItemId, ?int $userId): void
    {
        $orden = $imp->ordenes()->whereHas('items', fn ($q) => $q->whereKey($ordenItemId))->with('items')->first();
        if (! $orden) {
            throw new InvalidArgumentException('Esa línea no es de este contenedor.');
        }

        CrearProductosDeOrden::run($orden);
        $producto = $orden->items->firstWhere('id', $ordenItemId)?->fresh()?->producto;
        if (! $producto) {
            throw new InvalidArgumentException('Esa línea no tiene producto todavía: póngale referencia en la orden.');
        }

        $producto->agregarReferencia($referenciaLista, "Lista de empaque del contenedor {$imp->numero}", $userId);
    }

    /** @return array<string, array{referencia: string, producto_id: ?int, item_id: int, descripcion: string, cajas: int, cantidad: float}> */
    private function pedido(Importacion $imp): array
    {
        $imp->loadMissing('ordenes.items.producto');
        $pedido = [];
        foreach ($imp->ordenes as $orden) {
            foreach ($orden->items as $i) {
                $ref = $i->producto?->referencia ?: CrearProductosDeOrden::referenciaDe($i) ?: "línea {$i->id}";
                $pedido[$ref] ??= ['referencia' => $ref, 'producto_id' => $i->producto_id, 'item_id' => $i->id, 'descripcion' => (string) $i->descripcion, 'cajas' => 0, 'cantidad' => 0.0];
                $pedido[$ref]['cajas'] += (int) $i->cajas;
                $pedido[$ref]['cantidad'] += (float) $i->cantidad;
            }
        }

        return $pedido;
    }
}
