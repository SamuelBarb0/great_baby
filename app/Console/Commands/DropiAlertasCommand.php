<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Actions\EnviarAlertasDropi;
use Illuminate\Console\Command;

class DropiAlertasCommand extends Command
{
    protected $signature = 'dropi:alertas';

    protected $description = 'Avisos de la campana: plazos de 48/72 h, paquetes por fuera, devoluciones y diferencias de Dropi (ver EnviarAlertasDropi).';

    public function handle(): int
    {
        $r = (new EnviarAlertasDropi())->handle();
        $this->table(array_keys($r), [array_values($r)]);

        return self::SUCCESS;
    }
}
