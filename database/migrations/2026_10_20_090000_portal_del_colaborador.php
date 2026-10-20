<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F7 · El acceso del empleado a su propia información.
 *
 * Elena lo planteó así: «la idea es que el empleado pues tenga su parte también
 * de recursos humanos y que tenga también sus avisos». Y es la pieza que
 * destraba otras tres cosas de la reunión: que haga su inducción con su
 * usuario, que avise cuando se le daña una herramienta, y que llene su propia
 * entrevista de retiro.
 *
 * El empleado entra por el MISMO login que todos, con un rol `Colaborador` que
 * sólo abre su portal. No se hizo un guard aparte —como sí tiene el portal de
 * clientes B2B— porque un empleado no es un cliente: usa el mismo layout, la
 * misma campana de avisos y la misma matriz de permisos. Un guard nuevo sería
 * una segunda puerta que mantener.
 *
 * El token de activación vive en la tabla y no en caché (como el reset del
 * portal de clientes) por dos razones prácticas: Gestión Humana crea el acceso
 * y manda el enlace por WhatsApp, así que la persona puede abrirlo al otro día;
 * y un `cache:clear` no puede dejar a nadie sin poder entrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('vacante_id')
                ->constrained('users')->nullOnDelete();

            // Hash del token, nunca el token: quien lea la base no puede
            // activar la cuenta de otro.
            $t->string('activacion_token', 64)->nullable()->after('user_id');
            $t->timestamp('activacion_expira_at')->nullable()->after('activacion_token');
            $t->timestamp('acceso_creado_at')->nullable()->after('activacion_expira_at');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_empleados', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropColumn(['activacion_token', 'activacion_expira_at', 'acceso_creado_at']);
        });
    }
};
