<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada carga masiva de inventario queda como un documento con su porqué.
 *
 * Hasta hoy el Excel de inventario metía existencias al kardex sin decir de
 * dónde salían: ni compra, ni aporte, ni saldo inicial. Para la contadora eso
 * no pasa una auditoría —un inventario de 200 millones «porque se subió» no
 * tiene contrapartida— y en el libro no aparecía nada: el 1435 nunca se movía.
 *
 * Una carga = un motivo = un asiento. Las filas del kardex apuntan acá por
 * `referencia_tipo`/`referencia_id` y el asiento por `origen_type`/`origen_id`,
 * así desde cualquiera de los tres se llega a los otros dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargas_inventario', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 20)->unique();
            $t->string('motivo', 32);
            $t->text('observacion');
            // Número de factura, acta o documento que respalda el ingreso.
            $t->string('soporte', 60)->nullable();
            $t->foreignId('tercero_id')->nullable()->constrained('contactos')->nullOnDelete();
            $t->foreignId('ubicacion_id')->constrained('inventario_ubicaciones');
            $t->string('archivo_nombre', 191)->nullable();
            // web = pantalla, cli = `inventario:cargar-excel-cliente`.
            $t->string('canal', 10)->default('web');
            // Se guarda la cuenta usada en el momento: si la contadora cambia
            // la regla después, el documento sigue diciendo lo que se asentó.
            $t->string('cuenta_contrapartida', 20);
            $t->decimal('total_unidades', 14, 4)->default(0);
            $t->decimal('total_valor', 14, 2)->default(0);
            $t->unsignedInteger('referencias')->default(0);
            // Filas que movieron stock pero no tenían costo: no entran al asiento.
            $t->unsignedInteger('referencias_sin_costo')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['motivo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargas_inventario');
    }
};
