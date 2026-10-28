<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La otra mitad de la bolsita: compras que no se declaran.
 *
 * Hasta hoy los dos ejes sólo existían del lado de las ventas, y toda compra
 * caía en «fiscal» por el valor por defecto del estampado. Eso deja el balance
 * interno con ingresos que no se declaran y ningún costo que no se declara —o
 * sea, con la utilidad inflada— y además manda a SIIGO compras que quizá no
 * tienen factura con qué soportarse.
 *
 * El destino se decide en la ORDEN, que es cuando se sabe si el proveedor va a
 * facturar, y la RECEPCIÓN lo hereda. Va también en la recepción, y no sólo en
 * la orden, por dos razones:
 *
 *   1) `RecepcionCompra` es el `origen` de los asientos de compra, así que el
 *      estampado de `MovimientoContable` lo encuentra ahí sin que haya que
 *      tocar `RegistrarAsientoCompra`.
 *   2) La factura del proveedor a veces aparece después. Poder corregirlo en la
 *      recepción evita tener que anular y rehacer la orden entera.
 *
 * No se agrega `monto_fiscal`: en compras no hay «reparto». Declarar media
 * compra significaría descontar IVA de una factura que no existe por el resto,
 * y eso no es una decisión comercial sino un problema. Fiscal o interna.
 *
 * Por defecto todo queda «siigo», que es exactamente lo que pasa hoy: esta
 * migración no cambia el comportamiento de nada, sólo lo hace decidible.
 */
return new class extends Migration
{
    private const TABLAS = ['compras_ordenes', 'compras_recepciones'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'linea_negocio')) {
                    $t->string('linea_negocio', 20)->default('great_baby')->index()
                        ->comment('De quién es la compra: great_baby | dropi');
                }
                if (! Schema::hasColumn($tabla, 'destino_fiscal')) {
                    $t->string('destino_fiscal', 20)->default('siigo')->index()
                        ->comment('Si la compra se declara: siigo | interno');
                }
                if (! Schema::hasColumn($tabla, 'motivo_destino')) {
                    $t->string('motivo_destino', 300)->nullable()
                        ->comment('Por qué una compra no se declara. Obligatorio al sacarla de lo fiscal.');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                foreach (['linea_negocio', 'destino_fiscal', 'motivo_destino'] as $col) {
                    if (Schema::hasColumn($tabla, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
