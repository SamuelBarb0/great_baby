<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoCorte;
use App\Modules\Dropi\Models\DropiCorte;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * §5 Diseño Dropi — Asigna el corte correcto a un pedido según la hora de creación.
 * Corte 1: 00:00–14:00. Corte 2: 14:00–23:59.
 *
 * F7 · Si el corte "natural" del pedido ya está cerrado (por ejemplo llega un
 * pedido tarde por reintento de sync a las 15:30 y el corte 1 fue cerrado),
 * abrimos un corte con el siguiente número disponible en el mismo día — así
 * jamás rompemos el hash del corte cerrado ni la inmutabilidad del manifiesto.
 */
class AsignarCorteACarga
{
    use AsAction;

    public function handle(CarbonImmutable $creadoAt): DropiCorte
    {
        $fecha = $creadoAt->toDateString();
        $numero = $creadoAt->hour < 14 ? 1 : 2;

        // 1) firstOrCreate atómico (respeta UNIQUE(fecha, numero)).
        try {
            $corte = DropiCorte::firstOrCreate(
                ['fecha' => $fecha, 'numero' => $numero],
                ['estado' => EstadoCorte::Abierto],
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $corte = DropiCorte::whereDate('fecha', $fecha)->where('numero', $numero)->firstOrFail();
        }
        // `estado` está protegido: el corte recién creado lo toma del default de
        //   la base («abierto») pero el objeto quedaba sin él, y abajo se leía
        //   como cerrado → el Corte 2 nunca se usaba con el 1 abierto (25-nov-2026).
        if ($corte->wasRecentlyCreated) {
            $corte->refresh();
        }

        // 2) F7 · Si el corte natural está cerrado:
        //    - Re-audit H4 func · buscar PRIMERO cualquier otro corte abierto del día
        //      (evita crear un 3° corte cuando ya hay uno abierto).
        //    - Si no hay ninguno abierto, crear con numero+1.
        if ($this->esCerrado($corte)) {
            return DB::transaction(function () use ($fecha) {
                $abierto = DropiCorte::whereDate('fecha', $fecha)
                    ->where('estado', EstadoCorte::Abierto->value)
                    ->orderBy('numero')
                    ->first();
                if ($abierto) return $abierto;

                $ultimoNumero = (int) DropiCorte::whereDate('fecha', $fecha)->max('numero');
                $siguiente = $ultimoNumero + 1;

                try {
                    return DropiCorte::firstOrCreate(
                        ['fecha' => $fecha, 'numero' => $siguiente],
                        ['estado' => EstadoCorte::Abierto],
                    );
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    return DropiCorte::whereDate('fecha', $fecha)->where('numero', $siguiente)->firstOrFail();
                }
            });
        }

        return $corte;
    }

    /**
     * ¿Ya no recibe pedidos? Finalizado o con la entrada cerrada («Cerrar
     * corte», en despacho): en los dos casos el pedido nuevo va a otro corte.
     */
    private function esCerrado(DropiCorte $corte): bool
    {
        return ! EstadoCorte::de($corte->estado)?->recibePedidos();
    }
}
