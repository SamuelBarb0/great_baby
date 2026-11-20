<?php

namespace App\Console\Commands;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use App\Modules\Siigo\Jobs\PushAsientoDropiASiigo;
use Illuminate\Console\Command;

/**
 * Encola a SIIGO los asientos de la wallet de Dropi que todavía no viajaron
 * (y las reversas de recibos de caja de Dropi ya emitidos). Sirve para la
 * historia, para lo que se escribió con el push apagado y para reintentar lo
 * que SIIGO rechazó una vez corregida la causa (NIT de Dropi, tipo de
 * comprobante, cuenta).
 */
class SiigoEmpujarDropiCommand extends Command
{
    protected $signature = 'siigo:empujar-dropi
        {--dry-run : sólo contar, no encolar}';

    protected $description = 'Encola a SIIGO los comprobantes pendientes de la wallet de Dropi.';

    public function handle(): int
    {
        $origenes = MovimientoContable::query()->pendienteDeSiigo()
            ->where(fn ($q) => $q->where('origen_type', DropiWalletMovimiento::class)
                ->orWhere(fn ($w) => $w->where('origen_type', PagoVenta::class)->where('descripcion', 'like', '[REVERSA%')
                    ->whereIn('origen_id', PagoVenta::withTrashed()->where('medio_pago', 'wallet_dropi')->whereNotNull('siigo_id')->select('id'))))
            ->select('origen_type', 'origen_id')->distinct()->get();

        $this->info("{$origenes->count()} documento(s) con asiento pendiente de SIIGO.");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        foreach ($origenes as $o) {
            PushAsientoDropiASiigo::dispatchManual($o->origen_type, (int) $o->origen_id);
        }
        $this->info('Encolados en la cola de SIIGO.');

        return self::SUCCESS;
    }
}
