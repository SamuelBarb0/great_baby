<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Actions\RegistrarTerceroDropi;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Clientes y proveedores de Dropi desde Excel (23-nov-2026).
 *
 * Cada fila pasa por RegistrarTerceroDropi, la misma regla de la pantalla:
 * un documento nuevo nace sólo en Dropi; uno que ya tenía el ERP se comparte
 * (no se duplica ni se le cambian los datos). Una fila mala no frena las
 * demás: se informa con su número de fila.
 */
class ImportadorTercerosDropi
{
    public const MAX_FILAS = 5000;

    public const COLUMNAS = [
        'tipo_documento', 'numero_documento', 'nombre', 'es_proveedor', 'es_cliente',
        'email', 'telefono', 'direccion', 'ciudad',
    ];

    public const REQUERIDAS = ['numero_documento', 'nombre'];

    private const TIPOS = ['NIT' => 'NIT', 'CC' => 'CC', 'CE' => 'CE', 'PP' => 'PP', 'PASAPORTE' => 'PP',
        'CEDULA' => 'CC', 'CÉDULA' => 'CC'];

    /** @return array{creados:int, compartidos:int, errores:array<int,string>} */
    public function importar(string $ruta): array
    {
        $out = ['creados' => 0, 'compartidos' => 0, 'errores' => []];

        $reader = new Reader();
        $reader->open($ruta);
        $columnas = null;
        $n = 0;
        $filas = 0;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $valores = $row->toArray();
                    if ($columnas === null) {
                        $columnas = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $valores);
                        if ($faltan = array_diff(self::REQUERIDAS, $columnas)) {
                            $out['errores'][] = 'Faltan columnas: '.implode(', ', $faltan).'. Descargue la plantilla.';

                            return $out;
                        }

                        continue;
                    }
                    $f = [];
                    foreach ($columnas as $i => $col) {
                        if ($col !== '') {
                            $f[$col] = trim((string) ($valores[$i] ?? ''));
                        }
                    }
                    if (($f['numero_documento'] ?? '') === '' && ($f['nombre'] ?? '') === '') {
                        continue;
                    }
                    if (++$filas > self::MAX_FILAS) {
                        $out['errores'][] = 'El archivo pasa de '.self::MAX_FILAS.' filas: se cargaron las primeras.';
                        break 2;
                    }
                    $this->fila($f, $n, $out);
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    private function fila(array $f, int $n, array &$out): void
    {
        $numero = preg_replace('/[^0-9A-Za-z]/', '', (string) ($f['numero_documento'] ?? ''));
        $nombre = (string) ($f['nombre'] ?? '');
        if ($numero === '' || mb_strlen($nombre) < 2) {
            $out['errores'][] = "Fila {$n}: falta el número de documento o el nombre.";

            return;
        }
        $tipo = self::TIPOS[mb_strtoupper(trim((string) ($f['tipo_documento'] ?? '')))] ?? 'NIT';
        $proveedor = self::si($f['es_proveedor'] ?? null);
        $cliente = self::si($f['es_cliente'] ?? null);
        // Sin marcar nada se entiende proveedor: es para lo que más se usa en Dropi.
        if (! $proveedor && ! $cliente) {
            $proveedor = true;
        }
        $email = (string) ($f['email'] ?? '');
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out['errores'][] = "Fila {$n}: el correo «{$email}» no es válido; se cargó sin correo.";
            $email = '';
        }

        try {
            $r = RegistrarTerceroDropi::run([
                'nombre' => mb_substr($nombre, 0, 180),
                'tipo_documento' => $tipo,
                'numero_documento' => $numero,
                'email' => $email ?: null,
                'telefono' => mb_substr((string) ($f['telefono'] ?? ''), 0, 30) ?: null,
                'direccion' => mb_substr((string) ($f['direccion'] ?? ''), 0, 200) ?: null,
                'ciudad' => mb_substr((string) ($f['ciudad'] ?? ''), 0, 80) ?: null,
                'es_proveedor' => $proveedor,
                'es_cliente' => $cliente,
            ]);
            $r['ya_existia'] ? $out['compartidos']++ : $out['creados']++;
        } catch (ValidationException $e) {
            $out['errores'][] = "Fila {$n} ({$numero}): ".collect($e->errors())->flatten()->implode(' ');
        } catch (\Throwable $e) {
            report($e);
            $out['errores'][] = "Fila {$n} ({$numero}): {$e->getMessage()}";
        }
    }

    private static function si(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) $v)), ['si', 'sí', 's', 'x', '1', 'true', 'yes'], true);
    }

    public static function escribirPlantilla(string $ruta): void
    {
        $w = new Writer();
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(self::COLUMNAS));
        $w->addRow(Row::fromValues(['NIT', '900123456', 'Proveedor Ejemplo SAS', 'si', 'no', 'compras@ejemplo.com', '3001234567', 'Cra 1 # 2-3', 'Bogotá']));
        $w->addRow(Row::fromValues(['CC', '1020304050', 'Cliente Ejemplo', 'no', 'si', '', '', '', 'Medellín']));
        $w->close();
    }
}
