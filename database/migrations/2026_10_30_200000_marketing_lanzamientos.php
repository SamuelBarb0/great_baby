<?php

use App\Auth\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Tablero de lanzamiento por contenedor (30-oct-2026, «TABLERO GREAT BABY.pdf»).
 *
 *  - marketing_lanzamientos: uno por contenedor (importación).
 *  - marketing_lanzamiento_tarjetas: las 10 tarjetas del proceso, en una de las
 *    5 listas (por hacer, en proceso, pendiente de aprobación, bloqueado,
 *    finalizado), con responsable, vencimiento y los datos del bloqueo.
 *  - marketing_lanzamiento_items: el checklist de cada tarjeta.
 *  - marketing_lanzamiento_comentarios: comentarios y el rastro de cada
 *    movimiento, bloqueo y aprobación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_lanzamientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('importacion_id')->unique()->constrained('compras_importaciones')->cascadeOnDelete();
            $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('finalizado_en')->nullable();
            $t->timestamps();
        });

        Schema::create('marketing_lanzamiento_tarjetas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lanzamiento_id')->constrained('marketing_lanzamientos')->cascadeOnDelete();
            $t->string('clave', 30);
            $t->unsignedTinyInteger('numero');
            $t->string('titulo', 120);
            $t->string('area', 30)->comment('compras | dropshipping | marketing | bodega | marketing_dropshipping');
            $t->string('lista', 30)->default('por_hacer')->comment('por_hacer | en_proceso | pendiente_aprobacion | bloqueado | finalizado');
            $t->boolean('requiere_aprobacion')->default(false);
            $t->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('vence_en')->nullable();
            $t->text('bloqueo_motivo')->nullable();
            $t->foreignId('bloqueo_responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('bloqueo_accion')->nullable();
            $t->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('aprobada_en')->nullable();
            $t->timestamp('finalizada_en')->nullable();
            $t->timestamps();
            $t->unique(['lanzamiento_id', 'clave']);
            $t->index('lista');
        });

        Schema::create('marketing_lanzamiento_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tarjeta_id')->constrained('marketing_lanzamiento_tarjetas')->cascadeOnDelete();
            $t->string('texto', 200);
            $t->string('grupo', 30)->nullable()->comment('En la tarjeta de Publicación: marketing | dropshipping');
            $t->unsignedSmallInteger('posicion')->default(0);
            $t->foreignId('hecho_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('hecho_en')->nullable();
            $t->timestamps();
        });

        Schema::create('marketing_lanzamiento_comentarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tarjeta_id')->constrained('marketing_lanzamiento_tarjetas')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('tipo', 20)->default('comentario')->comment('comentario | movimiento | bloqueo | aprobacion | devolucion');
            $t->text('cuerpo');
            $t->timestamps();
        });

        // El permiso nuevo («Tablero de lanzamiento») para los roles de la matriz
        // y los root, como con «Material de marketing»: solo si los permisos ya
        // se sembraron; si no, decide la matriz en código (Permisos::puede).
        if (Schema::hasTable('permissions') && Permission::where('name', 'like', 'ver.%')->exists()) {
            $nombre = Permisos::permiso('marketing_lanzamientos');
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
            $roles = [...Permisos::MATRIZ['marketing_lanzamientos'], 'Aracely', 'Gerencia'];
            Role::whereIn('name', $roles)->where('guard_name', 'web')->get()
                ->each(fn (Role $r) => $r->givePermissionTo($nombre));
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_lanzamiento_comentarios');
        Schema::dropIfExists('marketing_lanzamiento_items');
        Schema::dropIfExists('marketing_lanzamiento_tarjetas');
        Schema::dropIfExists('marketing_lanzamientos');
        Permission::where('name', Permisos::permiso('marketing_lanzamientos'))->delete();
    }
};
