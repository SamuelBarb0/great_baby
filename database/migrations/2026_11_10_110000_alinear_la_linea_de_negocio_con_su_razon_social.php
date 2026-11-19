<?php

/*
 * Alinear la línea de negocio con la razón social que factura.
 *
 * El candado que impide mezclar Dropi con Great Baby —«que nunca se me vayan a
 * unir, siempre las necesito aparte», pedido con todas las letras— se puso en
 * el hook de `saving`, que corre en CADA guardado de la factura: al aplicar un
 * pago, al cambiar el estado, al tocar el saldo.
 *
 * Pero no vino con nada que arreglara lo que ya estaba guardado. Las facturas
 * de antes se crearon cuando la columna tenía default `great_baby` y nadie
 * miraba la razón social, así que una factura emitida por la empresa de Dropi
 * puede tener escrito `great_baby`. Esa factura quedaba imposible de guardar
 * para siempre: ni su pago se podía registrar, y el error hablaba de líneas de
 * negocio, que no tiene nada que ver con lo que la persona estaba haciendo.
 *
 * Acá se realinean esas filas. Manda la razón social, que es lo que ya hace el
 * hook de `creating` y lo que dice el libro contable.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facturas_venta') || ! Schema::hasColumn('facturas_venta', 'linea_negocio')) {
            return;
        }

        // La regla vive en `reglas_negocio` (clave/valor). Si todavía no está
        // guardada se cae al default del código, que es lo que lee `setting()`.
        $codigoDropi = trim((string) (
            DB::table('reglas_negocio')->where('clave', 'dropi.empresa')->value('valor')
            ?? (\App\Support\Reglas::DEFAULTS['dropi.empresa']['valor'] ?? '')
        ));

        if ($codigoDropi === '') {
            // Sin empresa de Dropi configurada no hay nada que separar.
            return;
        }

        $empresa = DB::table('empresa_config')->where('codigo', $codigoDropi)->first();

        if (! $empresa) {
            return;
        }

        /*
         * Si la razón social de Dropi ES la principal, acá no hay nada que
         * realinear — y realinear sería destruir.
         *
         * Esta migración se escribió cuando `dropi.empresa` valía `ecommerce`:
         * una razón social DEDICADA al canal, con la que no se factura otra
         * cosa. Bajo ese supuesto, «toda factura de esa empresa es de Dropi»
         * era cierto.
         *
         * El 7-nov eso dejó de ser cierto: la regla pasó a apuntar a
         * `great_baby` —misma empresa, otra resolución DIAN—, que es con la
         * que se factura TODO. Con ese valor, el UPDATE de abajo marca como
         * Dropi hasta la última venta B2B del portal, y `down()` no revierte
         * nada. En esta máquina se salvó de casualidad, por el orden en que
         * corrieron los lotes; en producción corre con la regla ya cambiada.
         *
         * Mismo criterio que usa `DropiReestamparLineaCommand`: una razón
         * social que factura las dos líneas no distingue ninguna.
         */
        if ((int) ($empresa->es_principal ?? 0) === 1) {
            return;
        }

        $empresaDropi = $empresa->id;

        // La factura que sale por la razón social de Dropi es de Dropi, diga
        // lo que diga la columna.
        $corregidas = DB::table('facturas_venta')
            ->where('empresa_id', $empresaDropi)
            ->where(fn ($q) => $q->where('linea_negocio', '!=', 'dropi')->orWhereNull('linea_negocio'))
            ->update(['linea_negocio' => 'dropi']);

        if ($corregidas > 0) {
            echo "  · {$corregidas} factura(s) realineadas a la línea Dropi.".PHP_EOL;
        }
    }

    /*
     * No se revierte.
     *
     * Volver a escribir la línea equivocada dejaría esas facturas otra vez
     * imposibles de guardar, que es exactamente el problema que esto arregla.
     */
    public function down(): void {}
};
