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

    /** Las listas de empaque traen las fotos de la carga: las reales llegan a casi 100 MB. */
    public const MAX_MB = 100;

    /**
     * Lo que de verdad se puede subir en este servidor. Por encima de sus límites
     * PHP ni siquiera deja responder, así que la pantalla lo avisa antes de enviar.
     */
    public static function limiteMb(): int
    {
        $subida = \Illuminate\Http\UploadedFile::getMaxFilesize() / 1048576;
        $post = ini_parse_quantity((string) ini_get('post_max_size')) / 1048576;

        return (int) floor(min(self::MAX_MB, $subida, $post > 0 ? $post : self::MAX_MB));
    }

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                /*
                 * Descargar es de quien cuenta; subir y borrar siguen siendo
                 * de Compras.
                 *
                 * La pantalla de recepción muestra los papeles del contenedor
                 * —factura final, lista de empaque— al lado del conteo,
                 * justamente para que se cuente contra ellos. Pero esa
                 * pantalla la abre quien tenga `compras_recepcion`, que
                 * incluye al Alistador, y acá se exigía `compras_importacion`,
                 * que NO lo incluye: los enlaces se le pintaban y todos le
                 * daban 403. O sea, la función quedó hecha para el único rol
                 * que no podía usarla.
                 *
                 * Ver un papel contra el que hay que contar no es lo mismo que
                 * poder cambiarlo: por eso sólo se abre `descargar`.
                 */
                $soloMira = $r->route()?->getActionMethod() === 'descargar';

                abort_unless(
                    \App\Auth\Permisos::puede($r->user(), 'compras_importacion')
                        || ($soloMira && \App\Auth\Permisos::puede($r->user(), 'compras_recepcion')),
                    403,
                );

                return $next($r);
            }),
        ];
    }

    public function subir(Request $r, Importacion $importacion): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', 'string', Rule::in(array_diff(array_keys(ImportacionDocumento::TIPOS), ['comprobante_gasto']))],
            'archivo' => ['required', 'file', 'max:'.(self::MAX_MB * 1024), 'mimes:'.self::MIMES],
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
