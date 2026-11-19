<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `retenciones_config.concepto` era varchar(100) y la tabla real del contador
 * no cabe: el concepto más largo tiene 224 caracteres.
 *
 * No se recorta a propósito. Lo que excede los 100 no es relleno, es la
 * condición que decide si la retención aplica — «…para trabajadores que
 * devenguen ingresos superiores al equivalente a 204 UVT», «…si el pagador es
 * persona jurídica». Guardar el concepto a medias deja una regla que parece
 * completa y se aplica donde no debe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retenciones_config', function (Blueprint $t) {
            $t->string('concepto', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('retenciones_config', function (Blueprint $t) {
            $t->string('concepto', 100)->change();
        });
    }
};
