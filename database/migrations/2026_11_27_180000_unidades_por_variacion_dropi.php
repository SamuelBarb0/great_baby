<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 27-nov-2026: en Dropi «FICHAS MAGNÉTICAS MINECRAFT» se vende «X 1 CAJA»,
 * «X 2 CAJAS» y «X 3 CAJAS», y las tres apuntan al mismo producto del ERP (una
 * caja). La guía decía dos cajas y la lista del corte una. Cada vínculo guarda
 * cuántas unidades del ERP trae una unidad de esa variación de Dropi; 1 por
 * defecto, así que nada de lo que ya existe cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropi_vinculos_producto', function (Blueprint $t) {
            $t->unsignedSmallInteger('unidades')->default(1)->after('variacion')
                ->comment('Unidades del ERP por cada unidad de esta variación de Dropi (packs: «X 2 CAJAS» = 2)');
        });
    }

    public function down(): void
    {
        Schema::table('dropi_vinculos_producto', function (Blueprint $t) {
            $t->dropColumn('unidades');
        });
    }
};
