<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\ComparadorEmpaque;
use App\Modules\Compras\Services\EtiquetasDeOrden;
use App\Modules\Compras\Services\SugeridoPedido;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoReferencia;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Lo que Alejandro hace hoy en Excel: qué reforzar, el archivo que va a China con
 * sus etiquetas, y comparar lo pedido con lo que dice la lista de empaque.
 */
class HerramientasComprasController extends Controller
{
    public function sugerido(Request $r, SugeridoPedido $sugerido): Response
    {
        abort_unless(Permisos::puede($r->user(), 'compras_reportes'), 403, 'Esta parte de Compras necesita su propio acceso.');

        $dias = in_array((int) $r->query('dias'), [30, 60, 90, 180, 365], true) ? (int) $r->query('dias') : 90;
        $objetivo = max(15, min(365, (int) ($r->query('objetivo') ?: 120)));
        $q = trim((string) $r->query('q', ''));
        $filas = $sugerido->handle($dias, $objetivo, $q);

        return Inertia::render('Compras/Sugerido', [
            'filas' => $filas,
            'resumen' => $sugerido->resumen($filas),
            'filtros' => ['dias' => $dias, 'objetivo' => $objetivo, 'q' => $q],
        ]);
    }

    /** Lleva precios de proveedor. */
    public function excel(Request $r, OrdenCompra $orden, EtiquetasDeOrden $etiquetas): HttpResponse
    {
        abort_unless($this->veCostos($r), 403, 'Esta parte de Compras necesita su propio acceso.');

        $orden->load(['items.producto.media', 'items.variante', 'items.empresaDestino', 'proveedor']);
        $temporal = tempnam(sys_get_temp_dir(), 'oc');
        (new Xlsx($etiquetas->excel($orden)))->save($temporal);

        return response()->download($temporal, "Orden {$orden->numero}.xlsx")->deleteFileAfterSend();
    }

    /** Sin precios: es lo que usa bodega para pegar y revisar. */
    public function etiquetasPdf(Request $r, OrdenCompra $orden, EtiquetasDeOrden $etiquetas): HttpResponse
    {
        abort_unless($this->veOrdenes($r), 403, 'Esta parte de Compras necesita su propio acceso.');

        $orden->load(['items.producto.media', 'items.variante']);

        return Pdf::loadView('compras.pdf.etiquetas-orden', ['orden' => $orden, 'lineas' => $etiquetas->paraPdf($orden)])
            ->setPaper('letter', 'portrait')
            ->stream("Etiquetas {$orden->numero}.pdf");
    }

    public function subirEtiqueta(Request $r, Producto $producto): RedirectResponse
    {
        abort_unless($this->editaProducto($r), 403);
        $r->validate(['etiqueta' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);

        $producto->addMediaFromRequest('etiqueta')->toMediaCollection('etiqueta_importacion');

        return back()->with('success', 'Etiqueta guardada. Saldrá sola en las órdenes de este producto.');
    }

    public function quitarEtiqueta(Request $r, Producto $producto): RedirectResponse
    {
        abort_unless($this->editaProducto($r), 403);
        $producto->clearMediaCollection('etiqueta_importacion');

        return back()->with('success', 'Etiqueta quitada.');
    }

    public function agregarReferencia(Request $r, Producto $producto): RedirectResponse
    {
        abort_unless($this->editaProducto($r), 403);
        $data = $r->validate([
            'referencia' => ['required', 'string', 'max:100'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $producto->agregarReferencia($data['referencia'], $data['motivo'] ?? null, $r->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "«{$data['referencia']}» ahora también es este producto.");
    }

    public function quitarReferencia(Request $r, ProductoReferencia $referencia): RedirectResponse
    {
        abort_unless($this->editaProducto($r), 403);
        $referencia->delete();

        return back()->with('success', 'Referencia quitada.');
    }

    public function comparacion(Request $r, Importacion $importacion, ImportacionDocumento $documento, ComparadorEmpaque $comparador): Response|RedirectResponse
    {
        $this->exigirContenedor($r, $importacion, $documento);

        try {
            $resultado = $comparador->comparar($importacion, $documento);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return Inertia::render('Compras/Importacion/Comparacion', [
            'importacion' => ['id' => $importacion->id, 'numero' => $importacion->numero, 'contenedor' => $importacion->contenedor],
            'documento' => ['id' => $documento->id, 'nombre' => $documento->nombre, 'tipo' => ImportacionDocumento::TIPOS[$documento->tipo] ?? $documento->tipo, 'version' => $documento->version],
            ...$resultado,
        ]);
    }

    public function guardarMotivo(Request $r, Importacion $importacion, ComparadorEmpaque $comparador): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'compras_importacion'), 403);
        $data = $r->validate([
            'referencia' => ['required', 'string', 'max:100'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $comparador->guardarMotivo($importacion, $data['referencia'], $data['motivo'], $r->user()?->id);

        return back()->with('success', 'Motivo guardado.');
    }

    /** Todos los motivos escritos de una vez: una lista real trae decenas de diferencias. */
    public function guardarMotivos(Request $r, Importacion $importacion, ComparadorEmpaque $comparador): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'compras_importacion'), 403);
        $data = $r->validate([
            'motivos' => ['required', 'array', 'min:1', 'max:3000'],
            'motivos.*.referencia' => ['required', 'string', 'max:100'],
            'motivos.*.motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $importacion, $comparador, $r) {
            foreach ($data['motivos'] as $m) {
                $comparador->guardarMotivo($importacion, $m['referencia'], $m['motivo'], $r->user()?->id);
            }
        });

        return back()->with('success', count($data['motivos']).' motivo(s) guardados.');
    }

    public function vincularReferencia(Request $r, Importacion $importacion, ComparadorEmpaque $comparador): RedirectResponse
    {
        abort_unless(Permisos::puede($r->user(), 'compras_importacion'), 403);
        $data = $r->validate([
            'referencia' => ['required', 'string', 'max:100'],
            'orden_item_id' => ['required', 'integer'],
        ]);

        try {
            $comparador->vincular($importacion, $data['referencia'], (int) $data['orden_item_id'], $r->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "«{$data['referencia']}» quedó como el mismo producto.");
    }

    private function exigirContenedor(Request $r, Importacion $importacion, ImportacionDocumento $documento): void
    {
        abort_unless(Permisos::puede($r->user(), 'compras_importacion'), 403, 'Esta parte de Compras necesita su propio acceso.');
        abort_if($documento->importacion_id !== $importacion->id, 404);
    }

    private function veCostos(Request $r): bool
    {
        return Permisos::puede($r->user(), 'compras_oc') || Permisos::puede($r->user(), 'compras_pagos_exterior');
    }

    private function veOrdenes(Request $r): bool
    {
        return $this->veCostos($r) || Permisos::puede($r->user(), 'compras_importacion') || Permisos::puede($r->user(), 'compras_recepcion');
    }

    private function editaProducto(Request $r): bool
    {
        return Permisos::puede($r->user(), 'productos') || Permisos::puede($r->user(), 'compras_importacion');
    }
}
