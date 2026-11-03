<?php

namespace App\Modules\Compras\Services;

use App\Auth\Permisos;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\CompromisoPago;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Lo que hay que pagarle a China, cuándo vence y qué tan cerca está.
 *
 * Es control de vencimientos, no contabilidad: el pago con su asiento se sigue
 * registrando en Pagos a proveedor.
 */
class PlanDePagos
{
    public const AVISO_NARANJA = 10;

    public const AVISO_ROJO = 3;

    /** Cuántos días deja tranquilo un seguimiento a una cuota vencida. */
    public const SEGUIMIENTO_VALE = 3;

    private const ESTADOS_VIVOS = ['aprobada', 'parcial', 'recibida'];

    /**
     * Toda orden de importación aprobada tiene plan: sin él, su deuda no aparece en
     * ninguna lista. Nace con la regla habitual y se cambia si el proveedor es otro.
     */
    public function asegurarPlanes(): void
    {
        OrdenCompra::query()
            ->where('tipo', 'importacion')
            ->whereIn('estado', self::ESTADOS_VIVOS)
            ->whereDoesntHave('compromisosPago')
            ->get(['id'])
            ->each(fn (OrdenCompra $o) => $o->compromisosPago()->create([
                'concepto' => 'Pago total',
                'porcentaje' => 100,
                'base' => 'zarpe',
                'dias' => (int) setting('compras.credito_dias_zarpe', 90),
            ]));
    }

    /**
     * Reemplaza el plan de una orden. Las cuotas tienen que sumar 100 %, y lo ya
     * pagado no se reescribe.
     *
     * @param  list<array{concepto:string, porcentaje:float, base:string, dias:int}>  $cuotas
     */
    public function guardarPlan(OrdenCompra $orden, array $cuotas): void
    {
        if ($orden->compromisosPago()->whereNotNull('pagado_en')->exists()) {
            throw new InvalidArgumentException('Esta orden ya tiene pagos registrados: su plan no se puede cambiar.');
        }
        if (abs(array_sum(array_column($cuotas, 'porcentaje')) - 100) > 0.01) {
            throw new InvalidArgumentException('Las cuotas tienen que sumar 100 %.');
        }

        $orden->compromisosPago()->delete();
        foreach ($cuotas as $c) {
            $orden->compromisosPago()->create($c);
        }
    }

    /** @return Collection<int, array<string, mixed>> cuotas sin pagar, la más urgente primero */
    public function pendientes(): Collection
    {
        return $this->cuotasSinPagar()
            ->map(fn (CompromisoPago $c) => $this->fila($c))
            ->sortBy(fn ($f) => $f['vence'] ?? '9999-12-31')
            ->values();
    }

    /** @return array<string, float|int> */
    public function resumen(Collection $filas): array
    {
        $suma = fn (callable $f) => round($filas->filter($f)->sum(fn ($x) => (float) $x['monto_cop']), 2);

        return [
            'vencido' => $suma(fn ($f) => $f['semaforo'] === 'vencida'),
            'en_10_dias' => $suma(fn ($f) => in_array($f['semaforo'], ['naranja', 'rojo'], true)),
            'en_30_dias' => $suma(fn ($f) => $f['dias'] !== null && $f['dias'] >= -30 && $f['dias'] <= 0),
            'total' => $suma(fn () => true),
            'cuotas' => $filas->count(),
            'interes' => round($filas->sum(fn ($x) => (float) $x['interes']), 2),
        ];
    }

    /** Cuotas vencidas que nadie ha revisado. Son las que abren el aviso que no se cierra. */
    public function sinSeguimiento(): Collection
    {
        return $this->cuotasSinPagar()
            ->map(fn (CompromisoPago $c) => $this->fila($c))
            ->filter(fn ($f) => $f['semaforo'] === 'vencida' && ! $f['seguimiento_vigente'] && $f['monto_moneda'] > 0)
            ->sortBy('vence')
            ->values();
    }

    public function registrarSeguimiento(CompromisoPago $c, string $nota, ?int $userId): void
    {
        $c->update([
            'ultimo_seguimiento_en' => $this->hoy()->toDateString(),
            'ultimo_seguimiento_nota' => $nota,
            'seguimiento_por' => $userId,
        ]);
    }

    public function marcarPagado(CompromisoPago $c, string $fecha, ?string $nota, ?int $userId): void
    {
        $c->update(['pagado_en' => $fecha, 'nota_pago' => $nota, 'pagado_por' => $userId]);
    }

    /**
     * Cuánto hay que tener listo cada semana: «mañana toca transferir 100 millones».
     * Junta las cuotas a China con lo que falta por pagar de nacionalización.
     *
     * @return array{semanas: list<array<string, mixed>>, sin_fecha: float}
     */
    public function proyeccion(Collection $filas): array
    {
        $semanas = [];
        $sinFecha = 0.0;
        $sumar = function (?string $fecha, string $tipo, float $monto) use (&$semanas, &$sinFecha) {
            if ($monto <= 0) {
                return;
            }
            if (! $fecha) {
                $sinFecha += $monto;

                return;
            }
            // Lo vencido se paga ya: cuenta en la semana actual, no en el pasado.
            $dia = Carbon::parse($fecha)->max($this->hoy());
            $lunes = $dia->copy()->startOfWeek()->toDateString();
            $semanas[$lunes] ??= ['semana' => $lunes, 'china' => 0.0, 'nacionalizacion' => 0.0];
            $semanas[$lunes][$tipo] += $monto;
        };

        foreach ($filas as $f) {
            $sumar($f['vence'], 'china', (float) $f['monto_cop']);
        }
        foreach ($this->nacionalizacionPorPagar() as $n) {
            $sumar($n['fecha'], 'nacionalizacion', $n['monto']);
        }
        ksort($semanas);

        return [
            'semanas' => array_values(array_map(fn ($s) => [
                ...$s,
                'china' => round($s['china'], 2),
                'nacionalizacion' => round($s['nacionalizacion'], 2),
                'total' => round($s['china'] + $s['nacionalizacion'], 2),
            ], $semanas)),
            'sin_fecha' => round($sinFecha, 2),
        ];
    }

    /**
     * Avisa a la campana una vez a los 10 días y otra a los 3. Vencida, repite cada
     * pocos días mientras nadie le haga seguimiento.
     *
     * @return int cuántas cuotas se avisaron
     */
    public function avisar(): int
    {
        $usuarios = null;
        $avisadas = 0;

        foreach ($this->cuotasSinPagar() as $c) {
            $f = $this->fila($c);
            $columna = match ($f['semaforo']) {
                'naranja' => 'aviso_naranja_en',
                'rojo' => 'aviso_rojo_en',
                'vencida' => 'aviso_vencida_en',
                default => null,
            };
            if (! $columna || $f['seguimiento_vigente']) {
                continue;
            }
            $ultimo = $c->{$columna};
            if ($ultimo && ($columna !== 'aviso_vencida_en' || $ultimo->diffInDays($this->hoy()) < self::SEGUIMIENTO_VALE)) {
                continue;
            }

            $usuarios ??= User::query()->get()->filter(fn (User $u) => Permisos::puede($u, 'compras_pagos_exterior'))->values();
            foreach ($usuarios as $u) {
                NotificacionErp::crear([
                    'user_id' => $u->id,
                    'tipo' => 'pago_exterior_'.$f['semaforo'],
                    'titulo' => $f['semaforo'] === 'vencida'
                        ? "Pago vencido hace {$f['dias']} día(s): {$f['orden']}"
                        : 'Pago por vencer en '.abs($f['dias'])." día(s): {$f['orden']}",
                    'mensaje' => "{$f['proveedor']} · {$f['concepto']} · ".number_format((float) $f['monto_moneda'], 2, ',', '.')." {$f['moneda']}",
                    'color' => $f['semaforo'] === 'naranja' ? 'warning' : 'danger',
                    'icono' => 'heroicon-o-exclamation-triangle',
                    'url' => '/app/compras/pagos-exterior',
                ]);
            }

            $c->{$columna} = $this->hoy()->toDateString();
            $c->saveQuietly();
            $avisadas++;
        }

        return $avisadas;
    }

    /** @return Collection<int, CompromisoPago> */
    private function cuotasSinPagar(): Collection
    {
        return CompromisoPago::query()
            ->whereNull('pagado_en')
            ->whereHas('orden', fn ($q) => $q->where('tipo', 'importacion')->whereIn('estado', self::ESTADOS_VIVOS))
            ->with(['orden.proveedor:id,nombre_completo,razon_social', 'orden.importaciones.etapas'])
            ->get();
    }

    /** @return array<string, mixed> */
    private function fila(CompromisoPago $c): array
    {
        $orden = $c->orden;
        $contenedor = $orden->importaciones->first();
        $vence = $this->vencimiento($c, $contenedor);
        // Positivo: días vencida. Negativo: días que faltan.
        $dias = $vence ? (int) $vence->diffInDays($this->hoy(), false) : null;

        $montoMoneda = round((float) $orden->subtotal * (float) $c->porcentaje / 100, 2);
        try {
            $montoCop = round($montoMoneda * app(CostoImportacion::class)->tasa($orden), 2);
        } catch (InvalidArgumentException) {
            $montoCop = null;
        }

        return [
            'id' => $c->id,
            'orden_id' => $orden->id,
            'orden' => $orden->numero,
            'proveedor' => $orden->proveedor?->razon_social ?: $orden->proveedor?->nombre_completo,
            'contenedor' => $contenedor?->contenedor ?: $contenedor?->numero,
            'concepto' => $c->concepto,
            'porcentaje' => (float) $c->porcentaje,
            'base' => CompromisoPago::BASES[$c->base] ?? $c->base,
            'plazo' => (int) $c->dias,
            'moneda' => $orden->moneda,
            'monto_moneda' => $montoMoneda,
            'monto_cop' => $montoCop,
            'vence' => $vence?->toDateString(),
            'dias' => $dias,
            'semaforo' => $this->semaforo($dias),
            'interes' => $this->interes($dias, $montoCop),
            'seguimiento_en' => $c->ultimo_seguimiento_en?->toDateString(),
            'seguimiento_nota' => $c->ultimo_seguimiento_nota,
            'seguimiento_vigente' => $c->ultimo_seguimiento_en
                && $c->ultimo_seguimiento_en->diffInDays($this->hoy()) < self::SEGUIMIENTO_VALE,
        ];
    }

    /** Sin zarpe o sin cargue todavía no hay fecha: inventarla daría una falsa tranquilidad. */
    private function vencimiento(CompromisoPago $c, ?Importacion $contenedor): ?Carbon
    {
        $base = match ($c->base) {
            'emision' => $c->orden->fecha_emision,
            'zarpe' => $contenedor?->fecha_zarpe,
            'fabricacion' => $contenedor?->etapas->first(fn ($e) => $e->etapa === EtapaContenedor::Cargado)?->fecha,
            default => null,
        };

        return $base ? Carbon::parse($base)->startOfDay()->addDays((int) $c->dias) : null;
    }

    private function semaforo(?int $dias): string
    {
        return match (true) {
            $dias === null => 'sin_fecha',
            $dias > 0 => 'vencida',
            $dias >= -self::AVISO_ROJO => 'rojo',
            $dias >= -self::AVISO_NARANJA => 'naranja',
            default => 'al_dia',
        };
    }

    /**
     * Interés estimado según los tramos pactados, de la regla `compras.interes_tramos`:
     * [{"desde":1,"hasta":30,"pct":1.5}]. Sin tramos no se estima nada.
     */
    private function interes(?int $dias, ?float $monto): float
    {
        if ($dias === null || $dias <= 0 || ! $monto) {
            return 0.0;
        }

        $tramos = setting('compras.interes_tramos', []);
        foreach (is_array($tramos) ? $tramos : [] as $t) {
            if ($dias >= (int) ($t['desde'] ?? 0) && $dias <= (int) ($t['hasta'] ?? PHP_INT_MAX)) {
                return round($monto * (float) ($t['pct'] ?? 0) / 100, 2);
            }
        }

        return 0.0;
    }

    /**
     * Lo que falta por pagar de cada contenedor sin liquidar: lo estimado en la
     * pre-liquidación menos los gastos ya registrados.
     *
     * @return list<array{fecha: ?string, monto: float}>
     */
    private function nacionalizacionPorPagar(): array
    {
        $seguimiento = app(SeguimientoContenedor::class);
        $preliquidar = app(PreliquidarOrden::class);
        $salida = [];

        $contenedores = Importacion::query()
            ->whereNull('fecha_liquidacion')
            ->whereHas('ordenes')
            ->with(['ordenes.items', 'gastos', 'etapas'])
            ->get();

        foreach ($contenedores as $imp) {
            $estimado = 0.0;
            foreach ($imp->ordenes as $orden) {
                try {
                    $lineas = $preliquidar->handle($orden)['lineas'] ?? [];
                } catch (\Throwable) {
                    continue;
                }
                $items = $orden->items->keyBy('id');
                foreach ($lineas as $l) {
                    $u = $l['unidad'] ?? null;
                    if ($u) {
                        $estimado += ((float) $u['flete'] + (float) $u['arancel'] + (float) $u['iva'] + (float) $u['gasto_colombia'])
                            * (float) ($items[$l['id']]->cantidad ?? 0);
                    }
                }
            }

            $falta = $estimado - (float) $imp->gastos->sum('monto_base');
            if ($falta > 0.01) {
                $resumen = $seguimiento->resumen($imp);
                $salida[] = [
                    'fecha' => $resumen['llegada_estimada'] ?? $imp->eta?->toDateString(),
                    'monto' => round($falta, 2),
                ];
            }
        }

        return $salida;
    }

    private function hoy(): Carbon
    {
        return now('America/Bogota')->startOfDay();
    }
}
