<?php

namespace App\Http\Controllers\Dropi;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Filament\Pages\ManifiestosDropi;
use App\Modules\Dropi\Models\DropiManifiesto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * El PDF o el Excel de un manifiesto de Dropi guardado en el ERP. Van en
 * almacenamiento privado (traen datos de clientes): sólo quien ve «Manifiestos».
 */
class ManifiestoDropiArchivoController extends Controller
{
    public function __invoke(DropiManifiesto $manifiesto, string $tipo): Response
    {
        abort_unless(ManifiestosDropi::canAccess(), 403);
        $ruta = $tipo === 'pdf' ? $manifiesto->pdf_path : $manifiesto->excel_path;
        abort_unless($ruta && Storage::disk('local')->exists($ruta), 404, 'Este archivo no se pudo traer de Dropi todavía.');

        $nombre = sprintf('manifiesto-%s-%s-%d.%s',
            \Illuminate\Support\Str::slug((string) $manifiesto->transportadora) ?: 'dropi',
            $manifiesto->dropi_creado_at?->timezone('America/Bogota')->format('Ymd-Hi') ?? 'sin-fecha',
            $manifiesto->dropi_id,
            $tipo === 'pdf' ? 'pdf' : 'xlsx');

        return Storage::disk('local')->response($ruta, $nombre, [], $tipo === 'pdf' ? 'inline' : 'attachment');
    }
}
