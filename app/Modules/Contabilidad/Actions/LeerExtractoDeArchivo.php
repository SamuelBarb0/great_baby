<?php

namespace App\Modules\Contabilidad\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Convierte el archivo del banco en el texto que lee `LeerExtractoBancario`.
 *
 * Pegar el extracto a mano funciona, pero obliga a abrir el PDF, seleccionar
 * 439 renglones sin saltarse ninguno y pegarlos sin que se desarme el formato.
 * Una línea que se quede por fuera no rompe nada a la vista: el cruce sale con
 * un movimiento menos y el descuadre aparece al final, cuando ya hay que
 * empezar de nuevo. Subir el archivo le quita ese paso de encima a quien
 * concilia, que es el paso donde se equivoca cualquiera.
 *
 * Acá sólo se saca el texto. Interpretarlo —fechas, valores, saldos— sigue
 * siendo trabajo de `LeerExtractoBancario`, que ya sabe hacerlo y ya tiene la
 * comprobación de que el extracto cuadra consigo mismo. Son dos trabajos
 * distintos y conviene que sigan separados: mañana entra un banco con otro
 * formato de archivo y se toca uno solo.
 */
class LeerExtractoDeArchivo
{
    use AsAction;

    /** Más que esto no es un extracto, es otra cosa. */
    public const MAX_MB = 20;

    public function handle(UploadedFile $archivo): string
    {
        $extension = strtolower($archivo->getClientOriginalExtension());

        $texto = match ($extension) {
            'pdf' => $this->desdePdf($archivo),
            'xlsx', 'xls', 'ods' => $this->desdeHojaDeCalculo($archivo),
            'csv', 'txt' => $this->desdeTextoPlano($archivo),
            default => throw ValidationException::withMessages([
                'archivo' => "No sé leer un archivo .{$extension}. Subí el extracto en PDF, "
                    .'Excel (.xlsx) o texto, tal como lo entrega el banco.',
            ]),
        };

        if (trim($texto) === '') {
            throw ValidationException::withMessages([
                'archivo' => 'El archivo no trae texto que se pueda leer. Si es un PDF escaneado '
                    .'—una foto de las hojas— no sirve: hay que bajarlo del portal del banco.',
            ]);
        }

        return $texto;
    }

    /**
     * El PDF se reconstruye por COORDENADAS, no por orden de lectura.
     *
     * El PDF del banco no guarda la tabla como tabla: guarda pedacitos de texto
     * sueltos, cada uno con su posición en la hoja. Pedirle el texto «de
     * corrido» devuelve la primera página bien y de la segunda en adelante
     * entrega las fechas todas juntas, después las descripciones todas juntas y
     * después los números —y al volver a armar los renglones la descripción
     * termina pegada al valor de otro movimiento. Eso no se nota: el extracto
     * sigue cuadrando porque los números son los mismos, pero cada línea queda
     * con el concepto equivocado.
     *
     * Por eso se agrupa por la coordenada Y —lo que está a la misma altura es
     * el mismo renglón— y dentro de cada renglón se ordena por X, que es como
     * lo lee un humano. Así salieron los 439 movimientos del extracto de
     * septiembre, con el mismo resultado que pegándolo a mano.
     *
     * La tolerancia de 2 puntos es porque dos pedazos del mismo renglón no
     * siempre traen la Y idéntica al decimal.
     */
    private function desdePdf(UploadedFile $archivo): string
    {
        try {
            $documento = (new PdfParser)->parseFile($archivo->getRealPath());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'archivo' => 'No se pudo abrir el PDF. Si tiene clave, quitásela y volvé a subirlo.',
            ]);
        }

        $lineas = [];

        foreach ($documento->getPages() as $pagina) {
            $renglones = [];

            foreach ($pagina->getDataTm() as [$matriz, $pedazo]) {
                if (trim($pedazo) === '') {
                    continue;
                }

                $alto = (int) round(((float) $matriz[5]) / 2);
                $renglones[$alto][] = [(float) $matriz[4], $pedazo];
            }

            krsort($renglones);   // de arriba de la hoja hacia abajo

            foreach ($renglones as $pedazos) {
                usort($pedazos, fn ($a, $b) => $a[0] <=> $b[0]);
                $lineas[] = trim(implode(' ', array_column($pedazos, 1)));
            }
        }

        return implode("\n", $lineas);
    }

    /**
     * Cada fila de la hoja se vuelve una línea de texto.
     *
     * No se adivina qué columna es cuál: se pegan las celdas con espacios y se
     * deja que el lector del extracto encuentre la fecha, el valor y el saldo
     * como lo hace con el texto pegado. Una hoja con columnas en otro orden no
     * se va a leer, y está bien que no se lea: es preferible que avise a que
     * invente.
     */
    private function desdeHojaDeCalculo(UploadedFile $archivo): string
    {
        $lector = IOFactory::createReaderForFile($archivo->getRealPath());
        $lector->setReadDataOnly(true);

        $hoja = $lector->load($archivo->getRealPath())->getActiveSheet();

        $lineas = [];

        foreach ($hoja->toArray(null, true, false, false) as $fila) {
            $celdas = array_filter(
                array_map(fn ($c) => trim((string) $c), $fila),
                fn ($c) => $c !== ''
            );

            if ($celdas !== []) {
                $lineas[] = implode(' ', $celdas);
            }
        }

        return implode("\n", $lineas);
    }

    /** Lo que ya es texto se usa tal cual, sólo normalizando la codificación. */
    private function desdeTextoPlano(UploadedFile $archivo): string
    {
        $crudo = (string) file_get_contents($archivo->getRealPath());

        // Los archivos del banco bajan en latin1 más seguido de lo que uno
        // quisiera, y un acento mal leído parte la descripción en dos.
        if (! mb_check_encoding($crudo, 'UTF-8')) {
            $crudo = mb_convert_encoding($crudo, 'UTF-8', 'ISO-8859-1');
        }

        return $crudo;
    }
}
