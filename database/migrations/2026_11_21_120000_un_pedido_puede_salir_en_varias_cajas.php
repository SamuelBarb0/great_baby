<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un pedido puede salir en varias cajas, y cada caja puede llevar su guía.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EL PROBLEMA
 *
 * El área lo describió el 20-nov-2026: «un mismo pedido puede requerir varias
 * cajas. Interrapidísimo maneja una guía independiente por cada caja; las
 * otras transportadoras manejan una sola guía para la totalidad del pedido».
 *
 * `pedidos_cliente.guia_transportadora` es UN campo de 60 caracteres. Así que
 * un pedido que sale en tres cajas por Interrapidísimo sólo puede guardar una
 * de las tres guías. Las otras dos quedan fuera del ERP —anotadas en un papel—
 * o amontonadas en el mismo campo separadas por comas, y entonces el enlace de
 * rastreo deja de funcionar porque busca una guía y encuentra tres pegadas.
 *
 * Y lo que se pierde no es un dato de adorno: la guía es con lo que se reclama
 * cuando una caja no llega.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ UNA TABLA HIJA Y NO MÁS COLUMNAS
 *
 * Porque el número de cajas no se sabe de antemano. Tres columnas de guía
 * resuelven los pedidos de hasta tres cajas y rompen el de cuatro.
 *
 * `guia_transportadora` SE QUEDA, con la primera guía: es lo que leen hoy la
 * pantalla de seguimiento, el descuento de stock y `urlDeRastreo()`, y
 * migrarlos todos de golpe sería cambiar cinco sitios por una mejora que se
 * puede hacer en uno. La columna pasa a significar «la guía principal», y la
 * tabla tiene el detalle completo.
 */
return new class extends Migration
{
    private const TABLA = 'pedido_cajas';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLA)) {
            return;
        }

        Schema::create(self::TABLA, function (Blueprint $t) {
            $t->id();

            $t->foreignId('pedido_id')
                ->constrained('pedidos_cliente')
                ->cascadeOnDelete();

            // 1, 2, 3… el número de caja dentro del pedido. Es lo que se
            // escribe con marcador en la caja física: «2 de 3».
            $t->unsignedSmallInteger('numero');

            /*
             * La guía de ESTA caja. Nullable a propósito: con las
             * transportadoras que dan una sola guía para todo el pedido, las
             * cajas existen igual —hay que saber cuántos bultos salieron— pero
             * la guía la lleva sólo la primera.
             */
            $t->string('guia', 60)->nullable();

            $t->timestamps();

            // Dos cajas no pueden ser la misma caja del mismo pedido.
            $t->unique(['pedido_id', 'numero'], 'pedido_cajas_pedido_numero_unique');

            // Buscar un pedido por su guía es lo que hace quien atiende un
            // reclamo con el número en la mano.
            $t->index('guia', 'pedido_cajas_guia_index');
        });

        $this->traerLasGuiasQueYaExisten();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLA);
    }

    /**
     * Cada pedido ya despachado estrena su caja 1 con la guía que tenía.
     *
     * Sin esto, la pantalla nueva mostraría «sin cajas» en todo lo que salió
     * antes de hoy, y quien atienda un reclamo de un pedido viejo no
     * encontraría la guía donde la nueva pantalla le dice que está.
     */
    private function traerLasGuiasQueYaExisten(): void
    {
        if (! Schema::hasTable('pedidos_cliente')) {
            return;
        }

        DB::table('pedidos_cliente')
            ->whereNotNull('guia_transportadora')
            ->where('guia_transportadora', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($pedidos) {
                $filas = [];

                foreach ($pedidos as $p) {
                    $filas[] = [
                        'pedido_id' => $p->id,
                        'numero' => 1,
                        'guia' => mb_substr((string) $p->guia_transportadora, 0, 60),
                        'created_at' => $p->despachado_at ?? now(),
                        'updated_at' => now(),
                    ];
                }

                if ($filas) {
                    DB::table(self::TABLA)->insert($filas);
                }
            });
    }
};
