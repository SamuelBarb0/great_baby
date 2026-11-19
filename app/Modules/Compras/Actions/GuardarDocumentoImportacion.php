<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Guarda un archivo del contenedor en el disco privado. Un tipo que se sube de
 * nuevo (p. ej. la lista de empaque final después del borrador) no pisa la
 * anterior: queda como versión siguiente.
 */
class GuardarDocumentoImportacion
{
    use AsAction;

    public function handle(Importacion $importacion, UploadedFile $archivo, string $tipo, ?GastoImportacion $gasto = null, ?string $notas = null): ImportacionDocumento
    {
        if (! array_key_exists($tipo, ImportacionDocumento::TIPOS)) {
            throw new InvalidArgumentException('Tipo de documento no válido.');
        }
        if ($gasto && $gasto->importacion_id !== $importacion->id) {
            throw new InvalidArgumentException('El gasto no es de esta importación.');
        }

        $extension = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension() ?: 'bin');
        $ruta = $archivo->storeAs("compras/importaciones/{$importacion->id}", Str::uuid().'.'.$extension, ImportacionDocumento::DISCO);

        return DB::transaction(function () use ($importacion, $archivo, $tipo, $gasto, $notas, $ruta) {
            $version = (int) ImportacionDocumento::withTrashed()
                ->where('importacion_id', $importacion->id)
                ->where('tipo', $tipo)
                ->when($gasto, fn ($q) => $q->where('gasto_id', $gasto->id))
                ->lockForUpdate()
                ->max('version') + 1;

            $documento = ImportacionDocumento::create([
                'importacion_id' => $importacion->id,
                'gasto_id' => $gasto?->id,
                'tipo' => $tipo,
                'nombre' => $archivo->getClientOriginalName(),
                'ruta' => $ruta,
                'mime' => $archivo->getClientMimeType(),
                'tamano' => $archivo->getSize(),
                'version' => $version,
                'notas' => $notas,
                'subido_por' => auth()->id(),
            ]);
            app(\App\Modules\Marketing\Services\TableroLanzamiento::class)->alDiaConCompras($importacion);

            return $documento;
        });
    }
}
