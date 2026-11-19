<?php

/*
 * `siigo.cta_banco_default` valía 1110, el encabezado «Bancos».
 *
 * Es la hermana de `contable.cta_bancos_default`, que esta misma mañana pasó
 * de la Davivienda inventada a la 11200501. Esta se quedó atrás: la propia
 * pantalla de SIIGO la lista entre las «cuentas contables que SIIGO va a
 * rechazar», porque 1110 agrupa tres subcuentas y no recibe movimiento.
 *
 * Va a la misma cuenta operativa: Bancolombia ahorros 30300002664, la del
 * extracto de septiembre, confirmada por Silvia el 2026-11-04.
 *
 * Quedan tres de esa lista sin resolver, y necesitan que la contadora elija la
 * auxiliar:
 *
 *   siigo.cta_gasto_default                 5195  encabezado, 27 subcuentas
 *   siigo.cta_sobrante_inventario_default   4295  encabezado, 8 subcuentas
 *   siigo.cta_perdida_inventario_default    5299  NO EXISTE en el plan
 *
 * Sólo se corrige si el valor guardado sigue siendo el viejo.
 */

use App\Models\ReglaNegocio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLAVE = 'siigo.cta_banco_default';

    private const VIEJO = '1110';

    private const NUEVO = '11200501';

    public function up(): void
    {
        $this->mover(self::VIEJO, self::NUEVO);
    }

    public function down(): void
    {
        $this->mover(self::NUEVO, self::VIEJO);
    }

    private function mover(string $desde, string $hacia): void
    {
        // Que la cuenta destino exista y admita movimiento en ESTA instalación.
        // Al revertir, 1110 no admite movimiento y la comprobación fallaría; por
        // eso el down se permite igual cuando el destino es el valor original.
        $sirve = $hacia === self::VIEJO || DB::table('plan_cuentas')
            ->where('codigo', $hacia)
            ->where('permite_movimiento', 1)
            ->exists();

        if (! $sirve) {
            return;
        }

        // Por el MODELO, no por query builder: ReglaNegocio cachea las reglas y
        // sólo invalida esa caché desde su observer de `saved`.
        ReglaNegocio::where('clave', self::CLAVE)
            ->where('valor', $desde)
            ->each(fn (ReglaNegocio $regla) => $regla->update(['valor' => $hacia]));
    }
};
