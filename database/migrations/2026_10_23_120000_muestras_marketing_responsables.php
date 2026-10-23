<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MKT-5 · Muestras a marketing con responsables.
 *
 * El cliente lo pidió así: «donde tú la solicitas, se lista y queda el
 * responsable, quién la recogió, quién la entregó y en qué estado». El
 * traslado ya guardaba quién pidió (`solicitado_por`), quién despachó
 * (`enviado_por`) y quién la marcó recibida (`ejecutado_por`), pero no quién
 * la aprobó, qué persona de Marketing se la llevó en la mano ni cómo estaba la
 * mercancía. Y la devolución no sabía de qué préstamo venía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traslados_inventario', function (Blueprint $table) {
            $table->foreignId('aprobado_por')->nullable()->after('solicitado_por')->constrained('users');
            $table->timestamp('aprobado_at')->nullable()->after('fecha_solicitud');
            // Quien la recogió (préstamo) o quien la trajo de vuelta (devolución).
            $table->foreignId('recogido_por')->nullable()->after('ejecutado_por')->constrained('users');
            $table->string('estado_mercancia', 20)->nullable()->after('motivo')
                ->comment('completo | incompleto | con_novedad · al entregar o al devolver');
            $table->text('observaciones_entrega')->nullable()->after('observaciones');
            $table->foreignId('prestamo_id')->nullable()->after('motivo')
                ->constrained('traslados_inventario')->nullOnDelete()
                ->comment('En una devolución: el préstamo que cierra');
        });
    }

    public function down(): void
    {
        Schema::table('traslados_inventario', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprobado_por');
            $table->dropConstrainedForeignId('recogido_por');
            $table->dropConstrainedForeignId('prestamo_id');
            $table->dropColumn(['aprobado_at', 'estado_mercancia', 'observaciones_entrega']);
        });
    }
};
