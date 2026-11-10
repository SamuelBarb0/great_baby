<?php

/*
 * El producto recuerda en qué rack vive.
 *
 * El catálogo comercial de Mimitos trae la posición de bodega metida dentro
 * del nombre —«BABERO (R6-S2-N1)»—, que es rack 6, estante 2, nivel 1. Es un
 * dato que la bodega usa todos los días para ir a buscar la mercancía, y
 * perderlo al importar sería quitarle al equipo lo único que hoy les dice
 * dónde está cada cosa.
 *
 * Pero tampoco puede quedarse en el nombre: de ahí sale a las facturas, a las
 * remisiones y a los correos del cliente, y al cliente el rack no le dice nada.
 *
 * ¿Por qué una columna y no una fila en `inventario_ubicaciones`?
 *
 * Porque esa tabla es de SIIGO: sus filas traen `siigo_id`, `cta_inventario` y
 * `siigo_sync_at`, y son BODEGAS —«PPAL», «BODEGA PARQUE CALDAS»—, no
 * posiciones dentro de una bodega. Meterle trescientos racks mezclaría dos
 * cosas distintas en la tabla que el sync de SIIGO pisa en cada corrida.
 *
 * Y tampoco se puede «asignarle» la ubicación al producto por el camino
 * normal, que es registrarle un movimiento de inventario, porque eso sería
 * inventar existencias que nadie contó.
 *
 * Así que queda acá: el texto tal como lo escribió bodega, para que la hoja de
 * picking lo pueda mostrar mientras el producto todavía no tiene movimientos,
 * y para que el día del conteo físico alguien sepa a qué rack ir.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('productos') || Schema::hasColumn('productos', 'posicion_bodega')) {
            return;
        }

        Schema::table('productos', function (Blueprint $t) {
            // Texto libre y no tres columnas: viene como lo escribió bodega, y
            // hay casos que no son «R-S-N» —«(ZP)», «(R2, 3-S5-N3)»— que si se
            // parten en tres se pierden o se inventan.
            $t->string('posicion_bodega', 60)->nullable()->after('stock_minimo')
                ->comment('Rack/estante/nivel como lo escribe bodega, p. ej. R6-S2-N1');

            $t->index('posicion_bodega');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('productos') && Schema::hasColumn('productos', 'posicion_bodega')) {
            Schema::table('productos', function (Blueprint $t) {
                $t->dropIndex(['posicion_bodega']);
                $t->dropColumn('posicion_bodega');
            });
        }
    }
};
