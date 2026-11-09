<?php

namespace App\Modules\Compras\Actions;

use App\Models\User;
use App\Modules\Compras\Models\ProgramacionPago;
use App\Modules\Compras\Services\CuentasPorPagar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Arma una programación de pagos (en borrador, falta autorizarla). Vivía en
 * `ProgramacionPagosController::guardar`; se sacó (7-nov) para el panel de Dropi.
 */
class CrearProgramacionPago
{
    use AsAction;

    public function __construct(protected CuentasPorPagar $servicio) {}

    /**
     * @param array{fecha_programada:string, monto_autorizado?:?float, notas?:?string,
     *     items:array<int, array{recepcion_id:int, monto:float}>} $data
     */
    public function handle(array $data, ?User $quien): ProgramacionPago
    {
        // Nadie puede programar más de lo que se debe de esa factura. Pasa
        // cuando se teclea el valor total en vez del saldo, y el error no se
        // nota hasta que el proveedor avisa que le pagaron de más.
        $saldos = $this->servicio->pendientes()->keyBy('recepcion_id');

        foreach ($data['items'] as $i => $item) {
            $saldo = (float) ($saldos[$item['recepcion_id']]['saldo'] ?? 0);
            if ((float) $item['monto'] > $saldo + 0.01) {
                throw ValidationException::withMessages([
                    "items.{$i}.monto" => 'De esa factura se deben $'.number_format($saldo, 0, ',', '.')
                        .': no se pueden programar $'.number_format((float) $item['monto'], 0, ',', '.').'.',
                ]);
            }
        }

        return DB::transaction(function () use ($data, $quien) {
            $p = ProgramacionPago::create([
                'numero' => ProgramacionPago::siguienteNumero(),
                'fecha_programada' => $data['fecha_programada'],
                'monto_autorizado' => $data['monto_autorizado'] ?? null,
                'notas' => $data['notas'] ?? null,
                'estado' => 'borrador',
                'creada_por' => $quien?->id,
            ]);

            foreach ($data['items'] as $item) {
                $p->items()->create([
                    'recepcion_id' => $item['recepcion_id'],
                    'monto' => $item['monto'],
                ]);
            }

            return $p;
        });
    }
}
