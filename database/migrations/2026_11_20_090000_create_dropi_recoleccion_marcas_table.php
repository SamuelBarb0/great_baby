<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El «chulito» del alistador (reunión del 19-nov-2026): en el modo Recolección
 * de la Vista del Alistador, quien recoge marca cada referencia del corte que ya
 * sacó de la bodega, en vez de resaltarla en la hoja impresa. Una marca por
 * corte y SKU, compartida entre tablets. Guarda la cantidad que había al marcar:
 * si después entra otro pedido al corte con esa referencia, la fila vuelve a
 * pendiente con lo que falta.
 *
 * No se usan `picking_lists`: exigen variante y ubicación, y los productos
 * agregados (la mayoría de los de Dropi) no tienen variante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropi_recoleccion_marcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corte_id')->constrained('dropi_cortes')->cascadeOnDelete();
            $table->string('sku_dropi', 120);
            $table->unsignedInteger('cantidad');
            $table->foreignId('marcado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marcado_at');
            $table->timestamps();

            $table->unique(['corte_id', 'sku_dropi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropi_recoleccion_marcas');
    }
};
