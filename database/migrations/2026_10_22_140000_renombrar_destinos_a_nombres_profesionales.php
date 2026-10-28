<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Bolsita» era el nombre que salió en la reunión y no puede quedar ni en
 * pantalla ni en la base: se filtra a los exports, a los logs y a cualquier
 * informe que vea el cliente.
 *
 * De paso se separan dos casos que estaban mezclados:
 *   solo_erp → interno   no se declara nada
 *   bolsita  → reparto   una parte se declara y el resto no
 */
return new class extends Migration
{
    private const TABLAS = [
        'movimientos_contables',
        'facturas_venta',
        'gastos_operativos',
        'compras_ordenes',
    ];

    private const CAMBIOS = [
        'solo_erp' => 'interno',
        'bolsita' => 'reparto',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'destino_fiscal')) continue;

            foreach (self::CAMBIOS as $viejo => $nuevo) {
                DB::table($tabla)->where('destino_fiscal', $viejo)->update(['destino_fiscal' => $nuevo]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'destino_fiscal')) continue;

            foreach (self::CAMBIOS as $viejo => $nuevo) {
                DB::table($tabla)->where('destino_fiscal', $nuevo)->update(['destino_fiscal' => $viejo]);
            }
        }
    }
};
