<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Support\ExportadorCsv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComprasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                /*
                 * LOG-H4 · Bodega también entra al listado.
                 *
                 * Era incoherente: bodega CREA órdenes de compra y hace las
                 * recepciones —`ComprasGestionController` la deja— pero no
                 * podía ver la lista de lo que ella misma creó. El botón
                 * «Cancelar» de Nueva OC apunta acá, así que salir del
                 * formulario la mandaba derecho a un 403.
                 *
                 * La misma puerta que la gestión, para que no vuelvan a
                 * separarse.
                 */
                abort_unless($r->user()?->esEquipoBodega() || $r->user()?->esContable(), 403);
                return $next($r);
            }),
        ];
    }

    /**
     * F2c · Compras con filtros de verdad.
     *
     * Las tres pestañas traían las últimas 30 o 50 filas y nada más: ni
     * búsqueda, ni rango de fechas, ni estado, ni paginación. Buscar una OC de
     * marzo era imposible desde acá, y el mes pasado desaparecía apenas había
     * cincuenta órdenes nuevas.
     *
     * El filtro se aplica a la pestaña ACTIVA. Mantener tres filtros en
     * paralelo —uno por pestaña— sonaba más completo, pero significa que al
     * cambiar de pestaña los números cambien por razones invisibles.
     *
     * Desde el 2026-10-26 las compras SI tienen los dos ejes, asi que el filtro
     * los incluye. Una compra sin factura del proveedor no puede ir a SIIGO, y
     * mientras no se pudiera decir, el balance interno mostraba ingresos no
     * declarados sin ningun costo no declarado: la utilidad salia inflada.
     */
    public function index(Request $request): Response
    {
        $tab = (string) $request->input('tab', 'ordenes');
        if (! in_array($tab, ['ordenes', 'importaciones', 'recepciones'], true)) {
            $tab = 'ordenes';
        }

        $f = $this->filtros($request);
        $ejes = FiltroEjes::deRequest($request);

        return Inertia::render('Compras/Index', [
            /*
             * Quién puede mover el eje fiscal. El selector salía editable para
             * cualquiera que abriera la lista, incluido bodega, y lo que se
             * decide ahí es si la compra se declara ante la DIAN.
             */
            'puede_destino' => ($request->user()?->esContable() || $request->user()?->esAracely()) ?? false,
            'tab' => $tab,
            'kpis' => $this->kpis(),
            'filtros' => array_merge($f, $ejes->aParametros()),
            'opcionesEjes' => FiltroEjes::opciones(),
            // Sin «reparto»: en compras es fiscal o interna.
            'destinosDeCompra' => DestinoFiscal::opcionesDeCompra(),
            'lineas' => LineaNegocio::opciones(),
            'estados' => [
                'ordenes' => $this->opcionesDe(EstadoOrdenCompra::cases()),
                'importaciones' => $this->opcionesDe(EstadoImportacion::cases()),
                // El estado de una recepción es texto plano en la tabla, no
                // un enum: por eso va escrito acá y no sale de ::cases().
                'recepciones' => [
                    ['valor' => 'todos', 'label' => 'Todos los estados'],
                    ['valor' => 'recibida', 'label' => 'Recibida'],
                    ['valor' => 'confirmada', 'label' => 'Confirmada'],
                    ['valor' => 'anulada', 'label' => 'Anulada'],
                ],
            ],
            // Sólo se consulta la pestaña que se está viendo: antes se armaban
            // las tres en cada carga, con sus relaciones, para mostrar una.
            'ordenes' => $tab === 'ordenes' ? $this->ordenes($f, $ejes) : null,
            // Las importaciones no llevan los ejes: una importacion es un
            // contenedor en ruta, y lo que se declara o no son las compras que
            // vienen adentro.
            'importaciones' => $tab === 'importaciones' ? $this->importaciones($f) : null,
            'recepciones' => $tab === 'recepciones' ? $this->recepciones($f, $ejes) : null,
        ]);
    }

    /** F2c · Bajar la pestaña activa tal como se está viendo. */
    public function exportar(Request $request): StreamedResponse
    {
        $tab = (string) $request->input('tab', 'ordenes');
        $f = $this->filtros($request);
        $ejes = FiltroEjes::deRequest($request);

        $pie = [
            '',
            'Rango: '.($f['desde'] ?: 'sin límite').' a '.($f['hasta'] ?: 'sin límite'),
            'Filtro: '.($f['estado'] !== 'todos' ? "estado {$f['estado']}" : 'todos los estados')
                .($f['q'] !== '' ? " · búsqueda «{$f['q']}»" : '')
                .' · '.$ejes->descripcion(),
        ];

        if ($tab === 'importaciones') {
            return ExportadorCsv::descargar(
                'compras-importaciones',
                ['Número', 'Contenedor', 'País', 'Puerto', 'Zarpe', 'ETA', 'Llegada', 'Liquidación', 'Costo total', 'Estado'],
                $this->consultaImportaciones($f)->orderBy('id'),
                fn ($i) => [
                    $i->numero, $i->contenedor, $i->proveedor_pais, $i->puerto_destino,
                    $i->fecha_zarpe?->toDateString(), $i->eta?->toDateString(),
                    $i->fecha_llegada?->toDateString(), $i->fecha_liquidacion?->toDateString(),
                    (float) $i->valor_total_costo, $i->estado?->value,
                ],
                $pie,
            );
        }

        if ($tab === 'recepciones') {
            return ExportadorCsv::descargar(
                'compras-recepciones',
                ['Fecha', 'Orden', 'Estado', 'Línea', 'Destino', 'Recibido por', 'Compra SIIGO', 'Observaciones'],
                $this->consultaRecepciones($f, $ejes)->with(['orden:id,numero', 'receptor:id,name'])->orderBy('id'),
                fn ($r) => [
                    $r->fecha_recepcion?->toDateString(), $r->orden?->numero,
                    $r->estado ?? 'recibida',
                    $r->linea_negocio?->label(), $r->destino_fiscal?->label(),
                    $r->receptor?->name,
                    $r->siigo_number, $r->observaciones,
                ],
                $pie,
            );
        }

        $consulta = $this->consultaOrdenes($f, $ejes)
            ->with(['proveedor:id,nombre_completo', 'bodega:id,nombre'])->orderBy('id');

        $totales = (clone $consulta)->reorder()
            ->selectRaw('COALESCE(SUM(total),0) t, COUNT(*) n')->first();

        return ExportadorCsv::descargar(
            'compras-ordenes',
            ['Número', 'Proveedor', 'Bodega', 'Emisión', 'Esperada', 'Total', 'Estado', 'Línea', 'Destino'],
            $consulta,
            fn ($o) => [
                $o->numero, $o->proveedor?->nombre_completo, $o->bodega?->nombre,
                $o->fecha_emision?->toDateString(), $o->fecha_esperada?->toDateString(),
                (float) $o->total, $o->estado?->value,
                $o->linea_negocio?->label(), $o->destino_fiscal?->label(),
            ],
            array_merge($pie, [
                'Total: '.number_format((float) ($totales->t ?? 0), 2, ',', '.'),
                'Órdenes: '.number_format((int) ($totales->n ?? 0), 0, ',', '.'),
            ]),
        );
    }

    /** @return array{q:string,desde:?string,hasta:?string,estado:string} */
    private function filtros(Request $request): array
    {
        $d = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        return [
            'q' => trim((string) ($d['q'] ?? '')),
            'desde' => $d['desde'] ?? null,
            'hasta' => $d['hasta'] ?? null,
            'estado' => trim((string) ($d['estado'] ?? 'todos')) ?: 'todos',
        ];
    }

    private function opcionesDe(array $casos): array
    {
        return array_merge(
            [['valor' => 'todos', 'label' => 'Todos los estados']],
            array_map(fn ($c) => [
                'valor' => $c->value,
                'label' => method_exists($c, 'label') ? $c->label() : $c->value,
            ], $casos),
        );
    }

    private function kpis(): array
    {
        return [
            'oc_abiertas' => OrdenCompra::whereNotIn('estado', [EstadoOrdenCompra::Cerrada, EstadoOrdenCompra::Anulada])->count(),
            'oc_valor_pendiente' => (float) OrdenCompra::whereNotIn('estado', [EstadoOrdenCompra::Cerrada, EstadoOrdenCompra::Anulada])->sum('total'),
            'contenedores_en_ruta' => Importacion::whereIn('estado', [EstadoImportacion::EnTransito, EstadoImportacion::EnPuerto])->count(),
            'recepciones_mes' => RecepcionCompra::whereBetween('fecha_recepcion', [now('America/Bogota')->startOfMonth(), now('America/Bogota')->endOfDay()])->count(),
        ];
    }

    // ── Las consultas, una sola vez: las comparten la pantalla y el export ───

    private function consultaOrdenes(array $f, ?FiltroEjes $ejes = null): Builder
    {
        return OrdenCompra::query()
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->when($f['q'] !== '', fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('numero', 'like', "%{$f['q']}%")
                    ->orWhereHas('proveedor', fn ($p) => $p
                        ->where('nombre_completo', 'like', "%{$f['q']}%")
                        ->orWhere('numero_documento', 'like', "%{$f['q']}%"));
            }))
            ->when($f['desde'], fn ($q) => $q->whereDate('fecha_emision', '>=', $f['desde']))
            ->when($f['hasta'], fn ($q) => $q->whereDate('fecha_emision', '<=', $f['hasta']))
            ->when($f['estado'] !== 'todos', fn ($q) => $q->where('estado', $f['estado']));
    }

    private function consultaImportaciones(array $f): Builder
    {
        return Importacion::query()
            ->when($f['q'] !== '', fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('numero', 'like', "%{$f['q']}%")
                    ->orWhere('contenedor', 'like', "%{$f['q']}%")
                    ->orWhere('proveedor_pais', 'like', "%{$f['q']}%");
            }))
            ->when($f['desde'], fn ($q) => $q->whereDate('fecha_zarpe', '>=', $f['desde']))
            ->when($f['hasta'], fn ($q) => $q->whereDate('fecha_zarpe', '<=', $f['hasta']))
            ->when($f['estado'] !== 'todos', fn ($q) => $q->where('estado', $f['estado']));
    }

    private function consultaRecepciones(array $f, ?FiltroEjes $ejes = null): Builder
    {
        return RecepcionCompra::query()
            ->when($ejes, fn ($q) => $ejes->aplicar($q))
            ->when($f['q'] !== '', fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('observaciones', 'like', "%{$f['q']}%")
                    ->orWhereHas('orden', fn ($o) => $o->where('numero', 'like', "%{$f['q']}%"));
            }))
            ->when($f['desde'], fn ($q) => $q->whereDate('fecha_recepcion', '>=', $f['desde']))
            ->when($f['hasta'], fn ($q) => $q->whereDate('fecha_recepcion', '<=', $f['hasta']))
            ->when($f['estado'] !== 'todos', fn ($q) => $q->where('estado', $f['estado']));
    }

    // ── Lo que ve la pantalla ────────────────────────────────────────────────

    private function ordenes(array $f, ?FiltroEjes $ejes = null): array
    {
        $p = $this->consultaOrdenes($f, $ejes)
            ->with(['proveedor:id,nombre_completo', 'bodega:id,nombre'])
            ->orderByDesc('fecha_emision')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return $p->through(function ($o) {
            $estadoVal = $o->estado?->value;
            // Sprint 3 · A.1 · exponer permisos por fila para que la UI
            // muestre/oculte acciones Editar/Anular/Duplicar/Recibir.
            $editable = in_array($estadoVal, ['borrador', 'pendiente'], true);
            $anulable = ! in_array($estadoVal, ['anulada', 'cerrada'], true);
            $recibible = in_array($estadoVal, ['aprobada', 'parcial', 'en_transito'], true);

            return [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->nombre_completo,
                'bodega' => $o->bodega?->nombre,
                'fecha_emision' => $o->fecha_emision?->toDateString(),
                'fecha_esperada' => $o->fecha_esperada?->toDateString(),
                'total' => (float) $o->total,
                'estado' => $estadoVal,
                'estado_label' => method_exists($o->estado, 'label') ? $o->estado->label() : $estadoVal,
                'estado_color' => method_exists($o->estado, 'color') ? $o->estado->color() : 'gray',
                'destino' => $o->destino_fiscal?->value ?? DestinoFiscal::Siigo->value,
                'destino_label' => ($o->destino_fiscal ?? DestinoFiscal::Siigo)->label(),
                'destino_color' => ($o->destino_fiscal ?? DestinoFiscal::Siigo)->color(),
                'linea_label' => ($o->linea_negocio ?? LineaNegocio::GreatBaby)->label(),
                'puede_editar' => $editable,
                'puede_anular' => $anulable,
                'puede_duplicar' => true,
                'puede_recibir' => $recibible,
            ];
        })->toArray();
    }

    private function importaciones(array $f): array
    {
        $p = $this->consultaImportaciones($f)
            ->orderByDesc('fecha_zarpe')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return $p->through(fn ($i) => [
            'id' => $i->id,
            'numero' => $i->numero,
            'contenedor' => $i->contenedor,
            'proveedor_pais' => $i->proveedor_pais,
            'puerto_destino' => $i->puerto_destino,
            'zarpe' => $i->fecha_zarpe?->toDateString(),
            'eta' => $i->eta?->toDateString(),
            'llegada' => $i->fecha_llegada?->toDateString(),
            'liquidacion' => $i->fecha_liquidacion?->toDateString(),
            'valor_total_costo' => (float) $i->valor_total_costo,
            'estado' => $i->estado?->value,
            'estado_label' => method_exists($i->estado, 'label') ? $i->estado->label() : $i->estado?->value,
            'estado_color' => method_exists($i->estado, 'color') ? $i->estado->color() : 'gray',
        ])->toArray();
    }

    private function recepciones(array $f, ?FiltroEjes $ejes = null): array
    {
        $p = $this->consultaRecepciones($f, $ejes)
            ->with(['orden:id,numero', 'receptor:id,name'])
            ->orderByDesc('fecha_recepcion')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return $p->through(function ($r) {
            // Sprint 3 · A.1 D.1 · badge SIIGO por recepción (F9).
            //   🟢 verde: sincronizado <24h · 🟡 amarillo: >24h · 🔴 rojo: fallido · ⚪ gris: pendiente
            $sync = $r->siigo_sync_at;
            $syncColor = ! $r->siigo_id
                ? ($r->estado === 'confirmada' ? 'amber' : 'gray')
                : ($sync?->diffInHours(now()) < 24 ? 'emerald' : 'amber');

            return [
                'id' => $r->id,
                'orden_id' => $r->orden?->id,
                'orden_numero' => $r->orden?->numero,
                'fecha_recepcion' => $r->fecha_recepcion?->toDateString(),
                'estado' => $r->estado ?? 'recibida',
                'observaciones' => $r->observaciones,
                'receptor' => $r->receptor?->name ?? '—',
                'siigo_id' => $r->siigo_id,
                'siigo_number' => $r->siigo_number,
                'siigo_sync_hace' => $sync?->diffForHumans(),
                'siigo_color' => $syncColor,
                'destino' => $r->destino_fiscal?->value ?? DestinoFiscal::Siigo->value,
                'destino_label' => ($r->destino_fiscal ?? DestinoFiscal::Siigo)->label(),
                'destino_color' => ($r->destino_fiscal ?? DestinoFiscal::Siigo)->color(),
                'linea_label' => ($r->linea_negocio ?? LineaNegocio::GreatBaby)->label(),
                // Una recepcion interna no viaja: el boton de reenviar a SIIGO
                // no tiene nada que hacer ahi.
                'puede_reenviar_siigo' => $r->estado === 'confirmada'
                    && ($r->destino_fiscal ?? DestinoFiscal::Siigo)->tieneParteFiscal(),
            ];
        })->toArray();
    }

    /**
     * Cambiar si una compra se declara o no.
     *
     * Se hace sobre la ORDEN, que es donde está la decisión —si el proveedor va
     * a facturar o no—, y arrastra las recepciones que todavía no viajaron a
     * SIIGO. Una recepción ya emitida allá no se toca: eso se revierte con nota
     * crédito, no borrando la decisión de la fila.
     */
    public function cambiarDestino(Request $r): RedirectResponse
    {
        /*
         * Marcar una compra como fiscal o interna es decidir si el costo se
         * declara ante la DIAN. Compartía puerta con el listado
         * —`esEquipoBodega() || esContable()`, abierta así para que bodega
         * pudiera ver las compras que recibe— y eso dejaba la decisión
         * tributaria a un clic de cualquier admin de bodega, desde la lista.
         */
        abort_unless($r->user()?->esContable() || $r->user()?->esAracely(), 403,
            'Decidir si una compra se declara es de contabilidad.');

        $data = $r->validate([
            'orden_id' => ['required', 'integer', 'exists:compras_ordenes,id'],
            'destino' => ['required', 'string', 'in:'.DestinoFiscal::Siigo->value.','.DestinoFiscal::Interno->value],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]);

        $destino = DestinoFiscal::from($data['destino']);
        $motivo = trim((string) ($data['motivo'] ?? ''));

        if ($destino !== DestinoFiscal::Siigo && $motivo === '') {
            return back()->withErrors([
                'motivo' => 'Para sacar una compra de lo fiscal hay que escribir por qué. '
                    .'Sin eso, dentro de tres meses nadie sabe si fue una decisión o un error.',
            ]);
        }

        $orden = OrdenCompra::with('recepciones')->findOrFail($data['orden_id']);

        $yaEnSiigo = $orden->recepciones->filter(
            fn ($rc) => $rc->siigo_id && ! $destino->tieneParteFiscal()
        );

        if ($yaEnSiigo->isNotEmpty()) {
            return back()->withErrors([
                'destino' => 'Esta orden ya tiene recepciones en SIIGO ('
                    .$yaEnSiigo->pluck('numero')->filter()->implode(', ')
                    .') y no se pueden pasar a interno. Eso se revierte con nota crédito.',
            ]);
        }

        DB::transaction(function () use ($orden, $destino, $motivo) {
            /*
             * `save()` y no `saveQuietly()`: declarar o no declarar una compra
             * es la decision de mas impacto del modulo y tiene que quedar en
             * `audits`. Con saveQuietly no se disparaba ningun evento, asi que
             * era la unica decision fiscal sin rastro.
             */
            $orden->forceFill([
                'destino_fiscal' => $destino,
                'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : $motivo,
            ])->save();

            // Las recepciones que todavía no viajaron siguen a su orden. Las que
            // ya están en SIIGO se quedan como están: el documento de allá no
            // cambia porque acá se marque otra cosa.
            foreach ($orden->recepciones as $rc) {
                if ($rc->siigo_id) {
                    continue;
                }

                $rc->forceFill([
                    'destino_fiscal' => $destino,
                    'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : $motivo,
                ])->save();

                // El asiento se rehace para que las líneas queden del lado
                // correcto; si no, el balance sigue mostrando lo de antes.
                if ($rc->estado === 'confirmada') {
                    app(\App\Modules\Compras\Actions\RegistrarAsientoCompra::class)->recepcion($rc->fresh());
                }
            }
        });

        return back()->with('success', "Orden {$orden->numero} → {$destino->label()}.");
    }
}
