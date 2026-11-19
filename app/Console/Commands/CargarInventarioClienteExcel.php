<?php

namespace App\Console\Commands;

use App\Models\Contacto;
use App\Modules\Catalogo\Models\Categoria;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Inventario\Actions\RegistrarCargaInventario;
use App\Modules\Inventario\Enums\MotivoCargaInventario;
use App\Modules\Inventario\Models\CargaInventario;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use Database\Seeders\CategoriasClienteBebesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * C-F7 · Comando para cargar el Excel de inventario del cliente Aracely.
 *
 *   php artisan inventario:cargar-excel-cliente
 *     --archivo="ruta/a/INVENTARIO DR REPORTE.xlsx"
 *     --bodega=1
 *     [--hoja=Hoja1] [--dry-run]
 *
 * Formato del Excel esperado:
 *   Fila 1: título ("INVENTARIO ACTUAL A ...")  → ignora
 *   Fila 2: headers ("Referencia | Descripcion | Existencia | Variación")
 *           + opcional una columna «Costo» / «Costo unitario» (de la E en adelante)
 *   Fila 3+:
 *     - Fila con solo col A no vacía → categoría actual (ej "ALIMENTACION")
 *     - Fila con las 4 cols → producto (crea/actualiza como agregado)
 *
 * Los productos se crean con:
 *   - desglose_stock = false  (modo agregado)
 *   - stock_directo   = existencia (se refleja también como movimiento de kardex
 *                       en la bodega indicada)
 *   - categoria_id    = la última categoría vista
 *   - nombre limpio (quitando el prefijo de referencia repetido)
 *   - variación se guarda en `descripcion` como texto informativo
 */
class CargarInventarioClienteExcel extends Command
{
    protected $signature = 'inventario:cargar-excel-cliente
        {--archivo= : Ruta absoluta al .xlsx del cliente}
        {--bodega= : ID de la bodega donde cargar el inventario inicial}
        {--hoja=Hoja1 : Nombre de la hoja del Excel a leer}
        {--dry-run : Solo muestra qué haría, no toca BD}
        {--force : Requerido en producción para confirmar la corrida}
        {--motivo= : Por qué entra la mercancía: compra | aporte_socios | saldo_inicial | donacion | sobrante}
        {--observacion= : De dónde viene la mercancía (obligatoria)}
        {--soporte= : Número de factura o documento que respalda el ingreso}
        {--tercero= : Número de documento del proveedor o socio (obligatorio para compra y aporte)}';

    protected $description = 'C-F7 · Carga inventario del cliente Aracely desde Excel (formato REPORTE) creando productos agregados con stock inicial en una bodega.';

    public function handle(): int
    {
        // C-F-QA5 · Fix CRÍTICO auditor roles: prevenir corrida accidental en prod.
        //   Sin este guard, cualquier ops con SSH puede correrlo y meter movs sin
        //   trazabilidad (el importador no llena user_id).
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('En producción requiere --force. Ejemplo: --force');
            return self::FAILURE;
        }

        $archivo = $this->option('archivo');
        $bodegaId = (int) $this->option('bodega');
        $hojaNombre = (string) $this->option('hoja');
        $dryRun = (bool) $this->option('dry-run');

        // ─── Validaciones de argumentos ───────────────────────────
        if (! $archivo || ! is_file($archivo)) {
            $this->error("--archivo no existe o no fue provisto: {$archivo}");
            return self::FAILURE;
        }
        if ($bodegaId <= 0 || ! InventarioUbicacion::whereKey($bodegaId)->exists()) {
            $this->error("--bodega debe ser un ID válido de InventarioUbicacion. Ubicaciones disponibles:");
            $this->table(['id', 'nombre'], InventarioUbicacion::query()->get(['id', 'nombre'])->toArray());
            return self::FAILURE;
        }

        // ─── Por qué entra la mercancía ───────────────────────────
        //   Igual que en la pantalla: sin motivo el ingreso no tiene
        //   contrapartida contable. El dry-run no escribe nada y no lo exige.
        $cargaDatos = null;
        if (! $dryRun) {
            $motivo = MotivoCargaInventario::tryFrom((string) $this->option('motivo'));
            if (! $motivo) {
                $this->error('--motivo es obligatorio. Opciones: '.implode(', ', array_column(MotivoCargaInventario::cases(), 'value')));
                return self::FAILURE;
            }
            $observacion = trim((string) $this->option('observacion'));
            if (mb_strlen($observacion) < 5) {
                $this->error('--observacion es obligatoria: explica de dónde viene la mercancía.');
                return self::FAILURE;
            }
            $terceroId = null;
            if ($doc = trim((string) $this->option('tercero'))) {
                $terceroId = Contacto::where('numero_documento', $doc)->value('id');
                if (! $terceroId) {
                    $this->error("No hay ningún contacto con documento {$doc}.");
                    return self::FAILURE;
                }
            }
            if ($motivo->requiereTercero() && ! $terceroId) {
                $this->error("El motivo «{$motivo->label()}» necesita --tercero (documento del proveedor o socio).");
                return self::FAILURE;
            }
            $cargaDatos = [
                'motivo' => $motivo,
                'observacion' => $observacion,
                'soporte' => trim((string) $this->option('soporte')) ?: null,
                'tercero_id' => $terceroId,
                'ubicacion_id' => $bodegaId,
                'archivo_nombre' => basename($archivo),
                'canal' => 'cli',
                'user_id' => null,
            ];
        }

        // ─── Semilla de categorías (idempotente) ──────────────────
        //   Siempre se corre (incluso en dry-run) porque es idempotente y
        //   sin sembrar no hay categorías para resolver las filas del Excel.
        $this->info('Sembrando 9 categorías del cliente (idempotente)…');
        (new CategoriasClienteBebesSeeder())->setContainer(app())->setCommand($this)->run();

        // Mapa nombre → categoría para lookup rápido.
        $catsPorNombre = Categoria::all()->keyBy(fn ($c) => mb_strtoupper(trim($c->nombre)));

        // ─── Lectura del Excel ────────────────────────────────────
        $reader = new XlsxReader();
        $reader->open($archivo);

        $categoriaActual = null;
        $creados = 0;
        $actualizados = 0;
        $movsInsertados = 0;
        $filasIgnoradas = 0;
        $sinCategoria = 0;
        $carga = null;
        // Columna opcional de costo unitario, reconocida por el encabezado
        // (igual que en la pantalla de importación).
        $colCosto = null;

        try {
            $callback = function () use (
                $reader, $hojaNombre, $bodegaId, $dryRun, $catsPorNombre,
                &$categoriaActual, &$creados, &$actualizados, &$movsInsertados,
                &$filasIgnoradas, &$sinCategoria, $cargaDatos, &$carga, &$colCosto
            ) {
                foreach ($reader->getSheetIterator() as $sheet) {
                    if ($sheet->getName() !== $hojaNombre) continue;

                    foreach ($sheet->getRowIterator() as $i => $row) {
                        $crudas = array_map(fn ($c) => $c->getValue(), $row->getCells());
                        $celdas = array_map(function ($v) {
                            // El cliente pone fechas en la fila 1 (título) → openspout devuelve DateTimeImmutable.
                            if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
                            return trim((string) $v);
                        }, $crudas);

                        // fila 1 (título) y fila 2 (headers): solo se busca la columna de costo.
                        if ($i <= 2) {
                            foreach ($celdas as $idx => $texto) {
                                if ($colCosto === null && $idx >= 4 && RegistrarCargaInventario::esEncabezadoCosto($texto)) {
                                    $colCosto = $idx;
                                }
                            }
                            continue;
                        }

                        [$ref, $desc, $existencia, $variacion] = array_pad(array_slice($celdas, 0, 4), 4, '');
                        $crudo = $colCosto !== null ? ($crudas[$colCosto] ?? null) : null;
                        $costoExcel = (is_int($crudo) || is_float($crudo))
                            ? ($crudo > 0 ? round((float) $crudo, 4) : null)
                            : RegistrarCargaInventario::parsearCosto((string) ($crudo ?? ''));

                        // Fila vacía completa → siguiente.
                        if ($ref === '' && $desc === '' && $existencia === '') {
                            $filasIgnoradas++;
                            continue;
                        }

                        // Fila de CATEGORÍA (solo col A, resto vacío).
                        if ($ref !== '' && $desc === '' && $existencia === '') {
                            $nombreCat = mb_strtoupper(trim($ref));
                            $categoriaActual = $catsPorNombre->get($nombreCat);
                            if (! $categoriaActual) {
                                $this->warn("  Categoría '{$nombreCat}' no encontrada · seedeando ad-hoc.");
                                if (! $dryRun) {
                                    $categoriaActual = Categoria::firstOrCreate(
                                        ['nombre' => $nombreCat],
                                        ['codigo' => mb_substr($nombreCat, 0, 4), 'activa' => true],
                                    );
                                    $catsPorNombre[$nombreCat] = $categoriaActual;
                                }
                            }
                            continue;
                        }

                        // Fila de PRODUCTO — pero antes filtrar filas basura del pie del Excel:
                        //   - "TOTAL EXISTENCIA" y variantes (fila de totales)
                        //   - fechas ISO en columna A (fila de fecha de emisión al final)
                        //   - descripción vacía (no es producto válido)
                        if ($ref === '') { $filasIgnoradas++; continue; }
                        if ($this->esBasuraDePie($ref, $desc)) { $filasIgnoradas++; continue; }
                        if ($desc === '') { $filasIgnoradas++; continue; }
                        if (! $categoriaActual) { $sinCategoria++; continue; }

                        $nombreLimpio = $this->limpiarNombre($desc, $ref);
                        $stock = (int) preg_replace('/[^0-9\-]/', '', $existencia);
                        $variacion = $variacion === '' || $variacion === 'NA' ? null : $variacion;

                        if ($dryRun) {
                            $this->line("  [DRY] {$ref} · {$nombreLimpio} · stock={$stock} · cat={$categoriaActual->nombre} · var=".($variacion ?: '—')
                                .' · costo='.($costoExcel !== null ? $costoExcel : 'catálogo'));
                            continue;
                        }

                        // FIX CRÍTICO auditor · rechazar refs que ya existen como granular.
                        //   Si updateOrCreate intenta cambiar desglose_stock=true → false
                        //   sobre un producto con movimientos, el trigger de bloqueo
                        //   aborta TODA la transacción. Peor aún: si no tiene movs, el
                        //   toggle sí cambia y queda un producto con variantes colgando
                        //   pero en modo agregado (estado inconsistente).
                        //   Solución raíz: buscar primero, si existe granular saltar
                        //   con warning; solo update si ya es agregado.
                        $existente = Producto::where('referencia', $ref)->first();
                        if ($existente && $existente->desglose_stock) {
                            $this->warn("  ⚠️  Skip: '{$ref}' ya existe como GRANULAR (id {$existente->id}). Import solo actualiza productos agregados. Renombra la referencia si es un producto distinto.");
                            $filasIgnoradas++;
                            continue;
                        }

                        $producto = Producto::updateOrCreate(
                            ['referencia' => $ref],
                            [
                                'nombre' => $nombreLimpio,
                                'categoria_id' => $categoriaActual->id,
                                'descripcion' => $variacion ? "Colores/variación: {$variacion}" : null,
                                'activo' => true,
                                'desglose_stock' => false,
                                'stock_directo' => $stock,
                                // Solo fijar precio si es creación o si estaba en 0
                                // (evita pisar precio real que Aracely cargó después).
                                ...(! $existente
                                    ? ['precio_proveedor' => $costoExcel ?? 0]
                                    : ($costoExcel !== null && (float) $existente->precio_proveedor <= 0
                                        ? ['precio_proveedor' => $costoExcel]
                                        : [])),
                            ],
                        );

                        if ($producto->wasRecentlyCreated) { $creados++; } else { $actualizados++; }

                        // Movimiento inicial en kardex — idempotente por (producto, ubicacion, tipo, notas).
                        if ($stock > 0) {
                            $notaMarker = "C-F7 · Carga inicial Excel cliente · ref {$ref}";
                            $existe = InventarioMovimiento::query()
                                ->where('producto_id', $producto->id)
                                ->whereNull('variante_id')
                                ->where('ubicacion_id', $bodegaId)
                                ->where('notas', $notaMarker)
                                ->exists();
                            if (! $existe) {
                                if ($cargaDatos !== null && $carga === null) {
                                    $carga = RegistrarCargaInventario::abrir($cargaDatos);
                                }
                                InventarioMovimiento::create([
                                    'producto_id' => $producto->id,
                                    'variante_id' => null,
                                    'ubicacion_id' => $bodegaId,
                                    'tipo' => 'carga_inicial_cliente',
                                    'cantidad' => $stock,
                                    // Mismo costo que toma la pantalla: sin él la fila
                                    // no vale nada en el asiento de la carga.
                                    'costo_unit' => $costoExcel ?? (float) ($producto->precio_proveedor ?: 0),
                                    'notas' => $notaMarker,
                                    'referencia_tipo' => $carga ? CargaInventario::class : null,
                                    'referencia_id' => $carga?->id,
                                ]);
                                $movsInsertados++;
                            }
                        }
                    }
                }

                if ($carga !== null) {
                    $carga = RegistrarCargaInventario::cerrar($carga);
                }
            };

            // Mismo motivo que en la pantalla de importación: este comando
            // escribe su propio kardex en la bodega indicada, así que el
            // automático de `Producto::created` duplicaría el stock y pondría
            // la mitad en «la primera ubicación activa».
            Producto::sinKardexAutomatico(function () use ($dryRun, $callback) {
                if ($dryRun) {
                    $callback();
                } else {
                    DB::transaction($callback);
                }
            });
        } finally {
            $reader->close();
        }

        // ─── Reporte final ────────────────────────────────────────
        $this->newLine();
        $this->info($dryRun ? '════ DRY RUN ════' : '════ CARGA COMPLETADA ════');
        $this->table(
            ['métrica', 'valor'],
            [
                ['Productos creados',            $creados],
                ['Productos actualizados',       $actualizados],
                ['Movs de kardex insertados',    $movsInsertados],
                ['Filas ignoradas (vacías)',     $filasIgnoradas],
                ['Filas sin categoría (skip)',   $sinCategoria],
            ],
        );

        if ($carga) {
            $this->info("Documento {$carga->numero} · {$carga->motivo->label()} · asiento por $"
                .number_format((float) $carga->total_valor, 0, ',', '.')
                ." contra la cuenta {$carga->cuenta_contrapartida}");
            if ($carga->referencias_sin_costo > 0) {
                $this->warn("  ⚠ {$carga->referencias_sin_costo} referencias sin costo: movieron stock pero no entraron al asiento.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Limpia el nombre quitando el prefijo de referencia repetido.
     * Ej: descripción "202510-5 LAVA TETERO" con ref "202510-5" → "LAVA TETERO".
     */
    private function limpiarNombre(string $desc, string $ref): string
    {
        $limpio = trim($desc);
        if ($ref !== '' && str_starts_with($limpio, $ref)) {
            $limpio = trim(mb_substr($limpio, mb_strlen($ref)));
        }
        return $limpio !== '' ? $limpio : $ref;
    }

    /**
     * Filtra filas del pie del Excel que no son productos:
     *   - "134 | TOTAL EXISTENCIA : | 8554" (totales)
     *   - "2026-09-19 | 1899-12-30 | 0" (fecha de emisión residual)
     * Se detectan porque `desc` contiene tokens típicos de esas filas.
     */
    private function esBasuraDePie(string $ref, string $desc): bool
    {
        $descUpper = mb_strtoupper($desc);
        if (str_contains($descUpper, 'TOTAL EXISTENCIA')) return true;
        if (str_contains($descUpper, 'TOTAL EXISTENCI')) return true; // por si trunca
        // Fila de fecha: ambos ref y desc son fechas ISO (YYYY-MM-DD).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desc)) {
            return true;
        }
        return false;
    }
}
