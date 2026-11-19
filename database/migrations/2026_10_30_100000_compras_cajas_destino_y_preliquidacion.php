<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras · la orden de importación como la arma Alejandro en su Excel.
 *
 * Él no pide unidades sueltas: pide CAJAS de N unidades, y lo que llena el
 * contenedor (68 m³) es el volumen de cada caja. Cada línea va además para
 * Great Baby o para el e-commerce de Aracely (las cajas marcadas «DROP»), y
 * esa división tiene que viajar hasta la recepción.
 *
 * Todo nullable: las OC nacionales y las viejas siguen siendo unidades sueltas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_orden_items', function (Blueprint $table) {
            $table->unsignedInteger('cajas')->nullable()->after('cantidad');
            $table->unsignedInteger('unidades_por_caja')->nullable()->after('cajas');
            $table->decimal('caja_largo_cm', 8, 2)->nullable()->after('unidades_por_caja');
            $table->decimal('caja_ancho_cm', 8, 2)->nullable()->after('caja_largo_cm');
            $table->decimal('caja_alto_cm', 8, 2)->nullable()->after('caja_ancho_cm');
            // Se puede escribir directo: muchas filas de su Excel traen el CBM sin medidas.
            $table->decimal('cbm_caja', 10, 6)->nullable()->after('caja_alto_cm');
            $table->decimal('peso_caja_kg', 8, 2)->nullable()->after('cbm_caja');
            // NULL = Great Baby (la principal), igual que en pedidos y facturas.
            $table->foreignId('empresa_destino_id')->nullable()->after('peso_caja_kg')
                ->constrained('empresa_config')->nullOnDelete();
        });

        Schema::table('compras_ordenes', function (Blueprint $table) {
            // Tasa del RMB, comisión, flete y gasto por m³, arancel, IVA, divisores
            // de precio, capacidad y valor mínimo. Ver PreliquidarOrden::DEFAULTS.
            $table->json('parametros_preliquidacion')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('compras_ordenes', function (Blueprint $table) {
            $table->dropColumn('parametros_preliquidacion');
        });

        Schema::table('compra_orden_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_destino_id');
            $table->dropColumn([
                'cajas', 'unidades_por_caja', 'caja_largo_cm', 'caja_ancho_cm',
                'caja_alto_cm', 'cbm_caja', 'peso_caja_kg',
            ]);
        });
    }
};
