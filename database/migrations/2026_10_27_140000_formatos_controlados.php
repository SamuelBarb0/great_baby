<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase G · Control documental.
 *
 * Cada formato que imprime el ERP lleva arriba un recuadro con tres datos:
 * código, versión y fecha de la versión. No es decoración — es lo que permite
 * decirle a una auditoría «esto se diligenció con la versión 002 del GH-F-015,
 * vigente desde tal fecha».
 *
 * Hasta ahora esos tres datos estaban ESCRITOS A MANO dentro de cada plantilla
 * Blade, cada una con su propia fecha: 07/ENE/2026 en tres formatos,
 * 11/07/2023 en uno, 30/05/2023 en otro, y la entrega de cargo imprimiendo en
 * ese renglón la fecha DEL REGISTRO, que no es lo mismo ni parecido.
 *
 * Consecuencia práctica: el día que Gestión Humana saque la versión 002 de un
 * formato —que es lo normal, para eso existe el número— tiene que pedirle al
 * desarrollador que le edite una plantilla. Así no se mantiene un sistema
 * documental: se abandona.
 *
 * ── Por qué una tabla y no una constante en el código ───────────────────────
 *
 * Porque el dueño de estos datos es el área, no el programa. El listado
 * maestro de documentos es del sistema de gestión: lo actualiza quien controla
 * los formatos, desde una pantalla, el día que aprueba una versión nueva.
 *
 * ── Por qué el código es opcional ───────────────────────────────────────────
 *
 * Dos de los formatos que mandó el área llegaron SIN código asignado (la
 * entrevista de retiro trae «GH-F-XX» literal). No se inventa uno: se imprime
 * en rojo que está sin asignar, hasta que el área lo asigne de verdad. Un
 * código inventado por el sistema es peor que ninguno, porque parece válido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formatos_controlados', function (Blueprint $t) {
            $t->id();

            /*
             * Con qué nombre lo pide el código.
             *
             * El código del formato (GH-F-015) NO sirve como llave: cambia
             * —es justo lo que el área va a editar acá— y hoy dos formatos no
             * tienen ninguno. La llave es interna y estable.
             */
            $t->string('clave', 60)->unique();

            // Lo que va en el recuadro de control.
            $t->string('codigo', 30)->nullable();
            $t->string('nombre', 160);
            $t->string('proceso', 80);
            $t->string('version', 10)->default('001');
            $t->date('fecha_version')->nullable();

            /*
             * Quién aprobó esta versión y cuándo la registró en el sistema.
             *
             * Sin esto el renglón de versión es un número que cambia solo. La
             * fecha de la versión es la que decide el área —puede ser anterior
             * a hoy—; esto otro es el rastro de quién la puso.
             */
            $t->timestamp('actualizado_at')->nullable();
            $t->foreignId('actualizado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formatos_controlados');
    }
};
