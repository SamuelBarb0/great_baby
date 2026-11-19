<?php

use App\Auth\Permisos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Tablero de Marketing (MKT-1 a MKT-4, 22-oct-2026).
 *
 *  - productos: público objetivo, «listo para publicar», la visibilidad para
 *    Ventas (el «botoncito de activar o desactivar») y qué piezas lleva.
 *  - marketing_creativos: la nube de creativos por producto / contenedor, con
 *    el permiso de cada archivo (interno, solo ver o descarga).
 *  - marketing_posts: el post de la parrilla ligado al contenedor y sus etapas
 *    grabación → edición → entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $t) {
            $t->string('publico_objetivo', 300)->nullable()->after('beneficios');
            $t->boolean('listo_publicar')->default(false)->after('publico_objetivo');
            // Visible para Ventas (vendedores y portal de clientes). Por defecto
            // SÍ: un producto que entra por SIIGO, Dropi o importación no debe
            // desaparecer del catálogo sin que nadie lo decida.
            $t->boolean('publicado')->default(true)->after('listo_publicar');
            $t->timestamp('publicado_cambiado_en')->nullable()->after('publicado');
            $t->foreignId('publicado_cambiado_por')->nullable()->after('publicado_cambiado_en')
                ->constrained('users')->nullOnDelete();
            $t->boolean('piezas_video')->default(false)->after('publicado_cambiado_por');
            $t->boolean('piezas_reel')->default(false)->after('piezas_video');
            $t->unsignedSmallInteger('piezas_imagenes')->default(0)->after('piezas_reel');
        });

        Schema::create('marketing_creativos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $t->foreignId('importacion_id')->nullable()->constrained('compras_importaciones')->nullOnDelete();
            $t->foreignId('post_id')->nullable()->constrained('marketing_posts')->nullOnDelete();
            $t->string('tipo', 20)->comment('video | reel | imagen | otro');
            $t->string('titulo', 200);
            $t->string('archivo_path')->nullable();
            $t->string('archivo_nombre')->nullable();
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('tamano')->nullable();
            $t->string('enlace_externo', 1000)->nullable();
            $t->string('permiso', 20)->default('interno')->comment('interno | solo_ver | descarga');
            $t->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['producto_id', 'tipo']);
            $t->index('permiso');
        });

        Schema::table('marketing_posts', function (Blueprint $t) {
            $t->foreignId('importacion_id')->nullable()->after('producto_id')
                ->constrained('compras_importaciones')->nullOnDelete();
            $t->string('etapa', 20)->nullable()->after('estado')->comment('grabacion | edicion | entregado');
            $t->timestamp('grabado_en')->nullable()->after('etapa');
            $t->timestamp('editado_en')->nullable()->after('grabado_en');
            $t->timestamp('entregado_en')->nullable()->after('editado_en');
        });

        // El permiso nuevo («Material de marketing») para los roles de la matriz
        // y los root. Solo si los permisos ya se sembraron: si no, la matriz en
        // código decide (Permisos::puede). No se corre el PermisosSeeder entero
        // porque también re-sincroniza los roles que hoy no tienen permisos.
        if (Schema::hasTable('permissions') && Permission::where('name', 'like', 'ver.%')->exists()) {
            $nombre = Permisos::permiso('marketing_material');
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
            $roles = [...Permisos::MATRIZ['marketing_material'], 'Aracely', 'Gerencia'];
            Role::whereIn('name', $roles)->where('guard_name', 'web')->get()
                ->each(fn (Role $r) => $r->givePermissionTo($nombre));
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('marketing_posts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('importacion_id');
            $t->dropColumn(['etapa', 'grabado_en', 'editado_en', 'entregado_en']);
        });
        Schema::dropIfExists('marketing_creativos');
        Schema::table('productos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('publicado_cambiado_por');
            $t->dropColumn([
                'publico_objetivo', 'listo_publicar', 'publicado', 'publicado_cambiado_en',
                'piezas_video', 'piezas_reel', 'piezas_imagenes',
            ]);
        });
    }
};
