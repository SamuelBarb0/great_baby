<?php

/*
 * Quién convirtió la cotización no es de quién es la venta.
 *
 * La conversión mandaba al pedido el usuario de la SESIÓN como vendedor, así
 * que cuando Gerencia le convertía la cotización a un vendedor —y tiene que
 * poder hacerlo: el vendedor está en la calle, el cliente llamó y dijo que
 * sí—, el pedido nacía a nombre de Gerencia. La venta, y con ella la comisión,
 * se le iba a quien sólo apretó el botón.
 *
 * El pedido pasa a quedar del vendedor que cotizó, que es quien hizo el
 * trabajo comercial. Pero entonces el dato de quién apretó el botón se perdía,
 * y ese dato importa: es la diferencia entre «Carlos cerró su venta» y
 * «Gerencia cerró la venta de Carlos mientras él manejaba». Por eso se guarda
 * acá, en la cotización, que es donde pasó.
 *
 * `pedidos_cliente` no tiene columna de «quién lo creó» —sólo vendedor_id—,
 * así que éste es el único lugar donde el rastro queda.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cotizaciones') || Schema::hasColumn('cotizaciones', 'convertida_por')) {
            return;
        }

        Schema::table('cotizaciones', function (Blueprint $t) {
            $t->foreignId('convertida_por')->nullable()->after('convertida_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('cotizaciones') && Schema::hasColumn('cotizaciones', 'convertida_por')) {
            Schema::table('cotizaciones', function (Blueprint $t) {
                $t->dropConstrainedForeignId('convertida_por');
            });
        }
    }
};
