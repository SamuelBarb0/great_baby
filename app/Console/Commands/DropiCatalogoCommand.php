<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Services\CatalogoDropi;
use Illuminate\Console\Command;

class DropiCatalogoCommand extends Command
{
    protected $signature = 'dropi:catalogo
        {--sin-leer : Usa la última foto en vez de volver a leer Dropi}
        {--vincular : Hace los vínculos seguros (si no, sólo muestra la vista previa)}';

    protected $description = 'Trae el catálogo propio de Dropi por la API y lo enlaza con el del ERP (ver CatalogoDropi).';

    public function handle(CatalogoDropi $catalogo): int
    {
        if (! $this->option('sin-leer')) {
            $this->info('Leyendo Dropi (páginas de 40; Dropi pide esperar entre llamadas)…');
            $r = $catalogo->actualizar();
            $this->info("{$r['productos']} productos leídos.");
        }

        $previa = $catalogo->previsualizar();
        if (! $previa['leido_at']) {
            $this->error('No hay foto del catálogo: corra el comando sin --sin-leer.');

            return self::FAILURE;
        }
        $this->table(array_keys($previa['resumen']), [array_values($previa['resumen'])]);

        if ($this->option('vincular')) {
            $r = $catalogo->vincular(null);
            $this->info("{$r['vinculos']} vínculos · {$r['items']} líneas de pedido reasignadas · {$r['pedidos_liberados']} pedidos liberados.");
            foreach ($r['errores'] as $e) {
                $this->warn($e);
            }
        }

        return self::SUCCESS;
    }
}
