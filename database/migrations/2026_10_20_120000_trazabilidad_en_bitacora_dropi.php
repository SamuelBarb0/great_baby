<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capas de seguridad del empaque/despacho Dropi: cada cambio de estado hecho
 * por una persona queda con desde dónde (GPS del equipo) y con qué equipo (IP
 * y navegador). La bitácora ya guardaba quién y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_estados_bitacora', function (Blueprint $table) {
            if (! Schema::hasColumn('dropi_estados_bitacora', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable()->after('user_id');
                $table->decimal('lng', 10, 7)->nullable()->after('lat');
                $table->unsignedInteger('gps_precision_m')->nullable()->after('lng');
                // ok | denegado | no_disponible | sin_dato
                $table->string('gps_estado', 20)->nullable()->after('gps_precision_m');
                $table->string('ip', 45)->nullable()->after('gps_estado');
                $table->string('user_agent', 255)->nullable()->after('ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dropi_estados_bitacora', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'gps_precision_m', 'gps_estado', 'ip', 'user_agent']);
        });
    }
};
