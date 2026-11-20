<?php

use App\Modules\Logistica\Support\ZonaDeLaBodega;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las zonas de la bodega pasan a llamarse como están pintadas en la pared.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ HACE FALTA UNA MIGRACIÓN
 *
 * El 19-nov se cambió el recorrido al que pidió el área —zonas «1», «2» y «3»,
 * y la zona 3 arrancando por el LOCAL— en `ZonaDeLaBodega::POR_DEFECTO`, pero
 * el valor REGISTRADO de la regla se quedó con el reparto viejo: zonas «A/B/C»
 * y la zona C recorrida al revés, con el LOCAL de último.
 *
 * Arreglar el literal no alcanza. `Reglas::seedDefaults()` dice explícito que
 * en una fila que ya existe «el valor y el tipo no se tocan nunca» —y hace
 * bien, porque ese dato es del usuario—. Así que toda instalación ya sembrada
 * seguiría imprimiendo «ZONA C» en la hoja de picking mientras en la pared
 * dice «ZONA 3», y mandando a caminar la zona 3 al revés.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * QUÉ TOCA Y QUÉ NO
 *
 * Sólo reescribe la fila si todavía tiene EXACTAMENTE el reparto viejo. Si el
 * área ya la editó a mano —que es justo lo que esta regla existe para
 * permitir— se deja como está y se dice en el log. Nadie pierde su
 * configuración por correr una migración.
 */
return new class extends Migration
{
    /** El reparto viejo, el único que esta migración se permite pisar. */
    private const VIEJO = [
        'A' => ['1', '2', '3', '4', '5', '6'],
        'B' => ['6.5', '7', '8', '9', '10', '10.5'],
        'C' => ['11', '12', '13', '14', '15', 'PARQUEADERO', 'ARSUTEX', 'LOCAL'],
    ];

    public function up(): void
    {
        $fila = DB::table('reglas_negocio')->where('clave', 'logistica.zonas_bodega')->first();

        if (! $fila) {
            // Sin fila no hay nada que corregir: `seedDefaults()` la va a
            // sembrar desde el literal, que ya quedó bien.
            return;
        }

        $guardado = json_decode((string) $fila->valor, true);

        if ($guardado === self::VIEJO) {
            DB::table('reglas_negocio')
                ->where('clave', 'logistica.zonas_bodega')
                ->update([
                    'valor' => json_encode(ZonaDeLaBodega::POR_DEFECTO, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

            return;
        }

        /*
         * Alguien ya la cambió. Puede ser el área acomodando sus racks, o una
         * instalación a medio camino. No se pisa, pero se deja dicho: si la
         * hoja sigue saliendo con letras, es por esta fila.
         */
        \Illuminate\Support\Facades\Log::info(
            'logistica.zonas_bodega no se tocó: el valor guardado ya no es el viejo A/B/C.',
            ['valor' => $fila->valor],
        );
    }

    public function down(): void
    {
        $fila = DB::table('reglas_negocio')->where('clave', 'logistica.zonas_bodega')->first();

        if (! $fila) {
            return;
        }

        // Sólo se devuelve lo que esta migración puso.
        if (json_decode((string) $fila->valor, true) === ZonaDeLaBodega::POR_DEFECTO) {
            DB::table('reglas_negocio')
                ->where('clave', 'logistica.zonas_bodega')
                ->update([
                    'valor' => json_encode(self::VIEJO, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }
};
