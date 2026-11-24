<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El sticker de la guía de cada pedido Dropi.
 *
 * Dropi deja el PDF del sticker en su almacenamiento público y su ruta viene en
 * la lista de pedidos (`guia_urls3`, p. ej. «colombia/guias/coordinadora/
 * ORDEN-97861178-GUIA-36397343108.pdf»). Guardando la ruta, la Estación de
 * Empaque lo imprime sin entrar a Dropi (ver GenerarGuiaDropi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $table) {
            $table->string('sticker_path', 255)->nullable()->after('guia');
        });
    }

    public function down(): void
    {
        Schema::table('dropi_pedidos', function (Blueprint $table) {
            $table->dropColumn('sticker_path');
        });
    }
};
