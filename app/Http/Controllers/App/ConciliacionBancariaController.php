<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Actions\ConciliarExtracto;
use App\Modules\Contabilidad\Actions\LeerExtractoBancario;
use App\Modules\Contabilidad\Actions\LeerExtractoDeArchivo;
use App\Modules\Contabilidad\Models\ConciliacionBancaria;
use App\Modules\Contabilidad\Models\ConciliacionLinea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Conciliar el extracto del banco contra los pagos del ERP.
 *
 * `conciliaciones_bancarias` existía desde septiembre —con su saldo de
 * extracto, su saldo de sistema y la resta de los dos— y nunca se enganchó a
 * una ruta: el modelo no lo nombraba ningún controlador. Pero aun enganchado
 * habría servido a medias, porque decir que sobran cuatro millones no ayuda a
 * encontrar CUÁL movimiento sobra. Eso se hacía a mano, con el extracto
 * impreso al lado.
 *
 * Acá se pega el extracto tal como lo entrega el banco y queda en tres
 * montones: lo que casó con un pago, lo que sólo está en el banco (ahí viven
 * el 4x1000 y la cuota de manejo, que son gastos que faltan asentar) y lo que
 * sólo está en el ERP (un recibo mal digitado, un cheque sin cobrar).
 *
 * Previsualizar NO guarda. Un extracto mal leído metería cuatrocientas líneas
 * falsas en la contabilidad, así que primero se muestra, con la comprobación
 * del propio extracto a la vista, y se guarda después.
 */
class ConciliacionBancariaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                /*
                 * Va bajo el acceso de `contabilidad` y no bajo uno nuevo.
                 *
                 * `PermisosSeeder` sólo reparte los permisos que nacen en su
                 * propia corrida, así que una sección nueva exige acordarse de
                 * correrlo en el despliegue —y el 2026-10-29 eso dejó a los
                 * siete roles de producción sin accesos durante semanas.
                 */
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'contabilidad'), 403,
                    'La conciliación bancaria la lleva contabilidad.');

                return $next($r);
            }),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Contabilidad/ConciliacionBancaria/Index', [
            'conciliaciones' => ConciliacionBancaria::query()
                ->withCount([
                    'lineas',
                    'lineas as conciliadas_count' => fn ($q) => $q->where('estado', 'conciliada'),
                ])
                ->with('usuario:id,name')
                ->orderByDesc('periodo_hasta')->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (ConciliacionBancaria $c) => [
                    'id' => $c->id,
                    'banco' => $c->banco,
                    'cuenta' => $c->cuenta_numero,
                    'desde' => $c->periodo_desde?->toDateString(),
                    'hasta' => $c->periodo_hasta?->toDateString(),
                    'saldo_extracto' => (float) $c->saldo_extracto,
                    'lineas' => $c->lineas_count,
                    'conciliadas' => $c->conciliadas_count,
                    'estado' => $c->estado,
                    'cuadra' => $c->cuadraConsigoMisma(),
                    'quien' => $c->usuario?->name,
                    'cuando' => $c->created_at?->toDateTimeString(),
                ]),
        ]);
    }

    /**
     * Lee el extracto pegado y lo cruza, sin guardar nada.
     *
     * Se devuelve también la comprobación del propio extracto —saldo anterior
     * + abonos − cargos = saldo actual— porque antes de buscar diferencias
     * contra el ERP hay que estar seguro de haber leído bien el banco.
     */
    public function previsualizar(Request $r)
    {
        $r->validate([
            'texto' => ['required_without:archivo', 'nullable', 'string', 'min:40', 'max:2000000'],
            'archivo' => $this->reglasDelArchivo(),
            'banco' => ['nullable', 'string', 'max:120'],
        ]);

        $extracto = LeerExtractoBancario::run($this->textoDelExtracto($r));

        if (! $extracto['movimientos']) {
            return response()->json([
                'error' => 'No se reconoció ningún movimiento. Subí el archivo tal como lo baja '
                    .'el banco, o pegá el extracto completo con el encabezado que trae las '
                    .'fechas y los saldos.',
            ], 422);
        }

        $cruce = ConciliarExtracto::run($extracto['movimientos'], null, $extracto['desde'], $extracto['hasta']);

        return response()->json([
            'extracto' => collect($extracto)->except('movimientos'),
            'cruce' => $cruce,
            'conciliados' => $cruce['conciliados'],
            'solo_banco' => $cruce['solo_banco'],
            'solo_erp' => $cruce['solo_erp'],
        ]);
    }

    /**
     * Guarda la conciliación con sus líneas.
     *
     * Se vuelve a leer y a cruzar en el servidor en vez de confiar en lo que
     * manda la pantalla: lo que llega del navegador se puede editar, y esto
     * termina siendo un asiento contable.
     */
    public function guardar(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'texto' => ['required_without:archivo', 'nullable', 'string', 'min:40', 'max:2000000'],
            'archivo' => $this->reglasDelArchivo(),
            'banco' => ['required', 'string', 'max:120'],
            'cuenta_puc' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * Se vuelve a leer en el servidor, también cuando vino un archivo.
         *
         * Lo que la pantalla muestra en la previsualización es informativo: lo
         * que se guarda se arma acá otra vez, desde el archivo o el texto que
         * llegó en ESTA petición. Si se guardara lo que manda el navegador,
         * bastaría con cambiarlo en el camino para asentar una conciliación
         * que nunca existió.
         */
        $extracto = LeerExtractoBancario::run($this->textoDelExtracto($r));

        if (! $extracto['movimientos']) {
            return back()->with('error', 'No se reconoció ningún movimiento en lo que subiste.');
        }

        /*
         * Un extracto que no cuadra consigo mismo no se guarda.
         *
         * Si saldo anterior + abonos − cargos no da el saldo actual, se perdió
         * alguna línea al leer. Guardar igual metería una conciliación que
         * parece completa y no lo está, y eso es peor que no tener ninguna.
         */
        if ($extracto['verificable'] && ! $extracto['cuadra']) {
            return back()->with('error', sprintf(
                'El extracto no cuadra consigo mismo: hay una diferencia de $%s entre el saldo '
                .'actual y el que dan los movimientos. Revisá que lo hayas pegado completo.',
                number_format(abs((float) $extracto['descuadre']), 2, ',', '.')
            ));
        }

        $cruce = ConciliarExtracto::run($extracto['movimientos'], null, $extracto['desde'], $extracto['hasta']);

        $conciliacion = DB::transaction(function () use ($data, $extracto, $cruce, $r) {
            $c = ConciliacionBancaria::create([
                'fecha' => $extracto['hasta'] ?? now()->toDateString(),
                'periodo_desde' => $extracto['desde'],
                'periodo_hasta' => $extracto['hasta'],
                'banco' => $data['banco'],
                'cuenta_numero' => $extracto['cuenta'],
                // `nullable` quiere decir que la llave puede no venir, no que
                // venga en null: sin el `??` esto es un 500 cuando el campo se
                // omite. 1110 es la cuenta de bancos del PUC.
                'cuenta_puc' => ($data['cuenta_puc'] ?? null) ?: '1110',
                'saldo_anterior' => $extracto['saldo_anterior'],
                'total_abonos' => $extracto['total_abonos'],
                'total_cargos' => $extracto['total_cargos'],
                'saldo_extracto' => $extracto['saldo_actual'] ?? 0,
                'saldo_sistema' => 0,
                'estado' => 'pendiente',
                'notas' => $data['notas'] ?? null,
                'user_id' => $r->user()?->id,
            ]);

            $filas = [];

            foreach ($cruce['conciliados'] as $l) {
                $filas[] = $this->fila($c->id, $l, 'conciliada', $l['pago_tipo'], $l['pago_id'], null);
            }

            foreach ($cruce['solo_banco'] as $l) {
                $filas[] = $this->fila($c->id, $l, 'solo_banco', null, null,
                    $l['cuenta_sugerida']['cuenta'] ?? null);
            }

            /*
             * Lo que sólo está en el ERP también se guarda como línea.
             *
             * No viene del extracto —no tiene saldo corrido ni descripción del
             * banco— pero es la mitad del trabajo: son los pagos registrados
             * que el banco nunca vio. Dejarlos fuera haría que la conciliación
             * guardada mintiera por omisión.
             */
            foreach ($cruce['solo_erp'] as $l) {
                $filas[] = [
                    'conciliacion_id' => $c->id,
                    'fecha' => $l['fecha'],
                    'descripcion' => mb_substr('Sólo en el ERP · '.($l['tercero'] ?: 'sin tercero')
                        .($l['referencia'] ? ' · '.$l['referencia'] : ''), 0, 255),
                    'valor' => $l['valor'],
                    'saldo' => null,
                    'estado' => 'solo_erp',
                    'pago_tipo' => $l['pago_tipo'],
                    'pago_id' => $l['pago_id'],
                    'cuenta_puc' => null,
                    'nota' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }

            // De a mil, que son cuatrocientas líneas por extracto mensual y un
            // insert por fila deja la transacción abierta demasiado tiempo.
            foreach (array_chunk($filas, 1000) as $lote) {
                ConciliacionLinea::insert($lote);
            }

            return $c;
        });

        return redirect("/app/contabilidad/conciliacion-bancaria/{$conciliacion->id}")
            ->with('success', sprintf(
                'Conciliación guardada: %d conciliadas, %d sólo en el banco, %d sólo en el ERP.',
                $cruce['resumen']['conciliados'],
                $cruce['resumen']['solo_banco'],
                $cruce['resumen']['solo_erp'],
            ));
    }

    public function show(ConciliacionBancaria $conciliacion): Response
    {
        return Inertia::render('Contabilidad/ConciliacionBancaria/Show', [
            'conciliacion' => [
                'id' => $conciliacion->id,
                'banco' => $conciliacion->banco,
                'cuenta' => $conciliacion->cuenta_numero,
                'cuenta_puc' => $conciliacion->cuenta_puc,
                'desde' => $conciliacion->periodo_desde?->toDateString(),
                'hasta' => $conciliacion->periodo_hasta?->toDateString(),
                'saldo_anterior' => (float) $conciliacion->saldo_anterior,
                'total_abonos' => (float) $conciliacion->total_abonos,
                'total_cargos' => (float) $conciliacion->total_cargos,
                'saldo_extracto' => (float) $conciliacion->saldo_extracto,
                'cuadra' => $conciliacion->cuadraConsigoMisma(),
                'sin_explicar' => $conciliacion->sinExplicar(),
                'estado' => $conciliacion->estado,
                'notas' => $conciliacion->notas,
                'quien' => $conciliacion->usuario?->name,
            ],
            'lineas' => $conciliacion->lineas()
                ->orderBy('fecha')->orderBy('id')
                ->get()
                ->map(fn (ConciliacionLinea $l) => [
                    'id' => $l->id,
                    'fecha' => $l->fecha?->toDateString(),
                    'descripcion' => $l->descripcion,
                    'valor' => (float) $l->valor,
                    'estado' => $l->estado,
                    'cuenta_puc' => $l->cuenta_puc,
                    'pago_tipo' => $l->pago_tipo,
                    'pago_id' => $l->pago_id,
                    'nota' => $l->nota,
                ]),
        ]);
    }

    /** Asignarle cuenta a una línea que el banco cobró y nadie registró. */
    public function marcarLinea(Request $r, ConciliacionBancaria $conciliacion, ConciliacionLinea $linea): RedirectResponse
    {
        abort_unless($linea->conciliacion_id === $conciliacion->id, 404);

        $data = $r->validate([
            'cuenta_puc' => ['nullable', 'string', 'max:20'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);

        // La misma trampa que el propio controlador advierte más arriba:
        // `nullable` quiere decir que la llave puede NO VENIR. Sin el `??`,
        // marcar sólo la nota —o sólo la cuenta— es un 500.
        $linea->update([
            'cuenta_puc' => ($data['cuenta_puc'] ?? null) ?: null,
            'nota' => ($data['nota'] ?? null) ?: null,
        ]);

        return back()->with('success', 'Línea actualizada.');
    }

    private function fila(int $conciliacionId, array $l, string $estado, ?string $pagoTipo, ?int $pagoId, ?string $cuenta): array
    {
        return [
            'conciliacion_id' => $conciliacionId,
            'fecha' => $l['fecha'],
            'descripcion' => mb_substr($l['descripcion'], 0, 255),
            'valor' => $l['valor'],
            'saldo' => $l['saldo'] ?? null,
            'estado' => $estado,
            'pago_tipo' => $pagoTipo,
            'pago_id' => $pagoId,
            'cuenta_puc' => $cuenta,
            'nota' => null,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /**
     * Qué se acepta subir.
     *
     * El PDF es lo que baja del portal del banco y es lo que va a usar la
     * gente. El Excel y el CSV están porque algunos bancos sólo ofrecen eso, y
     * el .txt porque sigue siendo la salida más vieja y más confiable.
     *
     * El tope de tamaño no es tacañería: un extracto de un mes pesa menos de
     * un mega, y lo que llega con veinte es otra cosa.
     */
    private function reglasDelArchivo(): array
    {
        return [
            'nullable',
            'file',
            'mimes:pdf,xlsx,xls,ods,csv,txt',
            'max:'.(LeerExtractoDeArchivo::MAX_MB * 1024),
        ];
    }

    /**
     * El texto del extracto, venga de donde venga.
     *
     * El archivo manda sobre el texto: si alguien sube uno, es porque quiere
     * conciliar ÉSE, no lo que quedó pegado en la casilla de antes.
     */
    private function textoDelExtracto(Request $r): string
    {
        return $r->hasFile('archivo')
            ? LeerExtractoDeArchivo::run($r->file('archivo'))
            : (string) $r->input('texto');
    }
}
