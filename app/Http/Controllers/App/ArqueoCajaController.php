<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Contabilidad\Models\Arqueo;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El día de una caja: lo que entró, lo que salió y qué hay en el cajón.
 *
 * Reemplaza la hoja por día del Excel «CAJA MENOR OCTUBRE 2026». La
 * estructura es la misma a propósito —ingresos, gastos con «entregado a»,
 * saldo esperado, conteo por denominación y conceptos del descuadre— para
 * que quien lo llena hoy a mano reconozca la pantalla.
 *
 * Lo único que cambia de fondo: acá el descuadre tiene que quedar explicado.
 */
class ArqueoCajaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'arqueo_caja'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $r, Caja $caja): Response
    {
        $fecha = $r->date('fecha')?->toDateString()
            ?? now('America/Bogota')->toDateString();

        $arqueo = Arqueo::with(['denominaciones', 'conceptos', 'realizadoPor:id,name'])
            ->where('caja_id', $caja->id)->whereDate('fecha', $fecha)->first();

        $movimientos = MovimientoCaja::with('autor:id,name')
            ->delDia($caja->id, $fecha)->orderBy('id')->get();

        $apertura = $arqueo
            ? (float) $arqueo->saldo_apertura
            : $this->saldoApertura($caja, $fecha);

        $ingresos = (float) $movimientos->where('tipo', 'ingreso')->sum('valor');
        $gastos = (float) $movimientos->where('tipo', 'gasto')->sum('valor');

        return Inertia::render('Contabilidad/ArqueoCaja', [
            'caja' => [
                'id' => $caja->id, 'codigo' => $caja->codigo, 'nombre' => $caja->nombre,
                'tipo' => $caja->tipo, 'se_arquea' => $caja->seArquea(),
                'destino_cierre' => $caja->destinoCierre
                    ? "{$caja->destinoCierre->codigo} · {$caja->destinoCierre->nombre}" : null,
            ],
            'fecha' => $fecha,
            'arqueo' => $arqueo ? [
                'id' => $arqueo->id,
                'numero' => $arqueo->numero,
                'estado' => $arqueo->estado,
                'saldo_contado' => (float) $arqueo->saldo_contado,
                'diferencia' => (float) $arqueo->diferencia,
                'resultado' => $arqueo->resultado,
                'observaciones' => $arqueo->observaciones,
                'realizado_por' => $arqueo->realizadoPor?->name,
                'cerrado_at' => $arqueo->cerrado_at?->format('Y-m-d H:i'),
                'denominaciones' => $arqueo->denominaciones
                    ->mapWithKeys(fn ($d) => ["{$d->especie}-{$d->denominacion}" => $d->cantidad]),
                'conceptos' => $arqueo->conceptos->map(fn ($c) => [
                    'concepto' => $c->concepto, 'valor' => (float) $c->valor,
                ]),
                'sin_explicar' => $arqueo->descuadreSinExplicar(),
            ] : null,
            'movimientos' => $movimientos->map(fn ($m) => [
                'id' => $m->id, 'tipo' => $m->tipo, 'descripcion' => $m->descripcion,
                'entregado_a' => $m->entregado_a, 'valor' => (float) $m->valor,
                'autor' => $m->autor?->name, 'en_arqueo' => (bool) $m->arqueo_id,
            ]),
            'totales' => [
                'apertura' => $apertura,
                'ingresos' => $ingresos,
                'gastos' => $gastos,
                'esperado' => round($apertura + $ingresos - $gastos, 2),
            ],
            'billetes' => Arqueo::BILLETES,
            'monedas' => Arqueo::MONEDAS,
            // A dónde se puede pasar plata desde esta caja.
            'destinos' => Caja::activas()->where('id', '!=', $caja->id)
                ->orderBy('codigo')->get(['id', 'codigo', 'nombre'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => "{$c->codigo} · {$c->nombre}"]),
        ]);
    }

    /**
     * Con cuánto abre el día.
     *
     * Es lo que contó el último arqueo cerrado antes de esta fecha; si la caja
     * nunca se arqueó, su saldo inicial. El Excel lo confirma: el 2 de octubre
     * abre con exactamente lo que cerró el 1 — la caja menor NO se vacía cada
     * día, el traslado a la general es una decisión aparte.
     */
    protected function saldoApertura(Caja $caja, string $fecha): float
    {
        $previo = Arqueo::where('caja_id', $caja->id)
            ->whereDate('fecha', '<', $fecha)
            ->whereIn('estado', ['cerrado', 'aprobado'])
            ->orderByDesc('fecha')->first();

        if ($previo) {
            return (float) $previo->saldo_contado;
        }

        $movidoAntes = (float) MovimientoCaja::where('caja_id', $caja->id)
            ->whereDate('fecha', '<', $fecha)
            ->get()->sum(fn ($m) => $m->efecto());

        return round((float) $caja->saldo_inicial + $movidoAntes, 2);
    }

    public function agregarMovimiento(Request $r, Caja $caja): RedirectResponse
    {
        // Anotar un gasto del dia es crear; no todo el que consulta la caja
        // deberia poder moverla.
        abort_unless(\App\Auth\Permisos::puedeAccion($r->user(), 'arqueo_caja', 'crear'), 403,
            'No tenes permiso para registrar movimientos de caja.');

        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'tipo' => ['required', Rule::in(['ingreso', 'gasto'])],
            'descripcion' => ['required', 'string', 'max:200'],
            // Sólo en los gastos, y ahí sí obligatorio: un gasto de caja sin
            // destinatario no se le puede cobrar a nadie ni pedirle soporte.
            'entregado_a' => ['nullable', 'required_if:tipo,gasto', 'string', 'max:120'],
            'valor' => ['required', 'numeric', 'gt:0'],
        ], [
            'entregado_a.required_if' => 'Hay que decir a quién se le entregó la plata.',
            'valor.gt' => 'El valor tiene que ser mayor que cero.',
        ]);

        if ($this->diaCerrado($caja, $data['fecha'])) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'El arqueo de ese día ya está cerrado. Para corregirlo hay que reabrirlo.']);
        }

        MovimientoCaja::create($data + ['caja_id' => $caja->id, 'user_id' => $r->user()?->id]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Movimiento registrado.']);
    }

    public function quitarMovimiento(Request $r, Caja $caja, MovimientoCaja $movimiento): RedirectResponse
    {
        abort_unless($movimiento->caja_id === $caja->id, 404);

        if ($movimiento->arqueo_id) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'Ese movimiento ya entró en un arqueo cerrado: no se puede quitar.']);
        }

        $movimiento->delete();

        return back()->with('flash', ['type' => 'success', 'message' => 'Movimiento quitado.']);
    }

    /**
     * Cerrar el día: contar la plata y cuadrar.
     *
     * El conteo llega por denominación, no como total, para que un error de
     * suma se pueda encontrar. Y el descuadre tiene que quedar explicado: es
     * la única diferencia de fondo con la hoja de cálculo, donde el 1 de
     * octubre quedaron $400 sin explicar y nadie los vio.
     */
    public function cerrar(Request $r, Caja $caja): RedirectResponse
    {
        // Cerrar el dia congela los movimientos: es la decision, no la captura.
        abort_unless(\App\Auth\Permisos::puedeAccion($r->user(), 'arqueo_caja', 'editar'), 403,
            'Cerrar el arqueo del dia necesita permiso de edicion.');

        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'conteo' => ['required', 'array'],
            'conteo.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'conceptos' => ['nullable', 'array', 'max:20'],
            'conceptos.*.concepto' => ['required', 'string', 'max:150'],
            'conceptos.*.valor' => ['required', 'numeric'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($this->diaCerrado($caja, $data['fecha'])) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Ese día ya está cerrado.']);
        }

        $movimientos = MovimientoCaja::delDia($caja->id, $data['fecha'])->get();
        $apertura = $this->saldoApertura($caja, $data['fecha']);
        $ingresos = (float) $movimientos->where('tipo', 'ingreso')->sum('valor');
        $gastos = (float) $movimientos->where('tipo', 'gasto')->sum('valor');
        $esperado = round($apertura + $ingresos - $gastos, 2);

        $contado = 0.0;
        foreach ($data['conteo'] as $clave => $cantidad) {
            [$especie, $denominacion] = array_pad(explode('-', (string) $clave), 2, null);
            if (! in_array($especie, ['billete', 'moneda'], true)) continue;
            $contado += ((int) $denominacion) * ((int) $cantidad);
        }

        $diferencia = round($contado - $esperado, 2);
        $explicado = round(collect($data['conceptos'] ?? [])->sum('valor'), 2);
        $sinExplicar = round($diferencia - $explicado, 2);

        if (abs($sinExplicar) >= 0.01) {
            return back()->withErrors([
                'conceptos' => 'Faltan $'.number_format(abs($sinExplicar), 0, ',', '.')
                    .' por explicar. Lo contado difiere de lo esperado en $'
                    .number_format(abs($diferencia), 0, ',', '.')
                    .' y los conceptos suman $'.number_format($explicado, 0, ',', '.').'.',
            ])->withInput();
        }

        DB::transaction(function () use ($caja, $data, $apertura, $ingresos, $gastos, $esperado, $contado, $diferencia, $r, $movimientos) {
            $arqueo = Arqueo::create([
                'numero' => Arqueo::siguienteNumero(new \DateTimeImmutable($data['fecha'])),
                'caja_id' => $caja->id,
                'fecha' => $data['fecha'],
                'saldo_apertura' => $apertura,
                'total_ingresos' => $ingresos,
                'total_gastos' => $gastos,
                'saldo_esperado' => $esperado,
                'saldo_contado' => $contado,
                'diferencia' => $diferencia,
                'resultado' => Arqueo::resultadoDe($diferencia),
                'estado' => 'cerrado',
                'cerrado_at' => now(),
                'realizado_por' => $r->user()?->id,
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            foreach ($data['conteo'] as $clave => $cantidad) {
                [$especie, $denominacion] = array_pad(explode('-', (string) $clave), 2, null);
                if (! in_array($especie, ['billete', 'moneda'], true) || ! (int) $cantidad) continue;

                $arqueo->denominaciones()->create([
                    'especie' => $especie,
                    'denominacion' => (int) $denominacion,
                    'cantidad' => (int) $cantidad,
                ]);
            }

            foreach ($data['conceptos'] ?? [] as $c) {
                $arqueo->conceptos()->create($c);
            }

            // Los movimientos del día quedan amarrados al arqueo: desde acá
            // ya no se pueden quitar sin reabrirlo.
            MovimientoCaja::whereIn('id', $movimientos->pluck('id'))->update(['arqueo_id' => $arqueo->id]);
        });

        return back()->with('flash', ['type' => 'success',
            'message' => 'Arqueo cerrado · '.Arqueo::resultadoDe($diferencia)
                .($diferencia != 0.0 ? ' por $'.number_format(abs($diferencia), 0, ',', '.').', explicado' : ''),
        ]);
    }

    /**
     * Pasar plata de una caja a otra.
     *
     * Son dos movimientos, no uno: una salida acá y una entrada allá, amarrados
     * entre sí. Si se registrara una sola punta, el arqueo de la caja que
     * recibe cerraría con un sobrante que nadie sabe explicar.
     *
     * El tope es el saldo esperado del día en la caja origen: trasladar más de
     * lo que hay deja la caja en negativo, que es un estado que no existe —
     * significa que alguien se equivocó al teclear y el error viaja a la otra
     * caja antes de que nadie lo note.
     */
    public function trasladar(Request $r, Caja $caja): RedirectResponse
    {
        abort_unless(\App\Auth\Permisos::puedeAccion($r->user(), 'arqueo_caja', 'editar'), 403,
            'Pasar plata entre cajas necesita permiso de edición.');

        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'destino_id' => ['required', 'integer', 'exists:cajas,id'],
            'valor' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ], ['valor.gt' => 'El valor tiene que ser mayor que cero.']);

        $destino = Caja::findOrFail($data['destino_id']);

        if ($destino->id === $caja->id) {
            return back()->withErrors(['destino_id' => 'No se puede trasladar a la misma caja.']);
        }

        if (! $destino->activa) {
            return back()->withErrors(['destino_id' => "«{$destino->nombre}» está inactiva."]);
        }

        if ($this->diaCerrado($caja, $data['fecha']) || $this->diaCerrado($destino, $data['fecha'])) {
            return back()->with('flash', ['type' => 'error',
                'message' => 'Alguna de las dos cajas ya cerró ese día. Hay que reabrirla para trasladar.']);
        }

        $disponible = $this->saldoDelDia($caja, $data['fecha']);
        if ((float) $data['valor'] > $disponible + 0.01) {
            return back()->withErrors([
                'valor' => 'En «'.$caja->nombre.'» hay $'.number_format($disponible, 0, ',', '.')
                    .' ese día: no se pueden trasladar $'.number_format((float) $data['valor'], 0, ',', '.').'.',
            ])->withInput();
        }

        $texto = trim($data['motivo'] ?? '') ?: 'Traslado de caja';

        DB::transaction(function () use ($caja, $destino, $data, $texto, $r) {
            $salida = MovimientoCaja::create([
                'caja_id' => $caja->id, 'fecha' => $data['fecha'], 'tipo' => 'gasto',
                'descripcion' => "{$texto} → {$destino->codigo} · {$destino->nombre}",
                'entregado_a' => $destino->nombre,
                'valor' => $data['valor'], 'user_id' => $r->user()?->id,
            ]);

            $entrada = MovimientoCaja::create([
                'caja_id' => $destino->id, 'fecha' => $data['fecha'], 'tipo' => 'ingreso',
                'descripcion' => "{$texto} ← {$caja->codigo} · {$caja->nombre}",
                'valor' => $data['valor'], 'user_id' => $r->user()?->id,
                'contraparte_id' => $salida->id,
            ]);

            $salida->update(['contraparte_id' => $entrada->id]);
        });

        return back()->with('flash', ['type' => 'success',
            'message' => 'Trasladados $'.number_format((float) $data['valor'], 0, ',', '.')
                ." a «{$destino->nombre}»."]);
    }

    /** Lo que hay en la caja ese día: apertura más lo que entró, menos lo que salió. */
    protected function saldoDelDia(Caja $caja, string $fecha): float
    {
        $movs = MovimientoCaja::delDia($caja->id, $fecha)->get();

        return round(
            $this->saldoApertura($caja, $fecha) + $movs->sum(fn ($m) => $m->efecto()),
            2,
        );
    }

    protected function diaCerrado(Caja $caja, string $fecha): bool
    {
        return Arqueo::where('caja_id', $caja->id)->whereDate('fecha', $fecha)
            ->whereIn('estado', ['cerrado', 'aprobado'])->exists();
    }
}
