<?php

/**
 * Las tres cosas que al despacho parcial le faltaban para poder CONTARSE.
 *
 * Un pedido ya puede salir en varios viajes y las guías de todos sobreviven.
 * Lo que no se podía era responder tres preguntas que en bodega se hacen todos
 * los días:
 *
 *   1. «¿Cuándo salió la primera caja?» — No existía la fecha. `despachado_at`
 *      se deja NULL mientras quede algo por entregar (el candado de
 *      idempotencia lo mira), así que un pedido que salió a medias hace ocho
 *      días figuraba como si nunca hubiera salido. Nadie podía ver que esa
 *      primera caja ya estaba vencida.
 *
 *   2. «¿Estas dos cajas salieron juntas?» — Las cajas se numeran corrido
 *      (1, 2, 3…) y eso basta para no perder ninguna, pero no para saber cuál
 *      viajó con cuál. Es lo primero que se pregunta cuando el cliente dice
 *      que le llegaron dos de tres.
 *
 *   3. Y la base no impedía guardar la misma guía dos veces en el mismo
 *      pedido. El control está en el controlador —y se probó— pero un control
 *      que vive sólo en una ruta se salta con un seeder, un tinker o una ruta
 *      nueva que nadie recuerde revisar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CAJAS = 'pedido_cajas';

    private const PEDIDOS = 'pedidos_cliente';

    private const UNICA_GUIA = 'pedido_cajas_pedido_guia_unique';

    public function up(): void
    {
        if (Schema::hasTable(self::PEDIDOS) && ! Schema::hasColumn(self::PEDIDOS, 'primer_despacho_at')) {
            Schema::table(self::PEDIDOS, function (Blueprint $t) {
                /*
                 * Cuándo salió la PRIMERA caja.
                 *
                 * No reemplaza a `despachado_at`, que sigue significando «ya
                 * salió todo». Ésta significa «algo de este pedido ya está en
                 * la calle», y es la que sirve para contar días de tránsito y
                 * para reclamarle a la transportadora.
                 */
                $t->timestamp('primer_despacho_at')->nullable()->after('despachado_at');
            });

            $this->ponerLaFechaDeLosQueYaSalieron();
        }

        if (Schema::hasTable(self::CAJAS) && ! Schema::hasColumn(self::CAJAS, 'viaje')) {
            Schema::table(self::CAJAS, function (Blueprint $t) {
                /*
                 * De qué viaje es esta caja. Empieza en 1.
                 *
                 * Se podría deducir del `created_at` —las cajas de un viaje se
                 * crean en la misma transacción— pero inferir por marca de
                 * tiempo es frágil: dos despachos del mismo pedido en el mismo
                 * segundo, un reloj corrido, o una corrección a mano y el
                 * agrupamiento miente sin avisar.
                 */
                $t->unsignedTinyInteger('viaje')->default(1)->after('numero');
            });

            $this->repartirLasCajasQueYaExisten();
        }

        if (Schema::hasTable(self::CAJAS) && ! $this->hayIndice(self::UNICA_GUIA)) {
            /*
             * La misma guía no se repite DENTRO de un pedido.
             *
             * No es única global a propósito: dos pedidos distintos pueden ir
             * en el mismo envío consolidado y compartir número. Lo que no puede
             * pasar es que un pedido tenga la misma guía en dos cajas, porque
             * entonces nadie sabe cuál de las dos es la que no llegó.
             *
             * Se limpia antes de crearlo: si la base ya trae repetidas, el
             * índice no se puede crear y la migración moriría a mitad de camino.
             */
            $this->quitarGuiasRepetidas();

            Schema::table(self::CAJAS, function (Blueprint $t) {
                $t->unique(['pedido_id', 'guia'], self::UNICA_GUIA);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::CAJAS) && $this->hayIndice(self::UNICA_GUIA)) {
            Schema::table(self::CAJAS, fn (Blueprint $t) => $t->dropUnique(self::UNICA_GUIA));
        }

        if (Schema::hasTable(self::CAJAS) && Schema::hasColumn(self::CAJAS, 'viaje')) {
            Schema::table(self::CAJAS, fn (Blueprint $t) => $t->dropColumn('viaje'));
        }

        if (Schema::hasTable(self::PEDIDOS) && Schema::hasColumn(self::PEDIDOS, 'primer_despacho_at')) {
            Schema::table(self::PEDIDOS, fn (Blueprint $t) => $t->dropColumn('primer_despacho_at'));
        }
    }

    /** ¿Está puesto ese índice? Se pregunta a la base, no se supone. */
    private function hayIndice(string $nombre): bool
    {
        return ! empty(DB::select(
            'SHOW INDEXES FROM `'.self::CAJAS.'` WHERE Key_name = ?', [$nombre]
        ));
    }

    /**
     * Lo que ya salió completo salió de una: su primer despacho es su despacho.
     *
     * Sin esto, todos los pedidos viejos quedarían sin fecha de primer envío y
     * el seguimiento los contaría como si no hubieran salido nunca.
     */
    private function ponerLaFechaDeLosQueYaSalieron(): void
    {
        DB::table(self::PEDIDOS)
            ->whereNotNull('despachado_at')
            ->whereNull('primer_despacho_at')
            ->update(['primer_despacho_at' => DB::raw('despachado_at')]);
    }

    /**
     * Las cajas que ya existen son todas del viaje 1.
     *
     * Es cierto por construcción: hasta hoy el segundo despacho BORRABA las
     * cajas del primero antes de recrearlas, así que lo que hay en la tabla es
     * siempre de un solo viaje —el último—. El `default(1)` ya las deja así;
     * esto está por si alguna fila entró con la columna en NULL.
     */
    private function repartirLasCajasQueYaExisten(): void
    {
        DB::table(self::CAJAS)->whereNull('viaje')->update(['viaje' => 1]);
    }

    /**
     * Si la base ya trae guías repetidas dentro de un pedido, se deja la
     * primera y se vacía la guía de las demás.
     *
     * Vaciar y no borrar: la caja existió y salió: lo que no se sabe es con qué
     * guía. Borrar la fila perdería el bulto; dejarla sin guía dice la verdad.
     */
    private function quitarGuiasRepetidas(): void
    {
        $repetidas = DB::table(self::CAJAS)
            ->select('pedido_id', 'guia', DB::raw('MIN(id) as primera'), DB::raw('COUNT(*) as cuantas'))
            ->whereNotNull('guia')
            ->groupBy('pedido_id', 'guia')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($repetidas as $r) {
            DB::table(self::CAJAS)
                ->where('pedido_id', $r->pedido_id)
                ->where('guia', $r->guia)
                ->where('id', '!=', $r->primera)
                ->update(['guia' => null]);
        }
    }
};
