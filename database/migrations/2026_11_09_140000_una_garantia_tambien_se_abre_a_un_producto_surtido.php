<?php

/*
 * Una garantía también se le abre a un producto de «colores surtidos».
 *
 * `garantia_tickets.variante_id` era NOT NULL, así que todo ticket exigía una
 * variante. Los productos AGREGADOS —`productos.desglose_stock = false`, los
 * que el cliente subió por el Excel— no tienen filas en `producto_variantes`:
 * el precio lo llevan en `precios_producto` y el stock en `stock_directo`. A
 * esos productos, que son la mayor parte del catálogo real, no se les podía
 * abrir garantía: el insert lo tumbaba la base.
 *
 * Es el mismo patrón que ya apareció varias veces en este repo: el camino
 * granular/Dropi tiene la función y el agregado/B2B no — las reservas, el
 * seguimiento de guías, las garantías de venta B2B y ahora el sujeto del
 * ticket.
 *
 * `producto_id` ya existía y es nullable desde el 6-nov, así que el sujeto
 * queda como en las otras ocho tablas del desglose dual: variante O producto.
 *
 * SIN CHECK de base, a diferencia de esas ocho. MariaDB no acepta un CHECK
 * sobre una columna que sea hija de una llave foránea —«expression
 * 'producto_id' cannot be used in the CHECK clause»— y acá `variante_id` sí
 * tiene FK a `producto_variantes`. Para replicarlo habría que tumbar y recrear
 * esa FK, como hizo `restaurar_checks_desglose_dual`. El seguro de «nunca
 * ambos null» queda en la validación del alta (`required_without`), que es
 * donde entra el único camino que crea tickets.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garantia_tickets', function (Blueprint $t) {
            $t->unsignedBigInteger('variante_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Volver atrás con garantías de agregado abiertas significaría
         * inventarles una variante que no existe. Mejor decirlo que escribir
         * un dato falso.
         */
        $deAgregado = DB::table('garantia_tickets')->whereNull('variante_id')->count();

        if ($deAgregado > 0) {
            throw new RuntimeException(
                "No se puede revertir: hay {$deAgregado} garantía(s) abiertas a productos "
                .'agregados, que no tienen variante. Resolvelas o borralas primero.'
            );
        }

        Schema::table('garantia_tickets', function (Blueprint $t) {
            $t->unsignedBigInteger('variante_id')->nullable(false)->change();
        });
    }
};
