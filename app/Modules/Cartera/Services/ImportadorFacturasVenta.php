<?php

namespace App\Modules\Cartera\Services;

use App\Models\Contacto;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * F2b · Subir facturas de venta desde el Excel del cliente.
 *
 * Great Baby llega al ERP con años de facturación hecha afuera. Cargarla a mano
 * no es una opción, y crear 300 facturas de un botón sin mirarlas tampoco: por
 * eso esto tiene DOS pasos separados —`previsualizar()` y `importar()`— y el
 * primero no toca la base.
 *
 * Una fila es un ÍTEM, no una factura. Las filas que comparten la columna
 * `factura` se agrupan en una sola factura con varios ítems, que es como se ve
 * una factura de verdad. La clave de agrupación la pone quien arma el archivo
 * (el número viejo, o 1, 2, 3): no se adivina por cliente + fecha, porque dos
 * facturas al mismo cliente el mismo día son algo que pasa todos los días.
 *
 * Todo lo que crea pasa por `CrearFacturaManual`, igual que el formulario: el
 * consecutivo, las retenciones y el asiento salen del mismo sitio. Un importador
 * que arme la factura por su cuenta es un importador que tarde o temprano deja
 * facturas sin asiento.
 */
class ImportadorFacturasVenta
{
    private const MAX_FILAS = 5000;

    /** Cuántas filas se miran buscando el encabezado antes de rendirse. */
    private const FILAS_PREAMBULO = 20;

    /** Encabezados que se aceptan para cada columna, ya normalizados. */
    private const ALIAS = [
        'factura' => ['factura', 'numero', 'numero factura', 'num factura', 'no factura', 'grupo', 'consecutivo'],
        'documento_cliente' => ['documento cliente', 'documento', 'nit', 'nit cliente', 'cedula', 'identificacion'],
        'fecha_emision' => ['fecha emision', 'fecha', 'emision', 'fecha de emision'],
        'fecha_vencimiento' => ['fecha vencimiento', 'vencimiento', 'vence', 'fecha de vencimiento'],
        'descripcion' => ['descripcion', 'detalle', 'concepto', 'producto', 'item'],
        'cantidad' => ['cantidad', 'cant', 'unidades'],
        'precio_unitario' => ['precio unitario', 'precio', 'valor unitario', 'vr unitario', 'precio unit'],
        'descuento_pct' => ['descuento pct', 'descuento', 'dcto', 'descuento %', '% descuento'],
        'iva_pct' => ['iva pct', 'iva', 'iva %', '% iva', 'impuesto'],
        'referencia_producto' => ['referencia producto', 'referencia', 'ref', 'codigo', 'codigo producto', 'sku'],
        'destino' => ['destino', 'destino fiscal'],
        'monto_fiscal' => ['monto fiscal', 'monto declarado', 'declarado'],
        'linea' => ['linea', 'linea negocio', 'linea de negocio', 'negocio'],
        'motivo' => ['motivo', 'motivo destino', 'justificacion'],
        'observaciones' => ['observaciones', 'observacion', 'notas', 'nota'],
    ];

    private const OBLIGATORIAS = ['factura', 'documento_cliente', 'fecha_emision', 'referencia_producto', 'descripcion', 'cantidad', 'precio_unitario'];

    /**
     * Lee el archivo y arma lo que se CREARÍA, sin crear nada.
     *
     * @return array{facturas:array<int,array<string,mixed>>, errores:array<int,string>, resumen:array<string,mixed>}
     */
    public function previsualizar(string $ruta, ?string $nombreOriginal = null): array
    {
        $lectura = $this->leer($ruta, $nombreOriginal);

        if ($lectura['errores'] !== [] && $lectura['filas'] === []) {
            return ['facturas' => [], 'errores' => $lectura['errores'], 'resumen' => $this->resumen([], $lectura['errores'])];
        }

        $agrupado = $this->agrupar($lectura['filas']);
        $errores = array_merge($lectura['errores'], $agrupado['errores']);

        return [
            'facturas' => $agrupado['facturas'],
            'errores' => $errores,
            'resumen' => $this->resumen($agrupado['facturas'], $errores),
        ];
    }

    /**
     * Crea de verdad. Sólo entra lo que la previsualización daría por bueno:
     * si una factura del archivo tiene errores se salta ENTERA, nunca a medias.
     *
     * @return array{creadas:int, numeros:array<int,string>, omitidas:int, errores:array<int,string>}
     */
    public function importar(string $ruta, ?int $userId = null, ?string $nombreOriginal = null): array
    {
        $previa = $this->previsualizar($ruta, $nombreOriginal);

        $creadas = [];
        $errores = $previa['errores'];
        $omitidas = 0;

        foreach ($previa['facturas'] as $f) {
            if ($f['errores'] !== []) {
                $omitidas++;
                continue;
            }

            try {
                /*
                 * Cada factura en su propia transacción. Si la número 180 del
                 * archivo revienta, las 179 anteriores quedan bien guardadas:
                 * volver a subir un archivo de 300 filas por una sola mala es
                 * exactamente lo que nadie va a hacer.
                 */
                $factura = DB::transaction(fn () => CrearFacturaManual::run([
                    'contacto_id' => $f['contacto_id'],
                    'fecha_emision' => $f['fecha_emision'],
                    'fecha_vencimiento' => $f['fecha_vencimiento'],
                    'linea_negocio' => $f['linea'],
                    'destino_fiscal' => $f['destino'],
                    'monto_fiscal' => $f['monto_fiscal'],
                    'motivo_destino' => $f['motivo'],
                    'observaciones' => $f['observaciones'],
                    'items' => $f['items'],
                ], $userId));

                $creadas[] = $factura->numero;
            } catch (Throwable $e) {
                $omitidas++;
                $errores[] = "Factura «{$f['clave']}»: ".$e->getMessage();
            }
        }

        return [
            'creadas' => count($creadas),
            'numeros' => $creadas,
            'omitidas' => $omitidas,
            'errores' => $errores,
        ];
    }

    /**
     * El archivo, fila por fila, ya mapeado a nombres de columna conocidos.
     *
     * @return array{filas:array<int,array<string,mixed>>, errores:array<int,string>}
     */
    private function leer(string $ruta, ?string $nombreOriginal = null): array
    {
        $reader = $this->crearLector($ruta, $nombreOriginal);

        try {
            $reader->open($ruta);
        } catch (Throwable $e) {
            return ['filas' => [], 'errores' => ['No se pudo abrir el archivo: '.$e->getMessage()]];
        }

        $filas = [];
        $errores = [];
        $columnas = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $buscandoHeader = true;
            $i = 0;

            foreach ($sheet->getRowIterator() as $row) {
                $i++;
                $cells = array_map(fn ($c) => trim((string) $c->getValue()), $row->getCells());

                // El encabezado se BUSCA: el Excel del cliente suele traer el
                // nombre de la empresa y un par de líneas antes de la tabla.
                if ($buscandoHeader) {
                    $mapa = $this->mapearEncabezado($cells);

                    if ($this->encabezadoSirve($mapa)) {
                        $buscandoHeader = false;
                        $columnas = $mapa;
                        continue;
                    }

                    if ($i > self::FILAS_PREAMBULO) {
                        break;
                    }

                    continue;
                }

                if (count($filas) >= self::MAX_FILAS) {
                    $errores[] = 'El archivo tiene más de '.self::MAX_FILAS.' filas: subilo por partes.';
                    break;
                }

                $fila = [];
                foreach ($columnas as $pos => $nombre) {
                    $fila[$nombre] = $cells[$pos] ?? '';
                }

                // Fila vacía (Excel las deja de a decenas al final).
                if (implode('', $fila) === '') {
                    continue;
                }

                $fila['_linea'] = $i;
                $filas[] = $fila;
            }

            break;  // sólo la primera hoja
        }

        $reader->close();

        if ($columnas === []) {
            $faltan = implode(', ', self::OBLIGATORIAS);
            $errores[] = "No se encontró el encabezado. Las columnas obligatorias son: {$faltan}. "
                .'Descargá la plantilla para ver el formato exacto.';
        } elseif ($filas === []) {
            $errores[] = 'El encabezado está bien pero no hay ninguna fila con datos debajo.';
        }

        return ['filas' => $filas, 'errores' => $errores];
    }

    /**
     * Junta las filas en facturas y valida cada una.
     *
     * @param  array<int,array<string,mixed>>  $filas
     * @return array{facturas:array<int,array<string,mixed>>, errores:array<int,string>}
     */
    private function agrupar(array $filas): array
    {
        $grupos = [];
        $errores = [];

        foreach ($filas as $fila) {
            $clave = trim((string) ($fila['factura'] ?? ''));
            $nLinea = $fila['_linea'];

            if ($clave === '') {
                $errores[] = "Fila {$nLinea}: sin columna «factura», no se sabe a qué factura pertenece el ítem.";
                continue;
            }

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'clave' => $clave,
                    'lineas' => [],
                    'cabecera' => $fila,
                ];
            }

            $grupos[$clave]['lineas'][] = $fila;
        }

        $facturas = [];
        foreach ($grupos as $g) {
            $facturas[] = $this->armar($g);
        }

        return ['facturas' => $facturas, 'errores' => $errores];
    }

    /**
     * Una factura del archivo, con sus totales ya calculados y sus problemas
     * listados. Es lo que la pantalla muestra antes de confirmar.
     *
     * @param  array{clave:string,lineas:array<int,array<string,mixed>>,cabecera:array<string,mixed>}  $g
     * @return array<string,mixed>
     */
    private function armar(array $g): array
    {
        $errores = [];
        $avisos = [];
        $cab = $g['cabecera'];

        // ── Cliente ──────────────────────────────────────────────────────────
        $doc = preg_replace('/[^0-9A-Za-z]/', '', (string) ($cab['documento_cliente'] ?? ''));
        $contacto = $doc !== ''
            ? Contacto::where('numero_documento', $doc)->first(['id', 'nombre_completo'])
            : null;

        if ($doc === '') {
            $errores[] = 'Falta el documento del cliente.';
        } elseif (! $contacto) {
            // Crear terceros desde acá sería llenar la base de clientes a medias
            // —sin ciudad, sin régimen— y después SIIGO los rechaza uno por uno.
            $errores[] = "El cliente con documento {$doc} no existe en el ERP. Crealo primero en Contactos.";
        }

        // ── Fechas ───────────────────────────────────────────────────────────
        $emision = $this->fecha($cab['fecha_emision'] ?? null);
        if ($emision === null) {
            $errores[] = 'La fecha de emisión está vacía o no se entiende (usá aaaa-mm-dd o dd/mm/aaaa).';
        }
        $vencimiento = $this->fecha($cab['fecha_vencimiento'] ?? null);
        if ($vencimiento !== null && $emision !== null && $vencimiento < $emision) {
            $errores[] = 'La fecha de vencimiento es anterior a la de emisión.';
        }

        // ── Los dos ejes ─────────────────────────────────────────────────────
        $destinoCrudo = $this->normalizar((string) ($cab['destino'] ?? ''));
        $destino = $this->destino($destinoCrudo);
        if ($destinoCrudo !== '' && $destino === null) {
            $errores[] = "No se entiende el destino «{$destinoCrudo}». Usá: fiscal, interno o reparto.";
        }
        $destino ??= DestinoFiscal::porDefecto();

        $lineaCruda = $this->normalizar((string) ($cab['linea'] ?? ''));
        $linea = $this->lineaNegocio($lineaCruda);
        if ($lineaCruda !== '' && $linea === null) {
            $errores[] = "No se entiende la línea «{$lineaCruda}». Usá: great_baby o dropi.";
        }
        $linea ??= LineaNegocio::GreatBaby;

        $motivo = trim((string) ($cab['motivo'] ?? ''));
        if ($destino !== DestinoFiscal::Siigo && $motivo === '') {
            $errores[] = 'Para que no vaya a lo fiscal hay que llenar la columna «motivo».';
        }

        $montoFiscal = $this->numero($cab['monto_fiscal'] ?? null);

        // ── Ítems ────────────────────────────────────────────────────────────
        $items = [];
        foreach ($g['lineas'] as $fila) {
            $nLinea = $fila['_linea'];
            $desc = trim((string) ($fila['descripcion'] ?? ''));
            $cantidad = $this->numero($fila['cantidad'] ?? null);
            $precio = $this->numero($fila['precio_unitario'] ?? null);

            if ($desc === '') {
                $errores[] = "Fila {$nLinea}: falta la descripción del ítem.";
                continue;
            }
            if ($cantidad === null || $cantidad <= 0) {
                $errores[] = "Fila {$nLinea}: la cantidad tiene que ser mayor que cero.";
                continue;
            }
            if ($precio === null || $precio < 0) {
                $errores[] = "Fila {$nLinea}: el precio unitario está vacío o es negativo.";
                continue;
            }

            /*
             * El producto es OBLIGATORIO: la base lo exige por CHECK y SIIGO
             * pide un código por línea. Para fletes y servicios se crea una
             * vez el producto en el catálogo y se reusa la referencia.
             */
            $productoId = null;
            $ref = trim((string) ($fila['referencia_producto'] ?? ''));

            if ($ref === '') {
                $errores[] = "Fila {$nLinea}: falta la referencia del producto. Si es un flete o un "
                    .'servicio, creá el producto una vez en el catálogo y poné su referencia acá.';
                continue;
            }

            $p = Producto::query()
                ->where(fn ($w) => $w->where('referencia', $ref)->orWhere('dropi_sku', $ref))
                ->first(['id', 'nombre', 'siigo_id']);

            if (! $p) {
                $errores[] = "Fila {$nLinea}: no hay ningún producto con referencia «{$ref}».";
                continue;
            }

            $productoId = $p->id;

            $items[] = [
                'producto_id' => $productoId,
                'descripcion' => $desc,
                'cantidad' => $cantidad,
                'precio_unit' => $precio,
                'descuento_pct' => $this->numero($fila['descuento_pct'] ?? null) ?? 0,
                'impuesto_pct' => $this->numero($fila['iva_pct'] ?? null) ?? 0,
                '_linea' => $nLinea,
            ];
        }

        if ($items === []) {
            $errores[] = 'No quedó ningún ítem válido en esta factura.';
        }

        // ── Totales y reparto ────────────────────────────────────────────────
        $calculo = app(CrearFacturaManual::class)->calcular($items);

        if ($destino === DestinoFiscal::Reparto) {
            if ($montoFiscal === null || $montoFiscal <= 0) {
                $errores[] = 'Es un reparto: falta la columna «monto_fiscal» con lo que se declara.';
            } elseif ($calculo['total'] > 0 && $montoFiscal >= $calculo['total']) {
                $errores[] = 'Lo declarado no puede ser igual ni mayor al total. Si se declara todo, el destino es «fiscal».';
            }
        } elseif ($montoFiscal !== null && $montoFiscal > 0) {
            $avisos[] = 'Tiene «monto_fiscal» pero el destino no es reparto: el monto se ignora.';
        }

        if ($errores === []) {
            $avisos = array_merge($avisos, CrearFacturaManual::avisosDeSiigo($items, $destino));
        }

        return [
            'clave' => $g['clave'],
            'contacto_id' => $contacto?->id,
            'cliente' => $contacto?->nombre_completo ?? ($doc !== '' ? "(no existe: {$doc})" : '—'),
            'documento' => $doc,
            'fecha_emision' => $emision,
            'fecha_vencimiento' => $vencimiento,
            'destino' => $destino->value,
            'destino_label' => $destino->label(),
            'linea' => $linea->value,
            'linea_label' => $linea->label(),
            'monto_fiscal' => $destino === DestinoFiscal::Reparto ? $montoFiscal : null,
            'motivo' => $motivo !== '' ? $motivo : null,
            'observaciones' => trim((string) ($cab['observaciones'] ?? '')) ?: null,
            // El número de fila sólo sirve para los mensajes de error; no viaja al ítem.
            'items' => array_map(fn ($i) => array_diff_key($i, ['_linea' => null]), $items),
            'cantidad_items' => count($items),
            'subtotal' => $calculo['subtotal'],
            'descuento' => $calculo['descuento'],
            'impuestos' => $calculo['impuestos'],
            'total' => $calculo['total'],
            'errores' => array_values(array_unique($errores)),
            'avisos' => array_values(array_unique($avisos)),
        ];
    }

    /** @param array<int,array<string,mixed>> $facturas */
    private function resumen(array $facturas, array $errores): array
    {
        $buenas = array_filter($facturas, fn ($f) => $f['errores'] === []);

        return [
            'facturas' => count($facturas),
            'listas' => count($buenas),
            'con_error' => count($facturas) - count($buenas),
            'items' => array_sum(array_column($facturas, 'cantidad_items')),
            'total_listas' => round(array_sum(array_column($buenas, 'total')), 2),
            'errores_generales' => count($errores),
        ];
    }

    // ── Lectura ──────────────────────────────────────────────────────────────

    /**
     * Posición de columna → nombre canónico, para las que se reconocen.
     *
     * @param  array<int,string>  $cells
     * @return array<int,string>
     */
    private function mapearEncabezado(array $cells): array
    {
        $mapa = [];
        foreach ($cells as $pos => $texto) {
            $norm = $this->normalizar($texto);
            if ($norm === '') {
                continue;
            }
            foreach (self::ALIAS as $canonico => $alias) {
                if (in_array($norm, $alias, true) && ! in_array($canonico, $mapa, true)) {
                    $mapa[$pos] = $canonico;
                    break;
                }
            }
        }

        return $mapa;
    }

    /** @param array<int,string> $mapa */
    private function encabezadoSirve(array $mapa): bool
    {
        foreach (self::OBLIGATORIAS as $c) {
            if (! in_array($c, $mapa, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * El lector sale de la extensión del nombre ORIGINAL, no de la ruta.
     *
     * Un archivo subido por formulario vive en disco como `php3B7.tmp`: mirando
     * la ruta, la extensión es «tmp», y todo CSV caía en el lector de XLSX, que
     * lo rechazaba con «Could not open … for reading» — un mensaje que no le
     * dice a nadie que el archivo estaba bien.
     */
    private function crearLector(string $ruta, ?string $nombreOriginal = null): ReaderInterface
    {
        $ext = strtolower(pathinfo($nombreOriginal ?: $ruta, PATHINFO_EXTENSION));

        // Si el nombre tampoco ayuda, se mira el contenido: un XLSX es un ZIP y
        // empieza por «PK». Cualquier otra cosa se lee como texto separado.
        if ($ext !== 'xlsx' && $ext !== 'csv' && $ext !== 'txt') {
            $ext = $this->pareceXlsx($ruta) ? 'xlsx' : 'csv';
        }

        if ($ext === 'csv' || $ext === 'txt') {
            $options = new CsvOptions();
            $options->FIELD_DELIMITER = $this->detectarDelimitador($ruta);

            return new CsvReader($options);
        }

        return new XlsxReader();
    }

    private function pareceXlsx(string $ruta): bool
    {
        $firma = '';
        if ($h = @fopen($ruta, 'rb')) {
            $firma = (string) fread($h, 2);
            fclose($h);
        }

        return $firma === 'PK';
    }

    /** Excel en es-CO exporta CSV con `;`. */
    private function detectarDelimitador(string $ruta): string
    {
        $primera = '';
        if ($h = @fopen($ruta, 'r')) {
            $primera = (string) fgets($h);
            fclose($h);
        }
        $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }

    // ── Normalizadores ───────────────────────────────────────────────────────

    private function normalizar(string $v): string
    {
        $v = mb_strtolower(trim($v), 'UTF-8');
        $v = strtr($v, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', '_' => ' ',
        ]);

        return trim(preg_replace('/\s+/', ' ', $v));
    }

    /**
     * Un numero escrito como lo escribe Colombia: 1.234.567,89.
     *
     * No alcanza con «el punto es el decimal»: con esa regla «45.000» se leia
     * como 45 pesos —una factura mil veces mas chica, en silencio— y
     * «1.000.000» directamente no se leia. Las dos cosas aparecieron subiendo
     * un archivo de verdad.
     *
     * La regla, en orden:
     *   · si estan los dos separadores, el ULTIMO es el decimal
     *   · si esta uno solo y se repite, son miles
     *   · si esta uno solo y una vez, decide cuantos digitos lo siguen:
     *     exactamente tres son miles (45.000 = cuarenta y cinco mil), y
     *     cualquier otra cantidad es decimal (45.5 · 1234.5678)
     *
     * Queda una ambiguedad real que ningun algoritmo resuelve: «1,500» puede
     * ser mil quinientos o uno y medio. Se elige miles porque es lo que escribe
     * quien arma el archivo aca, y porque la previsualizacion muestra el total
     * de cada factura justamente para que se vea antes de crear nada.
     *
     * Nada de esto corre con un .xlsx de verdad: ahi las celdas numericas
     * llegan como numero y entran por el atajo de arriba.
     */
    private function numero(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        $s = trim(str_replace(['$', ' ', "\u{00A0}", '%'], '', (string) $v));
        if ($s === '' || ! preg_match('/\d/', $s)) {
            return null;
        }

        $signo = str_starts_with($s, '-') ? -1 : 1;
        $s = ltrim($s, '+-');

        $puntos = substr_count($s, '.');
        $comas = substr_count($s, ',');

        if ($puntos > 0 && $comas > 0) {
            // Estan los dos: el ultimo que aparece es el decimal.
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $miles = $decimal === ',' ? '.' : ',';
            $s = str_replace($miles, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($puntos + $comas > 0) {
            $sep = $puntos > 0 ? '.' : ',';
            $veces = $puntos + $comas;
            $digitosDespues = strlen(substr($s, strrpos($s, $sep) + 1));

            if ($veces > 1 || $digitosDespues === 3) {
                $s = str_replace($sep, '', $s);   // miles
            } else {
                $s = str_replace($sep, '.', $s);  // decimal
            }
        }

        return is_numeric($s) ? $signo * (float) $s : null;
    }

    private function fecha(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($v))->toDateString();
        }

        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }

        // dd/mm/aaaa y dd-mm-aaaa, que es como lo escribe todo el mundo acá.
        // Carbon los leería como mm/dd, así que se arman a mano.
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
            if ((int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[1] < 1 || (int) $m[1] > 31) {
                return null;
            }

            try {
                return Carbon::createFromFormat('!d/n/Y', "{$m[1]}/{$m[2]}/{$m[3]}")->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($s)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function destino(string $norm): ?DestinoFiscal
    {
        if ($norm === '') {
            return null;
        }

        return match (true) {
            str_contains($norm, 'siigo'), str_starts_with($norm, 'fiscal'), str_starts_with($norm, 'dian') => DestinoFiscal::Siigo,
            str_starts_with($norm, 'repart'), str_contains($norm, 'parcial') => DestinoFiscal::Reparto,
            str_starts_with($norm, 'intern'), str_contains($norm, 'no fiscal') => DestinoFiscal::Interno,
            default => DestinoFiscal::tryFrom($norm),
        };
    }

    private function lineaNegocio(string $norm): ?LineaNegocio
    {
        if ($norm === '') {
            return null;
        }

        return match (true) {
            str_contains($norm, 'dropi') => LineaNegocio::Dropi,
            str_contains($norm, 'great'), str_contains($norm, 'baby'), str_contains($norm, 'gb') => LineaNegocio::GreatBaby,
            default => LineaNegocio::tryFrom($norm),
        };
    }
}
