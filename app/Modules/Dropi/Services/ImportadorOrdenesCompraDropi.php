<?php

namespace App\Modules\Dropi\Services;

use App\Models\Contacto;
use App\Modules\Compras\Actions\CrearOrdenCompra;
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
 *  · una orden con cualquier fila mala NO se crea, y el error dice la fila.
 *    Las demás órdenes del archivo sí.
 */
class ImportadorOrdenesCompraDropi
{
    public const MAX_FILAS = 5000;

    public const COLUMNAS = [
        'referencia_oc', 'proveedor_nit', 'proveedor_nombre', 'bodega', 'fecha_esperada',
        'producto_referencia', 'variante_codigo', 'descripcion',
        'cantidad', 'costo_unitario', 'descuento_pct', 'iva_pct', 'observaciones',
    ];

    public const REQUERIDAS = ['referencia_oc', 'proveedor_nit', 'producto_referencia', 'cantidad', 'costo_unitario'];

    /** @return array{ok:int, ordenes:array<int,string>, errores:array<int,string>} */
    public function importar(string $ruta): array
    {
        [$filas, $error] = $this->leer($ruta);
        if ($error) {
            return ['ok' => 0, 'ordenes' => [], 'errores' => [$error]];
        }
        if (! $filas) {
            return ['ok' => 0, 'ordenes' => [], 'errores' => ['El archivo no trae filas con referencia_oc.']];
        }

        $grupos = [];
        foreach ($filas as $f) {
            $grupos[self::texto($f['referencia_oc'])][] = $f;
        }

        $creadas = [];
        $errores = [];
        foreach ($grupos as $ref => $lineas) {
            try {
                $creadas[] = DB::transaction(fn () => $this->crearOrden($ref, $lineas));
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

    /** @param  array<int,array<string,mixed>>  $lineas */
    private function crearOrden(string $ref, array $lineas): string
    {
        $primera = $lineas[0];
        $nit = preg_replace('/[^0-9A-Za-z]/', '', self::texto($primera['proveedor_nit']));

        foreach ($lineas as $l) {
            if (preg_replace('/[^0-9A-Za-z]/', '', self::texto($l['proveedor_nit'])) !== $nit) {
                throw new FilaInvalida("fila {$l['_fila']}: el proveedor no es el mismo de la fila {$primera['_fila']}. Una orden es de un solo proveedor.");
            }
        }

        $proveedor = $this->proveedor($nit, self::texto($primera['proveedor_nombre'] ?? ''), (int) $primera['_fila']);
        $bodega = $this->bodega(self::texto($primera['bodega'] ?? ''), (int) $primera['_fila']);

        $items = [];
        foreach ($lineas as $l) {
            $fila = $l['_fila'];
            $refProd = self::texto($l['producto_referencia']);
            $producto = Producto::where('referencia', $refProd)->first();
            if (! $producto) {
                throw new FilaInvalida("fila {$fila}: el producto «{$refProd}» no existe.");
            }
            $variante = null;
            if (($codigo = self::texto($l['variante_codigo'] ?? '')) !== '') {
                $variante = ProductoVariante::where('producto_id', $producto->id)
                    ->where('codigo_barras', $codigo)->first();
                if (! $variante) {
                    throw new FilaInvalida("fila {$fila}: la variante «{$codigo}» no es de «{$refProd}».");
                }
            }
            $cantidad = self::numero($l['cantidad']);
            $costo = self::numero($l['costo_unitario']);
            // Celda vacía = el valor de siempre (sin descuento, IVA 19).
            $desc = self::vacio($l['descuento_pct'] ?? null) ? 0.0 : self::numero($l['descuento_pct']);
            $iva = self::vacio($l['iva_pct'] ?? null) ? 19.0 : self::numero($l['iva_pct']);
            if ($cantidad <= 0) {
                throw new FilaInvalida("fila {$fila}: la cantidad debe ser mayor que cero.");
            }
            if ($costo < 0) {
                throw new FilaInvalida("fila {$fila}: el costo no puede ser negativo.");
            }
            if ($desc < 0 || $desc > 100 || $iva < 0 || $iva > 100) {
                throw new FilaInvalida("fila {$fila}: el descuento y el IVA van entre 0 y 100.");
            }

            $items[] = [
                'producto_id' => $producto->id,
                'variante_id' => $variante?->id,
                'descripcion' => mb_substr(self::texto($l['descripcion'] ?? '') ?: $producto->nombre, 0, 200),
                'cantidad' => $cantidad,
                'precio_unit' => $costo,
                'descuento_pct' => $desc,
                'iva_pct' => $iva,
            ];
        }

        $orden = CrearOrdenCompra::run([
            'proveedor_id' => $proveedor->id,
            'bodega_id' => $bodega,
            'tipo' => 'nacional',
            'moneda' => 'COP',
            'tasa_cambio' => 1,
            'fecha_esperada' => self::fecha($primera['fecha_esperada'] ?? null),
            'observaciones' => mb_substr(self::texto($primera['observaciones'] ?? '') ?: "Importada desde Excel (referencia {$ref})", 0, 500),
            'linea_negocio' => LineaNegocio::Dropi,
            'items' => $items,
        ]);

        return (string) $orden->numero;
    }

    private function proveedor(string $nit, string $nombre, int $fila): Contacto
    {
        if ($nit === '') {
            throw new FilaInvalida("fila {$fila}: falta el NIT del proveedor.");
        }
        $c = Contacto::where('numero_documento', $nit)->first();
        if ($c && $c->en_dropi && $c->es_proveedor) {
            return $c;
        }
        if (! $c && $nombre === '') {
            throw new FilaInvalida("fila {$fila}: el proveedor {$nit} no existe. Escriba su nombre en «proveedor_nombre» para crearlo, o créelo en «Clientes y proveedores».");
        }

        return RegistrarTerceroDropi::run([
            'nombre' => $nombre ?: ($c?->nombre_completo ?? $nit),
            'tipo_documento' => 'NIT',
            'numero_documento' => $nit,
            'es_proveedor' => true,
        ])['contacto'];
    }

    private function bodega(string $valor, int $fila): int
    {
        $bodegas = ComprasDropi::bodegas();
        if ($valor === '') {
            $id = ComprasDropi::bodegaPorDefecto();
            if (! $id) {
                throw new FilaInvalida("fila {$fila}: escriba la bodega (no hay bodega de e-commerce por defecto en Reglas).");
            }

            return $id;
        }
        foreach ($bodegas as $id => $nombre) {
            if ((string) $id === $valor || mb_strtolower(trim($nombre)) === mb_strtolower($valor)) {
                return (int) $id;
            }
        }
        throw new FilaInvalida("fila {$fila}: la bodega «{$valor}» no existe. Use el nombre tal como sale en «Nueva OC».");
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
        $w->addRow(Row::fromValues([
            'COMPRA-001', '900123456', 'Proveedor Ejemplo SAS', '', '2026-12-01',
            'REF-001', '', 'Body bebé rosa T2',
            50, 6000, 0, 19, 'Ejemplo: borre esta fila antes de importar',
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

