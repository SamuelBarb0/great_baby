<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Enums\ClasificacionDiferencia;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Support\FiltroEjes;
use App\Support\ExportadorCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PagosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // La casilla manda. Antes la puerta preguntaba por el rol y la
                //   casilla de /app/roles no la consultaba nadie: el admin creia
                //   repartir accesos y solo movia lo que se ve en el menu.
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'pagos'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        $medio = (string) $request->input('medio', 'todos');
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');

        $ejes = FiltroEjes::deRequest($request);

        $query = $this->consultaDePagos($q, $medio, $desde, $hasta, $ejes)
            ->with(['factura:id,numero,contacto_id,linea_negocio,destino_fiscal', 'factura.contacto:id,nombre_completo', 'registrador:id,name']);

        $paginado = $query->orderByDesc('fecha')->orderByDesc('id')->paginate(30);

        $totalAplicado = (float) (clone $query)->sum('monto_aplicado');
        $totalRecibido = (float) (clone $query)->sum('monto_recibido');

        return Inertia::render('Cartera/Pagos/Index', [
            'pagos' => $paginado->through(fn ($p) => [
                'id' => $p->id,
                'fecha' => $p->fecha?->toDateString(),
                'factura_id' => $p->factura?->id,
                'factura_numero' => $p->factura?->numero,
                'cliente' => $p->factura?->contacto?->nombre_completo,
                'medio_pago' => $p->medio_pago,
                'monto_recibido' => (float) $p->monto_recibido,
                'monto_aplicado' => (float) $p->monto_aplicado,
                'diferencia' => (float) $p->diferencia,
                'clasificacion' => $p->clasificacion_diferencia,
                'referencia' => $p->referencia,
                'banco' => $p->banco,
                'registrado_por' => $p->registrador?->name,
                // Comprobante de SIIGO (RC-1-2453). Se guardaba y no se
                // mostraba en ningún lado, así que no había forma de saber si
                // el cobro llegó a la contabilidad ni con qué número.
                'siigo_numero' => $p->siigo_number,
                'en_siigo' => (bool) $p->siigo_id,
                // Los ejes salen de la FACTURA: un pago no tiene destino
                // propio, y dárselo abriría la puerta a un pago «fiscal» de
                // una factura interna, que no significa nada.
                'destino' => $p->factura?->destino_fiscal?->label() ?? '—',
                'destino_color' => $p->factura?->destino_fiscal?->color() ?? 'gray',
                'linea' => $p->factura?->linea_negocio?->label() ?? '—',
            ])->withQueryString(),
            'filtros' => array_merge(
                ['q' => $q, 'medio' => $medio, 'desde' => $desde, 'hasta' => $hasta],
                $ejes->aParametros(),
            ),
            'opcionesEjes' => FiltroEjes::opciones(),
            'totales' => [
                'aplicado' => $totalAplicado,
                'recibido' => $totalRecibido,
                'count' => $paginado->total(),
            ],
            'mediosPago' => PagoVenta::distinct()->orderBy('medio_pago')->pluck('medio_pago')->filter()->values()->all(),
            'clasificaciones' => collect(ClasificacionDiferencia::cases())
                ->map(fn ($c) => ['valor' => $c->value, 'label' => $c->label()])->all(),
            // Catálogo real de métodos (lo mantiene /app/cartera/metodos-pago).
            // Antes el formulario traía una lista fija en el Vue, así que
            // agregar un método en el maestro no servía de nada.
            'metodos' => \App\Modules\Cartera\Models\MetodoPago::where('activo', true)
                ->orderBy('orden')->orderBy('nombre')
                ->get(['codigo', 'nombre', 'requiere_referencia', 'requiere_banco'])
                ->map(fn ($m) => [
                    'codigo' => $m->codigo,
                    'nombre' => $m->nombre,
                    'pide_referencia' => (bool) $m->requiere_referencia,
                    'pide_banco' => (bool) $m->requiere_banco,
                ])->values(),
        ]);
    }

    /**
     * Facturas con saldo, para elegir a cuál se aplica el pago.
     * Devuelve el saldo vivo para que el formulario no deje aplicar de más.
     */
    public function facturasPendientes(Request $r): JsonResponse
    {
        $q = trim((string) $r->input('q', ''));

        $facturas = FacturaVenta::query()
            // `numero_documento` lo usa la pantalla de asientos manuales para
            // llenar el tercero de la línea sin que nadie lo teclee.
            ->with('contacto:id,nombre_completo,razon_social,numero_documento')
            ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
            ->where('saldo', '>', 0)
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('numero', 'like', "%{$q}%")
                    ->orWhereHas('contacto', fn ($c) => $c
                        ->where('nombre_completo', 'like', "%{$q}%")
                        ->orWhere('razon_social', 'like', "%{$q}%")
                        ->orWhere('numero_documento', 'like', "%{$q}%"));
            }))
            ->orderBy('fecha_vencimiento')
            ->limit(25)
            ->get(['id', 'numero', 'contacto_id', 'fecha_emision', 'fecha_vencimiento', 'total', 'saldo',
                   'destino_fiscal', 'monto_fiscal', 'linea_negocio']);

        return response()->json($facturas->map(fn ($f) => [
            'id' => $f->id,
            'numero' => $f->numero,
            'cliente' => $f->contacto?->razon_social ?: $f->contacto?->nombre_completo,
            'nit' => $f->contacto?->numero_documento,
            'emision' => $f->fecha_emision?->toDateString(),
            'vence' => $f->fecha_vencimiento?->toDateString(),
            'total' => (float) $f->total,
            'saldo' => (float) $f->saldo,
            /*
             * A donde va el pago. No se elige al pagar —se decidio al emitir la
             * factura— pero tiene que VERSE: sin esto se registra un abono sin
             * saber si entra al libro fiscal o no, que es justo lo que hay que
             * saber antes de darle al boton.
             */
            'destino' => ($f->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->value,
            'destino_label' => ($f->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->label(),
            'destino_color' => ($f->destino_fiscal ?? \App\Modules\Contabilidad\Enums\DestinoFiscal::Siigo)->color(),
            'linea_label' => ($f->linea_negocio ?? \App\Modules\Contabilidad\Enums\LineaNegocio::GreatBaby)->label(),
            // Cuanto de la factura se declara y cuanto de eso YA cubrieron los
            // abonos anteriores: con eso la pantalla puede mostrar como se va a
            // partir este pago antes de registrarlo.
            'monto_fiscal' => $f->monto_fiscal !== null ? (float) $f->monto_fiscal : null,
            'declarado_cubierto' => (float) \App\Modules\Cartera\Models\PagoVenta::where('factura_id', $f->id)->sum('monto_aplicado'),
        ]));
    }

    /**
     * Registra un pago recibido de un cliente.
     *
     * Esto sólo existía en el panel Filament. Al dejar `/admin` para Dropi, el
     * ERP se quedaba sin forma de registrar plata entrando, que es de lo más
     * usado todos los días.
     *
     * El saldo de la factura y el asiento a SIIGO los maneja `PagoVentaObserver`
     * (llama `factura->recalcular()` y empuja el recibo), así que acá sólo se
     * valida y se crea.
     */
    public function guardar(Request $r): RedirectResponse
    {
        $datos = $r->validate([
            'factura_id' => ['required', 'integer', 'exists:facturas_venta,id'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'monto_recibido' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'monto_aplicado' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'medio_pago' => ['required', 'string', 'max:40'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'banco' => ['nullable', 'string', 'max:120'],
            'clasificacion_diferencia' => ['nullable', 'string',
                \Illuminate\Validation\Rule::in(array_column(ClasificacionDiferencia::cases(), 'value'))],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'fecha.before_or_equal' => 'No se puede registrar un pago con fecha futura.',
        ]);

        $pago = DB::transaction(function () use ($datos, $r) {
            // Bloqueo de fila: dos personas registrando el mismo pago al tiempo
            // dejarían la factura sobrepagada sin que nadie se diera cuenta.
            $factura = FacturaVenta::whereKey($datos['factura_id'])->lockForUpdate()->firstOrFail();

            abort_if($factura->estado === EstadoFactura::Anulada, 422,
                'Esa factura está anulada: no se le pueden aplicar pagos.');

            $aplicado = round((float) $datos['monto_aplicado'], 2);
            abort_if($aplicado > (float) $factura->saldo + 0.01, 422,
                'Estás aplicando $'.number_format($aplicado, 0).' a una factura que debe $'
                .number_format((float) $factura->saldo, 0).'. Bajá el monto aplicado.');

            $recibido = round((float) $datos['monto_recibido'], 2);
            $diferencia = round($recibido - $aplicado, 2);

            return PagoVenta::create([
                'factura_id' => $factura->id,
                'contacto_id' => $factura->contacto_id,
                'fecha' => $datos['fecha'],
                'monto_recibido' => $recibido,
                'monto_aplicado' => $aplicado,
                'diferencia' => $diferencia,
                'clasificacion_diferencia' => abs($diferencia) < 0.01
                    ? null
                    : ($datos['clasificacion_diferencia'] ?? ClasificacionDiferencia::NoIdentificado->value),
                'medio_pago' => $datos['medio_pago'],
                'referencia' => $datos['referencia'] ?? null,
                'banco' => $datos['banco'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'registrado_por' => $r->user()->id,
            ]);
        });

        $factura = $pago->factura()->first();

        return back()->with('success',
            'Pago registrado en '.$factura->numero.'. Saldo ahora: $'
            .number_format((float) $factura->saldo, 0, ',', '.').'.');
    }

    /**
     * La consulta de pagos filtrada. La usan la pantalla y el export, para que
     * no exista la posibilidad de que el archivo traiga algo distinto de lo que
     * se está viendo.
     */
    private function consultaDePagos(
        string $q, string $medio, ?string $desde, ?string $hasta, FiltroEjes $ejes
    ): \Illuminate\Database\Eloquent\Builder {
        return PagoVenta::query()
            ->when($q !== '', fn ($query) => $query->where(function ($qq) use ($q) {
                $qq->where('referencia', 'like', "%{$q}%")
                    ->orWhere('banco', 'like', "%{$q}%")
                    ->orWhereHas('factura', fn ($f) => $f->where('numero', 'like', "%{$q}%"))
                    ->orWhereHas('factura.contacto', fn ($c) => $c->where('nombre_completo', 'like', "%{$q}%"));
            }))
            ->when($medio !== 'todos', fn ($query) => $query->where('medio_pago', $medio))
            ->when($desde, fn ($query) => $query->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('fecha', '<=', $hasta))
            // Los ejes viven en la factura, no en el pago.
            ->tap(fn ($query) => $ejes->aplicarPor($query, 'factura'));
    }

    /**
     * F2c · Bajar los pagos tal como se están viendo.
     *
     * Incluye la diferencia y su clasificación, que es lo que se concilia
     * contra el extracto del banco: sin eso el export no sirve para la única
     * tarea para la que alguien lo baja.
     */
    public function exportar(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $q = trim((string) $request->input('q', ''));
        $medio = (string) $request->input('medio', 'todos');
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');
        $ejes = FiltroEjes::deRequest($request);

        $consulta = $this->consultaDePagos($q, $medio, $desde, $hasta, $ejes)
            ->with(['factura:id,numero,contacto_id,linea_negocio,destino_fiscal', 'factura.contacto:id,nombre_completo', 'registrador:id,name'])
            ->orderBy('fecha')->orderBy('id');

        $totales = (clone $consulta)->reorder()
            ->selectRaw('COALESCE(SUM(monto_recibido),0) r, COALESCE(SUM(monto_aplicado),0) a, COUNT(*) n')->first();

        return ExportadorCsv::descargar(
            'pagos-recibidos',
            ['Fecha', 'Factura', 'Cliente', 'Línea', 'Destino', 'Medio', 'Recibido', 'Aplicado',
             'Diferencia', 'Clasificación', 'Referencia', 'Banco', 'Recibo SIIGO', 'Registrado por'],
            $consulta,
            fn ($p) => [
                $p->fecha?->toDateString(),
                $p->factura?->numero,
                $p->factura?->contacto?->nombre_completo,
                $p->factura?->linea_negocio?->label(),
                $p->factura?->destino_fiscal?->label(),
                $p->medio_pago,
                (float) $p->monto_recibido,
                (float) $p->monto_aplicado,
                (float) $p->diferencia,
                $p->clasificacion_diferencia,
                $p->referencia,
                $p->banco,
                $p->siigo_number,
                $p->registrador?->name,
            ],
            [
                '',
                'Rango: '.($desde ?: 'sin límite').' a '.($hasta ?: 'sin límite'),
                'Filtro: '.$ejes->descripcion().($medio !== 'todos' ? " · medio {$medio}" : '')
                    .($q !== '' ? " · búsqueda «{$q}»" : ''),
                'Recibido: '.number_format((float) ($totales->r ?? 0), 2, ',', '.'),
                'Aplicado: '.number_format((float) ($totales->a ?? 0), 2, ',', '.'),
                'Pagos: '.number_format((int) ($totales->n ?? 0), 0, ',', '.'),
            ],
        );
    }
}
