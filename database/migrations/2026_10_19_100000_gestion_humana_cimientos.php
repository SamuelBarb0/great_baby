<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F0 de Gestión Humana · los cimientos del módulo.
 *
 * El módulo `Rrhh` ya existía como esqueleto: vacantes, candidatos, empleados
 * y documentos, con pantallas básicas. Lo que la reunión del 19-oct dejó claro
 * es que falta todo lo de ANTES de la vacante y todo el rastro de lo que pasa
 * después:
 *
 *   · `rrhh_requisiciones` — la solicitud de personal que hace un líder de
 *     área y que Gestión Humana aprueba. Hoy es «la hojita» que a veces llega
 *     y a veces se pierde, y con ella se pierde el rastro del proceso entero.
 *
 *   · `rrhh_bitacora` — quién hizo qué y cuándo, en cualquier registro del
 *     módulo. Elena lo pidió textual: que el jefe pueda abrir su móvil y ver
 *     en qué etapa van sus candidatos sin tener que preguntar.
 *
 * Además corrige el enum de etapas de `rrhh_candidatos`, que colapsaba en una
 * sola «entrevista» las dos que Great Baby hace de verdad (jefe y psicología).
 * La tabla está vacía, así que no hay datos que migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── La solicitud de personal ──────────────────────────────────────
        Schema::create('rrhh_requisiciones', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 30)->unique();

            // Lo que el líder pide. Los campos salen de lo que Elena enumeró:
            // motivo, cargo, nº de vacantes, funciones, competencias, estudio
            // y experiencia, salario, quién solicita y para cuándo.
            $t->string('cargo', 160);
            $t->string('area', 120)->nullable();
            $t->unsignedSmallInteger('numero_vacantes')->default(1);
            $t->string('motivo', 500)->nullable();
            $t->text('funciones')->nullable();
            $t->text('competencias')->nullable();
            $t->string('estudios', 300)->nullable();
            $t->string('experiencia', 300)->nullable();
            $t->decimal('salario_min', 14, 2)->nullable();
            $t->decimal('salario_max', 14, 2)->nullable();
            $t->string('tipo_contrato', 60)->nullable();
            $t->date('fecha_requerida')->nullable();

            // Quién la pide: es el dato que gobierna el permiso de ver su
            // estado desde el móvil.
            $t->foreignId('solicitante_id')->constrained('users')->cascadeOnUpdate();

            $t->string('estado', 20)->default('borrador')->index();
            $t->timestamp('enviada_at')->nullable();
            $t->foreignId('aprobada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('aprobada_at')->nullable();
            $t->timestamp('rechazada_at')->nullable();
            $t->string('motivo_rechazo', 500)->nullable();
            $t->text('observaciones')->nullable();

            $t->timestamps();
            $t->softDeletes();
            $t->index(['estado', 'created_at']);
        });

        // ── El rastro de todo ─────────────────────────────────────────────
        Schema::create('rrhh_bitacora', function (Blueprint $t) {
            $t->id();
            // Polimórfica a propósito: la usan requisiciones, candidatos,
            // empleados y lo que venga, sin una tabla por cada uno.
            $t->morphs('registrable');
            $t->string('evento', 60);
            $t->string('descripcion', 500)->nullable();
            $t->string('de_estado', 40)->nullable();
            $t->string('a_estado', 40)->nullable();
            // Detalle libre (campos que cambiaron, resultado de una prueba…)
            // sin tener que migrar la tabla cada vez.
            $t->json('datos')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['registrable_type', 'registrable_id', 'created_at'], 'idx_bitacora_registro');
        });

        // ── La vacante nace de una requisición aprobada ───────────────────
        Schema::table('rrhh_vacantes', function (Blueprint $t) {
            $t->foreignId('requisicion_id')->nullable()->after('id')
                ->constrained('rrhh_requisiciones')->nullOnDelete();
            // De dónde salieron los candidatos (caja de compensación, redes,
            // correo, referido). Sin integración externa: es un registro.
            $t->string('fuente', 60)->nullable()->after('modalidad');
        });

        // ── Las etapas reales del candidato ───────────────────────────────
        // De `enum` a `string`: agregar una etapa no puede costar una
        // migración de esquema, y el enum de PHP ya gobierna los valores.
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->string('etapa_tmp', 40)->default('recibido')->after('etapa');
        });
        DB::table('rrhh_candidatos')->update(['etapa_tmp' => DB::raw('etapa')]);
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->dropColumn('etapa');
        });
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->renameColumn('etapa_tmp', 'etapa');
        });
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->index('etapa');
            // El dictamen de Gestión Humana y su salvedad.
            $t->string('valoracion', 30)->nullable()->after('calificacion');
            $t->string('valoracion_observacion', 500)->nullable()->after('valoracion');
            // Antecedentes judiciales: Elena los revisa ANTES de citar a
            // entrevista, en la página de la rama judicial. Acá sólo queda el
            // registro de que se hizo y qué salió.
            $t->boolean('antecedentes_revisados')->default(false)->after('valoracion_observacion');
            $t->string('antecedentes_resultado', 300)->nullable()->after('antecedentes_revisados');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_candidatos', function (Blueprint $t) {
            $t->dropIndex(['etapa']);
            $t->dropColumn(['valoracion', 'valoracion_observacion', 'antecedentes_revisados', 'antecedentes_resultado']);
        });
        Schema::table('rrhh_vacantes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requisicion_id');
            $t->dropColumn('fuente');
        });
        Schema::dropIfExists('rrhh_bitacora');
        Schema::dropIfExists('rrhh_requisiciones');
    }
};
