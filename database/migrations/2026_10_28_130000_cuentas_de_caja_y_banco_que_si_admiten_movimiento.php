<?php

/*
 * Caja y bancos apuntaban a cuentas que el plan prohíbe mover.
 *
 * `contable.cta_bancos_default` valía 112005 y `contable.cta_caja_default`
 * 110505. Las dos existen en el plan, pero marcadas como agrupadoras
 * (`permite_movimiento = 0`): son el nivel que agrupa, no el que recibe
 * asientos. La migración que creó el plan lo dice en la columna misma —«Solo
 * cuentas de detalle (hoja) reciben asientos»— y nadie lo estaba cumpliendo.
 *
 * Es parte de un problema más grande: hoy 104 de 104 líneas del libro, por
 * $31,9 millones, están contra cuentas agrupadoras, y las 18 reglas
 * `contable.cta*` apuntaban todas ahí. Esto corrige las dos que el asiento de
 * nómina necesitaba para no nacer con el mismo defecto. Las demás quedan
 * pendientes: hay que confirmarlas una por una con la contadora, porque
 * elegir la subcuenta correcta no es algo que se adivine desde el código.
 *
 * Lo que ya está asentado NO se mueve acá. Para eso existe el comando
 * `php artisan contabilidad:reestampar-cuentas --aplicar`, que es donde esa
 * decisión tiene revisión previa.
 *
 * Sólo se corrige si el valor guardado sigue siendo el viejo: si la contadora
 * ya puso otra cuenta, manda la suya.
 */

use App\Models\ReglaNegocio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** clave => [valor viejo que se corrige, valor nuevo] */
    private const CORRIGE = [
        'contable.cta_bancos_default' => ['112005', '111010'],
        'contable.cta_caja_default' => ['110505', '11050501'],
    ];

    public function up(): void
    {
        foreach (self::CORRIGE as $clave => [$viejo, $nuevo]) {
            // Que la cuenta nueva exista y admita movimiento en ESTA
            // instalación: el plan de cuentas lo carga cada empresa.
            $sirve = DB::table('plan_cuentas')
                ->where('codigo', $nuevo)
                ->where('permite_movimiento', 1)
                ->exists();

            if (! $sirve) {
                continue;
            }

            /*
             * Por el MODELO, no por query builder.
             *
             * `ReglaNegocio` cachea todas las reglas y sólo invalida esa caché
             * desde su observer de `saved`. Un `DB::table()->update()` cambia
             * la fila y deja la caché vieja viva una hora: la base diría 111010
             * y `setting()` seguiría devolviendo 112005. Costó descubrirlo acá
             * mismo.
             */
            ReglaNegocio::where('clave', $clave)
                ->where('valor', $viejo)
                ->each(fn (ReglaNegocio $regla) => $regla->update(['valor' => $nuevo]));
        }
    }

    public function down(): void
    {
        foreach (self::CORRIGE as $clave => [$viejo, $nuevo]) {
            ReglaNegocio::where('clave', $clave)
                ->where('valor', $nuevo)
                ->each(fn (ReglaNegocio $regla) => $regla->update(['valor' => $viejo]));
        }
    }
};
