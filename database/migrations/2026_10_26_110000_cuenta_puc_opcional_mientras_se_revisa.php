<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `retenciones_config.cuenta_puc` pasa a ser opcional MIENTRAS la regla está en
 * revisión.
 *
 * La tabla de retefuente del contador trae 29 conceptos; el PUC de Great Baby
 * sólo tiene cuenta abierta para 18 de ellos, porque las otras 11 son
 * retenciones que no practican. Con la columna obligatoria no había forma de
 * dejar esas 11 cargadas y visibles para que la contadora diga «esta no la
 * usamos» o «va a la cuenta X» — había que descartarlas en silencio, que es
 * justo lo que no queremos.
 *
 * El seguro no es la columna: es que `RetencionesController` no deja ACTIVAR
 * una regla sin cuenta. Inactiva y sin cuenta no calcula nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retenciones_config', function (Blueprint $t) {
            $t->string('cuenta_puc', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('retenciones_config', function (Blueprint $t) {
            $t->string('cuenta_puc', 30)->nullable(false)->change();
        });
    }
};
