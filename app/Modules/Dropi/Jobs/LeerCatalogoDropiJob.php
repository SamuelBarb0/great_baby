<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Dropi\Services\CatalogoDropi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lee el catálogo de Dropi en segundo plano: con páginas de 40 y las esperas
 * que Dropi impone, tarda minutos y no cabe en una petición web.
 * Único: dos clics seguidos no lanzan dos lecturas.
 */
class LeerCatalogoDropiJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const ESTADO = 'dropi:catalogo:estado';

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function handle(CatalogoDropi $catalogo): void
    {
        Cache::put(self::ESTADO, ['estado' => 'leyendo', 'desde' => now()->toIso8601String()], 900);

        try {
            $r = $catalogo->actualizar();
            Cache::put(self::ESTADO, ['estado' => 'listo', 'productos' => $r['productos']], 3600);
        } catch (\Throwable $e) {
            Log::warning('dropi.catalogo.fallo', ['msg' => $e->getMessage()]);
            Cache::put(self::ESTADO, ['estado' => 'error', 'mensaje' => $e->getMessage()], 3600);
        }
    }
}
