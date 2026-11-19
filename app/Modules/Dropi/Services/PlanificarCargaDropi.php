<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Models\DropiCargaOperacion as Op;

/**
 * Cruza las filas de un Excel de inventario del cliente con el catálogo vivo
 * de Dropi y propone, por fila, qué hacer: actualizar el stock de un producto
 * que ya existe (variación por variación), crear uno nuevo, o dejarla para
 * revisar a mano con el porqué.
 *
 * Mismas reglas que el cruce hecho a mano el 18-nov-2026 sobre «inventario con
 * variables.xls» (166 refs vs 332 productos):
 *  1. SKU igual —del producto, de una variación o la base de una variación
 *     (`2507-36-7` → `2507-36`)—, ignorando mayúsculas y signos.
 *  2. SKU igual cambiando el prefijo DROP/AND/NO., solo si el nombre se parece.
 *  3. Mismo nombre y el producto de Dropi sin SKU → actualizar.
 *  4. Nombre parecido → revisar (con el candidato). Nada parecido → crear.
 * Nunca decide sola cuando hay duda: lo que no cuadra queda en «revisar».
 */
class PlanificarCargaDropi
{
    private const STOP = ['DE', 'CON', 'PARA', 'Y', 'EN', 'EL', 'LA', 'LOS', 'LAS', 'DEL', 'TIPO', 'X'];

    /** Errores de tipeo vistos en los Excel del cliente. */
    private const TYPOS = [
        'AMAIRLLO' => 'AMARILLO', 'AMRILLO' => 'AMARILLO', 'VERES' => 'VERDE', 'VERE' => 'VERDE',
        'BLACO' => 'BLANCO', 'ROJA' => 'ROJO', 'AZULES' => 'AZUL', 'ROSADOS' => 'ROSADO',
        'CONEJOS' => 'CONEJO', 'TORTUGAS' => 'TORTUGA', 'ELEFANTES' => 'ELEFANTE',
        'PINGUINOS' => 'PINGUINO', 'DINOS' => 'DINO', 'PAJAROS' => 'PAJARO',
    ];

    /** @var array<string, array<int, array{0: array, 1: ?array}>> */
    private array $indice = [];

    /** @var array<string, array<int, array{0: array, 1: ?array}>> */
    private array $indiceCore = [];

    /**
     * @param  array<int, array{fila:int, ref:string, nombre:string, cantidad:float|int, variaciones:?string}>  $filas
     * @param  array<int, array>  $catalogo  productos de DropiWebClient::catalogo()
     * @return array<int, array>  atributos de DropiCargaOperacion (sin carga_id)
     */
    public function planificar(array $filas, array $catalogo, int $bodegaId): array
    {
        $this->indexar($catalogo);

        // Pasada 1: lo que casa por SKU.
        $hits = [];
        foreach ($filas as $i => $f) {
            $hits[$i] = $this->porSku($f);
        }
        $usados = [];
        foreach ($hits as $i => $h) {
            if ($h) {
                $usados[(int) $h['producto']['id']][] = $filas[$i]['ref'];
            }
        }

        // Pasada 2: plan de cada fila.
        $refsCrear = [];
        $ops = [];
        foreach ($filas as $i => $f) {
            $base = [
                'fila' => $f['fila'],
                'ref_excel' => $f['ref'],
                'nombre_excel' => $f['nombre'],
                'variaciones_excel' => $f['variaciones'] ?: null,
                'cantidad_excel' => (float) $f['cantidad'],
            ];

            if ($h = $hits[$i]) {
                $ops[] = $base + $this->actualizar($f, $h['producto'], $h['variaciones'], $h['como'], $bodegaId);
                continue;
            }

            [$puntaje, $secuencia, $p] = $this->masParecido($f['nombre'], $catalogo);
            $duenos = $p ? ($usados[(int) $p['id']] ?? []) : [];

            if ($p && $secuencia >= 0.85 && ! $duenos && empty($p['sku'])) {
                $ops[] = $base + $this->actualizar($f, $p, [], 'mismo nombre; en Dropi no tiene SKU', $bodegaId);
            } elseif ($p && $secuencia >= 0.85 && ! $duenos) {
                $ops[] = $base + $this->revisar($p, "Mismo nombre que «{$p['name']}», pero en Dropi tiene otro SKU ({$p['sku']}). ¿Es el mismo producto?");
            } elseif ($p && $puntaje >= 0.6 && ! $duenos) {
                $ops[] = $base + $this->revisar($p, sprintf('Nombre parecido a «%s» (%d%%). ¿Es el mismo producto?', $p['name'], round($puntaje * 100)));
            } elseif ($p && $puntaje >= 0.7 && $duenos) {
                $ops[] = $base + $this->revisar($p, sprintf('Parecido a «%s», que ya corresponde a %s. ¿Variación nueva o producto aparte?', $p['name'], implode(', ', $duenos)));
            } else {
                $refsCrear[] = $i;
                $ops[] = $base + ['tipo' => Op::CREAR, '_i' => $i];
            }
        }

        // Productos a crear: nombres repetidos llevan la referencia («Gimnasio» ×5).
        $nombres = array_count_values(array_map(fn ($i) => mb_strtoupper(trim($filas[$i]['nombre'])), $refsCrear));
        foreach ($ops as $k => $op) {
            if (array_key_exists('_i', $op)) {
                $f = $filas[$op['_i']];
                unset($op['_i']);
                $ops[$k] = $op + $this->crear($f, ($nombres[mb_strtoupper(trim($f['nombre']))] ?? 0) > 1);
            }
        }

        return $this->marcarChoques($ops);
    }

    /**
     * Plan de una fila contra un producto elegido a mano (en la vista previa:
     * «es este», o un producto suelto desde «cargar un producto»).
     *
     * @param  array{fila:?int, ref:?string, nombre:?string, cantidad:float|int|null, variaciones:?string}  $f
     */
    public function planFila(array $f, array $producto, int $bodegaId, string $como): array
    {
        if ($f['cantidad'] === null) {
            return [
                'tipo' => Op::ACTUALIZAR,
                'estado' => Op::REVISAR,
                'motivo' => 'Escribe el stock nuevo de cada variación que quieras cambiar.',
                'dropi_producto_id' => (int) $producto['id'],
                'dropi_nombre' => $producto['name'],
                'detalle' => ['como' => $como, 'tipo_dropi' => $producto['type'], 'lineas' => self::lineasDe($producto, $bodegaId)],
            ];
        }

        return $this->actualizar($f, $producto, [], $como, $bodegaId);
    }

    // ───────────────────────── emparejar ─────────────────────────

    private function indexar(array $catalogo): void
    {
        $this->indice = $this->indiceCore = [];
        foreach ($catalogo as $p) {
            if (! empty($p['sku'])) {
                foreach (self::partesSku($p['sku']) as $parte) {
                    $this->agregar($parte, $p, null);
                }
            }
            foreach ($p['variations'] ?? [] as $v) {
                if (empty($v['sku']) || $v['sku'] === '-0') {
                    continue;
                }
                foreach (self::partesSku($v['sku']) as $parte) {
                    $this->agregar($parte, $p, $v);
                    $base = preg_replace('/-\d{1,2}$/', '', $parte);
                    if ($base !== $parte && str_contains($base, '-') && strlen(self::norm($base)) >= 4) {
                        $this->agregar($base, $p, $v);
                    }
                }
            }
        }
    }

    private function agregar(string $parte, array $p, ?array $v): void
    {
        if (strlen(self::norm($parte)) < 3) {
            return;
        }
        $this->indice[self::norm($parte)][] = [$p, $v];
        $this->indiceCore[self::core($parte)][] = [$p, $v];
    }

    /** @return array{producto: array, variaciones: array<int, array>, como: string}|null */
    private function porSku(array $f): ?array
    {
        $partes = array_values(array_filter(explode('/', $f['ref'])));
        foreach ([[$this->indice, 'norm', 'SKU igual'], [$this->indiceCore, 'core', 'SKU igual con otro prefijo (DROP/AND)']] as [$indice, $fn, $como]) {
            foreach ($partes as $parte) {
                $hit = $indice[self::$fn($parte)] ?? null;
                if (! $hit) {
                    continue;
                }
                $producto = $hit[0][0];
                if ($fn === 'core' && $this->similitud($f['nombre'], $producto['name'])[0] < 0.5) {
                    continue;
                }
                $delProducto = array_filter($hit, fn ($h) => (int) $h[0]['id'] === (int) $producto['id']);
                // Si casó el SKU del producto, la fila es del producto entero
                // (se reparte por color); solo se fija una variación cuando el
                // SKU que casó es únicamente el de esa variación.
                $variaciones = collect($delProducto)->contains(fn ($h) => $h[1] === null)
                    ? []
                    : array_values(array_map(fn ($h) => $h[1], $delProducto));

                return ['producto' => $producto, 'variaciones' => $variaciones, 'como' => $como];
            }
        }

        return null;
    }

    /** @return array{0: float, 1: float, 2: ?array} puntaje, similitud de texto, producto */
    private function masParecido(string $nombre, array $catalogo): array
    {
        $mejor = [0.0, 0.0, null];
        foreach ($catalogo as $p) {
            [$puntaje, $secuencia] = $this->similitud($nombre, (string) $p['name']);
            if ($puntaje > $mejor[0] || ($puntaje === $mejor[0] && $secuencia > $mejor[1])) {
                $mejor = [$puntaje, $secuencia, $p];
            }
        }

        return $mejor;
    }

    /** @return array{0: float, 1: float} [máx(texto, cobertura de palabras), texto], con castigo a los combos */
    public function similitud(string $a, string $b): array
    {
        $A = self::palabras($a);
        $B = self::palabras($b);
        if (! $A || ! $B) {
            return [0.0, 0.0];
        }
        similar_text(implode(' ', $A), implode(' ', $B), $pct);
        $secuencia = $pct / 100;
        $cobertura = count($A) >= 2 ? count(array_intersect(array_unique($A), $B)) / count(array_unique($A)) * 0.9 : 0;
        $combo = preg_match('/COMBO|\+|SET VASO/', self::ascii($b)) && ! preg_match('/COMBO|\+/', self::ascii($a)) ? 0.75 : 1;

        return [round(max($secuencia, $cobertura) * $combo, 3), round($secuencia * $combo, 3)];
    }

    // ───────────────────────── armar operaciones ─────────────────────────

    /** @param array<int, array> $fijadas  variaciones que casaron por SKU */
    private function actualizar(array $f, array $p, array $fijadas, string $como, int $bodegaId): array
    {
        $lineas = self::lineasDe($p, $bodegaId);
        $total = (float) $f['cantidad'];
        $problemas = [];

        if ($p['type'] === 'SIMPLE') {
            $lineas[0]['cantidad'] = $total;
            [$tipo, $colores] = self::colores($f['variaciones'], $total);
            if ($tipo === 'ok' && count($colores) > 1) {
                $problemas[] = 'En Dropi no tiene colores y el Excel sí (' . $f['variaciones'] . '); se carga el total al producto';
            }
        } elseif (count($fijadas) === 1 && count(self::colores($f['variaciones'], $total)[1]) <= 1) {
            self::poner($lineas, (int) $fijadas[0]['id'], $total);
        } else {
            [$tipo, $colores] = self::colores($f['variaciones'], $total);
            $soloFijadas = array_map(fn ($v) => (int) $v['id'], $fijadas);
            if ($tipo === 'ok') {
                $faltan = [];
                $vistas = [];
                foreach ($colores as [$n, $color]) {
                    $id = self::variacionPorColor($p, $color, $soloFijadas) ?? self::variacionPorColor($p, $color, []);
                    if (! $id) {
                        $faltan[] = $color;
                        continue;
                    }
                    if (isset($vistas[$id])) {
                        $problemas[] = 'dos colores del Excel caen en la misma variación de Dropi';
                    }
                    $vistas[$id] = true;
                    self::poner($lineas, $id, (float) $n);
                }
                $suma = array_sum(array_column($colores, 0));
                if ($faltan) {
                    $problemas[] = 'colores que no existen en Dropi: ' . implode(', ', $faltan);
                }
                if (abs($suma - $total) > 0.001) {
                    $problemas[] = "por color suman {$suma} y la cantidad dice {$total}";
                }
            } elseif ($tipo === 'sin' && count($lineas) === 1) {
                $lineas[0]['cantidad'] = $total;
            } else {
                $problemas[] = match ($tipo) {
                    'sin' => 'el Excel dice que no tiene variaciones y en Dropi sí',
                    'sin_cantidades' => 'el Excel trae colores pero no la cantidad de cada uno',
                    'tallas' => 'viene por tallas y colores: hay que repartirlo a mano',
                    default => 'la variación dice «' . $f['variaciones'] . '»',
                };
            }
        }

        return [
            'tipo' => Op::ACTUALIZAR,
            'estado' => $problemas ? Op::REVISAR : Op::LISTO,
            'motivo' => $problemas ? ucfirst(implode('; ', array_unique($problemas))) . '.' : null,
            'dropi_producto_id' => (int) $p['id'],
            'dropi_nombre' => $p['name'],
            'detalle' => ['como' => $como, 'tipo_dropi' => $p['type'], 'lineas' => $lineas],
        ];
    }

    private function revisar(array $p, string $motivo): array
    {
        return [
            'tipo' => Op::ACTUALIZAR,
            'estado' => Op::REVISAR,
            'motivo' => $motivo,
            'dropi_producto_id' => null,
            'dropi_nombre' => null,
            'detalle' => ['como' => 'sin emparejar', 'lineas' => []],
            'candidato' => ['id' => (int) $p['id'], 'nombre' => $p['name'], 'sku' => $p['sku'] ?? null, 'tipo' => $p['type']],
        ];
    }

    private function crear(array $f, bool $nombreRepetido): array
    {
        $nombre = mb_convert_case(trim($f['nombre']), MB_CASE_TITLE);
        if ($nombreRepetido) {
            $nombre .= ' ' . $f['ref'];
        }
        [$tipo, $colores] = self::colores($f['variaciones'], (float) $f['cantidad']);
        $problemas = ['falta el precio y el precio sugerido'];

        $variaciones = [];
        if ($tipo === 'ok' && count($colores) > 1) {
            foreach (array_values($colores) as $k => [$n, $color]) {
                $variaciones[] = ['valor' => mb_convert_case($color, MB_CASE_TITLE), 'sku' => "{$f['ref']}-{$k}", 'cantidad' => (float) $n];
            }
            $suma = array_sum(array_column($colores, 0));
            if (abs($suma - (float) $f['cantidad']) > 0.001) {
                $problemas[] = "por color suman {$suma} y la cantidad dice {$f['cantidad']}";
            }
        } elseif (in_array($tipo, ['sin_cantidades', 'tallas'], true)) {
            $problemas[] = 'el Excel trae colores sin cantidad por color';
        }

        return [
            'estado' => Op::REVISAR,
            'motivo' => ucfirst(implode('; ', $problemas)) . '.',
            'detalle' => [
                'nombre' => $nombre,
                'sku' => $f['ref'],
                'descripcion' => $tipo === 'ok' && count($colores) === 1 ? "Color: " . mb_convert_case($colores[0][1], MB_CASE_TITLE) : '',
                'precio' => null,
                'sugerido' => null,
                'cantidad' => (float) $f['cantidad'],
                'variaciones' => $variaciones,
            ],
        ];
    }

    /** Dos filas listas que tocan la misma variación de Dropi: las dos a revisar. */
    private function marcarChoques(array $ops): array
    {
        $quien = [];
        foreach ($ops as $k => $op) {
            if ($op['tipo'] !== Op::ACTUALIZAR || empty($op['dropi_producto_id'])) {
                continue;
            }
            foreach ($op['detalle']['lineas'] as $l) {
                if ($l['cantidad'] !== null) {
                    $quien[$op['dropi_producto_id'] . ':' . ($l['variation_id'] ?? 's')][] = $k;
                }
            }
        }
        foreach ($quien as $ks) {
            if (count($ks) < 2) {
                continue;
            }
            $refs = implode(', ', array_map(fn ($k) => $ops[$k]['ref_excel'], $ks));
            foreach ($ks as $k) {
                $ops[$k]['estado'] = Op::REVISAR;
                $ops[$k]['motivo'] = trim(($ops[$k]['motivo'] ?? '') . " Varias filas ({$refs}) cambian la misma variación de Dropi.");
            }
        }

        return array_values($ops);
    }

    // ───────────────────────── utilidades ─────────────────────────

    /** Una línea por variación (o una para el producto simple), con el stock que tenía al planear. */
    public static function lineasDe(array $p, int $bodegaId): array
    {
        if (($p['type'] ?? '') !== 'VARIABLE') {
            $stock = collect($p['warehouse_product'] ?? [])->where('warehouse_id', $bodegaId)->sum('stock');

            return [['variation_id' => null, 'etiqueta' => 'Producto (sin variaciones)', 'sku' => $p['sku'] ?? null, 'stock_plan' => (int) $stock, 'cantidad' => null]];
        }

        return array_map(fn ($v) => [
            'variation_id' => (int) $v['id'],
            'etiqueta' => implode(' / ', array_column($v['attribute_values'] ?? [], 'value')) ?: ('Variación ' . $v['id']),
            'sku' => $v['sku'] ?? null,
            'stock_plan' => (int) collect($v['warehouse_product_variation'] ?? [])->where('warehouse_id', $bodegaId)->sum('stock'),
            'cantidad' => null,
        ], $p['variations'] ?? []);
    }

    private static function poner(array &$lineas, int $variacionId, float $cantidad): void
    {
        foreach ($lineas as &$l) {
            if ((int) $l['variation_id'] === $variacionId) {
                $l['cantidad'] = $cantidad;
            }
        }
    }

    /**
     * «8 NARANJA- 9 VERDE» → [[8, NARANJA], [9, VERDE]]. Un solo color sin
     * número se lleva el total.
     *
     * @return array{0: string, 1: array<int, array{0: ?float, 1: string}>}  tipo: ok|sin|sin_cantidades|tallas|nota
     */
    public static function colores(?string $texto, float $total): array
    {
        $v = trim((string) $texto);
        if ($v === '' || self::color($v) === 'NO HAY VARIABLE') {
            return ['sin', []];
        }
        if (preg_match('/AVERIA|POR AJUSTAR/', self::color($v))) {
            return ['nota', []];
        }
        if (str_contains($v, '(')) {
            return ['tallas', []];
        }
        $out = [];
        $conNumero = 0;
        foreach (preg_split('/-\s*/', $v) as $trozo) {
            $trozo = trim($trozo);
            if ($trozo === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*(.+)$/u', $trozo, $m)) {
                $out[] = [(float) $m[1], self::color($m[2])];
                $conNumero++;
            } else {
                $out[] = [null, self::color($trozo)];
            }
        }
        if ($conNumero === 0 && count($out) === 1) {
            return ['ok', [[$total, $out[0][1]]]];
        }

        return [$conNumero === count($out) ? 'ok' : 'sin_cantidades', $out];
    }

    private static function variacionPorColor(array $p, string $color, array $soloIds): ?int
    {
        $vs = array_filter($p['variations'] ?? [], fn ($v) => ! $soloIds || in_array((int) $v['id'], $soloIds, true));
        foreach ([true, false] as $exacto) {
            foreach ($vs as $v) {
                $valores = array_map(fn ($a) => self::color($a['value'] ?? ''), $v['attribute_values'] ?? []);
                if ($exacto && (in_array($color, $valores, true) || $color === implode(' ', $valores))) {
                    return (int) $v['id'];
                }
                if (! $exacto) {
                    foreach ($valores as $n) {
                        if (strlen($n) > 2 && (str_contains($color, $n) || str_contains($n, $color))) {
                            return (int) $v['id'];
                        }
                    }
                }
            }
        }

        return null;
    }

    public static function color(string $s): string
    {
        $s = preg_replace('/[^A-Z0-9\/ ]/', ' ', self::ascii($s));

        return trim(implode(' ', array_map(fn ($w) => self::TYPOS[$w] ?? $w, preg_split('/\s+/', trim($s)))));
    }

    /** @return array<int, string> */
    private static function partesSku(string $sku): array
    {
        return array_values(array_filter(preg_split('/\s+-\s+|\/|\s+/', $sku)));
    }

    /** @return array<int, string> */
    public static function palabras(string $s): array
    {
        $out = [];
        foreach (preg_split('/[^A-Z0-9]+/', self::ascii($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (strlen($w) > 4 && str_ends_with($w, 'S')) {
                $w = substr($w, 0, -1);
            }
            $w = str_replace('MONTESORI', 'MONTESSORI', $w);
            if (strlen($w) > 1 && ! in_array($w, self::STOP, true)) {
                $out[] = $w;
            }
        }

        return $out;
    }

    public static function norm(string $c): string
    {
        return preg_replace('/[^A-Z0-9]/', '', self::ascii($c));
    }

    public static function core(string $c): string
    {
        return preg_replace('/^(NO|DROOP|DROP|AND)/', '', self::norm($c));
    }

    private static function ascii(string $s): string
    {
        $t = \Normalizer::normalize($s, \Normalizer::FORM_D);

        return mb_strtoupper(preg_replace('/\p{Mn}/u', '', $t === false ? $s : $t));
    }
}
