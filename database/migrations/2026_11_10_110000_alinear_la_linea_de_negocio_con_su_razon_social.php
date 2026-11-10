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

        $empresaDropi = DB::table('empresa_config')->where('codigo', $codigoDropi)->value('id');

        if (! $empresaDropi) {
            return;
        }

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
