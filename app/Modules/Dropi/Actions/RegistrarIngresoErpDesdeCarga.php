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

            Producto::sinKardexAutomatico(function () use ($carga, $ingreso, $motivo, $ubicacionId, &$ci, &$r) {
                foreach ($carga->filas_excel ?? [] as $f) {
                    $ref = trim((string) $f['ref']);
                    $cantidad = (int) round((float) $f['cantidad']);
                    if ($ref === '' || $cantidad <= 0) {
                        continue;
                    }
                    $costo = isset($f['costo']) && (float) $f['costo'] > 0 ? (float) $f['costo'] : null;

                    $producto = Producto::where('referencia', $ref)->first();
                    if ($producto && $producto->desglose_stock) {
                        $r['omitidos']++;
                        $r['filas'][$ref] = 'omitido: en el ERP se maneja por variantes (granular)';
                        continue;
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
}
