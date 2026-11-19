<?php

namespace App\Modules\Tesoreria\Services;

use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use App\Modules\Gerencia\Models\GastoOperativo;
use App\Modules\Rrhh\Models\Nomina;
use App\Modules\Tesoreria\Models\FlujoConcepto;
use App\Modules\Tesoreria\Models\FlujoProyeccion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El flujo de caja: proyección contra realidad.
 *
 * La proyección la carga cada área. El REAL lo calcula el ERP, que es el
 * punto entero del ejercicio: hoy esa columna se teclea cada semana copiando
 * de otros archivos, y de ahí sale que el resumen del mes de octubre muestre
 * «mayor descuadre de caja: $106.054.462». Una cifra transcrita a mano cada
 * siete días no puede ser confiable; una calculada, sí.
 *
 * Los períodos son semanas (lunes a domingo) o meses. Se guarda siempre el
 * primer día, nunca un rango.
 */
class FlujoDeCaja
{
    /**
     * La grilla completa: conceptos por período, con proyectado y real.
     *
     * @return array{periodos: list<array{inicio:string, fin:string, etiqueta:string}>, grupos: list<array>}
     */
    public function grilla(string $tipo, Carbon $desde, int $cuantos): array
    {
        $periodos = $this->periodos($tipo, $desde, $cuantos);

        $conceptos = FlujoConcepto::activos()->with('responsable:id,name')
            ->orderBy('grupo')->orderBy('orden')->get();

        $proyecciones = FlujoProyeccion::query()
            ->where('periodo_tipo', $tipo)
            ->whereIn('periodo', collect($periodos)->pluck('inicio'))
            ->get()
            ->keyBy(fn ($p) => $p->concepto_id.'|'.$p->periodo->toDateString());

        // El real se calcula de una por período, no por concepto: una
        // consulta por celda son cientos en una pantalla de tres meses.
        $reales = [];
        foreach ($periodos as $p) {
            $reales[$p['inicio']] = $this->realesDelPeriodo(
                Carbon::parse($p['inicio']), Carbon::parse($p['fin'])
            );
        }

        $grupos = collect(FlujoConcepto::GRUPOS)
            ->map(function ($etiqueta, $clave) use ($conceptos, $periodos, $proyecciones, $reales) {
                $delGrupo = $conceptos->where('grupo', $clave);

                return [
                    'clave' => $clave,
                    'etiqueta' => $etiqueta,
                    'conceptos' => $delGrupo->map(fn (FlujoConcepto $c) => [
                        'id' => $c->id,
                        'nombre' => $c->nombre,
                        'tipo' => $c->tipo,
                        'responsable' => $c->responsable?->name,
                        'valor_fijo_mensual' => $c->valor_fijo_mensual !== null
                            ? (float) $c->valor_fijo_mensual : null,
                        'origen' => $c->origen,
                        'calculado' => $c->origen !== null,
                        'celdas' => collect($periodos)->map(function ($p) use ($c, $proyecciones, $reales) {
                            $pr = $proyecciones[$c->id.'|'.$p['inicio']] ?? null;

                            return [
                                'periodo' => $p['inicio'],
                                'proyectado' => $pr ? (float) $pr->monto : 0.0,
                                // Con origen manda el cálculo; sin origen, lo
                                // que alguien haya tecleado.
                                'real' => $c->origen
                                    ? (float) ($reales[$p['inicio']][$c->origen] ?? 0)
                                    : (float) ($pr?->real_manual ?? 0),
                            ];
                        })->all(),
                    ])->values()->all(),
                ];
            })
            ->values()->all();

        return ['periodos' => $periodos, 'grupos' => $grupos];
    }

    /** Los períodos de la pantalla, con su etiqueta legible. */
    public function periodos(string $tipo, Carbon $desde, int $cuantos): array
    {
        $out = [];
        $cursor = $tipo === 'mes' ? $desde->copy()->startOfMonth() : $desde->copy()->startOfWeek();

        for ($i = 0; $i < $cuantos; $i++) {
            $fin = $tipo === 'mes' ? $cursor->copy()->endOfMonth() : $cursor->copy()->endOfWeek();

            $out[] = [
                'inicio' => $cursor->toDateString(),
                'fin' => $fin->toDateString(),
                'etiqueta' => $tipo === 'mes'
                    ? ucfirst($cursor->locale('es')->isoFormat('MMMM YYYY'))
                    : $cursor->isoFormat('D MMM'),
            ];

            $cursor = $tipo === 'mes' ? $cursor->addMonth() : $cursor->addWeek();
        }

        return $out;
    }

    /**
     * Lo que de verdad pasó en ese período, por origen.
     *
     * Son cinco consultas por período y no una por celda: una pantalla de
     * trece semanas con veinte conceptos serían doscientas sesenta consultas,
     * y la pantalla se arrastraría hasta volverse inusable.
     *
     * @return array<string, float>
     */
    public function realesDelPeriodo(Carbon $desde, Carbon $hasta): array
    {
        $d = $desde->toDateString();
        $h = $hasta->toDateString();

        return [
            'recaudo_cartera' => (float) PagoVenta::whereBetween('fecha', [$d, $h])->sum('monto_recibido'),

            // Un pago anulado no salió de la caja: contarlo mostraría una
            // salida de plata que nunca ocurrió.
            'pagos_proveedor' => (float) PagoProveedor::whereBetween('fecha', [$d, $h])
                ->whereNull('anulado_at')->sum('monto_neto'),

            // La nómina pesa en el flujo el día que se CONSIGNA, no cuando se
            // liquida: entre una cosa y la otra pueden pasar semanas.
            'nomina' => (float) Nomina::whereNotNull('pagada_at')
                ->whereBetween('fecha_pago', [$d, $h])->sum('total_neto'),

            'gastos_caja' => (float) GastoOperativo::where('estado', 'pagado')
                ->whereBetween('fecha', [$d, $h])->sum('monto'),

            // Neto de la caja: lo que entró menos lo que salió.
            'movimientos_caja' => (float) MovimientoCaja::whereBetween('fecha', [$d, $h])
                ->get()->sum(fn ($m) => $m->efecto()),
        ];
    }

    /**
     * El resumen que encabeza la hoja: proyectado contra ejecutado.
     *
     * Los egresos se restan acá y no en la captura, para que nadie tenga que
     * acordarse de escribir un número en negativo.
     */
    public function resumen(array $grilla): array
    {
        $acumular = function (string $campo) use ($grilla): float {
            $total = 0.0;
            foreach ($grilla['grupos'] as $g) {
                if ($g['clave'] === 'saldo_inicial') continue;   // no es flujo, es foto
                $signo = str_starts_with($g['clave'], 'egreso') || str_starts_with($g['clave'], 'otro_egreso') ? -1 : 1;

                foreach ($g['conceptos'] as $c) {
                    foreach ($c['celdas'] as $celda) {
                        $total += $signo * $celda[$campo];
                    }
                }
            }

            return round($total, 2);
        };

        $proyectado = $acumular('proyectado');
        $real = $acumular('real');

        return [
            'proyectado' => $proyectado,
            'real' => $real,
            // Negativo = entró menos de lo que se esperaba. Es el número que
            // hoy nadie mira hasta fin de mes.
            'desviacion' => round($real - $proyectado, 2),
        ];
    }

    /** Los conceptos que una persona tiene que informar. */
    public function pendientesDe(int $userId, string $tipo, Carbon $desde, int $cuantos): Collection
    {
        $periodos = collect($this->periodos($tipo, $desde, $cuantos))->pluck('inicio');

        $mios = FlujoConcepto::activos()->where('responsable_id', $userId)->get();

        $cargados = FlujoProyeccion::whereIn('concepto_id', $mios->pluck('id'))
            ->where('periodo_tipo', $tipo)->whereIn('periodo', $periodos)
            ->get()->keyBy(fn ($p) => $p->concepto_id.'|'.$p->periodo->toDateString());

        return $mios->flatMap(fn (FlujoConcepto $c) => $periodos
            ->reject(fn ($p) => isset($cargados[$c->id.'|'.$p]))
            ->map(fn ($p) => ['concepto' => $c->nombre, 'periodo' => $p]));
    }
}
