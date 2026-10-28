<?php

/*
 * Quién anuló el pago, cuándo y por qué.
 *
 * `anular()` ponía `estado = 'anulado'` y nada más: no quedaba el nombre, ni la
 * hora, ni el motivo. Y a partir de ahora anular además BORRA el asiento que el
 * pago había escrito en el libro —la cancelación del pasivo con el proveedor y
 * las retenciones practicadas—, así que es una acción que mueve la
 * contabilidad. Una acción así sin firma no se puede auditar: el libro cambia y
 * no hay a quién preguntarle.
 *
 * Las tres columnas son las mismas que ya tiene `facturas_venta` para su propia
 * anulación (`anulada_at`, `anulada_por_user_id`, `motivo_anulacion`), con los
 * nombres en masculino porque acá el sujeto es «el pago».
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_proveedor', function (Blueprint $t) {
            $t->timestamp('anulado_at')->nullable()->after('confirmado_por');
            $t->unsignedBigInteger('anulado_por')->nullable()->after('anulado_at');
            $t->string('motivo_anulacion', 500)->nullable()->after('anulado_por');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_proveedor', function (Blueprint $t) {
            $t->dropColumn(['anulado_at', 'anulado_por', 'motivo_anulacion']);
        });
    }
};
