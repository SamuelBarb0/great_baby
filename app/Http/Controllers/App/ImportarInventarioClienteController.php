<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Catalogo\Models\Categoria;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Actions\RegistrarCargaInventario;
use App\Modules\Inventario\Enums\MotivoCargaInventario;
use App\Modules\Inventario\Models\CargaInventario;
use Database\Seeders\CategoriasClienteBebesSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importador Excel del cliente Aracely desde UI · reutiliza la lógica del command
 *   `inventario:cargar-excel-cliente` sin exigir SSH. Aracely arrastra el .xlsx
 *   (formato REPORTE del cliente con filas de categoría + productos agregados)
 *   y el sistema crea 134 productos agregados con stock inicial en la bodega
 *   seleccionada.
 */
class ImportarInventarioClienteController extends Controller
{
    public function show()
    {
        abort_unless(auth()->user()?->esEquipoBodega(), 403);  // A1 FIX · AdminBodega debe poder importar inventario del cliente

        $ubicaciones = InventarioUbicacion::query()
            ->where(fn ($q) => $q->where('activa', true)->orWhereNull('activa'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo', 'bodega_id', 'pasillo', 'estante', 'nivel', 'responsable_user_id']);

        // Las sedes de Great Baby van primero y son el default.
        //
        // El ambiente de SIIGO es compartido, así que el catálogo de warehouses
        // trae decenas de bodegas de otras empresas («01», «Three Labs SAS
        // Principal», «Bodega Diesel»…). Ordenado solo por nombre, la primera
        // opción —y por lo tanto el default— era una bodega ajena: bastaba
        // subir el Excel sin tocar el select para mandar las 134 referencias
        // al depósito de un tercero.
        $sede = fn ($b) => $b->bodega_id === null && $b->responsable_user_id !== null;

        $opciones = $ubicaciones
            ->sortBy([
                fn ($a, $b) => ($sede($b) ? 1 : 0) <=> ($sede($a) ? 1 : 0),
                fn ($a, $b) => ($a->bodega_id === null ? 0 : 1) <=> ($b->bodega_id === null ? 0 : 1),
                fn ($a, $b) => strcasecmp($a->nombre, $b->nombre),
            ])
            ->values()
            ->map(fn ($b) => [
                'id' => $b->id,
                'label' => "{$b->nombre} ({$b->codigo})"
                    .($b->posicion() ? ' · '.$b->posicion() : ''),
                // `es_bodega` manda en la pantalla: el pasillo/estante/nivel
                // solo se puede pedir si el destino es una bodega. Si ya se
                // eligió una posición concreta, anidar otra adentro no existe
                // en el modelo.
                'es_bodega' => $b->esBodega(),
                'grupo' => $sede($b)
                    ? 'Sedes de Great Baby'
                    : ($b->esBodega() ? 'Otras bodegas' : 'Posiciones dentro de una bodega'),
            ]);

        return Inertia::render('Inventario/ImportarCliente', [
            'bodegas' => $opciones,
            'grupos' => $opciones->pluck('grupo')->unique()->values(),
            'motivos' => MotivoCargaInventario::opciones(),
        ]);
    }

    public function procesar(Request $request)
    {
        abort_unless(auth()->user()?->esEquipoBodega(), 403);  // A1 FIX · AdminBodega debe poder importar inventario del cliente

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'], // 10 MB
            'bodega_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'hoja' => ['nullable', 'string', 'max:60'],
            'dry_run' => ['nullable', 'boolean'],
            // Posición por defecto para las filas que no la traigan en el Excel.
            'pasillo' => ['nullable', 'string', 'max:30'],
            'estante' => ['nullable', 'string', 'max:30'],
            'nivel' => ['nullable', 'string', 'max:30'],
            // Por qué entra la mercancía. Sin esto el ingreso no tiene
            // contrapartida contable y no pasa una auditoría.
            'motivo' => ['required', Rule::enum(MotivoCargaInventario::class)->only(MotivoCargaInventario::delErp())],
            'observacion' => ['required', 'string', 'min:5', 'max:1000'],
            'soporte' => ['nullable', 'string', 'max:60'],
            'tercero_id' => ['nullable', 'integer', 'exists:contactos,id'],
        ], [
            'motivo.required' => 'Elige por qué entra esta mercancía.',
            'observacion.required' => 'Escribe una observación: de dónde viene la mercancía.',
            'observacion.min' => 'La observación es muy corta: explica de dónde viene la mercancía.',
        ]);

        $motivo = MotivoCargaInventario::from($data['motivo']);
        if ($motivo->requiereTercero() && empty($data['tercero_id'])) {
            return back()->withErrors([
                'tercero_id' => $motivo === MotivoCargaInventario::Compra
                    ? 'Una compra se le debe a un proveedor: elige a quién.'
                    : 'Un aporte lo hace un socio: elige quién.',
            ]);
        }

        $path = $request->file('archivo')->getRealPath();
        $hoja = $data['hoja'] ?: 'Hoja1';
        $bodegaId = (int) $data['bodega_id'];
        $dryRun = (bool) ($data['dry_run'] ?? false);
        $posDefecto = [
            'pasillo' => trim((string) ($data['pasillo'] ?? '')),
            'estante' => trim((string) ($data['estante'] ?? '')),
            'nivel' => trim((string) ($data['nivel'] ?? '')),
        ];

        $cargaDatos = [
            'motivo' => $motivo,
            'observacion' => trim($data['observacion']),
            'soporte' => trim((string) ($data['soporte'] ?? '')) ?: null,
            'tercero_id' => isset($data['tercero_id']) ? (int) $data['tercero_id'] : null,
            'ubicacion_id' => $bodegaId,
            'archivo_nombre' => $request->file('archivo')->getClientOriginalName(),
            'canal' => 'web',
        ];

        try {
            $resumen = $this->cargar($path, $bodegaId, $hoja, $dryRun, $posDefecto, $cargaDatos);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'archivo' => 'No pude leer el Excel: '.$e->getMessage()
                    .'. Verifica que el nombre de la hoja sea "'.$hoja.'".',
            ]);
        }

        $accion = $dryRun ? '✅ Simulación (dry-run) completada' : '✅ Inventario cargado correctamente';
        $detalle = "Productos creados: {$resumen['creados']} · actualizados: {$resumen['actualizados']} · "
            ."movimientos de kardex: {$resumen['movs']} · filas ignoradas: {$resumen['ignoradas']}"
            .($resumen['sin_cat'] > 0 ? " · SIN CATEGORÍA: {$resumen['sin_cat']}" : '')
            .($resumen['omitidos_granular'] > 0 ? " · saltados (ya existen como granular): {$resumen['omitidos_granular']}" : '')
            .($resumen['ubicaciones_creadas'] > 0
                ? ($dryRun ? " · posiciones que se crearían: {$resumen['ubicaciones_creadas']}" : " · posiciones creadas: {$resumen['ubicaciones_creadas']}")
                : '')
            .($resumen['pos_ignoradas'] > 0 ? " · ⚠ filas con pasillo/estante/nivel ignorado: {$resumen['pos_ignoradas']}" : '')
            .($resumen['ya_cargados'] > 0 ? " · ya cargados antes (no se repiten): {$resumen['ya_cargados']}" : '')
            .($resumen['carga']
                ? " · documento {$resumen['carga']['numero']} · asiento por \$".number_format($resumen['carga']['total_valor'], 0, ',', '.')
                : '');

        // Fix bug feedback UX · usar clave 'success' que sí está compartida por
        // HandleInertiaRequests. Antes usaba 'flash' custom → Vue no la veía y
        // Aracely no sabía si la carga funcionó.
        return back()
            ->with('success', $accion.' · '.$detalle)
            ->with('importResumen', [
                'accion' => $accion,
                'detalle' => $detalle,
                'creados' => $resumen['creados'],
                'actualizados' => $resumen['actualizados'],
                'movs' => $resumen['movs'],
                'ignoradas' => $resumen['ignoradas'],
                'sin_cat' => $resumen['sin_cat'],
                'omitidos_granular' => $resumen['omitidos_granular'],
                'ubicaciones_creadas' => $resumen['ubicaciones_creadas'],
                'ubicaciones_detalle' => $resumen['ubicaciones_detalle'],
                'pos_ignoradas' => $resumen['pos_ignoradas'],
                'columnas_posicion' => $resumen['columnas_posicion'],
                'ya_cargados' => $resumen['ya_cargados'],
                'columna_costo' => $resumen['columna_costo'],
                'con_costo_excel' => $resumen['con_costo_excel'],
                'costo_ilegible' => $resumen['costo_ilegible'],
                // Lo contable: cuánto vale lo que entra, cuántas filas no tienen
                // costo (no entran al asiento) y contra qué cuenta se acredita.
                'valor' => $resumen['valor'],
                'sin_costo' => $resumen['sin_costo'],
                'sin_costo_detalle' => $resumen['sin_costo_detalle'],
                'motivo' => $motivo->label(),
                'cuenta_contrapartida' => $motivo->cuentaContrapartida(),
                'carga' => $resumen['carga'],
                'dry_run' => $dryRun,
            ]);
    }

    /**
     * Núcleo de carga · lee el Excel del cliente y crea/actualiza productos agregados.
     * Idempotente sobre (producto, ubicacion, marker) para permitir re-corridas seguras.
     *
     * Dónde queda parada la mercancía
     * ───────────────────────────────
     * El Excel puede traer la posición física en las columnas E, F y G
     * (pasillo, estante, nivel). Si la fila las trae, el producto entra en esa
     * posición dentro de la bodega elegida —creándola si no existía—; si no las
     * trae, se usa la posición por defecto del formulario, y si tampoco hay,
     * entra directo a la bodega.
     *
     * Era el agujero: la carga mandaba las 134 referencias a la bodega como un
     * bulto único, así que después nadie sabía en qué pasillo estaban los
     * pañales de recién nacido y el conteo había que hacerlo caminando.
     *
     * Por qué entró y contra qué cuenta
     * ─────────────────────────────────
     * `$cargaDatos` trae el motivo, la observación y el tercero. La carga
     * (documento CI-…) se abre al escribir el primer movimiento —una re-subida
     * que no mueve nada no deja documentos vacíos—, cada fila del kardex
     * cuelga de ella y al final se escribe el asiento 1435 contra la cuenta del
     * motivo, todo dentro de la misma transacción: o entra el stock con su
     * asiento, o no entra nada.
     *
     * @param  array{pasillo:string,estante:string,nivel:string}  $posDefecto
     * @param  ?array<string,mixed>  $cargaDatos  datos de RegistrarCargaInventario::abrir()
     */
    protected function cargar(string $path, int $bodegaId, string $hojaNombre, bool $dryRun, array $posDefecto = ['pasillo' => '', 'estante' => '', 'nivel' => ''], ?array $cargaDatos = null): array
    {
        $carga = null;
        $valor = 0.0;
        $sinCosto = 0;
        $sinCostoDetalle = [];
        $yaCargados = 0;
        $anotarValor = function (string $ref, int $stock, float $costo) use (&$valor, &$sinCosto, &$sinCostoDetalle) {
            if ($stock <= 0) return;
            if ($costo > 0) {
                $valor += round($stock * $costo, 2);
                return;
            }
            $sinCosto++;
            if (count($sinCostoDetalle) < 50) $sinCostoDetalle[] = $ref;
        };

        // El destino tiene que ser una bodega para poder colgarle posiciones.
        // Si Aracely eligió una posición concreta, la mercancía va ahí tal cual
        // y lo que traiga el Excel se reporta como ignorado — no se inventa un
        // nivel adentro de otro nivel.
        $bodega = InventarioUbicacion::find($bodegaId);
        $destinoEsBodega = $bodega ? $bodega->esBodega() : true;
        $codigoBodega = $bodega->codigo ?? 'UB';
        $catBodega = $bodega?->categoria?->value ?? 'venta';
        $cacheUbic = [];
        $ubicacionesCreadas = [];
        $posIgnoradas = 0;
        // Qué columna es pasillo/estante/nivel se decide leyendo el encabezado,
        // no por posición fija. El REPORTE del cliente puede traer columnas
        // extra (totales, fechas) y tomar «la quinta» a ciegas habría metido
        // esa basura como nombre de pasillo, creando ubicaciones inventadas.
        $colPos = ['pasillo' => null, 'estante' => null, 'nivel' => null];
        // Columna opcional de costo unitario, también por encabezado. Sin ella
        // todo producto nuevo entraba a costo 0 y la carga no valía nada en el
        // libro: el stock subía pero el 1435 no se movía.
        $colCosto = null;
        $colCostoNombre = null;
        $conCostoExcel = 0;
        $costoIlegible = [];

        (new CategoriasClienteBebesSeeder())->setContainer(app())->run();
        $catsPorNombre = Categoria::all()->keyBy(fn ($c) => mb_strtoupper(trim($c->nombre)));

        $reader = new XlsxReader();
        $reader->open($path);

        $categoriaActual = null;
        $creados = 0; $actualizados = 0; $movs = 0;
        $ignoradas = 0; $sinCat = 0; $omitidosGran = 0;

        try {
            $run = function () use (
                $reader, $hojaNombre, $bodegaId, $dryRun, &$catsPorNombre,
                &$categoriaActual, &$creados, &$actualizados, &$movs,
                &$ignoradas, &$sinCat, &$omitidosGran,
                $posDefecto, $destinoEsBodega, $codigoBodega, $catBodega,
                &$cacheUbic, &$ubicacionesCreadas, &$posIgnoradas, &$colPos,
                $cargaDatos, &$carga, &$yaCargados, $anotarValor,
                &$colCosto, &$colCostoNombre, &$conCostoExcel, &$costoIlegible
            ) {
                foreach ($reader->getSheetIterator() as $sheet) {
                    if ($sheet->getName() !== $hojaNombre) continue;

                    foreach ($sheet->getRowIterator() as $i => $row) {
                        $crudas = array_map(fn ($c) => $c->getValue(), $row->getCells());
                        $celdas = array_map(function ($v) {
                            if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
                            return trim((string) $v);
                        }, $crudas);

                        // Las dos primeras filas son título + encabezado. De ahí
                        // se saca en qué columna viene cada parte de la posición.
                        if ($i <= 2) {
                            foreach ($celdas as $idx => $texto) {
                                $t = mb_strtolower($texto);
                                foreach (['pasillo', 'estante', 'nivel'] as $campo) {
                                    if ($colPos[$campo] === null && str_contains($t, $campo)) {
                                        $colPos[$campo] = $idx;
                                    }
                                }
                                if ($colCosto === null && $idx >= 4 && RegistrarCargaInventario::esEncabezadoCosto($texto)) {
                                    $colCosto = $idx;
                                    $colCostoNombre = $texto;
                                }
                            }
                            continue;
                        }

                        [$ref, $desc, $existencia, $variacion] = array_pad(array_slice($celdas, 0, 4), 4, '');
                        $leerCol = fn (?int $idx) => $idx === null ? '' : trim((string) ($celdas[$idx] ?? ''));
                        $colPasillo = $leerCol($colPos['pasillo']);
                        $colEstante = $leerCol($colPos['estante']);
                        $colNivel = $leerCol($colPos['nivel']);

                        if ($ref === '' && $desc === '' && $existencia === '') { $ignoradas++; continue; }

                        // Fila de categoría (solo col A).
                        if ($ref !== '' && $desc === '' && $existencia === '') {
                            $nombreCat = mb_strtoupper(trim($ref));
                            $categoriaActual = $catsPorNombre->get($nombreCat);
                            if (! $categoriaActual && ! $dryRun) {
                                $categoriaActual = Categoria::firstOrCreate(
                                    ['nombre' => $nombreCat],
                                    ['codigo' => mb_substr($nombreCat, 0, 4), 'activa' => true],
                                );
                                $catsPorNombre[$nombreCat] = $categoriaActual;
                            }
                            continue;
                        }

                        if ($ref === '') { $ignoradas++; continue; }
                        if ($this->esBasuraDePie($ref, $desc)) { $ignoradas++; continue; }
                        if ($desc === '') { $ignoradas++; continue; }
                        if (! $categoriaActual) { $sinCat++; continue; }

                        $nombreLimpio = $this->limpiarNombre($desc, $ref);
                        $stock = (int) preg_replace('/[^0-9\-]/', '', $existencia);
                        $variacion = ($variacion === '' || $variacion === 'NA') ? null : $variacion;

                        // Costo del Excel: una celda numérica no tiene ambigüedad;
                        // el texto («$ 45.000») se interpreta en formato colombiano.
                        // Si no hay o no se entiende, la fila usa el del catálogo.
                        $costoExcel = null;
                        if ($colCosto !== null) {
                            $crudo = $crudas[$colCosto] ?? null;
                            $costoExcel = (is_int($crudo) || is_float($crudo))
                                ? ($crudo > 0 ? round((float) $crudo, 4) : null)
                                : RegistrarCargaInventario::parsearCosto((string) ($crudo ?? ''));
                            if ($costoExcel === null && trim((string) ($crudo ?? '')) !== '' && count($costoIlegible) < 50) {
                                $costoIlegible[] = "{$ref}: «{$crudo}»";
                            }
                            if ($costoExcel !== null && $stock > 0) $conCostoExcel++;
                        }

                        // La posición se calcula antes del corte de dry-run: la
                        // simulación tiene que poder decir cuántas posiciones
                        // nuevas aparecerían, que es justo lo que se revisa
                        // antes de cargar en firme.
                        $pos = [
                            'pasillo' => $colPasillo !== '' ? $colPasillo : $posDefecto['pasillo'],
                            'estante' => $colEstante !== '' ? $colEstante : $posDefecto['estante'],
                            'nivel' => $colNivel !== '' ? $colNivel : $posDefecto['nivel'],
                        ];
                        $traePos = $pos['pasillo'] !== '' || $pos['estante'] !== '' || $pos['nivel'] !== '';

                        if ($dryRun) {
                            // La pantalla promete que la simulación dice cuántos
                            // se crean y cuántos se actualizan. Antes salía 0 y 0
                            // siempre, porque el conteo vivía después de este
                            // corte: el dry-run no servía para lo único que se
                            // le pedía.
                            $yaExiste = Producto::where('referencia', $ref)->first();
                            if ($yaExiste && $yaExiste->desglose_stock) {
                                $omitidosGran++;
                            } elseif ($yaExiste) {
                                $actualizados++;
                                $anotarValor($ref, $stock, $costoExcel ?? (float) ($yaExiste->precio_proveedor ?: 0));
                            } else {
                                $creados++;
                                // Producto nuevo: nace con el costo del Excel, o 0.
                                $anotarValor($ref, $stock, $costoExcel ?? 0.0);
                            }

                            if ($traePos && $stock > 0) {
                                if (! $destinoEsBodega) {
                                    $posIgnoradas++;
                                } else {
                                    $this->resolverPosicion(
                                        $bodegaId, $codigoBodega, $catBodega, $pos,
                                        $cacheUbic, $ubicacionesCreadas, true
                                    );
                                }
                            }
                            continue;
                        }

                        // Rechazo raíz: si ya existe como granular, no forzamos toggle.
                        $existente = Producto::where('referencia', $ref)->first();
                        if ($existente && $existente->desglose_stock) {
                            $omitidosGran++; continue;
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
                                // El costo del Excel llena el catálogo sólo donde no
                                // había uno: producto nuevo o con precio de proveedor
                                // en 0. Un costo real que Aracely ya cargó no se pisa.
                                ...(! $existente
                                    ? ['precio_proveedor' => $costoExcel ?? 0]
                                    : ($costoExcel !== null && (float) $existente->precio_proveedor <= 0
                                        ? ['precio_proveedor' => $costoExcel]
                                        : [])),
                            ],
                        );
                        if ($producto->wasRecentlyCreated) { $creados++; } else { $actualizados++; }

                        // Mov inicial en kardex — idempotente.
                        if ($stock > 0) {
                            if ($traePos && ! $destinoEsBodega) {
                                $posIgnoradas++;
                                $destinoId = $bodegaId;
                            } elseif ($traePos) {
                                $destinoId = $this->resolverPosicion(
                                    $bodegaId, $codigoBodega, $catBodega, $pos,
                                    $cacheUbic, $ubicacionesCreadas
                                );
                            } else {
                                $destinoId = $bodegaId;
                            }

                            $marker = "C-F7 · Carga inicial Excel cliente · ref {$ref}";
                            $existeMov = InventarioMovimiento::query()
                                ->where('producto_id', $producto->id)
                                ->whereNull('variante_id')
                                ->where('ubicacion_id', $destinoId)
                                ->where('notas', $marker)
                                ->exists();
                            if ($existeMov) {
                                $yaCargados++;
                            } else {
                                if ($cargaDatos !== null && $carga === null) {
                                    $carga = RegistrarCargaInventario::abrir($cargaDatos);
                                }
                                // El costo de ESTE lote: el del Excel si vino, si no
                                // el del catálogo.
                                $costoUnit = $costoExcel ?? (float) ($producto->precio_proveedor ?: 0);
                                $anotarValor($ref, $stock, $costoUnit);
                                InventarioMovimiento::create([
                                    'producto_id' => $producto->id,
                                    'variante_id' => null,
                                    'ubicacion_id' => $destinoId,
                                    'tipo' => 'carga_inicial_cliente',
                                    'cantidad' => $stock,
                                    // El Excel del cliente trae existencias pero no costos,
                                    // así que se toma el del catálogo. Sin costo el
                                    // movimiento no se puede asentar y el push a SIIGO muere
                                    // con «Movimiento kardex N sin costo_unit»: así quedaron
                                    // 134 cargas sin contabilizar. Si el producto todavía no
                                    // tiene precio de proveedor queda en 0 y lo completa
                                    // después `php artisan inventario:reparar-kardex`.
                                    'costo_unit' => $costoUnit,
                                    'notas' => $marker,
                                    // El porqué de la entrada vive en la carga.
                                    'referencia_tipo' => $carga ? CargaInventario::class : null,
                                    'referencia_id' => $carga?->id,
                                    'user_id' => auth()->id(),
                                ]);
                                $movs++;
                            }
                        }
                    }
                }

                // Mismo commit que el stock: si el asiento no cuadra, no entra nada.
                if ($carga !== null) {
                    $carga = RegistrarCargaInventario::cerrar($carga);
                }
            };

            // El kardex de esta carga lo escribe el bloque de arriba, en la
            // bodega y posición elegidas. Sin apagar el automático, cada
            // referencia nueva entraba dos veces y la mitad del stock quedaba
            // en «la primera ubicación activa».
            Producto::sinKardexAutomatico(function () use ($dryRun, $run) {
                if ($dryRun) { $run(); } else { DB::transaction($run); }
            });
        } finally {
            $reader->close();
        }

        return compact('creados', 'actualizados', 'movs', 'ignoradas', 'sinCat', 'omitidosGran')
            + [
                'sin_cat' => $sinCat,
                'omitidos_granular' => $omitidosGran,
                'ubicaciones_creadas' => count($ubicacionesCreadas),
                'ubicaciones_detalle' => array_values($ubicacionesCreadas),
                'pos_ignoradas' => $posIgnoradas,
                'columnas_posicion' => array_keys(array_filter($colPos, fn ($v) => $v !== null)),
                'ya_cargados' => $yaCargados,
                'columna_costo' => $colCostoNombre,
                'con_costo_excel' => $conCostoExcel,
                'costo_ilegible' => $costoIlegible,
                'valor' => round($valor, 2),
                'sin_costo' => $sinCosto,
                'sin_costo_detalle' => $sinCostoDetalle,
                'carga' => $carga ? [
                    'id' => $carga->id,
                    'numero' => $carga->numero,
                    'total_valor' => (float) $carga->total_valor,
                    'url' => "/app/inventario/cargas/{$carga->id}",
                ] : null,
            ];
    }

    /**
     * Devuelve el id de la posición (pasillo/estante/nivel) dentro de la bodega,
     * creándola la primera vez que aparece en el Excel.
     *
     * El match es por los tres campos normalizados, no por el código: así
     * «Pasillo 4» y «pasillo 04» no terminan siendo dos estantes distintos
     * cuando el Excel viene escrito a mano.
     *
     * @param  array{pasillo:string,estante:string,nivel:string}  $pos
     * @param  array<string,int>  $cache
     * @param  array<string,string>  $creadas
     *
     * Con `$simular` no escribe nada: solo anota qué posición haría falta, para
     * que el dry-run muestre un número real y no un cero decorativo.
     */
    protected function resolverPosicion(int $bodegaId, string $codigoBodega, string $catBodega, array $pos, array &$cache, array &$creadas, bool $simular = false): int
    {
        $norm = fn (string $v) => mb_strtoupper(preg_replace('/\s+/', ' ', trim($v)));
        $clave = $bodegaId.'|'.$norm($pos['pasillo']).'|'.$norm($pos['estante']).'|'.$norm($pos['nivel']);

        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $existente = InventarioUbicacion::where('bodega_id', $bodegaId)
            ->whereRaw("UPPER(TRIM(COALESCE(pasillo, ''))) = ?", [$norm($pos['pasillo'])])
            ->whereRaw("UPPER(TRIM(COALESCE(estante, ''))) = ?", [$norm($pos['estante'])])
            ->whereRaw("UPPER(TRIM(COALESCE(nivel, ''))) = ?", [$norm($pos['nivel'])])
            ->first();

        if ($existente) {
            return $cache[$clave] = (int) $existente->id;
        }

        if ($simular) {
            $creadas[$clave] = $this->codigoPosicion($codigoBodega, $pos).' · '.$this->nombrePosicion($pos);

            return $cache[$clave] = $bodegaId;
        }

        $nueva = InventarioUbicacion::create([
            'bodega_id' => $bodegaId,
            'pasillo' => $pos['pasillo'] ?: null,
            'estante' => $pos['estante'] ?: null,
            'nivel' => $pos['nivel'] ?: null,
            'codigo' => $this->codigoPosicion($codigoBodega, $pos),
            'nombre' => $this->nombrePosicion($pos),
            // Hereda la categoría de la bodega: un estante dentro de una bodega
            // de venta es stock de venta. Poner 'venta' a ciegas metería al
            // stock vendible mercancía averiada o reservada para garantía.
            'categoria' => $catBodega,
            'activa' => true,
            'notas' => 'Creada automáticamente al importar el Excel de inventario.',
        ]);

        $creadas[$clave] = $nueva->codigo.' · '.$nueva->nombre;

        return $cache[$clave] = (int) $nueva->id;
    }

    /** «Pasillo 4 · Estante 6 · Nivel 3» — cabe en `nombre` varchar(100). */
    protected function nombrePosicion(array $pos): string
    {
        return mb_substr(implode(' · ', array_filter([
            $pos['pasillo'] !== '' ? "Pasillo {$pos['pasillo']}" : null,
            $pos['estante'] !== '' ? "Estante {$pos['estante']}" : null,
            $pos['nivel'] !== '' ? "Nivel {$pos['nivel']}" : null,
        ])), 0, 100);
    }

    /** Código único y legible: BOG-P4-E6-N3. */
    protected function codigoPosicion(string $codigoBodega, array $pos): string
    {
        $tr = fn (string $v) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($v));
        $partes = array_filter([
            $pos['pasillo'] !== '' ? 'P'.$tr($pos['pasillo']) : null,
            $pos['estante'] !== '' ? 'E'.$tr($pos['estante']) : null,
            $pos['nivel'] !== '' ? 'N'.$tr($pos['nivel']) : null,
        ]);
        $base = mb_substr($tr($codigoBodega).'-'.implode('-', $partes), 0, 40);

        // `codigo` es UNIQUE en toda la tabla: si otra bodega ya usó ese código
        // —o quedó uno viejo con el mismo nombre— se desempata con un sufijo en
        // vez de reventar la importación completa con un error de SQL.
        $codigo = $base;
        $n = 2;
        while (InventarioUbicacion::where('codigo', $codigo)->exists()) {
            $sufijo = '-'.$n++;
            $codigo = mb_substr($base, 0, 40 - mb_strlen($sufijo)).$sufijo;
        }

        return $codigo;
    }

    private function limpiarNombre(string $desc, string $ref): string
    {
        $limpio = trim($desc);
        if ($ref !== '' && str_starts_with($limpio, $ref)) {
            $limpio = trim(mb_substr($limpio, mb_strlen($ref)));
        }
        return $limpio !== '' ? $limpio : $ref;
    }

    private function esBasuraDePie(string $ref, string $desc): bool
    {
        $descUpper = mb_strtoupper($desc);
        if (str_contains($descUpper, 'TOTAL EXISTENCIA')) return true;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desc)) return true;
        return false;
    }
}
