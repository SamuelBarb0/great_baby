<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Actions\CrearProductosNuevosDropi;
use Illuminate\Console\Command;

class DropiCrearProductosNuevosCommand extends Command
{
    protected $signature = 'dropi:crear-productos-nuevos {--simular : Solo muestra qué crearía, sin escribir nada}';

    protected $description = 'Crea en el ERP, ya vinculados, los productos de Dropi que llegan en pedidos y no existen en el ERP (ver CrearProductosNuevosDropi).';

    public function handle(CrearProductosNuevosDropi $accion): int
    {
        $simular = (bool) $this->option('simular');
        $r = $accion->handle($simular);

        if ($r['releido']) {
            $this->info('Se releyó el catálogo de Dropi.');
        }
        if ($r['creados']) {
            $this->table(
                ['producto', 'referencia', 'nombre', 'llaves', 'pedidos liberados'],
                array_map(fn ($c) => [$c['producto_id'] ?? '(simulado)', $c['referencia'], $c['nombre'], implode(', ', $c['llaves']), $c['pedidos_liberados']], $r['creados']),
            );
        } else {
            $this->line('No hay productos nuevos por crear.');
        }
        foreach ($r['errores'] as $e) {
            $this->warn($e);
        }
        if ($simular) {
            $this->comment('Simulación: no se escribió nada.');
        }

        return self::SUCCESS;
    }
}
