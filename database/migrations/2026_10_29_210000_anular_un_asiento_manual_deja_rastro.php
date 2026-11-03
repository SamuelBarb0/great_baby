<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un asiento manual equivocado tiene que poder anularse, y con firma.
 *
 * Hasta hoy no había cómo: la pantalla ofrecía crear y aprobar, nada más. Un
 * asiento mal digitado —la cuenta que no era, el monto cambiado— se quedaba
 * en el libro para siempre y la única salida era un segundo asiento que lo
 * revirtiera, que es correcto en la teoría y en la práctica nadie hace.
 * Medido el 2026-10-29 en producción: un asiento de prueba contra la cuenta
 * 1110, que es de encabezado, quedó con saldo en el balance y hubo que
 * sacarlo por consola.
 *
 * El asiento NO se borra: se marca `anulado` y se queda a la vista en la
 * lista con su motivo. Lo que desaparece del balance son sus líneas, que se
 * borran suave — que es justo lo que el panel contable ya entiende por
 * «anulado» en su casilla «Incluir asientos anulados» (`withTrashed`).
 *
 * El motivo es obligatorio en el controlador: una acción que saca plata del
 * libro sin firma no se puede auditar seis meses después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asientos_manuales', function (Blueprint $table) {
            $table->timestamp('anulado_at')->nullable()->after('estado');
            $table->foreignId('anulado_por')->nullable()->after('anulado_at')->constrained('users');
            $table->text('motivo_anulacion')->nullable()->after('anulado_por');
        });

        // `estado` es un ENUM y «anulado» no estaba entre sus valores: MySQL
        // no falla, trunca y guarda vacío. Se amplía en SQL crudo porque
        // cambiar un enum no se puede expresar con el Blueprint.
        DB::statement(
            "ALTER TABLE asientos_manuales MODIFY estado "
            ."ENUM('borrador','cuadrado','aprobado','sincronizado','anulado') NOT NULL DEFAULT 'borrador'"
        );
    }

    public function down(): void
    {
        // Lo anulado vuelve a «cuadrado» antes de encoger el enum: si queda
        // alguna fila en un valor que el enum ya no admite, el ALTER la trunca.
        DB::table('asientos_manuales')->where('estado', 'anulado')->update(['estado' => 'cuadrado']);

        DB::statement(
            "ALTER TABLE asientos_manuales MODIFY estado "
            ."ENUM('borrador','cuadrado','aprobado','sincronizado') NOT NULL DEFAULT 'borrador'"
        );

        Schema::table('asientos_manuales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['anulado_at', 'motivo_anulacion']);
        });
    }
};
