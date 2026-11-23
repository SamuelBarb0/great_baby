<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Models\DropiCargaProducto as Carga;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\RegistrarCargaInventario;
use App\Modules\Inventario\Enums\MotivoCargaInventario;
use App\Modules\Inventario\Models\CargaInventario;
use Illuminate\Support\Facades\DB;

/**
 * Antes de mandar una carga a Dropi, registra la misma mercancía en el
 * inventario del ERP: un documento CI- con su motivo, el kardex de cada
 * referencia y el asiento (1435 contra la cuenta del motivo).
 *
 * Toma TODAS las filas del Excel —es la mercancía física, se cargue o no a
 * Dropi— y crea como producto agregado las referencias que el ERP no tiene
 * (Samuel, 19-nov-2026), con el costo de la columna COSTO. Como toda creación
 * de producto, esas se envían solas a SIIGO.
 *
 * Igual que la carga masiva de inventario: el costo del Excel solo llena el
 * catálogo donde no había uno, los productos granulares se saltan (no se
 * fuerza el cambio de modo), y todo va en una transacción: o entra el stock
 * con su asiento, o no entra nada. Es idempotente por carga: si se corta y se
 * vuelve a correr, no duplica.
 */
class RegistrarIngresoErpDesdeCarga
{
    /** @return array{carga_inventario: ?CargaInventario, resultado: array} */
    public function handle(Carga $carga): array
    {
        $ingreso = $carga->ingreso_erp ?? [];
        $motivo = MotivoCargaInventario::from($ingreso['motivo']);
        $ubicacionId = (int) $ingreso['ubicacion_id'];

        return DB::transaction(function () use ($carga, $ingreso, $motivo, $ubicacionId) {
            $ci = $carga->carga_inventario_id ? CargaInventario::find($carga->carga_inventario_id) : null;
            $r = ['creados' => 0, 'actualizados' => 0, 'movimientos' => 0, 'ya_estaban' => 0, 'omitidos' => 0, 'sin_costo' => 0, 'filas' => []];

            // fila del Excel → producto de Dropi con que la vista previa la emparejó
            $dropiPorFila = $carga->operaciones()->whereNotNull('dropi_producto_id')->pluck('dropi_producto_id', 'fila')->all();

            Producto::sinKardexAutomatico(function () use ($carga, $ingreso, $motivo, $ubicacionId, $dropiPorFila, &$ci, &$r) {
                foreach ($carga->filas_excel ?? [] as $f) {
                    $ref = trim((string) $f['ref']);
                    $cantidad = (int) round((float) $f['cantidad']);
                    if ($ref === '' || $cantidad <= 0) {
                        continue;
                    }
                    $costo = isset($f['costo']) && (float) $f['costo'] > 0 ? (float) $f['costo'] : null;

                    // La referencia del Excel no siempre es la del ERP (el Excel
                    // dice DROP2606-41 y el producto, traído de Dropi, AND2606-41):
                    // si no casa exacta, se usa el producto de Dropi que encontró
                    // la vista previa para esa fila. Solo si no hay ninguno se crea.
                    $producto = Producto::where('referencia', $ref)->first()
                        ?? (isset($dropiPorFila[$f['fila']]) ? Producto::where('dropi_sku', (string) $dropiPorFila[$f['fila']])->first() : null);
                    if ($producto && $producto->desglose_stock) {
                        $r['omitidos']++;
                        $r['filas'][$ref] = 'omitido: en el ERP se maneja por variantes (granular)';
                        continue;
                    }
                    if ($motivo === MotivoCargaInventario::TrasladoGreatBaby) {
                        // Mercancía que YA es de Great Baby: no se crea nada, sale
                        // de su bodega y entra a la de Dropi al costo que tenía.
                        if (! $producto) {
                            throw new \RuntimeException("La referencia {$ref} no existe en Great Baby: un traslado sólo mueve mercancía que ya está en el ERP.");
                        }
                        $this->trasladar($carga, $ingreso, $motivo, $ubicacionId, $producto, $ref, $cantidad, $ci, $r);

                        continue;
                    }
                    if (! $producto && $motivo === MotivoCargaInventario::Compra) {
                        // 23-nov-2026, la clienta: primero se crea el producto (en
                        // Dropi o en el ERP, y quedan conectados); la compra sólo
                        // le pone inventario. Si no existe, no se inventa una ficha.
                        throw new \RuntimeException("La referencia {$ref} no existe en el ERP: créela primero (en Dropi o en el ERP) y después registre la compra.");
                    }
                    if (! $producto) {
                        $producto = Producto::create([
                            'referencia' => $ref,
                            'nombre' => mb_convert_case(trim((string) ($f['nombre'] ?: $ref)), MB_CASE_TITLE),
                            'descripcion' => ! empty($f['variaciones']) ? "Colores/variación: {$f['variaciones']}" : null,
                            'activo' => true,
                            'desglose_stock' => false,
                            'stock_directo' => $cantidad,
                            'precio_proveedor' => $costo ?? 0,
                        ]);
                        $r['creados']++;
                        $estado = 'creado en el ERP';
                    } else {
                        if ($costo !== null && (float) $producto->precio_proveedor <= 0) {
                            $producto->forceFill(['precio_proveedor' => $costo])->save();
                        }
                        $r['actualizados']++;
                        $estado = 'entró al inventario';
                    }

                    $marker = "{$carga->numero} · Ingreso al ERP · ref {$ref}";
                    $yaEsta = InventarioMovimiento::query()
                        ->where('producto_id', $producto->id)->whereNull('variante_id')
                        ->where('notas', $marker)->exists();
                    if ($yaEsta) {
                        $r['ya_estaban']++;
                        $r['filas'][$ref] = 'ya estaba registrado';
                        continue;
                    }

                    $ci ??= RegistrarCargaInventario::abrir([
                        'motivo' => $motivo,
                        'observacion' => $ingreso['observacion'],
                        'soporte' => $ingreso['soporte'] ?? null,
                        'tercero_id' => $ingreso['tercero_id'] ?? null,
                        'ubicacion_id' => $ubicacionId,
                        'archivo_nombre' => $carga->archivo_nombre,
                        'canal' => 'dropi',
                        'user_id' => $carga->user_id,
                    ]);

                    $costoUnit = $costo ?? (float) ($producto->precio_proveedor ?: 0);
                    if ($costoUnit <= 0) {
                        $r['sin_costo']++;
                        $estado .= ' (sin costo: no suma al asiento)';
                    }
                    InventarioMovimiento::create([
                        'producto_id' => $producto->id,
                        'variante_id' => null,
                        'ubicacion_id' => $ubicacionId,
                        'tipo' => 'carga_inicial_cliente',
                        'cantidad' => $cantidad,
                        'costo_unit' => $costoUnit,
                        'notas' => $marker,
                        'referencia_tipo' => CargaInventario::class,
                        'referencia_id' => $ci->id,
                        'user_id' => $carga->user_id,
                    ]);
                    $r['movimientos']++;
                    $r['filas'][$ref] = $estado;
                }
            });

            if ($ci) {
                RegistrarCargaInventario::cerrar($ci);
            }
            $carga->forceFill([
                'carga_inventario_id' => $ci?->id ?? $carga->carga_inventario_id,
                'ingreso_erp' => ($carga->ingreso_erp ?? []) + ['hecho_at' => now()->toIso8601String()],
            ])->save();
            $carga->forceFill(['ingreso_erp' => array_merge($carga->ingreso_erp, ['resultado' => $r])])->save();

            return ['carga_inventario' => $ci?->refresh(), 'resultado' => $r];
        });
    }

    /**
     * Traslado desde Great Baby (23-nov-2026): salida de la bodega del ERP y
     * entrada a la de Dropi, las dos al costo promedio que tenía en el origen.
     * Sin stock suficiente en el origen no se mueve NADA (la excepción deshace
     * la transacción entera y no se manda nada a Dropi).
     */
    private function trasladar(Carga $carga, array $ingreso, MotivoCargaInventario $motivo, int $destinoId, Producto $producto, string $ref, int $cantidad, ?CargaInventario &$ci, array &$r): void
    {
        $origenId = (int) ($ingreso['origen_ubicacion_id'] ?? 0);
        $marker = "{$carga->numero} · Traslado desde Great Baby · ref {$ref}";
        $yaEsta = InventarioMovimiento::query()
            ->where('producto_id', $producto->id)->whereNull('variante_id')
            ->where('notas', $marker)->exists();
        if ($yaEsta) {
            $r['ya_estaban']++;
            $r['filas'][$ref] = 'ya estaba trasladado';

            return;
        }

        $disponible = app(\App\Modules\Inventario\Services\StockService::class)->saldoDisponibleProducto($producto->id, $origenId);
        if ($disponible < $cantidad) {
            $bodega = \App\Modules\Dropi\Models\InventarioUbicacion::whereKey($origenId)->value('nombre') ?? "#{$origenId}";
            throw new \RuntimeException("No alcanza el stock de {$ref} en {$bodega}: hay {$disponible} y el traslado pide {$cantidad}.");
        }

        $costo = \App\Modules\Inventario\Support\CostoPromedio::porUbicacion(
            [['variante_id' => null, 'producto_id' => $producto->id]], $origenId,
        )[\App\Modules\Inventario\Support\CostoPromedio::clave(null, $producto->id)] ?? null;
        $costo = $costo && $costo > 0 ? (float) $costo : (float) ($producto->precio_proveedor ?: 0);

        $ci ??= RegistrarCargaInventario::abrir([
            'motivo' => $motivo,
            'observacion' => $ingreso['observacion'],
            'soporte' => $ingreso['soporte'] ?? null,
            'tercero_id' => null,
            'ubicacion_id' => $destinoId,
            'archivo_nombre' => $carga->archivo_nombre,
            'canal' => 'dropi',
            'user_id' => $carga->user_id,
        ]);

        foreach ([[$origenId, 'traslado_salida', -$cantidad], [$destinoId, 'traslado_entrada', $cantidad]] as [$ubic, $tipo, $cant]) {
            InventarioMovimiento::create([
                'producto_id' => $producto->id,
                'variante_id' => null,
                'ubicacion_id' => $ubic,
                'tipo' => $tipo,
                'cantidad' => $cant,
                'costo_unit' => $costo,
                'notas' => $marker,
                'referencia_tipo' => CargaInventario::class,
                'referencia_id' => $ci->id,
                'user_id' => $carga->user_id,
            ]);
        }
        $r['movimientos']++;
        $r['actualizados']++;
        if ($costo <= 0) {
            $r['sin_costo']++;
        }
        $r['filas'][$ref] = 'trasladado de Great Baby'.($costo <= 0 ? ' (sin costo: no suma al asiento)' : '');
    }
}
