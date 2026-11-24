<?php

namespace App\Console\Commands;

use App\Modules\Inventario\Actions\LiberarReservasExpiradas;
use App\Modules\Inventario\Actions\VerificarAlertasStock;
use Illuminate\Console\Command;

class InventarioVerificarAlertas extends Command
{
    protected $signature = 'inventario:barrer';

    protected $description = 'Verifica alertas de stock y libera reservas expiradas.';

    public function handle(): int
    {
        $liberadas = LiberarReservasExpiradas::run();
        $this->info("Reservas expiradas liberadas: {$liberadas}");

        // Devuelve un arreglo con los contadores (disparadas, resueltas,
        // procesadas). Se imprimía entero dentro del texto y PHP reventaba con
        // «Array to string conversion»: el comando fallaba cada 15 minutos
        // desde el 6-nov (las alertas sí se guardaban; el que caía era el aviso).
        $r = VerificarAlertasStock::run();
        $this->info("Alertas nuevas: {$r['disparadas']} · resueltas: {$r['resueltas']} · configuraciones revisadas: {$r['procesadas']}");

        return self::SUCCESS;
    }
}
