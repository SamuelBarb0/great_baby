<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La excepción del auxilio de transporte.
 *
 * El sistema decidía quién lo devenga mirando UNA sola cosa: el salario, hasta
 * dos mínimos. Eso es la mitad de la regla.
 *
 * La otra mitad es que el auxilio compensa el transporte al trabajo, así que
 * **no lo devenga quien no se transporta**: el que vive en el lugar de trabajo
 * y aquel a quien la empresa le pone el transporte. Son dos casos reales —un
 * celador que duerme en la bodega, una ruta de empresa— y el salario no los
 * distingue.
 *
 * ── Por qué una bandera y no un monto por persona ───────────────────────────
 *
 * Porque el auxilio es un valor legal, el mismo para todo el que lo devenga;
 * no es negociable por persona. Lo que cambia de una persona a otra es SI lo
 * devenga, no CUÁNTO. Lo pactado aparte ya tiene su campo —`valor_adicional`
 * con su concepto— y mezclarlos escondería un acuerdo privado dentro de una
 * prestación legal.
 *
 * ── Por qué con motivo obligatorio ──────────────────────────────────────────
 *
 * Porque quitarle el auxilio a alguien es quitarle plata todos los meses. Si
 * se puede hacer con una casilla y sin decir por qué, el día que alguien
 * reclame no hay con qué responderle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->boolean('auxilio_excluido')->default(false)->after('salario');
            $t->string('auxilio_excluido_motivo', 200)->nullable()->after('auxilio_excluido');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropColumn(['auxilio_excluido', 'auxilio_excluido_motivo']);
        });
    }
};
