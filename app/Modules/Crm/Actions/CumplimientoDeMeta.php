<?php

namespace App\Modules\Crm\Actions;

use App\Models\MetaVendedor;
use App\Services\SeguimientoVendedorService;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Cómo va un vendedor contra su meta, y si el ritmo le alcanza.
 *
 * El panel mostraba lo vendido y nada contra qué compararlo: «$4.120.000 este
 * mes» no dice si va bien o mal. El vendedor se enteraba en la reunión de fin
 * de mes, que es cuando ya no se puede hacer nada.
 *
 * Lo que de verdad sirve no es el porcentaje cumplido sino el RITMO. Ir al 60%
 * de la meta no significa lo mismo el día 12 que el día 28, y es la diferencia
 * entre «salí a buscar dos millones» y «este mes ya se perdió». Por eso acá se
 * calcula también cuánto hay que vender por día hábil restante.
 *
 * La venta se toma del mismo servicio que alimenta el panel —facturado +
 * despachado, sobre `facturado_at`—, no de una consulta propia: dos
 * definiciones de «lo vendido» terminarían dando números distintos en dos
 * pantallas que están una al lado de la otra.
 */
class CumplimientoDeMeta
{
    use AsAction;

    public function handle(int $vendedorId, ?int $anio = null, ?int $mes = null): array
    {
        $hoy = now();
        $anio ??= (int) $hoy->year;
        $mes ??= (int) $hoy->month;

        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth();
        $fin = (clone $inicio)->endOfMonth();

        $vendido = (float) app(SeguimientoVendedorService::class)
            ->resumenVendedor($vendedorId, $inicio, $fin)['total_ventas'];

        $meta = MetaVendedor::para($vendedorId, $anio, $mes);
        $montoMeta = (float) ($meta?->monto ?? 0);

        // Sin meta no se inventa una: se dice que falta, que es un dato en sí.
        if ($montoMeta <= 0) {
            return [
                'anio' => $anio, 'mes' => $mes,
                'tiene_meta' => false,
                'meta' => 0.0,
                'vendido' => round($vendido, 2),
                'cumplimiento' => null,
                'falta' => null,
                'dias_restantes' => $this->diasHabilesRestantes($inicio, $fin),
                'ritmo_necesario' => null,
                'va_en_ritmo' => null,
                'avance_del_mes' => $this->avanceDelMes($inicio, $fin),
            ];
        }

        $cumplimiento = round(($vendido / $montoMeta) * 100, 1);
        $falta = round(max(0, $montoMeta - $vendido), 2);
        $diasRestantes = $this->diasHabilesRestantes($inicio, $fin);
        $avance = $this->avanceDelMes($inicio, $fin);

        return [
            'anio' => $anio, 'mes' => $mes,
            'tiene_meta' => true,
            'meta' => round($montoMeta, 2),
            'meta_pedidos' => $meta?->pedidos,
            'vendido' => round($vendido, 2),
            'cumplimiento' => $cumplimiento,
            'falta' => $falta,
            'dias_restantes' => $diasRestantes,
            /*
             * Cuánto hay que vender por día hábil que queda.
             *
             * Es el número accionable. Si el mes ya terminó no queda ritmo que
             * calcular: dividir entre cero daría infinito y mostrarlo sería
             * peor que no mostrar nada.
             */
            'ritmo_necesario' => $falta > 0 && $diasRestantes > 0
                ? round($falta / $diasRestantes, 2)
                : null,
            /*
             * Si va a ritmo: lo vendido comparado con lo que debería llevar a
             * esta altura del mes. Un 60% cumplido con el 50% del mes corrido
             * va adelantado; con el 90% corrido, va tarde.
             */
            'va_en_ritmo' => $cumplimiento >= $avance,
            'avance_del_mes' => $avance,
        ];
    }

    /**
     * Días hábiles que faltan, contando hoy.
     *
     * Hábiles y no corridos porque un vendedor B2B no factura en domingo:
     * decirle que le quedan 4 días cuando dos son fin de semana le hace creer
     * que tiene el doble de margen del que tiene. Los festivos colombianos no
     * se descuentan —no hay calendario cargado— y eso juega a favor del
     * conservador: el ritmo real exigido es algo mayor, nunca menor.
     */
    private function diasHabilesRestantes(Carbon $inicio, Carbon $fin): int
    {
        $desde = now()->startOfDay();

        if ($desde->greaterThan($fin)) {
            return 0;
        }

        if ($desde->lessThan($inicio)) {
            $desde = $inicio->copy();
        }

        $dias = 0;
        $cursor = $desde->copy();

        while ($cursor->lessThanOrEqualTo($fin)) {
            if (! $cursor->isWeekend()) {
                $dias++;
            }
            $cursor->addDay();
        }

        return $dias;
    }

    /**
     * Qué parte del mes ya pasó, sin pasar por un vendedor.
     *
     * Lo necesita el tablero de Gerencia para la línea de referencia. Pedirlo
     * con `handle(0, ...)` parecía más corto pero el `when($vendedorId)` del
     * servicio trata el 0 como «sin filtro» y habría sumado la venta de TODO
     * el equipo para después descartarla.
     */
    public static function avanceDelMesDe(int $anio, int $mes): float
    {
        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth();

        return (new self)->avanceDelMes($inicio, (clone $inicio)->endOfMonth());
    }

    /** Qué porcentaje del mes ya pasó, en días hábiles. */
    private function avanceDelMes(Carbon $inicio, Carbon $fin): float
    {
        $habiles = 0;
        $cursor = $inicio->copy();

        while ($cursor->lessThanOrEqualTo($fin)) {
            if (! $cursor->isWeekend()) {
                $habiles++;
            }
            $cursor->addDay();
        }

        if ($habiles === 0) {
            return 100.0;
        }

        $restantes = $this->diasHabilesRestantes($inicio, $fin);

        return round((($habiles - $restantes) / $habiles) * 100, 1);
    }
}
