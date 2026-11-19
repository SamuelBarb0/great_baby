<?php

namespace App\Modules\Contabilidad\Services;

use App\Modules\Contabilidad\Models\PlanCuenta;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importa el plan de cuentas de Silvia desde Excel (.xlsx) o CSV.
 *
 * Columnas: `codigo` y `nombre` obligatorias; `naturaleza` (debito|credito),
 * `permite_movimiento` (1/0, si/no) y `siigo_cuenta_id` opcionales.
 * Si no viene `permite_movimiento`, se infiere: una cuenta es de movimiento
 * cuando es HOJA (ninguna otra cuenta del archivo la tiene como prefijo).
 *
 * Idempotente: hace updateOrCreate por `codigo`, así que re-subir el archivo
 * corregido actualiza sin duplicar. Al final revincula la jerarquía padre→hijo.
 */
class ImportadorPlanCuentas
{
    private const MAX_FILAS = 20000;

    /** Cuántas filas se miran buscando el encabezado antes de rendirse. */
    private const FILAS_PREAMBULO = 20;

    /**
     * @return array{creados:int, actualizados:int, errores:array<int,string>}
     */
    public function importar(string $rutaArchivo): array
    {
        $reader = $this->crearLector($rutaArchivo);
        $reader->open($rutaArchivo);

        $columnas = [];
        $registros = [];      // codigo => datos (última fila gana)
        $errores = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $esHeader = true;
            $i = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $i++;
                $cells = array_map(fn ($c) => trim((string) $c->getValue()), $row->getCells());

                /*
                 * La cabecera se BUSCA, no se asume que es la primera fila.
                 *
                 * El PUC que manda el cliente trae cuatro líneas de preámbulo
                 * —«Cuentas contables», la razón social, el NIT y una vacía—
                 * antes del encabezado, y lo escribe «Código» con tilde. Con la
                 * versión anterior, que tomaba la fila 1 tal cual, su archivo
                 * real moría con «faltan columnas obligatorias» y había que
                 * editarlo a mano. Les prometimos que mandaran el Excel como lo
                 * usan hoy: esto es lo que sostiene esa promesa.
                 */
                if ($esHeader) {
                    $posibles = array_map([self::class, 'normalizarEncabezado'], $cells);

                    if (in_array('codigo', $posibles, true) && in_array('nombre', $posibles, true)) {
                        $esHeader = false;
                        $columnas = $posibles;
                        continue;
                    }

                    if ($i > self::FILAS_PREAMBULO) {
                        $reader->close();
                        return ['creados' => 0, 'actualizados' => 0, 'errores' => [
                            'No se encontró el encabezado: el archivo debe tener una fila con las columnas '
                                . '"codigo" y "nombre" dentro de las primeras ' . self::FILAS_PREAMBULO . ' filas. '
                                . 'Descarga la plantilla oficial con el botón "Plantilla".',
                        ]];
                    }

                    continue;  // sigue siendo preámbulo
                }

                if (empty(array_filter($cells))) {
                    continue;
                }

                $fila = @array_combine(
                    array_pad($columnas, count($cells), ''),
                    array_pad($cells, count($columnas), '')
                );

                $codigoCrudo = (string) ($fila['codigo'] ?? '');

                /*
                 * Las filas de prosa no son cuentas y no se reportan como error.
                 *
                 * El export del cliente termina con «Procesado en septiembre 23
                 * 2026 12:57». Al quitarle los no-dígitos quedaba «2320261257»
                 * y se contaba como una cuenta sin nombre: la importación decía
                 * «con avisos · 1 error» por un pie de página, y eso hace dudar
                 * de las otras mil que sí entraron bien.
                 *
                 * Una fila cuyo código trae LETRAS no es una cuenta: se salta en
                 * silencio. Un código numérico sin nombre sí sigue siendo error.
                 */
                if (preg_match('/\p{L}/u', $codigoCrudo)) {
                    continue;
                }

                $codigo = preg_replace('/\D/', '', $codigoCrudo);
                $nombre = self::sanitizar((string) ($fila['nombre'] ?? ''));

                if ($codigo === '') {
                    $errores[] = "Fila {$i}: código vacío o sin dígitos.";
                    continue;
                }
                if ($nombre === '') {
                    $errores[] = "Fila {$i} (cuenta {$codigo}): nombre vacío.";
                    continue;
                }

                $registros[$codigo] = [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'naturaleza' => self::naturalezaValida($fila['naturaleza'] ?? null),
                    'permite_movimiento' => self::boolOpcional($fila['permite_movimiento'] ?? null),
                    'siigo_cuenta_id' => self::sanitizar((string) ($fila['siigo_cuenta_id'] ?? '')) ?: null,
                    'activa' => self::boolOpcional($fila['activa'] ?? null) ?? true,
                ];

                if (count($registros) > self::MAX_FILAS) {
                    $reader->close();
                    return ['creados' => 0, 'actualizados' => 0, 'errores' => [
                        'El archivo excede el límite de ' . self::MAX_FILAS . ' cuentas.',
                    ]];
                }
            }
            break; // solo la primera hoja
        }
        $reader->close();

        /*
         * Si el archivo se acabó sin que apareciera el encabezado, el problema
         * es el encabezado y hay que decirlo así. Antes caía en el mensaje
         * genérico de abajo —«no se encontraron cuentas válidas»— que manda a
         * revisar las filas cuando lo que falta es el título de las columnas.
         */
        if ($esHeader) {
            return ['creados' => 0, 'actualizados' => 0, 'errores' => [
                'No se encontró el encabezado: el archivo debe tener una fila con las columnas '
                    . '"codigo" y "nombre". Descarga la plantilla oficial con el botón "Plantilla".',
            ]];
        }

        if (empty($registros)) {
            return ['creados' => 0, 'actualizados' => 0, 'errores' => array_merge($errores, ['No se encontraron cuentas válidas para importar.'])];
        }

        // Inferir hojas (permite_movimiento) donde no venga explícito.
        $codigos = array_keys($registros);
        foreach ($registros as $codigo => &$datos) {
            if ($datos['permite_movimiento'] === null) {
                $esHoja = true;
                foreach ($codigos as $otro) {
                    if ($otro !== $codigo && str_starts_with($otro, $codigo) && strlen($otro) > strlen($codigo)) {
                        $esHoja = false;
                        break;
                    }
                }
                $datos['permite_movimiento'] = $esHoja;
            }
        }
        unset($datos);

        $creados = 0;
        $actualizados = 0;

        DB::transaction(function () use ($registros, &$creados, &$actualizados): void {
            foreach ($registros as $datos) {
                $cuenta = PlanCuenta::updateOrCreate(
                    ['codigo' => $datos['codigo']],
                    [
                        'nombre' => $datos['nombre'],
                        'naturaleza' => $datos['naturaleza'] ?? PlanCuenta::naturalezaPorClase(substr($datos['codigo'], 0, 1)),
                        'permite_movimiento' => $datos['permite_movimiento'],
                        'siigo_cuenta_id' => $datos['siigo_cuenta_id'],
                        'activa' => $datos['activa'],
                    ]
                );
                $cuenta->wasRecentlyCreated ? $creados++ : $actualizados++;
            }

            PlanCuenta::vincularPadres();
        });

        return ['creados' => $creados, 'actualizados' => $actualizados, 'errores' => $errores];
    }

    /**
     * Elige el lector según extensión. Para CSV auto-detecta el separador
     * (Excel en es-CO suele exportar con `;`).
     */
    private function crearLector(string $ruta): ReaderInterface
    {
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            $options = new CsvOptions();
            $options->FIELD_DELIMITER = $this->detectarDelimitador($ruta);
            return new CsvReader($options);
        }

        return new XlsxReader();
    }

    private function detectarDelimitador(string $ruta): string
    {
        $primera = '';
        if ($h = @fopen($ruta, 'r')) {
            $primera = (string) fgets($h);
            fclose($h);
        }
        // BOM fuera.
        $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }

    /** naturaleza válida ('debito'/'credito') o null para que la derive la clase. */
    private static function naturalezaValida(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (str_starts_with($v, 'deb') || $v === 'd') {
            return 'debito';
        }
        if (str_starts_with($v, 'cred') || str_starts_with($v, 'cré') || $v === 'c') {
            return 'credito';
        }
        return null;
    }

    private static function boolOpcional(?string $v): ?bool
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['1', 'si', 'sí', 'true', 'x', 'yes', 'y', 'verdadero'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'no', 'false', 'n', 'falso'], true)) {
            return false;
        }
        return null;
    }

    /** Anti CSV-injection + corte de longitud. */
    /**
     * Un encabezado, comparable: minúsculas, sin tildes y sin espacios sobrantes.
     *
     * El cliente escribe «Código»; la plantilla dice «codigo». Son la misma
     * columna y el importador no tiene por qué obligarlo a elegir.
     */
    private static function normalizarEncabezado(string $h): string
    {
        $h = mb_strtolower(trim($h), 'UTF-8');

        return strtr($h, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n',
        ]);
    }

    private static function sanitizar(?string $v): string
    {
        if ($v === null) {
            return '';
        }
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if (in_array(mb_substr($v, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }
        return mb_substr($v, 0, 150);
    }
}
