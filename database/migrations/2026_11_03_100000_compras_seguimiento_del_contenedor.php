<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El camino real del contenedor (reuniones con Alejandro y Liz, 21 y 30-oct-2026).
 *
 * `EstadoImportacion` tiene cinco estados y nadie los movía; Liz cuenta unas
 * once etapas entre la fábrica y la bodega, con tiempos que hoy lleva en Excel.
 * El estado se deja como está porque de él dependen el kardex, los reportes y
 * marketing: las etapas van aparte y el estado se deduce de ellas.
 *
 * La selectividad de la DIAN es por DECLARACIÓN, no por contenedor: de diez
 * pueden salir dos físicas y ocho automáticas. Las inspecciones del contenedor
 * (la forzosa en puerto, el aleatorio de la DUTA) son otra cosa y van en su tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_importacion_etapas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('importacion_id')->constrained('compras_importaciones')->cascadeOnDelete();
            $t->string('etapa', 30);
            $t->date('fecha');
            $t->text('notas')->nullable();
            $t->date('ultima_alerta_en')->nullable()->comment('Para no repetir el aviso de atraso todos los días');
            $t->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['importacion_id', 'etapa']);
        });

        Schema::create('compras_importacion_declaraciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('importacion_id')->constrained('compras_importaciones')->cascadeOnDelete();
            $t->string('numero', 40)->nullable();
            $t->string('selectividad', 20)->default('pendiente');
            $t->date('fecha_cita')->nullable()->comment('Cita con el inspector, si salió física o documental');
            $t->date('fecha_levante')->nullable();
            $t->text('notas')->nullable();
            $t->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('compras_importacion_inspecciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('importacion_id')->constrained('compras_importaciones')->cascadeOnDelete();
            $t->string('tipo', 30);
            $t->date('fecha');
            $t->text('notas')->nullable();
            $t->date('resuelta_en')->nullable();
            $t->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_importacion_inspecciones');
        Schema::dropIfExists('compras_importacion_declaraciones');
        Schema::dropIfExists('compras_importacion_etapas');
    }
};
