<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Cerrar corte» antes de empacar (24-nov-2026): el corte deja de recibir
 * pedidos (estado `en_despacho`) y se sigue empacando; «Finalizar» lo congela
 * después. Quién cerró la entrada y cuándo, aparte del cierre final
 * (cerrado_por / cerrado_at). La columna `estado` ya es texto: no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_cortes', function (Blueprint $table) {
            $table->timestamp('cortado_at')->nullable()->after('estado')
                ->comment('«Cerrar corte»: desde aquí no recibe pedidos nuevos');
            $table->foreignId('cortado_por')->nullable()->after('cortado_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dropi_cortes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cortado_por');
            $table->dropColumn('cortado_at');
        });
    }
};
