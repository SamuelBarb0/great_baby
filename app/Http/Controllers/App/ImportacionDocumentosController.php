<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Actions\GuardarDocumentoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos del contenedor: subir, abrir y quitar. Mismo acceso que la
 * pantalla de la importación; quitar es solo de Aracely/Gerencia («solo el
 * admin borra», reunión de Compras del 21-oct) y deja el archivo en papelera.
 */
class ImportacionDocumentosController extends Controller implements HasMiddleware
{
    public const MIMES = 'pdf,xlsx,xls,csv,doc,docx,jpg,jpeg,png,webp,zip';

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esEquipoBodega(), 403);
                return $next($r);
            }),
        ];
    }

    public function subir(Request $r, Importacion $importacion): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', 'string', Rule::in(array_diff(array_keys(ImportacionDocumento::TIPOS), ['comprobante_gasto']))],
            'archivo' => ['required', 'file', 'max:20480', 'mimes:'.self::MIMES],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);

        $doc = GuardarDocumentoImportacion::run($importacion, $r->file('archivo'), $data['tipo'], null, $data['notas'] ?? null);

        return back()->with('success', ImportacionDocumento::TIPOS[$doc->tipo].($doc->version > 1 ? " (versión {$doc->version})" : '').' subido.');
    }

    public function descargar(ImportacionDocumento $documento): StreamedResponse
    {
        abort_unless(Storage::disk(ImportacionDocumento::DISCO)->exists($documento->ruta), 404, 'El archivo ya no está en el servidor.');

        return Storage::disk(ImportacionDocumento::DISCO)->response($documento->ruta, $documento->nombre);
    }

    public function eliminar(Request $r, ImportacionDocumento $documento): RedirectResponse
    {
        abort_unless($r->user()?->esAracely(), 403, 'Solo Aracely o Gerencia quitan documentos.');
        $documento->delete();

        return back()->with('success', 'Documento quitado.');
    }
}
