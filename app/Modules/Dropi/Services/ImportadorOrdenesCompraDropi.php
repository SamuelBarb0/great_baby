<?php

namespace App\Modules\Dropi\Services;

use App\Models\Contacto;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Services\ColoresDeImportacion;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Actions\RegistrarTerceroDropi;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Órdenes de compra DE DROPI desde Excel (23-nov-2026).
 *
 * La clienta compra a muchos proveedores y cargarlas de a una no le sirve.
 * El importador del ERP (ImportadorOrdenesCompra) deja la compra como de
 * Great Baby; éste es el de Dropi y no toca aquél:
 *
 *  · cada fila es un producto; las filas con la misma `referencia_oc` forman
 *    UNA orden (mismo proveedor y misma bodega);
 *  · la orden queda de la línea Dropi y en borrador, igual que si se creara a
 *    mano en «Nueva OC»: después se aprueba y se recibe como siempre;
 *  · el proveedor se busca por NIT. Si no existe y la fila trae
 *    `proveedor_nombre`, se crea como proveedor de Dropi; si existe en el ERP
 *    se comparte la ficha (RegistrarTerceroDropi);
 *  · el color va por fila (24-nov-2026): una fila por color de la misma
 *    referencia, y casa con la variante que ya existe en el ERP (ver
 *    variante()). Un producto sin variantes estrena las suyas al importar, con
 *    su código de barras (ColoresDeImportacion), y su stock entra aparte;
 *  · una orden con cualquier fila mala NO se crea, y el error dice la fila.
 *    Las demás órdenes del archivo sí;
 *  · vista previa (24-nov-2026): analizar() lo resuelve todo sin escribir, y
 *    «Nueva OC» lo muestra lleno para escoger lo que falte antes de crear().
 */
class ImportadorOrdenesCompraDropi
{
    public const MAX_FILAS = 5000;

    public const COLUMNAS = [
        'referencia_oc', 'proveedor_nit', 'proveedor_nombre', 'bodega', 'fecha_esperada',
        'producto_referencia', 'variante_codigo', 'color', 'descripcion',
        'cantidad', 'costo_unitario', 'descuento_pct', 'iva_pct', 'observaciones',
    ];

    public const REQUERIDAS = ['referencia_oc', 'proveedor_nit', 'producto_referencia', 'cantidad', 'costo_unitario'];

    /**
     * Si cada producto llegó a este archivo SIN variantes (producto_id => bool).
     * Se mira la primera vez que aparece: así la segunda fila de un producto que
     * estrena colores (ROJO, luego AZUL) también lleva el suyo, y no se confunde
     * con un producto que ya tenía variantes en el ERP.
     *
     * @var array<int, bool>
     */
    private array $sinVariantesAlInicio = [];

    /**
     * Proveedores nuevos ya vistos en este archivo (NIT => nombre): la segunda
     * orden del mismo proveedor no tiene que repetir el nombre, igual que cuando
     * la primera lo creaba antes de leer la segunda.
     *
     * @var array<string, string>
     */
    private array $proveedoresNuevos = [];

    /**
     * Crea de una vez las órdenes que vienen limpias. Una orden con cualquier
     * aviso NO se crea, y el error dice la fila (el mismo texto que muestra la
     * vista previa). La pantalla usa analizar() y crear() por separado.
     *
     * @return array{ok:int, ordenes:array<int,string>, errores:array<int,string>}
     */
    public function importar(string $ruta): array
    {
        $analisis = $this->analizar($ruta);
        if ($analisis['errores']) {
            return ['ok' => 0, 'ordenes' => [], 'errores' => $analisis['errores']];
        }

        $creadas = [];
        $errores = [];
        foreach ($analisis['ordenes'] as $orden) {
            $ref = $orden['referencia'];
            if ($aviso = self::primerAviso($orden)) {
                $errores[] = "OC {$ref}: {$aviso}";

                continue;
            }
            try {
                $creadas[] = (string) $this->crear($orden)->numero;
            } catch (FilaInvalida $e) {
                $errores[] = "OC {$ref}: ".$e->getMessage();
            } catch (\Illuminate\Validation\ValidationException $e) {
                $errores[] = "OC {$ref}: ".collect($e->errors())->flatten()->implode(' ');
            } catch (\Throwable $e) {
                report($e);
                $errores[] = "OC {$ref}: ".$e->getMessage();
            }
        }

        return ['ok' => count($creadas), 'ordenes' => $creadas, 'errores' => $errores];
    }

    /**
     * Lee el Excel y lo deja listo para revisar, SIN escribir nada (24-nov-2026:
     * «que tengan una vista previa para asignárselo a un producto»).
     *
     * Cada orden trae su proveedor, bodega y líneas ya resueltos. Lo que no se
     * pudo resolver queda vacío y con su aviso, para escogerlo en «Nueva OC».
     * Lo que haya que crear —un proveedor nuevo, el color nuevo de un producto
     * sin variantes— se anota y se crea en crear(), no antes.
     *
     * @return array{errores: list<string>, ordenes: list<array<string,mixed>>}
     */
    public function analizar(string $ruta): array
    {
        $this->sinVariantesAlInicio = [];
        $this->proveedoresNuevos = [];
        [$filas, $error] = $this->leer($ruta);
        if ($error) {
            return ['errores' => [$error], 'ordenes' => []];
        }
        if (! $filas) {
            return ['errores' => ['El archivo no trae filas con referencia_oc.'], 'ordenes' => []];
        }

        $grupos = [];
        foreach ($filas as $f) {
            $grupos[self::texto($f['referencia_oc'])][] = $f;
        }

        $ordenes = [];
        foreach ($grupos as $ref => $lineas) {
            $ordenes[] = $this->analizarOrden((string) $ref, $lineas);
        }

        return ['errores' => [], 'ordenes' => $ordenes];
    }

    /**
     * Crea UNA orden ya revisada (de analizar() o editada en «Nueva OC»): registra
     * el proveedor pendiente, crea los colores nuevos y arma la OC en borrador.
     * Todo o nada.
     *
     * Línea: producto_id, variante_id, color_nuevo, descripcion, cantidad,
     * precio_unit, descuento_pct, iva_pct.
     *
     * @param  array<string,mixed>  $orden
     */
    public function crear(array $orden): \App\Modules\Compras\Models\OrdenCompra
    {
        return DB::transaction(function () use ($orden) {
            $proveedorId = $orden['proveedor_id'] ?? null;
            if (! $proveedorId && ($p = $orden['proveedor_pendiente'] ?? null)) {
                $proveedorId = RegistrarTerceroDropi::run([
                    'nombre' => $p['nombre'], 'tipo_documento' => 'NIT',
                    'numero_documento' => $p['nit'], 'es_proveedor' => true,
                ])['contacto']->id;
            }
            if (! $proveedorId) {
                throw new FilaInvalida('falta el proveedor.');
            }

            $items = [];
            foreach ($orden['items'] as $it) {
                $producto = Producto::find($it['producto_id'] ?? null);
                if (! $producto) {
                    throw new FilaInvalida('hay una línea sin producto.');
                }
                $variante = ! empty($it['variante_id'])
                    ? ProductoVariante::where('producto_id', $producto->id)->find($it['variante_id'])
                    : null;
                if (! $variante && filled($it['color_nuevo'] ?? null)) {
                    $variante = self::varianteDelColor($producto, $it['color_nuevo']);
                    if (! $variante) {
                        throw new FilaInvalida("«{$producto->referencia}» ya tiene inventario sin colores: no se le puede crear el color «{$it['color_nuevo']}».");
                    }
                }
                $items[] = [
                    'producto_id' => $producto->id,
                    'variante_id' => $variante?->id,
                    'color_nombre' => $variante?->color_nombre,
                    'descripcion' => mb_substr((string) $it['descripcion'], 0, 200),
                    'cantidad' => (float) $it['cantidad'],
                    'precio_unit' => (float) $it['precio_unit'],
                    'descuento_pct' => (float) ($it['descuento_pct'] ?? 0),
                    'iva_pct' => (float) ($it['iva_pct'] ?? 19),
                ];
            }

            // Un producto con variantes no entra sin la suya: su stock quedaría suelto.
            foreach ($items as $item) {
                if ($item['variante_id'] === null && ProductoVariante::where('producto_id', $item['producto_id'])->exists()) {
                    $p = Producto::find($item['producto_id']);
                    throw new FilaInvalida("«{$p->referencia}» tiene variantes: escoja en cada línea cuál llega.".self::opciones($p));
                }
            }

            return CrearOrdenCompra::run([
                'proveedor_id' => (int) $proveedorId,
                'bodega_id' => (int) $orden['bodega_id'],
                'tipo' => 'nacional',
                'moneda' => 'COP',
                'tasa_cambio' => 1,
                'fecha_esperada' => $orden['fecha_esperada'] ?? null,
                'observaciones' => $orden['observaciones'] ?? null,
                'linea_negocio' => LineaNegocio::Dropi,
                'items' => $items,
            ]);
        });
    }

    /**
     * La variante de ese color: la que ya existe (sin mayúsculas ni tildes) o una
     * nueva con su código de barras. Null si el producto ya no admite colores.
     */
    public static function varianteDelColor(Producto $producto, string $color): ?ProductoVariante
    {
        return $producto->variantes()->orderBy('id')->get()
            ->first(fn (ProductoVariante $v) => self::normal((string) $v->color_nombre) === self::normal($color))
            ?? app(ColoresDeImportacion::class)->varianteDe($producto, $color);
    }

    /** El primer aviso de la orden, con su fila: el error de importar(). */
    public static function primerAviso(array $orden): ?string
    {
        if ($orden['avisos']) {
            return $orden['avisos'][0];
        }
        foreach ($orden['items'] as $it) {
            if ($it['aviso']) {
                return "fila {$it['fila']}: {$it['aviso']}";
            }
        }

        return null;
    }

    /** @param  array<int,array<string,mixed>>  $lineas */
    private function analizarOrden(string $ref, array $lineas): array
    {
        $primera = $lineas[0];
        $filaUno = (int) $primera['_fila'];
        $nit = preg_replace('/[^0-9A-Za-z]/', '', self::texto($primera['proveedor_nit']));
        $avisos = [];

        foreach ($lineas as $l) {
            if (preg_replace('/[^0-9A-Za-z]/', '', self::texto($l['proveedor_nit'])) !== $nit) {
                $avisos[] = "fila {$l['_fila']}: el proveedor no es el mismo de la fila {$filaUno}. Una orden es de un solo proveedor.";
                break;
            }
        }

        [$proveedorId, $pendiente, $avisoProveedor] = $this->proveedor($nit, self::texto($primera['proveedor_nombre'] ?? ''));
        if ($avisoProveedor) {
            $avisos[] = "fila {$filaUno}: {$avisoProveedor}";
        }
        [$bodegaId, $avisoBodega] = $this->bodega(self::texto($primera['bodega'] ?? ''));
        if ($avisoBodega) {
            $avisos[] = "fila {$filaUno}: {$avisoBodega}";
        }

        $items = [];
        foreach ($lineas as $l) {
            $items[] = $this->analizarLinea($l);
        }

        // Una fila sin color de un producto que en esta orden estrena colores no
        //   tiene a qué variante entrar: su stock quedaría suelto.
        $estrenan = collect($items)->filter(fn ($i) => $i['color_nuevo'])->pluck('producto_id')->unique()->all();
        foreach ($items as &$it) {
            if (! $it['aviso'] && ! $it['variante_id'] && ! $it['color_nuevo'] && in_array($it['producto_id'], $estrenan, true)) {
                $it['aviso'] = "«{$it['referencia']}» se carga por colores en este archivo: escriba el color también en esta fila.";
            }
        }
        unset($it);

        return [
            'referencia' => $ref,
            'proveedor_id' => $proveedorId,
            'proveedor_pendiente' => $pendiente,
            'proveedor_nit' => $nit,
            'bodega_id' => $bodegaId,
            'fecha_esperada' => self::fecha($primera['fecha_esperada'] ?? null),
            'observaciones' => mb_substr(self::texto($primera['observaciones'] ?? '') ?: "Importada desde Excel (referencia {$ref})", 0, 500),
            'avisos' => $avisos,
            'items' => $items,
        ];
    }

    /** @param  array<string,mixed>  $l */
    private function analizarLinea(array $l): array
    {
        $refProd = self::texto($l['producto_referencia']);
        $cantidad = self::numero($l['cantidad']);
        $costo = self::numero($l['costo_unitario']);
        // Celda vacía = el valor de siempre (sin descuento, IVA 19).
        $desc = self::vacio($l['descuento_pct'] ?? null) ? 0.0 : self::numero($l['descuento_pct']);
        $iva = self::vacio($l['iva_pct'] ?? null) ? 19.0 : self::numero($l['iva_pct']);

        $linea = [
            'fila' => (int) $l['_fila'],
            'referencia' => $refProd,
            'producto_id' => null,
            'variante_id' => null,
            'color_nuevo' => null,
            'descripcion' => mb_substr(self::texto($l['descripcion'] ?? '') ?: $refProd, 0, 200),
            'cantidad' => max(0.0, $cantidad),
            'precio_unit' => max(0.0, $costo),
            'descuento_pct' => $desc,
            'iva_pct' => $iva,
            'aviso' => null,
        ];

        $producto = $refProd === '' ? null : Producto::where('referencia', $refProd)->first();
        if (! $producto) {
            $linea['aviso'] = "el producto «{$refProd}» no existe.";

            return $linea;
        }
        $linea['producto_id'] = $producto->id;
        [$variante, $colorNuevo, $aviso] = $this->variante($producto, $l);
        $linea['variante_id'] = $variante?->id;
        $linea['color_nuevo'] = $colorNuevo;
        $color = $variante?->color_nombre ?? $colorNuevo;
        $linea['descripcion'] = mb_substr(self::texto($l['descripcion'] ?? '') ?: trim($producto->nombre.($color ? " · {$color}" : '')), 0, 200);
        $linea['aviso'] = $aviso
            ?? match (true) {
                $cantidad <= 0 => 'la cantidad debe ser mayor que cero.',
                $costo < 0 => 'el costo no puede ser negativo.',
                $desc < 0 || $desc > 100 || $iva < 0 || $iva > 100 => 'el descuento y el IVA van entre 0 y 100.',
                default => null,
            };

        return $linea;
    }

    /**
     * La variante del ERP a la que entra la fila (24-nov-2026: «tiene que
     * coincidir con lo que esté en el ERP»). Todo en la misma hoja:
     *
     *  · `variante_codigo` manda: el código de barras exacto de la variante;
     *  · si no, el `color`, comparado sin mayúsculas, tildes ni espacios de más;
     *  · un producto que YA tiene variantes no inventa otra: el color que no casa,
     *    o la fila sin color, quedan con aviso diciendo cuáles hay. Un color nuevo
     *    se crea primero en la ficha del producto;
     *  · un producto que llegó al archivo sin variantes: sin color entra al
     *    producto; con color, la variante se anota como nueva y nace en crear(),
     *    con su código de barras (ColoresDeImportacion::varianteDe), si el
     *    producto aún admite colores. No se deja para la recepción: ésa sólo
     *    crea colores en importaciones, y estas órdenes son nacionales.
     *
     * @param  array<string,mixed>  $l
     * @return array{0:?ProductoVariante, 1:?string, 2:?string} variante, color nuevo, aviso
     */
    private function variante(Producto $producto, array $l): array
    {
        $refProd = $producto->referencia;
        $codigo = self::texto($l['variante_codigo'] ?? '');
        $color = self::texto($l['color'] ?? '');

        if ($codigo !== '') {
            $variante = ProductoVariante::where('producto_id', $producto->id)
                ->where('codigo_barras', $codigo)->first();

            return $variante
                ? [$variante, null, null]
                : [null, null, "la variante «{$codigo}» no es de «{$refProd}».".self::opciones($producto)];
        }

        $variantes = $producto->variantes()->orderBy('id')->get();
        $igualA = fn (string $c) => $variantes->filter(fn (ProductoVariante $v) => self::normal((string) $v->color_nombre) === self::normal($c))->values();

        if ($this->sinVariantesAlInicio[$producto->id] ??= $variantes->isEmpty()) {
            if ($color === '') {
                return [null, null, null];
            }
            if (! app(ColoresDeImportacion::class)->admiteColores($producto)) {
                return [null, null, "«{$refProd}» ya tiene inventario sin colores, así que no se le puede cargar el color «{$color}». Quite el color de la fila."];
            }

            return [null, $color, null];
        }

        if ($color === '') {
            return [null, null, "«{$refProd}» tiene variantes en el ERP: escriba en «color» cuál llega.".self::opciones($producto, $variantes)];
        }

        $iguales = $igualA($color);
        if ($iguales->isEmpty()) {
            return [null, null, "«{$refProd}» no tiene el color «{$color}» en el ERP.".self::opciones($producto, $variantes)
                .' Si es un color nuevo, créelo primero en la ficha del producto.'];
        }
        if ($iguales->count() > 1) {
            return [null, null, "«{$refProd}» tiene el color «{$color}» en varias tallas: escriba en «variante_codigo» el código de la que llega."
                .self::opciones($producto, $iguales)];
        }

        return [$iguales->first(), null, null];
    }

    /** « Tiene: AZUL, ROSA T2 (770…)» para que el error diga qué escribir. */
    private static function opciones(Producto $producto, ?\Illuminate\Support\Collection $variantes = null): string
    {
        $variantes ??= $producto->variantes()->orderBy('id')->get();
        if ($variantes->isEmpty()) {
            return '';
        }
        $conTalla = $variantes->countBy(fn ($v) => self::normal((string) $v->color_nombre))->contains(fn ($n) => $n > 1);
        $nombres = $variantes->map(function (ProductoVariante $v) use ($conTalla) {
            $nombre = trim(($v->color_nombre ?? '').($v->talla ? " T{$v->talla}" : '')) ?: 'sin color';

            return $conTalla && filled($v->codigo_barras) ? "{$nombre} ({$v->codigo_barras})" : $nombre;
        })->unique()->values();

        return ' Tiene: '.$nombres->take(15)->implode(', ').($nombres->count() > 15 ? ' y '.($nombres->count() - 15).' más' : '').'.';
    }

    /** «Rosá », «rosa» y «ROSA» son el mismo color. */
    private static function normal(string $v): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim(\Illuminate\Support\Str::ascii($v))));
    }

    /**
     * El proveedor por NIT, sin crearlo: [id, pendiente, aviso]. Si es de Dropi,
     * su id. Si no existe y trae nombre —o existe sólo en el ERP— queda
     * pendiente: crear() lo registra como de Dropi (RegistrarTerceroDropi).
     *
     * @return array{0:?int, 1:?array{nit:string, nombre:string}, 2:?string}
     */
    private function proveedor(string $nit, string $nombre): array
    {
        if ($nit === '') {
            return [null, null, 'falta el NIT del proveedor.'];
        }
        $c = Contacto::where('numero_documento', $nit)->first();
        if ($c && $c->en_dropi && $c->es_proveedor) {
            return [$c->id, null, null];
        }
        $nombre = $nombre ?: ($this->proveedoresNuevos[$nit] ?? '');
        if (! $c && $nombre === '') {
            return [null, null, "el proveedor {$nit} no existe. Escriba su nombre en «proveedor_nombre» para crearlo, o créelo en «Clientes y proveedores»."];
        }

        $this->proveedoresNuevos[$nit] = $nombre ?: ($c?->nombre_completo ?? $nit);

        return [null, ['nit' => $nit, 'nombre' => $this->proveedoresNuevos[$nit]], null];
    }

    /** @return array{0:?int, 1:?string} la bodega y, si no se pudo, por qué */
    private function bodega(string $valor): array
    {
        if ($valor === '') {
            $id = ComprasDropi::bodegaPorDefecto();

            return $id ? [$id, null] : [null, 'escriba la bodega (no hay bodega de e-commerce por defecto en Reglas).'];
        }
        foreach (ComprasDropi::bodegas() as $id => $nombre) {
            if ((string) $id === $valor || mb_strtolower(trim($nombre)) === mb_strtolower($valor)) {
                return [(int) $id, null];
            }
        }

        return [null, "la bodega «{$valor}» no existe. Use el nombre tal como sale en «Nueva OC»."];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:?string} */
    private function leer(string $ruta): array
    {
        $reader = new Reader();
        $reader->open($ruta);
        $filas = [];
        $columnas = null;
        $n = 0;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $valores = $row->toArray();
                    if ($columnas === null) {
                        $columnas = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $valores);
                        $faltan = array_diff(self::REQUERIDAS, $columnas);
                        if ($faltan) {
                            return [[], 'Faltan columnas: '.implode(', ', $faltan).'. Descargue la plantilla de Dropi.'];
                        }

                        continue;
                    }
                    $fila = ['_fila' => $n];
                    foreach ($columnas as $i => $col) {
                        if ($col !== '') {
                            $fila[$col] = $valores[$i] ?? null;
                        }
                    }
                    if (self::texto($fila['referencia_oc'] ?? '') === '') {
                        continue;
                    }
                    $filas[] = $fila;
                    if (count($filas) > self::MAX_FILAS) {
                        return [[], 'El archivo pasa de '.self::MAX_FILAS.' filas. Divídalo en varios.'];
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return [$filas, null];
    }

    /** La plantilla, con una fila de ejemplo. */
    public static function escribirPlantilla(string $ruta): void
    {
        $w = new Writer();
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(self::COLUMNAS));
        // Una referencia en dos colores: una fila por color. La variante de cada
        //   color nace al importar, con su código de barras.
        $w->addRow(Row::fromValues([
            'COMPRA-001', '900123456', 'Proveedor Ejemplo SAS', '', '2026-12-01',
            'REF-001', '', 'ROSADO', '',
            50, 6000, 0, 19, 'Ejemplo: borre estas filas antes de importar',
        ]));
        $w->addRow(Row::fromValues([
            'COMPRA-001', '900123456', 'Proveedor Ejemplo SAS', '', '2026-12-01',
            'REF-001', '', 'AZUL', '',
            30, 6000, 0, 19, '',
        ]));
        $w->close();
    }

    private static function texto(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return trim((string) $v);
    }

    private static function vacio(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }

    private static function numero(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace([' ', '$'], '', trim((string) $v));
        // «6.000,50» o «6,000.50»: el último separador es el decimal.
        if (preg_match('/[.,]\d{1,2}$/', $s)) {
            $dec = substr($s, -3, 1) === ',' || substr($s, -2, 1) === ',' ? ',' : '.';
            $s = $dec === ',' ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(['.', ','], '', $s);
        }

        return is_numeric($s) ? (float) $s : -1;
    }

    private static function fecha(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

