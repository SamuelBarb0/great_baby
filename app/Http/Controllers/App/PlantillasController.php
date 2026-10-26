<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Plantillas\Models\PlantillaDocumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PlantillasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esAracely(), 403);
                return $next($r);
            }),
        ];
    }

    /** Lista + editor de la plantilla (por tipo). */
    public function index(Request $request): Response
    {
        $tipo = (string) $request->input('tipo', 'factura');
        $plantillas = PlantillaDocumento::where('tipo', $tipo)->orderByDesc('predeterminada')->orderByDesc('id')->get();
        // QA-final #2: id=0 significa "form vacío nuevo" (antes caía al fallback y mostraba la primera).
        // id positivo busca esa; ausente/null muestra la predeterminada.
        $editando = $request->input('id', null);
        if ($editando === '0' || $editando === 0) {
            $activa = null; // form nuevo vacío
        } else {
            $activa = $editando ? PlantillaDocumento::find((int) $editando) : ($plantillas->firstWhere('predeterminada', true) ?? $plantillas->first());
        }

        return Inertia::render('Plantillas/Editor', [
            'tipo' => $tipo,
            'plantillas' => $plantillas->map(fn ($p) => [
                'id' => $p->id, 'nombre' => $p->nombre, 'predeterminada' => $p->predeterminada, 'activa' => $p->activa,
            ]),
            'plantilla' => $activa ? [
                'id' => $activa->id,
                'nombre' => $activa->nombre,
                'predeterminada' => $activa->predeterminada,
                'activa' => $activa->activa,
                'config' => $activa->configEfectiva(),
            ] : [
                'id' => null,
                'nombre' => 'Plantilla predeterminada',
                'predeterminada' => true,
                'activa' => true,
                'config' => PlantillaDocumento::defaults(),
            ],
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        // QA-final #3: validación estricta de shape del config JSON.
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:plantillas_documento,id'],
            'tipo' => ['required', 'in:factura,oc,recepcion,nc,cotizacion,egreso'],
            'nombre' => ['required', 'string', 'max:100'],
            'predeterminada' => ['boolean'],
            'activa' => ['boolean'],
            'config' => ['required', 'array'],
            'config.colores' => ['required', 'array'],
            'config.colores.primario' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.colores.secundario' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.colores.texto' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.colores.acento' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.tipografia' => ['required', 'in:sans,serif,mono'],
            'config.layout' => ['required', 'in:espacioso,compacto'],
            // Un data URI de ~1 MB en base64 pesa ~1,4 M caracteres; 2048 dejaba
            // por fuera cualquier logo subido como archivo.
            'config.logo_url' => ['nullable', 'string', 'max:1400000'],
            'config.logo_alto_px' => ['required', 'integer', 'min:20', 'max:200'],
            'config.encabezado_extra' => ['nullable', 'string', 'max:1000'],
            'config.encabezado_alineacion' => ['required', 'in:izquierda,centrado,derecha'],
            'config.mostrar_qr' => ['boolean'],
            'config.mostrar_bloque_banco' => ['boolean'],
            'config.mostrar_bloque_retenciones' => ['boolean'],
            'config.mostrar_bloque_notas' => ['boolean'],
            'config.mostrar_totales_en_letras' => ['boolean'],
            'config.pie_html' => ['nullable', 'string', 'max:1000'],
            'config.terminos_condiciones' => ['nullable', 'string', 'max:2000'],
            'config.sello_texto' => ['nullable', 'string', 'max:50'],
            'config.watermark_activo' => ['boolean'],
            'config.mostrar_firma' => ['boolean'],
            'config.firma_url' => ['nullable', 'string', 'max:1400000'],
            'config.firma_alto_px' => ['nullable', 'integer', 'min:20', 'max:150'],
            'config.firma_nombre' => ['nullable', 'string', 'max:100'],
            'config.firma_cargo' => ['nullable', 'string', 'max:100'],
        ], [
            // Fix demo · mensajes en español para evitar keys raw ("validation.regex")
            //   filtrando al usuario. Laravel no las traduce por defecto para regex/in
            //   con paths anidados (config.colores.primario).
            'regex' => 'El campo :attribute tiene un formato inválido.',
            'config.colores.*.regex' => 'El color debe estar en formato hexadecimal de 6 dígitos (ej: #b45309).',
            'in' => 'El valor de :attribute no está permitido.',
            'required' => 'El campo :attribute es obligatorio.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'min' => 'El campo :attribute debe ser al menos :min.',
            'max' => 'El campo :attribute no puede exceder :max.',
        ]);

        // QA-final #4: whitelist scheme del logo — solo https:// o data:image/*
        foreach (['logo_url' => 'del logo', 'firma_url' => 'de la firma'] as $campo => $de) {
            if (! empty($data['config'][$campo]) && ! PlantillaDocumento::urlSegura($data['config'][$campo])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "config.{$campo}" => "La imagen {$de} debe ser un archivo subido o una URL https:// pública.",
                ]);
            }
        }

        // QA-final #5: transacción + cache invalidate para reasignar predeterminada.
        $p = DB::transaction(function () use ($data) {
            $p = $data['id']
                ? PlantillaDocumento::whereKey($data['id'])->lockForUpdate()->firstOrFail()
                : new PlantillaDocumento();

            $p->fill([
                'tipo' => $data['tipo'],
                'nombre' => $data['nombre'],
                'predeterminada' => (bool) ($data['predeterminada'] ?? false),
                'activa' => (bool) ($data['activa'] ?? true),
                'config' => $data['config'],
            ])->save();

            if ($p->predeterminada) {
                PlantillaDocumento::where('tipo', $p->tipo)
                    ->where('id', '!=', $p->id)
                    ->update(['predeterminada' => false]);
                // Forget cache manualmente porque Builder::update NO dispara observer.
                \Illuminate\Support\Facades\Cache::forget("plantilla.pred.{$p->tipo}");
            }
            return $p;
        });

        return redirect()->route('app.plantillas.index', ['tipo' => $p->tipo, 'id' => $p->id])
            ->with('success', 'Plantilla guardada.');
    }

    public function eliminar(int $plantilla): RedirectResponse
    {
        $p = PlantillaDocumento::findOrFail($plantilla);
        abort_if($p->predeterminada, 422, 'No se puede eliminar la plantilla predeterminada. Marca otra como predeterminada primero.');
        $tipo = $p->tipo;
        $p->delete();
        return redirect()->route('app.plantillas.index', ['tipo' => $tipo])->with('success', 'Plantilla eliminada.');
    }

    /** Preview PDF con la config del request (no persiste). Renderiza sobre la última factura demo. */
    public function preview(Request $request): HttpResponse
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:factura,oc,recepcion,nc,cotizacion,egreso'],
            'config' => ['required', 'array'],
        ]);

        // Merge con defaults para preview robusto
        $cfg = PlantillaDocumento::configParaPdf(array_replace_recursive(PlantillaDocumento::defaults(), $data['config']));

        // Por ahora sólo tipo=factura tiene renderer implementado. El resto usa la vista de factura como placeholder visual.
        $factura = FacturaVenta::with(['contacto', 'items.variante.producto', 'pagos'])
            ->latest('id')->first();
        if (! $factura) abort(422, 'No hay facturas para hacer preview. Crea al menos una primero.');

        $empresa = EmpresaConfig::current();

        return Pdf::loadView('cartera.pdf.factura-plantilla', [
            'factura' => $factura,
            'empresa' => $empresa,
            'qrSvg' => '', // Skip QR en preview para no invocar DIAN
            'publica' => false,
            'cfg' => $cfg,
        ])->setPaper('letter', 'portrait')
          ->stream('preview.pdf', ['Attachment' => false]);
    }
}
