<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Una guía de Dropi no puede tener dos devoluciones.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ, SI YA LO IMPIDE EL CÓDIGO
 *
 * `RegistrarDevolucion` revisa `if ($pedido->devolucion)` y corta con «la guía
 * ya tiene una devolución registrada». Funciona, y por eso hoy no hay ni un
 * caso duplicado.
 *
 * Pero esa comprobación lee y después escribe, sin candado. Dos personas
 * escaneando la misma guía al mismo tiempo —dos pistolas en la mesa de
 * devoluciones, que es exactamente como trabaja el área— pasan las dos.
 *
 * Y lo que cuelga de ahí no es un registro repetido: es una NOTA CRÉDITO.
 * `EmitirNotaCreditoDropi` reserva su turno por DEVOLUCIÓN
 * (`genero_nota_credito`), no por factura, y cada nota vale la factura
 * COMPLETA porque la anula. Dos devoluciones de la misma guía serían dos
 * notas crédito por el total entero: el cliente queda acreditado dos veces y
 * en SIIGO la factura queda con saldo negativo.
 *
 * Una probabilidad baja por un costo alto se cierra en la base, no en el
 * código.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * EL ÍNDICE Y EL BORRADO LÓGICO · leer antes de tocar
 *
 * `dropi_devoluciones` tiene `softDeletes()`, y en MySQL un índice único NO
 * distingue entre una fila viva y una borrada lógicamente: las dos ocupan el
 * mismo lugar. O sea que si alguien borrara lógicamente una devolución, esta
 * guía NUNCA más podría registrar otra —y el mensaje sería un error de base
 * de datos, no una explicación.
 *
 * Hoy eso no puede pasar: se revisó el código y NADIE borra devoluciones, ni
 * lógica ni físicamente. Por eso el índice va sobre `pedido_id` a secas.
 *
 * Si mañana se agrega una pantalla que borre devoluciones, este índice hay
 * que repensarlo ANTES. `UNIQUE(pedido_id, deleted_at)` no sirve de reemplazo:
 * MySQL trata cada NULL como distinto, así que dejaría pasar dos devoluciones
 * vivas, que es exactamente lo que esto viene a impedir.
 * ───────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    private const TABLA = 'dropi_devoluciones';

    private const INDICE = 'dropi_devoluciones_pedido_id_unique';

    /**
     * ¿Está puesto el índice?
     *
     * Hace falta en las DOS direcciones, y por un motivo que no es simetría
     * decorativa: el `up()` de abajo tiene a propósito un camino que NO crea
     * el índice (cuando ya hay duplicados). Laravel igual deja la migración
     * registrada como corrida. Si después hay que echar atrás el despliegue,
     * un `dropUnique` a secas da el error 1091 «Can't DROP; check that
     * column/key exists», el rollback se corta a mitad y la base queda a
     * medio revertir — justo en la ventana de emergencia en que uno menos lo
     * quiere.
     *
     * Es la misma guarda que ya usan `2026_09_28_150001` y `2026_10_01_020000`
     * en este repositorio.
     */
    private function hayIndice(): bool
    {
        return ! empty(DB::select(
            'SHOW INDEXES FROM `'.self::TABLA.'` WHERE Key_name = ?',
            [self::INDICE],
        ));
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLA) || $this->hayIndice()) {
            return;
        }

        /*
         * Si ya hay duplicados, el índice no entra y la migración reventaría
         * a mitad del despliegue. Se avisa y se sigue: el dato existente es
         * de alguien y no se borra desde acá.
         */
        $duplicados = DB::table(self::TABLA)
            ->select('pedido_id')
            ->groupBy('pedido_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('pedido_id');

        if ($duplicados->isNotEmpty()) {
            Log::warning(
                'No se pudo poner el índice único de devoluciones de Dropi: ya hay guías con más de una. '
                .'Hay que revisar si emitieron nota crédito de más.',
                ['pedidos' => $duplicados->take(20)->all(), 'cuantos' => $duplicados->count()],
            );

            return;
        }

        Schema::table(self::TABLA, function (Blueprint $t) {
            $t->unique('pedido_id', self::INDICE);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLA) || ! $this->hayIndice()) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $t) {
            $t->dropUnique(self::INDICE);
        });
    }
};
