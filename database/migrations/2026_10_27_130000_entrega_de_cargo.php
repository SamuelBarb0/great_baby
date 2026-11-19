<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase F2 · La entrega de cargo y el paz y salvo.
 *
 * Cuando alguien se va, el formato «Entrega de cargo - paz y salvo» recorre
 * TRES áreas, cada una con su propia firma:
 *
 *   1. **Jefe inmediato** — qué hacía, qué deja a medias, dónde están sus
 *      archivos digitales y físicos, y qué equipos entrega.
 *   2. **Gestión Humana** — la dotación.
 *   3. **Tesorería** — el paz y salvo de plata.
 *
 * Hoy no existía nada de eso: la persona se iba y lo que sabía se iba con
 * ella. El sistema sólo sabía qué cosas le habían entregado.
 *
 * ── Lo que NO se guarda acá ─────────────────────────────────────────────────
 *
 * Los equipos y la dotación ya viven en `rrhh_asignaciones` CON SERIAL, y el
 * formato los separa igual que `Asignacion::TIPOS`: `equipo`/`herramienta` van
 * en el bloque del jefe y `dotacion` en el de Gestión Humana. El impreso los
 * lee de allá. Volver a escribirlos acá sería tener el mismo hecho en dos
 * tablas y, el día que difieran, nadie sabría cuál creer.
 *
 * ── Por qué tres confirmaciones y no una ────────────────────────────────────
 *
 * Porque son tres áreas distintas y ninguna puede responder por la otra: el
 * jefe no sabe si tesorería tiene algo pendiente, y tesorería no sabe si la
 * persona dejó documentado dónde están sus archivos. Un solo botón de «listo»
 * haría que quien lo apriete firme por las tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrhh_entregas_cargo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('rrhh_empleados')->cascadeOnDelete();

            $t->date('fecha');

            /*
             * Quién recibe el cargo.
             *
             * Puede ser alguien del sistema o no: muchas veces el cargo queda
             * provisionalmente con el propio jefe, o con alguien que todavía
             * no está creado como usuario. Por eso van los dos campos y el
             * nombre libre no es un respaldo sino un caso normal.
             */
            $t->foreignId('recibe_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recibe_nombre', 160)->nullable();

            $t->text('observaciones')->nullable();

            // Las tres firmas del formato, cada una con su responsable.
            $t->timestamp('jefe_confirmo_at')->nullable();
            $t->foreignId('jefe_confirmo_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('gh_confirmo_at')->nullable();
            $t->foreignId('gh_confirmo_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('tesoreria_confirmo_at')->nullable();
            $t->foreignId('tesoreria_confirmo_por_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            // Una por persona: si se corrige, se corrige la que hay.
            $t->unique('empleado_id');
        });

        /*
         * Las listas del formato, todas con la misma forma: un nombre y un
         * detalle.
         *
         * Una tabla y no seis columnas de texto porque son LISTAS de largo
         * variable —ocho funciones, tres pendientes, dos carpetas— y meterlas
         * en un campo de texto las volvería imposibles de leer de a una.
         */
        Schema::create('rrhh_entrega_cargo_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entrega_id')->constrained('rrhh_entregas_cargo')->cascadeOnDelete();

            // funcion · pendiente · archivo_digital · archivo_fisico ·
            // paz_salvo · contacto
            $t->string('bloque', 20);
            $t->string('nombre', 250);
            // La frecuencia de una función, la ubicación de un archivo, la
            // observación de un concepto de tesorería: cambia según el bloque.
            $t->string('detalle', 250)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);

            $t->timestamps();

            $t->index(['entrega_id', 'bloque', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrhh_entrega_cargo_items');
        Schema::dropIfExists('rrhh_entregas_cargo');
    }
};
