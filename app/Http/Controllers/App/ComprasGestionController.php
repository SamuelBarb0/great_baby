<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\RecibirMercancia;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Services\LectorOrdenProveedor;
use App\Modules\Compras\Services\PreliquidarOrden;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FIL-A · CRUD detalle Compras (paridad Filament):
 *  - Orden de compra: crear, editar items, aprobar, anular
 *  - Recepción: preparar desde OC, confirmar
 *  - Importación: crear, agregar gastos, liquidar (prorratear costos)
 *  - Reporte compras
 */
class ComprasGestionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                /*
                 * Dos equipos trabajan sobre las compras y los dos tienen que
                 * entrar: bodega para RECIBIR mercancia y gestionar devoluciones
                 * al proveedor, y contabilidad porque las OC son de
                 * Aracely/Contador —lo decia el comentario de este mismo guard,
                 * pero el guard pedia solo bodega—.
                 *
                 * El sintoma: el Contador veia el listado de /app/compras
                 * (guardado por `esContable`), hacia clic en cualquier orden y
                 * recibia un 403. La puerta de la pantalla y la del detalle
                 * pedian cosas distintas.
                 *
                 * Quien ve que en el menu lo decide el menu, no esta puerta.
                 */
                $u = $r->user();
                /*
                 * La puerta del modulo. Cada pantalla de adentro exige despues
                 * su propia casilla.
                 *
                 * Se agrego `tieneAlguno`: la matriz le daba `compras_recepcion`
                 * al Alistador desde siempre —recibir mercancia es su tarea—, y
                 * esta puerta lo frenaba antes de llegar al metodo, porque pedia
                 * `bodega` y el alistador tiene `vista_alistador`. El menu se lo
                 * ofrecia y la pantalla se lo negaba. El propio comentario de
                 * `recepcionForm` ya describia el sintoma: «quien recibe la
                 * mercancia chocaba contra un 403 justo en su tarea principal».
                 */
                abort_unless(
                    $u?->esEquipoBodega() || $u?->esContable()
                        || \App\Auth\Permisos::tieneAlguno($u, [
                            'compras_oc', 'compras_recepcion',
                            'compras_importacion', 'compras_reportes',
                        ]),
                    403
                );
                return $next($r);
            }),
        ];
    }

    // ================== ORDEN DE COMPRA ==================
    public function ocShow(Request $r, int $orden): Response
    {
        $o = OrdenCompra::with(['proveedor:id,nombre_completo,razon_social', 'items'])->findOrFail($orden);

        // COMP-B6 · Semáforo SIIGO de la OC = agregación del estado SIIGO de sus
        // recepciones. Verde (todas con siigo_id), amarillo (al menos una pendiente),
        // rojo (ninguna sincronizada), gris (sin recepciones todavía).
        $recepciones = \App\Modules\Compras\Models\RecepcionCompra::where('orden_id', $o->id)
            ->where('estado', 'confirmada')
            ->get(['id', 'numero', 'estado', 'siigo_id', 'siigo_number', 'siigo_sync_at', 'fecha_recepcion']);
        $totRec = $recepciones->count();
        $sincronizadas = $recepciones->whereNotNull('siigo_id')->count();
        $siigoEstado = match (true) {
            $totRec === 0 => 'sin_recepciones',
            $sincronizadas === $totRec => 'ok',
            $sincronizadas === 0 => 'pendiente',
            default => 'parcial',
        };

        $guia = app(\App\Modules\Compras\Services\PasoDeCompra::class);
        $contenedor = $o->esImportacion() ? $this->contenedorDe($o) : null;

        return Inertia::render('Compras/OC/Show', [
            'paso' => $guia->deOrden($o, $r->user()),
            'contenedor' => $contenedor ? ['id' => $contenedor->id, 'nombre' => $contenedor->contenedor ?: $contenedor->numero] : null,
            // Sólo los que pueden recibirla: un contenedor lleva una sola moneda.
            'contenedores_abiertos' => $o->esImportacion() && ! $contenedor
                ? Importacion::whereNotIn('estado', [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada])
                    ->where(fn ($q) => $q->where('moneda_origen', $o->moneda)->orWhereDoesntHave('ordenes'))
                    ->orderByDesc('id')->limit(50)->get(['id', 'numero', 'contenedor'])
                    ->map(fn ($i) => ['id' => $i->id, 'nombre' => $i->contenedor ? "{$i->contenedor} ({$i->numero})" : $i->numero])
                    ->all()
                : [],
            'puede_etiqueta' => \App\Auth\Permisos::puede($r->user(), 'productos') || \App\Auth\Permisos::puede($r->user(), 'compras_importacion'),
            'lineas_etiqueta' => $o->esImportacion()
                ? $o->items->loadMissing('producto.media')->filter(fn ($i) => $i->producto)->unique('producto_id')
                    ->map(fn ($i) => [
                        'producto_id' => $i->producto_id,
                        'nombre' => $i->descripcion,
                        'tiene_etiqueta' => (bool) $i->producto->getFirstMedia('etiqueta_importacion'),
                    ])->values()->all()
                : [],
            /*
             * El impreso de la OC es sólo de Aracely/Gerencia (lleva costos
             * de proveedor). El enlace salía igual para todos: el
             * Contador, el Gerente y el admin de bodega entran
             * legítimamente a la OC, ven «PDF» y reciben 403.
             */
            'puede_pdf' => \App\Auth\Permisos::tieneAlguno($r->user(), ['compras_oc', 'compras_importacion']),
            // Mismo motivo que `puede_pdf`: el botón «Aprobar» salía para
            // todo el que entrara a la OC, y aprobar compromete plata.
            'puede_aprobar' => (\App\Auth\Permisos::puede($r->user(), 'compras_oc')
                || ($r->user()?->esAracely() ?? false))
                && ! \App\Modules\Compras\Actions\AprobarOrdenCompra::laCreoQuienAprueba($o, $r->user()),
            'puede_excel' => \App\Auth\Permisos::puede($r->user(), 'compras_oc') || \App\Auth\Permisos::puede($r->user(), 'compras_pagos_exterior'),
            'etiquetas' => $o->esImportacion()
                ? app(\App\Modules\Compras\Services\EtiquetasDeOrden::class)->resumen($o->loadMissing('items.producto.media'))
                : null,
            'lineas_sin_producto' => $o->esImportacion() ? $o->items
                ->filter(fn ($i) => ! $i->producto_id && ! $i->variante_id && ! $i->esComponente())
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'descripcion' => $i->descripcion,
                    'referencia' => \App\Modules\Compras\Actions\CrearProductosDeOrden::referenciaDe($i),
                ])->values()->all() : [],
            'orden' => [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo,
                'proveedor_id' => $o->proveedor_id,
                'estado' => is_object($o->estado) ? $o->estado->value : $o->estado,
                'tipo' => $o->tipo,
                'moneda' => $o->moneda,
                'tasa_cambio' => (float) $o->tasa_cambio,
                'fecha_emision' => $o->fecha_emision?->toDateString(),
                'fecha_esperada' => $o->fecha_esperada?->toDateString(),
                'subtotal' => (float) $o->subtotal,
                'iva' => (float) $o->iva,
                'total' => (float) $o->total,
                'descuento' => (float) $o->descuento,
                'observaciones' => $o->observaciones,
                // Si la compra se declara o no. La pantalla lo necesita para no
                // ofrecer «Enviar a SIIGO» en una compra que decidieron no
                // declarar: el boton existia y el envio reventaba despues.
                'destino' => ($o->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->value,
                'destino_label' => ($o->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->label(),
                'destino_color' => ($o->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->color(),
                'motivo_destino' => $o->motivo_destino,
                'linea_label' => ($o->linea_negocio ?? \App\Modules\Contabilidad\Enums\LineaNegocio::GreatBaby)->label(),
                'va_a_siigo' => ($o->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->tieneParteFiscal(),
                'items' => $o->items->map(fn ($i) => [
                    'id' => $i->id, 'descripcion' => $i->descripcion,
                    'cantidad' => (float) $i->cantidad, 'cantidad_recibida' => (float) $i->cantidad_recibida,
                    'precio_unit' => (float) $i->precio_unit, 'iva_pct' => (float) $i->iva_pct,
                    'descuento_pct' => (float) $i->descuento_pct,
                    'subtotal' => (float) $i->subtotal, 'total' => (float) $i->total,
                    'cajas' => $i->cajas, 'unidades_por_caja' => $i->unidades_por_caja,
                    'cbm_caja' => $i->cbm_caja !== null ? (float) $i->cbm_caja : null,
                    'empresa_destino_id' => $i->empresa_destino_id,
                ])->all(),
                'preliquidacion' => $o->esImportacion() ? app(PreliquidarOrden::class)->handle($o) : null,
                'siigo' => [
                    'estado' => $siigoEstado,
                    'total' => $totRec,
                    'sincronizadas' => $sincronizadas,
                    'pendientes' => $totRec - $sincronizadas,
                    'recepciones' => $recepciones->map(fn ($r) => [
                        'id' => $r->id,
                        'numero' => $r->numero,
                        'fecha' => $r->fecha_recepcion?->toDateString(),
                        'siigo_id' => $r->siigo_id,
                        'siigo_number' => $r->siigo_number,
                        'siigo_sync_at' => $r->siigo_sync_at?->toIso8601String(),
                    ])->all(),
                ],
            ],
        ]);
    }

    public function ocForm(): Response
    {
        /*
         * La puerta de clase se ensancho para que el Alistador llegara a
         * recepcion. Las pantallas de la OC conservan su publico de
         * siempre: bodega y contabilidad arman la orden, y `ocAprobar`
         * exige ademas la casilla `compras_oc` para firmarla.
         */
        abort_unless(request()->user()?->puedeArmarOrdenes(), 403,
            'Las ordenes de compra las arma Compras, bodega o contabilidad.');

        return Inertia::render('Compras/OC/Nueva', [
            // COMP-B10 · incluir numero_documento para búsqueda por NIT en el autocomplete.
            'proveedores' => \App\Models\Contacto::where('es_proveedor', true)->where('activo', true)
                ->orderBy('nombre_completo')->limit(500)->get(['id', 'nombre_completo', 'razon_social', 'numero_documento'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'nombre' => $c->razon_social ?: $c->nombre_completo,
                    'numero_documento' => $c->numero_documento,
                ]),
            // Re-audit M2 UX-A4 · bodegas destino para elección explícita en OC.
            'bodegas' => \App\Modules\Dropi\Models\InventarioUbicacion::orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre]),
            // Importación: con qué parámetros y tasa nace la pre-liquidación, y
            // para quién va cada línea (Great Baby o el e-commerce de Aracely).
            'parametrosIniciales' => PreliquidarOrden::parametrosIniciales(),
            'modelosCompra' => [
                PreliquidarOrden::TRADING => PreliquidarOrden::delModelo(PreliquidarOrden::TRADING),
                PreliquidarOrden::FABRICA => PreliquidarOrden::delModelo(PreliquidarOrden::FABRICA),
            ],
            'tasasIniciales' => PreliquidarOrden::tasasIniciales(),
            'empresasDestino' => self::empresasDestino(),
        ]);
    }

    /** Sólo las dos de la reunión del 21-oct: Great Baby y E-commerce. */
    private static function empresasDestino(): array
    {
        return \App\Models\EmpresaConfig::query()
            ->whereIn('codigo', ['great_baby', 'ecommerce'])
            ->orderByDesc('es_principal')
            ->get(['id', 'codigo', 'nombre_comercial', 'razon_social'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'nombre' => $e->codigo === 'ecommerce' ? 'E-commerce' : ($e->nombre_comercial ?: $e->razon_social),
            ])->all();
    }

    /**
     * Lee el Excel de pedido del proveedor y devuelve las líneas para que el
     * formulario las muestre. No crea nada: la OC se guarda con «Crear OC».
     */
    public function proveedorCrear(Request $r): JsonResponse
    {
        abort_unless($r->user()?->puedeArmarOrdenes(), 403,
            'Los proveedores de una orden los crea Compras, bodega o contabilidad.');

        $datos = $r->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'tipo_documento' => ['required', 'in:NIT,CC,CE,PP'],
            'numero_documento' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ], [
            'nombre.required' => 'Escriba el nombre del proveedor.',
            'numero_documento.required' => 'Escriba el número de documento. Si es del exterior, el de su registro o su factura.',
            'email.email' => 'Ese correo no parece válido.',
        ]);
        // Un documento repetido es el mismo contacto: puede ser ya cliente, y se marca
        // también proveedor. Sólo se devuelve su nombre y documento: quien compra no
        // tiene por qué ver los datos de un cliente.
        $r = \App\Modules\Compras\Actions\RegistrarProveedor::run($datos);

        return response()->json(
            ['proveedor' => $this->proveedorParaLaOrden($r['contacto']), 'ya_existia' => $r['ya_existia']],
            $r['ya_existia'] ? 200 : 201,
        );
    }

    /** La misma forma que la lista de proveedores de la orden nueva. */
    private function proveedorParaLaOrden(\App\Models\Contacto $c): array
    {
        return ['id' => $c->id, 'nombre' => $c->razon_social ?: $c->nombre_completo, 'numero_documento' => $c->numero_documento];
    }

    public function ocLeerExcel(Request $r, LectorOrdenProveedor $lector): JsonResponse
    {
        $r->validate([
            // Sus Excel traen fotos: el del 24-oct pesa 13 MB.
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:51200'],
        ]);

        try {
            return response()->json($lector->leer($r->file('archivo')->getRealPath()));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Cambia los parámetros de la pre-liquidación mientras la OC es borrador. */
    public function ocParametros(Request $r, int $orden): RedirectResponse
    {
        $o = OrdenCompra::findOrFail($orden);
        abort_unless($o->esImportacion(), 422, 'Sólo las OC de importación se pre-liquidan.');
        abort_unless($o->estado === EstadoOrdenCompra::Borrador, 422, 'La OC ya no es borrador: los parámetros quedaron fijos.');

        $data = $r->validate(self::reglasParametros() + [
            'tasa_cambio' => ['required', 'numeric', 'gt:0'],
        ]);

        $o->tasa_cambio = $data['tasa_cambio'];
        $o->parametros_preliquidacion = PreliquidarOrden::normalizar($data);
        $o->save();

        return back()->with('success', 'Pre-liquidación recalculada.');
    }

    public function calculadora(): Response
    {
        return Inertia::render('Compras/Calculadora', [
            'parametros' => PreliquidarOrden::parametrosIniciales(),
            'tasa' => PreliquidarOrden::tasaInicial('CNY') ?? 520,
        ]);
    }

    /** Costo puesto en Colombia de UN producto, con la misma fórmula de la OC. */
    public function calcular(Request $r): JsonResponse
    {
        $data = $r->validate(self::reglasParametros() + [
            'tasa_cambio' => ['required', 'numeric', 'gt:0'],
            'precio' => ['required', 'numeric', 'min:0'],
            'unidades_por_caja' => ['required', 'integer', 'min:1'],
            'cbm_caja' => ['nullable', 'numeric', 'gt:0', 'required_without_all:caja_largo_cm,caja_ancho_cm,caja_alto_cm'],
            'caja_largo_cm' => ['nullable', 'numeric', 'gt:0', 'required_without:cbm_caja'],
            'caja_ancho_cm' => ['nullable', 'numeric', 'gt:0', 'required_without:cbm_caja'],
            'caja_alto_cm' => ['nullable', 'numeric', 'gt:0', 'required_without:cbm_caja'],
        ]);

        // Igual que en la OC: el CBM escrito manda; si no hay, sale de las medidas.
        $cbm = ! empty($data['cbm_caja'])
            ? (float) $data['cbm_caja']
            : $data['caja_largo_cm'] * $data['caja_ancho_cm'] * $data['caja_alto_cm'] / 1_000_000;

        $unidad = app(PreliquidarOrden::class)->porUnidad(
            PreliquidarOrden::normalizar($data), (float) $data['tasa_cambio'],
            (float) $data['precio'], (int) $data['unidades_por_caja'], $cbm,
        );

        return response()->json([
            'cbm_caja' => round($cbm, 6),
            'unidad' => array_map(fn ($v) => round($v, 2), $unidad),
        ]);
    }

    /** @return array<string,array<int,string>> */
    private static function reglasParametros(): array
    {
        return [
            'comision_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'flete_m3' => ['required', 'numeric', 'min:0'],
            'gasto_colombia_m3' => ['required', 'numeric', 'min:0'],
            'arancel_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'iva_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'divisor_precio_1' => ['required', 'numeric', 'gt:0', 'max:1'],
            'divisor_precio_2' => ['required', 'numeric', 'gt:0', 'max:1'],
            'capacidad_m3' => ['required', 'numeric', 'min:0'],
            'valor_minimo' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function ocCrear(Request $r): RedirectResponse
    {
        /*
         * La puerta de clase se ensancho para que el Alistador llegara a
         * recepcion. Las pantallas de la OC conservan su publico de
         * siempre: bodega y contabilidad arman la orden, y `ocAprobar`
         * exige ademas la casilla `compras_oc` para firmarla.
         */
        abort_unless($r->user()?->puedeArmarOrdenes(), 403,
            'Las ordenes de compra las arma Compras, bodega o contabilidad.');

        $data = $r->validate([
            'proveedor_id' => ['required', 'integer', 'exists:contactos,id'],
            // Re-audit M2 UX-A4 · bodega ahora obligatoria al crear (antes silenciosa).
            'bodega_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'tipo' => ['required', 'string', 'max:30'],
            'modelo_compra' => ['nullable', 'string', 'in:trading,fabrica'],
            'moneda' => ['required', 'string', 'size:3'],
            'tasa_cambio' => ['required', 'numeric', 'min:0'],
            'fecha_esperada' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descripcion' => ['required', 'string', 'max:200'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.001'],
            'items.*.precio_unit' => ['required', 'numeric', 'min:0'],
            'items.*.iva_pct' => ['nullable', 'numeric'],
            'items.*.descuento_pct' => ['nullable', 'numeric'],
            // Se validaban como enteros sueltos: un id inventado pasaba y
            // dejaba la línea apuntando a un producto que no existe.
            'items.*.producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'items.*.variante_id' => ['nullable', 'integer', 'exists:producto_variantes,id'],
            // Importación por cajas (Excel de Alejandro).
            'items.*.cajas' => ['nullable', 'integer', 'min:1'],
            'items.*.unidades_por_caja' => ['nullable', 'integer', 'min:1', 'required_with:items.*.cajas'],
            'items.*.caja_largo_cm' => ['nullable', 'numeric', 'gt:0'],
            'items.*.caja_ancho_cm' => ['nullable', 'numeric', 'gt:0'],
            'items.*.caja_alto_cm' => ['nullable', 'numeric', 'gt:0'],
            'items.*.cbm_caja' => ['nullable', 'numeric', 'gt:0'],
            'items.*.peso_caja_kg' => ['nullable', 'numeric', 'gt:0'],
            'items.*.empresa_destino_id' => ['nullable', 'integer', 'exists:empresa_config,id'],
            'items.*.tipo_linea' => ['nullable', 'string', 'in:producto,componente'],
            'items.*.color_nombre' => ['nullable', 'string', 'max:60'],
            'parametros_preliquidacion' => ['nullable', 'array'],
        ]);
        if ($data['tipo'] === 'importacion' && isset($data['parametros_preliquidacion'])) {
            validator($data['parametros_preliquidacion'], self::reglasParametros())->validate();
        }
        $oc = CrearOrdenCompra::run($data);
        return redirect()->route('app.compras.oc.show', $oc->id)->with('success', "OC {$oc->numero} creada.");
    }

    public function ocAprobar(Request $r, int $orden): RedirectResponse
    {
        /*
         * Aprobar una OC compromete plata con un proveedor.
         *
         * La puerta de clase es `esEquipoBodega() || esContable()`, que está
         * bien para VER compras (bodega recibe mercancía y necesita el
         * listado), pero dejaba que un admin de bodega aprobara la orden. La
         * Action sí tiene segregación de funciones, sólo que impide que la
         * apruebe QUIEN LA CREÓ: si la creaba Aracely, Jorge la aprobaba.
         *
         * Y la llave correcta ya estaba declarada en la matriz desde el
         * principio —`compras_oc => ['Gerente']`— sin que ningún control la
         * consultara. Es una de las 23 casillas de /app/roles que no
         * gobernaban nada; ésta ya gobierna.
         */
        abort_unless(
            \App\Auth\Permisos::puede($r->user(), 'compras_oc') || $r->user()?->esAracely(),
            403,
            'Aprobar una orden de compra es de Gerencia.'
        );

        $o = OrdenCompra::findOrFail($orden);
        AprobarOrdenCompra::run($o);
        return back()->with('success', "OC {$o->numero} aprobada.");
    }

    /**
     * Sprint 3 · A.1 · Duplica una OC (crea otra en borrador con los mismos ítems).
     * Útil cuando Aracely quiere reordenar al mismo proveedor casi lo mismo.
     */
    public function ocDuplicar(int $orden): RedirectResponse
    {
        /*
         * La puerta de clase se ensancho para que el Alistador llegara a
         * recepcion. Las pantallas de la OC conservan su publico de
         * siempre: bodega y contabilidad arman la orden, y `ocAprobar`
         * exige ademas la casilla `compras_oc` para firmarla.
         */
        abort_unless(request()->user()?->puedeArmarOrdenes(), 403,
            'Las ordenes de compra las arma Compras, bodega o contabilidad.');

        $o = OrdenCompra::with('items')->findOrFail($orden);
        // Conserva la línea de negocio: una OC de Dropi duplicada sigue siendo de Dropi.
        $nueva = \App\Modules\Compras\Actions\DuplicarOrdenCompra::run($o);
        return redirect()->route('app.compras.oc.show', $nueva->id)
            ->with('flash', ['type' => 'success', 'message' => "OC {$nueva->numero} duplicada desde {$o->numero}."]);
    }

    /**
     * Sprint 3 · A.1 D.1 · Reenvía la última recepción confirmada de la OC a SIIGO.
     * Bypasea kill-switch (es acción manual explícita).
     */
    public function ocReenviarSiigo(int $orden): RedirectResponse
    {
        $o = OrdenCompra::with(['recepciones' => fn ($q) => $q->where('estado', 'confirmada')->orderByDesc('id')])
            ->findOrFail($orden);
        $rc = $o->recepciones->first();
        if (! $rc) {
            return back()->with('flash', ['type' => 'error', 'message' => 'La OC no tiene recepciones confirmadas para reenviar.']);
        }
        \App\Modules\Siigo\Jobs\PushRecepcionASiigo::dispatchManual($rc->id);
        return back()->with('flash', [
            'type' => 'success',
            'message' => "Recepción {$rc->numero} encolada · se envía a SIIGO en breve.",
        ]);
    }

    public function ocAnular(Request $r, int $orden): RedirectResponse
    {
        $data = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:300']]);
        $o = \App\Modules\Compras\Actions\AnularOrdenCompra::run(OrdenCompra::findOrFail($orden), $data['motivo']);
        return back()->with('success', "OC {$o->numero} anulada.");
    }

    // ================== RECEPCIÓN ==================
    public function recepcionShow(int $recepcion): Response
    {
        $rc = RecepcionCompra::with([
            'orden.items', 'items.ubicacion', 'items.producto:id,nombre,referencia',
            'items.ordenItem:id,descripcion', 'bodega',
        ])->findOrFail($recepcion);
        $contenedor = $rc->orden ? $this->contenedorDe($rc->orden) : null;

        /*
         * Si el envío a SIIGO murió, esta pantalla tiene que decirlo.
         *
         * Antes era binaria: o había `siigo_id` y decía «Sincronizada», o
         * decía «Pendiente de SIIGO · se enviará automáticamente». Un job que
         * agotó sus reintentos caía en el segundo caso, así que una recepción
         * MUERTA se veía idéntica a una recién creada y la persona esperaba
         * algo que ya no iba a pasar. El error estaba escrito, y bien escrito
         * —«El producto X todavía no está en SIIGO: sincronizalo antes de
         * facturarlo»—, pero en el log de SIIGO, que no es donde se está
         * mirando. Se busca sólo cuando no hay `siigo_id`: con documento
         * arriba, lo viejo ya no importa.
         */
        $fallosSiigo = $rc->siigo_id ? collect() : \App\Modules\Siigo\Models\SiigoSyncLog::query()
            ->where('recurso', 'compras')
            ->where('estado', 'fallido')
            ->where('detalle->recepcion_id', $rc->id)
            ->latest('id')
            ->get(['id', 'mensaje', 'created_at']);

        $ultimoFallo = $fallosSiigo->first();

        /*
         * Los nombres de las bodegas que tocan estas líneas, de una sola
         * consulta. Se resuelven acá y no dentro del map para no pedirle a la
         * base una fila por renglón sólo para pintar un nombre.
         */
        $nombresBodega = \App\Modules\Dropi\Models\InventarioUbicacion::query()
            ->whereIn('id', $rc->items
                ->map(fn ($i) => $i->bodegaDondeQuedo())
                ->merge($rc->items->pluck('ubicacion_id'))
                ->push($rc->bodega_id)
                ->filter()->unique()->values()->all())
            ->pluck('nombre', 'id');

        $nombreDeBodega = fn (?int $id) => $id ? ($nombresBodega[$id] ?? null) : null;

        return Inertia::render('Compras/Recepcion/Show', [
            'paso' => $rc->orden ? app(\App\Modules\Compras\Services\PasoDeCompra::class)->deOrden($rc->orden, auth()->user()) : null,
            'contenedor' => $contenedor ? ['id' => $contenedor->id, 'nombre' => $contenedor->contenedor ?: $contenedor->numero] : null,
            'recepcion' => [
                'id' => $rc->id, 'numero' => $rc->numero,
                'orden_id' => $rc->orden?->id,
                'orden_numero' => $rc->orden?->numero,
                'estado' => $rc->estado,
                'fecha_recepcion' => $rc->fecha_recepcion?->format('Y-m-d H:i'),
                'remision_proveedor' => $rc->remision_proveedor,
                'factura_proveedor' => $rc->factura_proveedor,
                'transportista' => $rc->transportista,
                'observaciones' => $rc->observaciones,
                'total_recibido' => (float) $rc->total_recibido,
                // Sprint 3 · D.1 · exponer estado SIIGO al Vue Show.
                'siigo_id' => $rc->siigo_id,
                'siigo_number' => $rc->siigo_number,
                'siigo_sync_hace' => $rc->siigo_sync_at?->diffForHumans(),
                // El motivo en limpio: se le quita el prefijo técnico
                // «[job-failed] recepcion=N · », que no le dice nada a nadie.
                'siigo_fallo' => $ultimoFallo ? [
                    'motivo' => trim(preg_replace('/^\[[^\]]*\]\s*recepcion=\d+\s*·?\s*/u', '', (string) $ultimoFallo->mensaje)),
                    'hace' => $ultimoFallo->created_at?->diffForHumans(),
                    'intentos' => $fallosSiigo->count(),
                ] : null,
                'items' => $rc->items->map(fn ($i) => [
                    'id' => $i->id,
                    /*
                     * De qué producto es el renglón.
                     *
                     * Esta es la pantalla con la que se audita el reparto —«de
                     * estas 50, 30 a Great Baby y 20 a Dropi»— y no decía de
                     * qué era cada línea: nueve columnas de cantidades, costos
                     * y bodegas, y ninguna con el nombre. Con una recepción de
                     * veinte renglones no se puede revisar nada.
                     */
                    'producto' => $i->producto?->nombre
                        ?: ($i->ordenItem?->descripcion ?: '—'),
                    'referencia' => $i->producto?->referencia,
                    'cantidad_recibida' => (float) $i->cantidad_recibida,
                    'costo_unit' => (float) $i->costo_unit,
                    'subtotal' => (float) $i->subtotal,
                    'lote' => $i->lote,
                    'observaciones' => $i->observaciones,
                    // LOG-J8 · destino al descargar (apto/averia/cuarentena/revision/faltante) + motivo.
                    'clasificacion' => $i->clasificacion ?? 'apto',
                    'motivo_clasificacion' => $i->motivo_clasificacion,
                    'color_nombre' => $i->color_nombre,
                    /*
                     * Dónde QUEDÓ esta parte de la línea, no a dónde la
                     * mandaron. No es lo mismo: lo que no llegó apto entra a
                     * averías —no al destino elegido— y lo faltante no entra a
                     * ninguna parte. Esta columna se imprime en el PDF que
                     * alguien firma, así que decía que unas cajas estaban en la
                     * principal cuando estaban en averías.
                     */
                    'bodega_destino' => $nombreDeBodega($i->bodegaDondeQuedo())
                        ?: (($i->clasificacion ?: 'apto') === 'faltante' ? 'no llegó' : '—'),
                    'bodega_elegida' => $i->ubicacion_id !== null
                        && ($i->clasificacion ?: 'apto') === 'apto',
                    // Para que la pantalla pueda explicar por qué el destino
                    // que eligieron no es el que salió.
                    'destino_pedido' => $i->ubicacion_id !== null
                        && ($i->clasificacion ?: 'apto') !== 'apto'
                        ? $nombreDeBodega($i->ubicacion_id)
                        : null,
                    /*
                     * El saldo sin identificar es de la variante EN SU BODEGA.
                     *
                     * Antes se buscaba una sola fila por variante y se medía
                     * contra el encabezado. Repartida la misma variante entre
                     * dos bodegas, la segunda nunca ofrecía su formulario y
                     * esas unidades se quedaban sin color para siempre.
                     */
                    'sin_identificar' => ($bodegaReal = $i->bodegaDondeQuedo()) !== null
                        && $rc->items
                            ->first(fn ($o) => $o->variante_id === $i->variante_id
                                && $o->bodegaDondeQuedo() === $bodegaReal)?->id === $i->id
                        ? app(\App\Modules\Compras\Services\ColoresDeImportacion::class)
                            ->saldoSinIdentificar($i, $bodegaReal)
                        : 0.0,
                ])->all(),
            ],
        ]);
    }

    /** Lo que llegó revuelto pasa a su color sin rehacer la recepción. */
    public function recepcionReclasificar(Request $r, RecepcionCompra $recepcion): RedirectResponse
    {
        abort_unless(\App\Auth\Permisos::puede($r->user(), 'compras_recepcion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        $data = $r->validate([
            'item_id' => ['required', 'integer'],
            'color' => ['required', 'string', 'max:60'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
        ]);
        $fila = $recepcion->items()->whereKey($data['item_id'])->firstOrFail();

        try {
            $destino = app(\App\Modules\Compras\Services\ColoresDeImportacion::class)
                ->reclasificar($fila, $data['color'], (float) $data['cantidad'], $r->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reclasificar' => $e->getMessage()]);
        }

        return back()->with('success', "Pasaron {$data['cantidad']} a {$destino->color_nombre}.");
    }

    /**
     * Sprint 3 · D.1 · reenvía la recepción a SIIGO desde la vista de detalle.
     */
    public function recepcionReenviarSiigo(int $recepcion): RedirectResponse
    {
        $rc = RecepcionCompra::findOrFail($recepcion);
        if ($rc->estado !== 'confirmada') {
            return back()->with('flash', ['type' => 'error', 'message' => 'Solo se pueden reenviar recepciones confirmadas.']);
        }
        \App\Modules\Siigo\Jobs\PushRecepcionASiigo::dispatchManual($rc->id);
        return back()->with('flash', [
            'type' => 'success',
            'message' => "Recepción {$rc->numero} encolada a SIIGO.",
        ]);
    }

    public function recepcionDesdeOc(int $orden): RedirectResponse
    {
        // Redirige a form-preparar-recepción con items pre-llenados desde OC.
        return redirect()->route('app.compras.recepcion.nueva', ['oc' => $orden]);
    }

    public function recepcionForm(Request $request): Response
    {
        // Casilla: Recepcion de mercancia.
        abort_unless(\App\Auth\Permisos::puede($request->user(), 'compras_recepcion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        $ocId = (int) $request->input('oc', 0);
        $oc = $ocId ? OrdenCompra::with(['items', 'proveedor:id,nombre_completo,razon_social'])->find($ocId) : null;

        // Sin OC elegida, la pantalla mandaba a /app/compras a buscarla — y esa
        // pantalla exige perfil contable, así que quien recibe la mercancía
        // chocaba contra un 403 justo en su tarea principal. Le damos acá mismo
        // las órdenes que puede recibir.
        $ocsPendientes = $oc ? [] : OrdenCompra::query()
            ->whereIn('estado', ['aprobada', 'parcial'])
            ->with('proveedor:id,nombre_completo,razon_social')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (OrdenCompra $o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo,
                'estado' => is_object($o->estado) ? $o->estado->value : $o->estado,
                'fecha_esperada' => $this->llegadaEsperada($o),
                'contenedor' => $this->contenedorDe($o)?->contenedor,
                'total' => $this->totalEnPesos($o),
            ])->all();

        // Jorge no recibe en la moneda del proveedor: en importación ve el valor
        // en pesos, o el aviso de que falta la tasa antes de que intente confirmar.
        $esImportacion = (bool) $oc?->esImportacion();
        $costos = app(\App\Modules\Compras\Services\CostoImportacion::class);
        $faltaTasa = null;
        if ($esImportacion) {
            try {
                $costos->tasa($oc);
            } catch (\InvalidArgumentException $e) {
                $faltaTasa = $e->getMessage();
            }
        }
        $principal = \App\Models\EmpresaConfig::current()->id;
        if ($oc) {
            $oc->loadMissing('items.empresaDestino:id,razon_social,nombre_comercial');
        }

        return Inertia::render('Compras/Recepcion/Nueva', [
            'ocs_pendientes' => $ocsPendientes,
            'oc' => $oc ? [
                'id' => $oc->id, 'numero' => $oc->numero,
                'proveedor' => $oc->proveedor?->razon_social ?: $oc->proveedor?->nombre_completo,
                'es_importacion' => $esImportacion,
                'contenedor' => $this->contenedorDe($oc)?->contenedor,
                'importacion_id' => $this->contenedorDe($oc)?->id,
                'falta_tasa' => $faltaTasa,
                'items' => $oc->items->map(fn ($i) => [
                    'id' => $i->id, 'descripcion' => $i->descripcion,
                    'cantidad_pendiente' => (float) $i->cantidad - (float) $i->cantidad_recibida,
                    'precio_unit' => $esImportacion && ! $faltaTasa ? $costos->fobUnitario($i, $oc) : (float) $i->precio_unit,
                    'cajas' => (int) $i->cajas,
                    'unidades_por_caja' => (int) $i->unidades_por_caja,
                    'destino' => $i->empresa_destino_id ? $i->empresaDestino?->nombreCorto() : null,
                    'para_otra_empresa' => $i->empresa_destino_id && (int) $i->empresa_destino_id !== (int) $principal,
                    // Sin producto y sin referencia de dónde crearlo: no subirá al inventario.
                    'no_sube' => $esImportacion && ! $i->producto_id && ! $i->variante_id && ! $i->esComponente()
                        && ! \App\Modules\Compras\Actions\CrearProductosDeOrden::referenciaDe($i),
                    'es_componente' => $i->esComponente(),
                    /*
                     * La línea no tiene producto del catálogo, así que al
                     * recibirla NO va a sumar inventario.
                     *
                     * A veces está bien —un flete o un seguro no son
                     * mercancía— y por eso no se bloquea la recepción. Lo que
                     * no puede seguir pasando es que nadie se entere: antes la
                     * recepción se confirmaba, la orden quedaba recibida, el
                     * asiento se hacía, y el inventario no se movía en
                     * silencio.
                     */
                    'sin_producto' => ! $i->producto_id && ! $i->variante_id && ! $i->esComponente(),
                    'referencia' => \App\Modules\Compras\Actions\CrearProductosDeOrden::referenciaDe($i),
                    'color_nombre' => $i->color_nombre,
                    // Ya tiene stock sin colores: el color queda en la recepción y su stock sigue siendo uno.
                    'sin_colores' => $esImportacion && $i->producto
                        && ! app(\App\Modules\Compras\Services\ColoresDeImportacion::class)->admiteColores($i->producto),
                ])->filter(fn ($x) => $x['cantidad_pendiente'] > 0)->values(),
                'colores' => \App\Modules\Catalogo\Models\Color::query()->where('activo', true)->orderBy('nombre')->pluck('nombre')->values(),
            ] : null,

            /*
             * A dónde se puede mandar lo que se está descargando.
             *
             * No se ofrece la lista de bodegas: hay 55 filas y 44 son espejos
             * del catálogo compartido de SIIGO —«TK0007UREA», «Miyuki Test»,
             * «Bodega pruebas luis»—, indistinguibles por `activa`,
             * `categoria` o `disponible_para_venta`. Se ofrece el par con el
             * que razona la bodega, que además es la regla que puso el área:
             * oficina 10 u 11, almacén 01 ó 07. Son cuatro opciones limpias en
             * vez de sesenta sucias.
             */
            'destinos' => $this->destinosDeIngreso(),
            'bodega_orden' => $oc?->bodega_id ? [
                'id' => $oc->bodega_id,
                'nombre' => $oc->bodega?->nombre,
            ] : null,
        ]);
    }

    /**
     * Le pone producto a una línea de la orden, sin salir de la recepción.
     *
     * Quien descarga el contenedor se encuentra con que la factura trae
     * mercancía que no está en el catálogo. Hasta hoy su única salida era
     * irse al catálogo —otra casilla de permiso—, crear el producto, volver a
     * la orden y empezar de nuevo; en la práctica recibía igual y la
     * mercancía no entraba al inventario sin que nadie se enterara.
     *
     * Va con la casilla de recepción, no con la de productos: la persona que
     * tiene el contenedor abierto es la que sabe qué llegó.
     */
    public function lineaProducto(Request $r, int $item): RedirectResponse
    {
        abort_unless(\App\Auth\Permisos::puede($r->user(), 'compras_recepcion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        $data = $r->validate([
            // Enlazar uno que ya existe…
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            // …o crear uno con lo mínimo.
            'nombre' => ['nullable', 'string', 'max:200'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'desglose_stock' => ['boolean'],
            'color_nombre' => ['nullable', 'string', 'max:60'],
        ]);

        $linea = \App\Modules\Compras\Models\OrdenCompraItem::findOrFail($item);

        try {
            $linea = \App\Modules\Compras\Actions\EnlazarProductoALinea::run($linea, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['producto' => $e->getMessage()]);
        }

        return back()->with('success',
            "«{$linea->descripcion}» quedó enlazada a {$linea->producto?->nombre}. Ahora sí va a sumar inventario.");
    }

    /**
     * Traduce el par «oficina:almacén» de cada renglón a la bodega concreta.
     *
     * Si el par no admite ingreso directo, `UbicacionDelPar` lo rechaza con el
     * motivo en palabras —«al almacén 04 OUTLET no se ingresa directo…»— y la
     * recepción no se crea. Esa es la única puerta por donde entra el destino,
     * así que por acá no pasa una bodega inventada.
     *
     * @param  array<int, array>  $items
     * @return array<int, array>
     */
    private function resolverDestinos(array $items, ?int $bodegaDeLaOrdenId = null): array
    {
        $par = app(\App\Modules\Inventario\Support\UbicacionDelPar::class);
        $cache = [];

        foreach ($items as $i => $it) {
            $elegido = trim((string) ($it['destino_ingreso'] ?? ''));
            unset($items[$i]['destino_ingreso']);

            if ($elegido === '') {
                $items[$i]['ubicacion_id'] = null;

                continue;
            }

            if (! isset($cache[$elegido])) {
                [$oficinaId, $almacenId] = array_pad(explode(':', $elegido, 2), 2, null);

                $oficina = \App\Modules\Inventario\Models\Oficina::find((int) $oficinaId);
                $almacen = \App\Modules\Inventario\Models\Almacen::find((int) $almacenId);

                if (! $oficina || ! $almacen) {
                    throw new \InvalidArgumentException('El destino elegido ya no existe en el catálogo.');
                }

                // Se pregunta el motivo ANTES de resolver, para poder decirlo
                // en palabras en vez de cortar la petición con un 422 mudo.
                $motivo = $par->motivoParaNoRecibir($oficina, $almacen);
                if ($motivo !== null) {
                    throw new \InvalidArgumentException($motivo);
                }

                /*
                 * La bodega de la orden va como molde: si hay que crear la del
                 * par, hereda responsable y ciudad de ella. Sin eso la bodega
                 * nueva nace sin dueño y lo que acaban de recibir no le
                 * aparece a nadie en su cola ni en su kardex.
                 */
                $cache[$elegido] = $par->resolver(
                    $oficina,
                    $almacen,
                    false,
                    $bodegaDeLaOrdenId
                        ? \App\Modules\Dropi\Models\InventarioUbicacion::find($bodegaDeLaOrdenId)
                        : null,
                )->id;
            }

            $items[$i]['ubicacion_id'] = $cache[$elegido];
        }

        return array_values($items);
    }

    /**
     * Los pares (oficina, almacén) que admiten mercancía del contenedor.
     *
     * Se resuelven acá y no en el Vue para que la pantalla no tenga que
     * repetir la regla, y para que si el área la cambia desde el catálogo, la
     * lista cambie sola.
     *
     * @return array<int, array{valor:string, label:string, oficina_id:int, almacen_id:int}>
     */
    private function destinosDeIngreso(): array
    {
        $destinos = [];

        $oficinas = \App\Modules\Inventario\Models\Oficina::deIngreso()->orderBy('orden')->get();
        $almacenes = \App\Modules\Inventario\Models\Almacen::deIngreso()->orderBy('orden')->get();

        foreach ($oficinas as $o) {
            foreach ($almacenes as $a) {
                $destinos[] = [
                    'valor' => "{$o->id}:{$a->id}",
                    'label' => "{$o->nombre} · {$a->nombre}",
                    'oficina_id' => $o->id,
                    'almacen_id' => $a->id,
                ];
            }
        }

        return $destinos;
    }

    public function recepcionCrear(Request $r): RedirectResponse
    {
        // Casilla: Recepcion de mercancia.
        abort_unless(\App\Auth\Permisos::puede($r->user(), 'compras_recepcion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        // Re-audit M2 R3 PATRÓN Q (FUNC-C3) · TX UNIFICADA. Antes había una tx
        //   externa que committeaba `RecepcionCompra` + items, y DESPUÉS otra
        //   tx en `RecibirMercancia::handle`. Si el Action tronaba, quedaba
        //   recepción huérfana en `borrador` + cada retry creaba OTRA con nuevo
        //   consecutivo → acumulación de "recepciones fantasma".
        //   Ahora TODO va bajo el mismo `DB::transaction` — o se persiste el
        //   flujo completo o se rollea todo. `RecibirMercancia` sigue haciendo
        //   su propia tx anidada (Laravel las combina, sin doble commit).
        //
        // Re-audit M2 R3 PATRÓN Q (FUNC-A3) · dedupe `orden_item_id` — antes un
        //   payload con duplicados pasaba items y el Action detectaba
        //   sobre-recepción en el segundo intento, pero la recepción ya estaba
        //   committeada. Ahora fusionamos duplicados sumando cantidades ANTES
        //   de crear la recepción.
        //
        // Re-audit M2 R3 PATRÓN M (FUNC-C1) · fillable clave = `recibido_por`.
        //   Antes pasaba `'creado_por'` → mass-assign silencioso lo descartaba,
        //   la columna quedaba NULL y perdíamos trazabilidad de autor.

        $data = $r->validate([
            'orden_id' => ['required', 'integer', 'exists:compras_ordenes,id'],
            'remision_proveedor' => ['nullable', 'string', 'max:100'],
            'factura_proveedor' => ['nullable', 'string', 'max:100'],
            'transportista' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            // La tabla es `compra_orden_items` en singular (así la declara
            // `OrdenCompraItem::$table`). Con el nombre en plural la regla
            // `exists` reventaba con «table doesn't exist» y CONFIRMAR UNA
            // RECEPCIÓN FALLABA SIEMPRE con 500: no se podía meter mercancía a
            // bodega por la interfaz. Encontrado el 2026-10-14 recibiendo una
            // OC real; el botón se quedaba en «Guardando…» sin decir nada.
            'items.*.orden_item_id' => ['required', 'integer', 'exists:compra_orden_items,id'],
            'items.*.cantidad_recibida' => ['required', 'numeric', 'min:0.001'],
            'items.*.lote' => ['nullable', 'string', 'max:80'],
            // LOG-J8 · cada línea declara su destino (apto/averia/cuarentena/revision/faltante).
            // Default: apto (comportamiento retro-compatible con el flujo anterior).
            'items.*.clasificacion' => ['nullable', 'string', 'in:apto,averia,cuarentena,revision,faltante'],
            'items.*.motivo_clasificacion' => ['nullable', 'string', 'max:180'],
            'items.*.color_nombre' => ['nullable', 'string', 'max:60'],
            /*
             * El destino de ESTA parte de la línea, como par oficina:almacén.
             *
             * Vacío = la bodega de la orden, que es como entró todo hasta hoy;
             * así el panel de Dropi, que confirma con esta misma acción y no
             * manda destino, sigue funcionando sin tocarlo.
             *
             * Se valida como par y no como id de bodega a propósito: por el
             * POST no puede entrar un espejo de SIIGO ni una ubicación de
             * avería, porque sólo se aceptan combinaciones que el catálogo
             * marcó como de ingreso directo.
             */
            'items.*.destino_ingreso' => ['nullable', 'string', 'max:20'],
            'motivo_exceso' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $data['items'] = $this->resolverDestinos(
                $data['items'],
                (int) OrdenCompra::whereKey($data['orden_id'])->value('bodega_id') ?: null,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // `UbicacionDelPar` rechaza con abort cuando la bodega del par
            // está desactivada: mismo trato, o el mensaje no llega.
            if ($e->getStatusCode() >= 500) {
                throw $e;
            }

            return back()->withErrors([
                'recepcion' => $e->getMessage() ?: 'No se pudo resolver el destino.',
            ]);
        } catch (\InvalidArgumentException $e) {
            // El motivo tiene que LEERSE. Con `abort(422)` la pantalla de
            // Inertia se queda muda y quien está descargando no sabe por qué
            // no se guardó.
            return back()->withErrors(['recepcion' => $e->getMessage()]);
        }

        // La lógica vive en RegistrarRecepcion (7-nov): el panel de Dropi recibe
        //   con las mismas reglas.
        try {
            $rc = \App\Modules\Compras\Actions\RegistrarRecepcion::run($data);
        } catch (\InvalidArgumentException $e) {
            // Sin tasa de cambio o recibiendo de más: que lo lea quien está contando.
            return back()->withErrors(['recepcion' => $e->getMessage()]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            /*
             * Los `abort(422)` del Action tampoco se pueden quedar mudos.
             *
             * `RegistrarRecepcion` rechaza con abort cuando la OC no está en
             * estado receptable, cuando no tiene bodega destino o cuando una
             * línea no le pertenece. Son rechazos correctos, pero en una
             * petición de Inertia un abort devuelve una página de error que la
             * pantalla no sabe leer: el botón «Confirmar recepción» se apaga,
             * la pantalla se queda exactamente igual y quien está descargando
             * no tiene idea de por qué no pasó nada. Lo probamos con una orden
             * en borrador: 422 y ni una letra.
             *
             * Se reenvía como error de campo, que es lo que el resto del
             * sistema ya usa y la pantalla sí pinta. Se deja el abort en el
             * Action porque el panel de Dropi también lo llama.
             */
            if ($e->getStatusCode() >= 500) {
                throw $e;
            }

            return back()->withErrors([
                'recepcion' => $e->getMessage() ?: 'No se pudo registrar la recepción.',
            ]);
        }

        return redirect()->route('app.compras.recepcion.show', $rc->id)->with('success', "Recepción {$rc->numero} creada.");
    }

    /** Sin tasa todavía se muestra lo que hay: la pantalla de recibir avisa que falta. */
    private function totalEnPesos(OrdenCompra $orden): float
    {
        if (! $orden->esImportacion()) {
            return (float) $orden->total;
        }

        try {
            return (float) $orden->total * app(\App\Modules\Compras\Services\CostoImportacion::class)->tasa($orden);
        } catch (\InvalidArgumentException) {
            return (float) $orden->total;
        }
    }

    /** El contenedor en el que viaja una orden, si ya va en uno. */
    /** El lanzamiento de este contenedor, para verlo desde Compras. */
    private function marketingDe(Importacion $imp): ?array
    {
        $lanzamiento = \App\Modules\Marketing\Models\Lanzamiento::with('tarjetas:id,lanzamiento_id,numero,titulo,lista')
            ->where('importacion_id', $imp->id)->first();
        if (! $lanzamiento) {
            return null;
        }
        $u = auth()->user();
        $abiertas = $lanzamiento->tarjetas->where('lista', '!=', 'finalizado')->sortBy('numero');

        return [
            'id' => $lanzamiento->id,
            'total' => $lanzamiento->tarjetas->count(),
            'finalizadas' => $lanzamiento->tarjetas->count() - $abiertas->count(),
            'siguiente' => $abiertas->first()?->titulo,
            'puede_abrir' => \App\Auth\Permisos::puede($u, 'marketing_lanzamientos'),
            'puede_productos' => \App\Modules\Marketing\Models\MarketingCreativo::puedeGestionar($u),
        ];
    }

    private function contenedorDe(OrdenCompra $orden): ?Importacion
    {
        return Importacion::whereHas('ordenes', fn ($q) => $q->whereKey($orden->id))->first();
    }

    /** Para Jorge: la estimación del contenedor, y si no va en uno, la fecha de la orden. */
    private function llegadaEsperada(OrdenCompra $orden): ?string
    {
        $contenedor = $this->contenedorDe($orden);
        $estimada = $contenedor ? app(\App\Modules\Compras\Services\SeguimientoContenedor::class)->resumen($contenedor)['llegada_estimada'] : null;

        return $estimada ?? optional($orden->fecha_esperada)->format('Y-m-d');
    }

    // ================== IMPORTACIÓN ==================

    /**
     * Sprint 4 · A.3 · Listado de contenedores (paridad Filament).
     */
    public function importacionIndex(Request $r): Response
    {
        // Casilla: Importaciones.
        abort_unless(\App\Auth\Permisos::puede($r->user(), 'compras_importacion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        $q = Importacion::query();
        if ($busca = trim((string) $r->query('q', ''))) {
            $q->where(function ($qq) use ($busca) {
                $qq->where('numero', 'like', "%$busca%")
                   ->orWhere('contenedor', 'like', "%$busca%")
                   ->orWhere('bl_awb', 'like', "%$busca%");
            });
        }
        if ($estado = $r->query('estado')) $q->where('estado', $estado);

        $seguimiento = app(\App\Modules\Compras\Services\SeguimientoContenedor::class);

        return Inertia::render('Compras/Importacion/Index', [
            'filtros' => ['q' => $r->query('q', ''), 'estado' => $estado],
            'importaciones' => $q->orderByDesc('id')->paginate(20)->through(fn ($i) => [
                'id' => $i->id,
                'numero' => $i->numero,
                'contenedor' => $i->contenedor,
                'bl_awb' => $i->bl_awb,
                'estado' => is_object($i->estado) ? $i->estado->value : $i->estado,
                'moneda_origen' => $i->moneda_origen,
                'tasa_cambio_liquidacion' => (float) $i->tasa_cambio_liquidacion,
                'eta' => $i->eta?->format('Y-m-d'),
                'fecha_llegada' => $i->fecha_llegada?->format('Y-m-d'),
                'fecha_liquidacion' => $i->fecha_liquidacion?->format('Y-m-d'),
                'total_fob' => (float) $i->lineas()->sum('costo_fob_total'),
                'total_gastos' => (float) $i->gastos()->sum('monto_base'),
                'lineas_count' => $i->lineas()->count(),
                'seguimiento' => $seguimiento->resumen($i),
            ]),
            'kpis' => [
                'total' => Importacion::count(),
                // QA-FIX #6 · abarca todos los estados intermedios (en_transito + en_puerto + nacionalizada).
                'en_transito' => Importacion::whereIn('estado', ['en_transito', 'en_puerto', 'nacionalizada'])->count(),
                'por_liquidar' => Importacion::whereNotNull('fecha_llegada')->whereNull('fecha_liquidacion')->count(),
                'liquidados_mes' => Importacion::whereMonth('fecha_liquidacion', now()->month)->whereYear('fecha_liquidacion', now()->year)->count(),
                'atrasados' => Importacion::where('estado', '!=', 'cerrada')->whereHas('etapas')->with('etapas')->get()
                    ->filter(fn ($i) => $seguimiento->resumen($i)['dias_atraso'] > 0)->count(),
            ],
        ]);
    }

    public function importacionShow(int $importacion): Response
    {
        $imp = Importacion::with(['lineas', 'gastos', 'documentos.autor:id,name'])->findOrFail($importacion);
        $comprobantes = $imp->documentos->whereNotNull('gasto_id')->groupBy('gasto_id');
        $previsto = app(\App\Modules\Compras\Actions\LiquidarImportacion::class)->previsto($imp);
        // Hasta liquidar no hay líneas: lo que se ve es lo que saldría si se liquida ahora.
        $esPrevisto = $imp->lineas->isEmpty();
        return Inertia::render('Compras/Importacion/Show', [
            'paso' => app(\App\Modules\Compras\Services\PasoDeCompra::class)->deContenedor($imp, auth()->user()),
            'marketing' => $this->marketingDe($imp),
            'tipos_documento' => array_diff_key(\App\Modules\Compras\Models\ImportacionDocumento::TIPOS, ['comprobante_gasto' => true]),
            'puede_quitar_documentos' => (bool) auth()->user()?->esAracely(),
            'max_subida_mb' => ImportacionDocumentosController::limiteMb(),
            'seguimiento' => app(\App\Modules\Compras\Services\SeguimientoContenedor::class)->paraPantalla($imp),
            'puede_editar_seguimiento' => \App\Auth\Permisos::puede(auth()->user(), 'compras_importacion'),
            'selectividades' => \App\Modules\Compras\Models\ImportacionDeclaracion::SELECTIVIDAD,
            'tipos_inspeccion' => \App\Modules\Compras\Models\ImportacionInspeccion::TIPOS,
            'importacion' => [
                'id' => $imp->id, 'numero' => $imp->numero,
                'contenedor' => $imp->contenedor, 'bl_awb' => $imp->bl_awb,
                'estado' => is_object($imp->estado) ? $imp->estado->value : $imp->estado,
                'puerto_origen' => $imp->puerto_origen, 'puerto_destino' => $imp->puerto_destino,
                'incoterm' => $imp->incoterm, 'moneda_origen' => $imp->moneda_origen,
                'tasa_cambio_liquidacion' => (float) $imp->tasa_cambio_liquidacion,
                'fecha_zarpe' => $imp->fecha_zarpe?->toDateString(),
                'eta' => $imp->eta?->toDateString(),
                'fecha_llegada' => $imp->fecha_llegada?->toDateString(),
                'fecha_liquidacion' => $imp->fecha_liquidacion?->toDateString(),
                'observaciones' => $imp->observaciones,
                'lineas' => $imp->lineas->map(fn ($l) => [
                    'id' => $l->id,
                    'cantidad' => (float) $l->cantidad,
                    'costo_fob_unit' => (float) $l->costo_fob_unit,
                    'costo_fob_total' => (float) $l->costo_fob_total,
                    'gasto_prorrateado' => (float) $l->gasto_prorrateado,
                    'costo_final_unit' => (float) $l->costo_final_unit,
                ])->all(),
                'gastos' => $imp->gastos->map(fn ($g) => [
                    'id' => $g->id, 'concepto' => $g->concepto,
                    'moneda' => $g->moneda, 'monto' => (float) $g->monto,
                    'monto_base' => (float) $g->monto_base,
                    'capitalizable' => (bool) $g->capitalizable,
                    'metodo_prorrateo' => $g->metodo_prorrateo,
                    'fecha' => $g->fecha?->toDateString(),
                    'comprobantes' => ($comprobantes[$g->id] ?? collect())->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->nombre])->values()->all(),
                ])->all(),
                'documentos' => $imp->documentos->whereNull('gasto_id')->sortByDesc('version')->map(fn ($d) => [
                    'id' => $d->id, 'tipo' => $d->tipo, 'nombre' => $d->nombre, 'version' => $d->version,
                    'notas' => $d->notas, 'tamano' => (int) $d->tamano,
                    'subido_por' => $d->autor?->name, 'subido_en' => $d->created_at?->format('Y-m-d H:i'),
                ])->values()->all(),
                'total_gastos' => (float) $imp->gastos->sum('monto_base'),
                'total_fob' => $esPrevisto ? $previsto['fob'] : (float) $imp->lineas->sum('costo_fob_total'),
                'fob_es_previsto' => $esPrevisto,
                'fob_sin_recibir' => $previsto['sin_recibir'],
                'fob_aviso' => $esPrevisto ? $previsto['aviso'] : null,
                'gastos_al_inventario' => $previsto['gastos_al_inventario'],
            ],
        ]);
    }

    /**
     * Re-audit M2 UX-C1 · pantalla nueva importación (antes solo por Filament).
     */
    public function importacionForm(): Response
    {
        // Casilla: Importaciones.
        abort_unless(\App\Auth\Permisos::puede(request()->user(), 'compras_importacion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        return Inertia::render('Compras/Importacion/Nueva');
    }

    public function importacionCrear(Request $r): RedirectResponse
    {
        // Casilla: Importaciones.
        abort_unless(\App\Auth\Permisos::puede($r->user(), 'compras_importacion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        $data = $r->validate([
            'contenedor' => ['nullable', 'string', 'max:50'],
            'bl_awb' => ['nullable', 'string', 'max:50'],
            'proveedor_pais' => ['nullable', 'string', 'max:100'],
            'puerto_origen' => ['nullable', 'string', 'max:100'],
            'puerto_destino' => ['nullable', 'string', 'max:100'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'moneda_origen' => ['required', 'string', 'size:3'],
            'tasa_cambio_liquidacion' => ['nullable', 'numeric', 'min:0'],
            'fecha_zarpe' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
        ]);
        $imp = \App\Modules\Compras\Actions\CrearImportacion::run($data);
        return redirect()->route('app.compras.importacion.show', $imp->id)->with('success', "Importación {$imp->numero} creada.");
    }

    public function importacionGastoAgregar(Request $r, int $importacion): RedirectResponse
    {
        // Casilla: Importaciones.
        abort_unless(\App\Auth\Permisos::puede($r->user(), 'compras_importacion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        // Re-audit M2 PATRÓN L (FUNC-A6 / DATOS-A7) · unificado con Action:
        // valor|cantidad|peso|volumen (antes HTTP aceptaba "fob" que caía al
        // default silenciosamente y rechazaba "peso" que Filament sí ofrece).
        $data = $r->validate([
            // El modelo lo lee como enum: un concepto que no exista rompe la pantalla.
            'concepto' => ['required', \Illuminate\Validation\Rule::enum(\App\Modules\Compras\Enums\ConceptoGastoImportacion::class)],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'moneda' => ['required', 'string', 'size:3'],
            'monto' => ['required', 'numeric', 'min:0'],
            'capitalizable' => ['boolean'],
            'metodo_prorrateo' => ['required', 'string', 'in:valor,cantidad,peso,volumen'],
            'factura_proveedor' => ['nullable', 'string', 'max:100'],
            'fecha' => ['required', 'date'],
            'proveedor_id' => ['nullable', 'integer'],
            // El soporte del gasto (factura del flete, de la agencia…): lo pidió
            // Compras para contabilidad (reunión del 21-oct).
            'comprobante' => ['nullable', 'file', 'max:20480', 'mimes:'.ImportacionDocumentosController::MIMES],
        ]);
        unset($data['comprobante']);
        \App\Modules\Compras\Actions\AgregarGastoImportacion::run(
            Importacion::findOrFail($importacion), $data, $r->file('comprobante'),
        );
        return back()->with('success', 'Gasto agregado.');
    }

    public function importacionLiquidar(int $importacion): RedirectResponse
    {
        // Casilla: Importaciones.
        abort_unless(\App\Auth\Permisos::puede(request()->user(), 'compras_importacion'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        $imp = Importacion::findOrFail($importacion);
        abort_if($imp->estado === EstadoImportacion::Liquidada, 422, 'Ya liquidada.');
        LiquidarImportacion::run($imp);
        return back()->with('success', 'Importación liquidada — costos prorrateados.');
    }

    // ================== REPORTE COMPRAS ==================
    public function reporte(\Illuminate\Http\Request $request): Response
    {
        // Casilla: Reportes de compras.
        abort_unless(\App\Auth\Permisos::puede($request->user(), 'compras_reportes'), 403,
            'Esta parte de Compras necesita su propio acceso.');

        // COMP-B9 · filtros fecha editables + comparativa mes anterior.
        $tz = 'America/Bogota';
        $inicio = $request->filled('desde')
            ? \Carbon\Carbon::parse($request->string('desde'), $tz)->startOfDay()
            : now($tz)->startOfMonth();
        $fin = $request->filled('hasta')
            ? \Carbon\Carbon::parse($request->string('hasta'), $tz)->endOfDay()
            : now($tz)->endOfMonth();
        // Mismo rango un mes antes para la comparativa delta%.
        $inicioPrev = $inicio->copy()->subMonthNoOverflow();
        $finPrev    = $fin->copy()->subMonthNoOverflow();

        $ocPendientes = OrdenCompra::whereIn('estado', [EstadoOrdenCompra::Aprobada, EstadoOrdenCompra::Enviada, EstadoOrdenCompra::Parcial])->count();
        $ocPeriodo = (float) OrdenCompra::whereBetween('fecha_emision', [$inicio, $fin])->sum('total');
        $ocPrev    = (float) OrdenCompra::whereBetween('fecha_emision', [$inicioPrev, $finPrev])->sum('total');
        $contenedoresPorLiquidar = Importacion::whereIn('estado', [EstadoImportacion::Nacionalizada, EstadoImportacion::EnPuerto])->count();
        $totalFobPeriodo = Importacion::whereBetween('created_at', [$inicio, $fin])
            ->with('lineas')->get()->sum(fn ($i) => $i->lineas->sum('costo_fob_total'));

        $topProveedores = OrdenCompra::selectRaw('proveedor_id, COUNT(*) as ocs, SUM(total) as total')
            ->whereBetween('fecha_emision', [$inicio, $fin])
            ->groupBy('proveedor_id')->orderByDesc('total')->limit(10)
            ->with('proveedor:id,nombre_completo,razon_social,numero_documento')
            ->get()->map(fn ($r) => [
                'proveedor_id' => $r->proveedor?->id,
                'nombre' => $r->proveedor?->razon_social ?: $r->proveedor?->nombre_completo,
                'nit' => $r->proveedor?->numero_documento,
                'ocs' => (int) $r->ocs, 'total' => (float) $r->total,
            ])->all();

        // COMP-B9 · cruce con SIIGO /v1/accounts-payable (si está disponible).
        // No bloquea el reporte si falla · solo omite la columna "SIIGO".
        $cxpSiigo = $this->cruzarCxPConSiigo($topProveedores, $inicio, $fin);

        $delta = fn ($a, $b) => $b > 0 ? round((($a - $b) / $b) * 100, 1) : null;

        return Inertia::render('Compras/Reporte', [
            'periodo' => [
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
                'inicio_prev' => $inicioPrev->toDateString(),
                'fin_prev' => $finPrev->toDateString(),
            ],
            'kpis' => [
                'oc_pendientes' => $ocPendientes,
                'oc_periodo' => $ocPeriodo,
                'oc_prev' => $ocPrev,
                'oc_delta_pct' => $delta($ocPeriodo, $ocPrev),
                'contenedores_por_liquidar' => $contenedoresPorLiquidar,
                'total_fob_periodo' => (float) $totalFobPeriodo,
            ],
            'topProveedores' => $topProveedores,
            'contenedores' => app(\App\Modules\Compras\Services\ReporteContenedores::class)->handle(),
            'siigo' => $cxpSiigo, // ['disponible'=>bool, 'por_nit'=>[...], 'mensaje'=>?string]
        ]);
    }

    /**
     * COMP-B9 · Trae el saldo CxP por proveedor desde SIIGO para los NIT del
     * top10 local. Si SIIGO está caído o no habilitado, degradamos silencioso
     * (reporte igual se renderiza, solo se oculta la columna comparativa).
     */
    private function cruzarCxPConSiigo(array $topProveedores, \Carbon\Carbon $inicio, \Carbon\Carbon $fin): array
    {
        try {
            $cliente = app(\App\Modules\Siigo\Clients\SiigoClient::class);
            // Agrupamos por NIT · un GET por proveedor con limit chico (10) basta.
            $out = [];
            foreach ($topProveedores as $tp) {
                if (empty($tp['nit'])) continue;
                $r = $cliente->request('GET', '/v1/accounts-payable', [
                    'identification' => $tp['nit'],
                    'due_date_start' => $inicio->toDateString(),
                    'due_date_end' => $fin->toDateString(),
                    'page_size' => 25,
                ]);
                if (! $r->ok()) continue;
                $data = $r->json();
                $items = $data['results'] ?? (is_array($data) ? $data : []);
                $saldo = 0.0; $facturas = 0;
                foreach ($items as $it) {
                    $saldo += (float) ($it['balance'] ?? $it['total'] ?? 0);
                    $facturas++;
                }
                $out[$tp['nit']] = [
                    'facturas' => $facturas,
                    'saldo' => round($saldo, 2),
                ];
            }
            return ['disponible' => true, 'por_nit' => $out, 'mensaje' => null];
        } catch (\Throwable $e) {
            return ['disponible' => false, 'por_nit' => [], 'mensaje' => $e->getMessage()];
        }
    }
}
