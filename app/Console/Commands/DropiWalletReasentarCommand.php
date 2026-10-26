<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use Illuminate\Console\Command;

/**
 * 9.2 · Rehace el asiento de los movimientos de wallet que se contabilizaron
 * mal: las sanciones que Dropi cobra («indemnizaciones» que son SALIDAS) se
 * asentaban como ingreso 4295. El listener reversa el asiento viejo con un
 * ajuste inverso (nunca borra historia) y escribe el nuevo: gasto 5395 con
 * Dropi como tercero.
 *
 * Por defecto sólo muestra qué haría; con --aplicar lo hace.
 */
class DropiWalletReasentarCommand extends Command
{
    protected $signature = 'dropi:wallet-reasentar
        {--tipo=indemnizacion : tipo de movimiento de wallet}
        {--aplicar : escribir los asientos (sin esto sólo lista)}';

    protected $description = 'Rehace los asientos de movimientos de wallet Dropi mal contabilizados (sanciones como ingreso).';

    public function handle(): int
    {
        $movs = DropiWalletMovimiento::with('pedido')
            ->where('tipo', (string) $this->option('tipo'))
            ->orderBy('fecha')
            ->get();

        $this->table(['id', 'fecha', 'monto', 'categoría'], $movs->map(fn ($m) => [
            $m->id, $m->fecha?->toDateString(), $m->monto, $m->categoria,
        ])->all());

        if (! $this->option('aplicar')) {
            $this->warn("{$movs->count()} movimiento(s). Nada escrito: corra con --aplicar para rehacer los asientos.");

            return self::SUCCESS;
        }

        $listener = new RegistrarAsientoWalletDropi();
        foreach ($movs as $m) {
            $listener->handle($m);
        }
        $this->info("{$movs->count()} asiento(s) rehechos (reverso del viejo + asiento nuevo).");

        return self::SUCCESS;
    }
}
