<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Modules\Cartera\Actions\CrearFacturaManual;
use App\Modules\Cartera\Services\ImportadorFacturasVenta;
use App\Modules\Catalogo\Models\Impuesto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class FacturasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);  // A1/A3 FIX · unificado con sidebar (Contador/Gerente)
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        $filtro = (string) $request->input('filtro', 'todas'); // todas | vencidas | pagadas | pendientes
        $pagina = max(1, (int) $request->input('page', 1));

        /*
         * F2 · Una sola pantalla, con los dos ejes separados donde corresponde.
         *
         *   · PESTAÑAS = destino fiscal (todas | siigo | interna). Es la
         *     decisión que el contador toma a diario, así que va al frente.
         *   · FILTRO = línea de negocio (great_baby | dropi). Es el otro eje:
         *     si se cruzaran como pestañas harían falta seis.
         *
         * Antes las pestañas eran el ESTADO de la factura; eso bajó al filtro,
         * donde estorba menos.
         */
        $destino = (string) $request->input('destino', 'todas');
        $linea = (string) $request->input('linea', FiltroEjes::LINEA_ERP_POR_DEFECTO);

        $query = FacturaVenta::query()->with('contacto:id,nombre_completo,telefono');

        // Los dos ejes salen de `FiltroEjes`, que es el mismo filtro que usan el
        // libro y los pagos. Repetirlo aca era la forma de que un dia «internas»
        // significara algo distinto en cada pantalla.
        FiltroEjes::de($linea, $destino)->aplicar($query);

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('numero', 'like', "%{$q}%")
                    ->orWhereHas('contacto', fn ($c) => $c->where('nombre_completo', 'like', "%{$q}%")->orWhere('numero_documento', 'like', "%{$q}%"));
            });
        }

        $hoy = today('America/Bogota');
        match ($filtro) {
            'vencidas' => $query->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])->where('fecha_vencimiento', '<', $hoy),
            'pagadas' => $query->where('estado', EstadoFactura::Pagada),
            'pendientes' => $query->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada]),
            default => null,
        };

        $paginado = $query->orderByDesc('fecha_emision')->orderByDesc('id')->paginate(25);

        return Inertia::render('Cartera/Facturas/Index', [
            'facturas' => $paginado->through(fn ($f) => [
                'id' => $f->id,
                'numero' => $f->numero,
                'contacto' => $f->contacto?->nombre_completo ?? '—',
                'contacto_id' => $f->contacto_id,
                'telefono' => $f->contacto?->telefono,
                'fecha_emision' => $f->fecha_emision?->toDateString(),
                'fecha_vencimiento' => $f->fecha_vencimiento?->toDateString(),
                'total' => (float) $f->total,
                'saldo' => (float) $f->saldo,
                'estado' => $f->estado?->value ?? (string) $f->estado,
                'estado_label' => is_object($f->estado) && method_exists($f->estado, 'label') ? $f->estado->label() : (string) ($f->estado?->value ?? $f->estado),
                'estado_color' => is_object($f->estado) && method_exists($f->estado, 'color') ? $f->estado->color() : 'gray',
                'siigo_id' => $f->siigo_id,
                'destino_fiscal' => $f->destino_fiscal?->value ?? DestinoFiscal::Siigo->value,
                'destino_label' => ($f->destino_fiscal ?? DestinoFiscal::Siigo)->label(),
                'destino_color' => ($f->destino_fiscal ?? DestinoFiscal::Siigo)->color(),
                'linea_negocio' => $f->linea_negocio?->value ?? LineaNegocio::GreatBaby->value,
                'linea_label' => ($f->linea_negocio ?? LineaNegocio::GreatBaby)->label(),
                'dias_mora' => $f->fecha_vencimiento ? max(0, (int) $f->fecha_vencimiento->diffInDays($hoy, absolute: false)) : 0,
            ])->withQueryString(),
            'filtros' => ['q' => $q, 'filtro' => $filtro, 'destino' => $destino, 'linea' => $linea],
            'opciones' => [
                'destinos' => DestinoFiscal::opciones(),
                'lineas' => LineaNegocio::opciones(),
                // Para el formulario de factura manual. Salen de la tabla y no
                // hardcodeados: el IVA del 19 % no es una ley de la fisica.
                'ivas' => $this->ivasDisponibles(),
            ],
            'totales' => [
                'todas' => FacturaVenta::count(),
                'vencidas' => FacturaVenta::whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])->where('fecha_vencimiento', '<', $hoy)->count(),
                'pagadas' => FacturaVenta::where('estado', EstadoFactura::Pagada)->count(),
                'pendientes' => FacturaVenta::whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])->count(),
                // Contadores de las pestañas. No llevan el filtro de línea a
                // propósito: si al cambiar de pestaña el número bailara, nadie
                // entendería de dónde salen.
                'destino_todas' => FacturaVenta::count(),
                // Tambien por `FiltroEjes`: si el contador contara distinto que
                // la pestaña, el numero del boton no cuadraria con la tabla.
                'destino_siigo' => FiltroEjes::de(null, 'siigo')->aplicar(FacturaVenta::query())->count(),
                'destino_interna' => FiltroEjes::de(null, 'interna')->aplicar(FacturaVenta::query())->count(),
            ],
        ]);
    }

    public function show(FacturaVenta $factura): Response
    {
        $factura->load(['contacto', 'items.variante.producto', 'pagos.registrador']);

        return Inertia::render('Cartera/Facturas/Show', [
            'factura' => [
                'id' => $factura->id,
                'numero' => $factura->numero,
                'fecha_emision' => $factura->fecha_emision?->toDateString(),
                'fecha_vencimiento' => $factura->fecha_vencimiento?->toDateString(),
                'subtotal' => (float) $factura->subtotal,
                'descuento' => (float) $factura->descuento,
                'impuestos' => (float) $factura->impuestos,
                'total' => (float) $factura->total,
                'saldo' => (float) $factura->saldo,
                'estado' => $factura->estado?->value ?? (string) $factura->estado,
                'estado_label' => is_object($factura->estado) && method_exists($factura->estado, 'label') ? $factura->estado->label() : (string) $factura->estado,
                'estado_color' => is_object($factura->estado) && method_exists($factura->estado, 'color') ? $factura->estado->color() : 'gray',
                'ari_factura_id' => $factura->ari_factura_id,
                'siigo_id' => $factura->siigo_id,
                'numero_siigo' => $factura->numero_siigo,
                // Documento impreso que devuelve SIIGO al emitir. Sirve aunque
                // la DIAN todavía no haya timbrado, que es cuando el PDF DIAN
                // no existe y antes no quedaba nada que imprimir.
                'siigo_public_url' => $factura->siigo_public_url,
                'cufe' => $factura->cufe,
                'stamp_status' => $factura->stamp_status,
                'qr_url' => $factura->qr_url,
                'token_publico' => $factura->token_publico,
                'es_electronica' => (bool) $factura->es_electronica,
                'emitida_at' => $factura->emitida_at?->toIso8601String(),
            ],
            'contacto' => $factura->contacto ? [
                'id' => $factura->contacto->id,
                'nombre' => $factura->contacto->nombre_completo,
                'documento' => trim(($factura->contacto->tipo_documento ?? '') . ' ' . ($factura->contacto->numero_documento ?? '')),
                'telefono' => $factura->contacto->telefono,
                'email' => $factura->contacto->email,
                'ciudad' => $factura->contacto->ciudad,
                'direccion' => $factura->contacto->direccion,
            ] : null,
            'items' => $factura->items->map(fn ($it) => [
                'id' => $it->id,
                'descripcion' => $it->descripcion ?? ($it->variante?->producto?->nombre ?? '—'),
                'referencia' => $it->variante?->producto?->referencia,
                'codigo_barras' => $it->variante?->codigo_barras,
                'cantidad' => (float) $it->cantidad,
                // QA-D Bloque3: columnas reales del modelo son precio_unit / descuento_pct / impuesto_pct
                // (antes leía precio_unitario / iva_porcentaje inexistentes → siempre 0 en UI).
                'precio_unitario' => (float) $it->precio_unit,
                'descuento' => (float) ($it->descuento_pct ?? 0),
                'iva_porcentaje' => (float) ($it->impuesto_pct ?? 0),
                'subtotal' => (float) $it->subtotal,
            ]),
            'pagos' => $factura->pagos->map(fn ($p) => [
                'id' => $p->id,
                'fecha' => $p->fecha?->toDateString(),
                'medio_pago' => $p->medio_pago,
                'monto_aplicado' => (float) $p->monto_aplicado,
                'monto_recibido' => (float) $p->monto_recibido,
                'referencia' => $p->referencia,
                'banco' => $p->banco,
                'registrado_por' => $p->registrador?->name,
            ]),
        ]);
    }

    /**
     * F2 · Cambiar a dónde va una factura, o varias de una.
     *
     * Se hace desde el listado a propósito: el contador revisa el corte del día
     * de arriba abajo y va marcando. Obligarlo a entrar y salir de cada factura
     * para esto era garantizar que lo dejara para después.
     *
     * Una factura YA EMITIDA en SIIGO no se puede sacar de SIIGO cambiándole una
     * casilla acá: eso se deshace con una nota crédito, no borrando la decisión.
     */
    public function cambiarDestino(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:facturas_venta,id'],
            'destino' => ['required', 'string', 'in:'.implode(',', array_column(DestinoFiscal::cases(), 'value'))],
            // Por qué se saca de lo fiscal. Sin esto, dentro de tres meses nadie
            // sabe si fue una decisión o un error de dedo.
            'motivo' => ['nullable', 'string', 'max:300'],
            // Cuánto se declara. Sólo tiene sentido en reparto.
            'monto_fiscal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $destino = DestinoFiscal::from($data['destino']);
        $motivo = $data['motivo'] ?? null;
        $montoFiscal = isset($data['monto_fiscal']) ? (float) $data['monto_fiscal'] : null;

        if ($destino !== DestinoFiscal::Siigo && $motivo === null) {
            return back()->withErrors([
                'motivo' => 'Para sacar una factura de lo fiscal hay que escribir por qué.',
            ]);
        }

        /*
         * El reparto exige decir CUÁNTO se declara, y que sea una porción de
         * verdad. Si fuera 0 no es un reparto, es «interno»; si fuera el total
         * tampoco, es «fiscal». Dejar pasar cualquiera de los dos deja una
         * factura marcada reparto que no reparte nada, y el balance fiscal
         * saldría mal sin que nadie lo note.
         */
        if ($destino === DestinoFiscal::Reparto && ($montoFiscal === null || $montoFiscal <= 0)) {
            return back()->withErrors([
                'monto_fiscal' => 'En un reparto hay que decir cuánto se declara en SIIGO.',
            ]);
        }

        // El reparto es por factura: con varias seleccionadas no hay un monto
        // que valga para todas.
        if ($destino === DestinoFiscal::Reparto && count($data['ids']) > 1) {
            return back()->withErrors([
                'destino' => 'El reparto se define factura por factura, porque el monto declarado '
                    . 'es distinto en cada una. Seleccioná una sola.',
            ]);
        }

        $facturas = FacturaVenta::whereIn('id', $data['ids'])->get();

        $yaEnSiigo = $facturas->filter(fn ($f) => $f->siigo_id && $destino !== DestinoFiscal::Siigo);
        if ($yaEnSiigo->isNotEmpty()) {
            return back()->withErrors([
                'destino' => 'Estas facturas ya están emitidas en SIIGO y no se pueden pasar a interno: '
                    .$yaEnSiigo->pluck('numero')->implode(', ')
                    .'. Para revertirlas hay que hacerles nota crédito.',
            ]);
        }

        foreach ($facturas as $f) {
            // Lo declarado no puede pasarse del total de la factura: eso sería
            // declarar una venta que no existe.
            if ($destino === DestinoFiscal::Reparto && $montoFiscal >= (float) $f->total) {
                return back()->withErrors([
                    'monto_fiscal' => 'Lo declarado ($'.number_format($montoFiscal, 0, ',', '.').') no puede ser '
                        . 'igual ni mayor al total de la factura ($'.number_format((float) $f->total, 0, ',', '.').'). '
                        . 'Si se declara todo, el destino es «Fiscal».',
                ]);
            }

            $f->destino_fiscal = $destino;
            $f->monto_fiscal = $destino === DestinoFiscal::Reparto ? $montoFiscal : null;
            $f->motivo_destino = $destino === DestinoFiscal::Siigo ? null : $motivo;
            $f->save();

            // El asiento se rehace con el nuevo reparto: si no, el balance
            // fiscal sigue mostrando la proporción vieja.
            (new \App\Modules\Cartera\Actions\RegistrarAsientoContable())->factura($f->fresh());
        }

        return back()->with('success', $facturas->count() === 1
            ? "Factura {$facturas->first()->numero} → {$destino->label()}."
            : "{$facturas->count()} facturas → {$destino->label()}.");
    }

    /**
     * Los IVA que el ERP conoce, para el selector del formulario manual.
     *
     * Siempre ofrece el 0 %: hay ventas excluidas y servicios que no lo llevan,
     * y si no está en la lista la gente escribe 19 % «porque era el que había».
     */
    private function ivasDisponibles(): array
    {
        $deLaTabla = Impuesto::query()
            ->where('activo', true)
            ->where('tipo', 'iva')
            ->orderBy('porcentaje')
            ->pluck('porcentaje')
            ->map(fn ($p) => (float) $p)
            ->all();

        $pcts = array_values(array_unique(array_merge([0.0], $deLaTabla)));
        sort($pcts);

        return array_map(fn (float $p) => [
            'valor' => $p,
            'label' => rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',').' %',
        ], $pcts);
    }

    /**
     * F2b · Guardar una factura hecha a mano.
     *
     * La validación de forma vive acá y la de negocio en `CrearFacturaManual`,
     * que es la que también usa el Excel. Duplicar la de negocio acá sería
     * garantizar que algún día las dos puertas acepten cosas distintas.
     */
    public function guardarManual(Request $r): RedirectResponse
    {

        /*
         * La casilla de la pantalla de roles, cumplida.
         *
         * Hasta ahora «facturas» se manejaba sólo con «ver»: quien podía mirar
         * podía también emitir facturas a mano. Marcar o desmarcar «Crear» y «Editar» en
         * /app/roles no cambiaba nada, y eso hace creer que el candado está
         * puesto cuando no lo está.
         *
         * Se distingue por si la fila ya existe, que es lo que diferencia
         * crear de editar. Gerencia pasa siempre.
         */
        abort_unless(
            \App\Auth\Permisos::puedeAccion($r->user(), 'facturas', 'crear'),
            403,
            'No tenés permiso para emitir facturas.'
        );
        $data = $r->validate([
            'contacto_id' => ['required', 'integer', 'exists:contactos,id'],
            'fecha_emision' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'linea_negocio' => ['nullable', 'string', 'in:'.implode(',', array_column(LineaNegocio::cases(), 'value'))],
            'destino_fiscal' => ['nullable', 'string', 'in:'.implode(',', array_column(DestinoFiscal::cases(), 'value'))],
            'monto_fiscal' => ['nullable', 'numeric', 'min:0'],
            'motivo_destino' => ['nullable', 'string', 'max:300'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            // Obligatorio: la base lo exige por CHECK y SIIGO pide un codigo por linea.
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.descripcion' => ['required', 'string', 'max:255'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.precio_unit' => ['required', 'numeric', 'min:0'],
            'items.*.descuento_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.impuesto_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $factura = CrearFacturaManual::run($data, $r->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        // Si va a SIIGO y algún ítem no tiene con qué viajar, se avisa ACÁ y no
        // horas después en la campana, cuando ya nadie tiene la factura delante.
        $avisos = CrearFacturaManual::avisosDeSiigo($data['items'], $factura->destino_fiscal);

        $redir = redirect()->route('app.facturas.show', $factura->id)
            ->with('success', "Factura {$factura->numero} creada por $".number_format((float) $factura->total, 0, ',', '.').'.');

        return $avisos === [] ? $redir : $redir->with('warning', implode(' · ', $avisos));
    }

    /**
     * La plantilla del Excel de facturas, con filas de ejemplo.
     *
     * Es un CSV con `;` y BOM: así Excel en español lo abre en columnas de una,
     * sin el asistente de importación que nadie sabe usar.
     */
    public function plantillaExcel(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $columnas = [
            'factura', 'documento_cliente', 'fecha_emision', 'fecha_vencimiento',
            'referencia_producto', 'descripcion', 'cantidad', 'precio_unitario',
            'descuento_pct', 'iva_pct', 'destino', 'monto_fiscal', 'linea', 'motivo', 'observaciones',
        ];

        /*
         * Las dos primeras filas comparten la clave «factura»: es la forma de
         * mostrar que una factura con dos ítems son DOS filas, que es justo lo
         * que más se pregunta al armar el archivo. La tercera es un reparto.
         */
        $ejemplo = [
            ['1', '900123456', '01/10/2026', '31/10/2026', 'REF-001', 'Pañal etapa 2 x 40 und', '10', '45000', '0', '19', 'fiscal', '', 'great_baby', '', 'Venta de mostrador'],
            ['1', '900123456', '01/10/2026', '31/10/2026', '', 'Flete Bogotá', '1', '20000', '0', '0', 'fiscal', '', 'great_baby', '', ''],
            ['2', '900123456', '02/10/2026', '', '', 'Venta acuerdo comercial', '1', '100000000', '0', '0', 'reparto', '20000000', 'great_baby', 'Acuerdo comercial: se declara el 20 %', ''],
        ];

        return response()->streamDownload(function () use ($columnas, $ejemplo) {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $columnas, ';');
            foreach ($ejemplo as $fila) {
                fputcsv($out, $fila, ';');
            }
            fclose($out);
        }, 'plantilla-facturas-venta.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Lo que el archivo CREARÍA, sin crear nada.
     *
     * Devuelve JSON porque es una tabla larga que se pinta dentro del modal: si
     * viajara como prop de Inertia habría que recargar la pantalla entera para
     * ver una previsualización que todavía no cambió nada.
     */
    public function previsualizarExcel(Request $r): JsonResponse
    {
        $r->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:8192'],
        ]);

        $archivo = $r->file('archivo');

        return response()->json(
            // El nombre original importa: en disco el archivo es un `.tmp` y el
            // lector no podria saber si es CSV o XLSX.
            (new ImportadorFacturasVenta())->previsualizar(
                $archivo->getRealPath(),
                $archivo->getClientOriginalName(),
            )
        );
    }

    /**
     * Crear de verdad lo que el archivo trae.
     *
     * Se vuelve a leer el archivo en lugar de confiar en la previsualización que
     * el navegador tenga en memoria: si entre un paso y el otro alguien creó el
     * cliente que faltaba, lo correcto es importarla; y si alguien editó el
     * archivo, lo correcto es volver a validarlo.
     */
    public function importarExcel(Request $r): RedirectResponse
    {
        $r->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:8192'],
        ]);

        $archivo = $r->file('archivo');

        $resultado = (new ImportadorFacturasVenta())->importar(
            $archivo->getRealPath(),
            $r->user()?->id,
            $archivo->getClientOriginalName(),
        );

        if ($resultado['creadas'] === 0) {
            return back()->withErrors([
                'archivo' => 'No se creó ninguna factura. '
                    .($resultado['errores'][0] ?? 'Revisá los errores de la previsualización.'),
            ]);
        }

        $msg = $resultado['creadas'].' factura(s) creada(s): '
            .implode(', ', array_slice($resultado['numeros'], 0, 5))
            .(count($resultado['numeros']) > 5 ? '…' : '').'.';

        if ($resultado['omitidas'] > 0) {
            $msg .= ' Se omitieron '.$resultado['omitidas'].' con errores: corregí el archivo y subí sólo esas.';
        }

        return back()->with($resultado['omitidas'] > 0 ? 'warning' : 'success', $msg);
    }
}
