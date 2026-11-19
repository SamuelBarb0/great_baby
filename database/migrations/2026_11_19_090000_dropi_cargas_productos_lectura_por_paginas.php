<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos ajustes a las cargas de productos a Dropi:
 *
 *  1. La vista previa ya no lee el catálogo entero en una sola petición
 *     (~1 minuto, y en Hostinger se corta): la carga nace en estado «leyendo»
 *     con las filas del Excel y la pantalla pide el catálogo de a una página.
 *     Aquí se guardan esas filas y por dónde va la lectura.
 *  2. Cada fila puede tener su propia razón (por defecto, el concepto de la
 *     carga), y se puede editar después: así el equipo documenta producto por
 *     producto por qué entró. Lo que ya se mandó a Dropi queda con el texto
 *     que llevaba en ese momento (Dropi no deja editar su historial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_cargas_productos', function (Blueprint $t) {
            // Filas del Excel ya leídas, a la espera de cruzarlas con Dropi.
            $t->json('filas_excel')->nullable()->after('archivo_nombre');
            // {privados: bool, inicio: int, leidos: int, categorias: [...]}
            $t->json('lectura')->nullable()->after('filas_excel');
        });

        Schema::table('dropi_carga_operaciones', function (Blueprint $t) {
            // null = usa el concepto de la carga.
            $t->string('razon', 200)->nullable()->after('motivo');
            // La razón con la que se mandó a Dropi (queda fija en su historial).
            $t->string('razon_enviada', 200)->nullable()->after('razon');
        });
    }

    public function down(): void
    {
        Schema::table('dropi_carga_operaciones', function (Blueprint $t) {
            $t->dropColumn(['razon', 'razon_enviada']);
        });
        Schema::table('dropi_cargas_productos', function (Blueprint $t) {
            $t->dropColumn(['filas_excel', 'lectura']);
        });
    }
};
