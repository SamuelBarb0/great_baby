<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EL ALMACÉN 08 · DEVOLUCIONES Y GARANTÍAS DE CLIENTES.
 *
 * Lo pidió Don Jorge por escrito el 23-nov-2026, y hasta hoy no existía: la
 * bodega iba del 01 al 07, así que la mercancía que devuelve un cliente no
 * tenía dónde entrar. O se quedaba en el piso sin existir para el sistema, o
 * se metía al 01 —el almacén de venta— y volvía a ofrecerse a otro cliente
 * antes de que nadie la mirara.
 *
 * Sus palabras: «cuando un cliente realiza una devolución, el producto se
 * incorpora al almacén 08, denominado "Devoluciones y garantías de clientes",
 * con estado inicial "Por revisar". A más tardar al día siguiente a su ingreso
 * se debe realizar la inspección física. Una vez finalizada la revisión, la
 * mercancía se traslada al almacén que corresponda».
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO HACE FALTA UNA COLUMNA DE ESTADO
 *
 * «Por revisar» NO es una marca por producto: es estar en el 08. La mercancía
 * entra ahí, se inspecciona, y sale con un traslado al almacén que le toque
 * —01 si quedó buena, 02 si tiene avería leve, 03 si es baja—. El día que sale
 * del 08 deja de estar por revisar, sin que nadie cambie un campo.
 *
 * Eso encaja con el modelo que el ERP ya tiene —ubicaciones como par oficina ×
 * almacén, y traslados entre pares— y evita inventar una máquina de estados
 * que habría que mantener sincronizada con el sitio físico. Lo que sí hace
 * falta aparte es el aviso de lo que lleva más de un día sin revisar, porque
 * ese plazo lo puso el área y nada lo vigila.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * LAS TRES DECISIONES DE SUS CASILLAS
 *
 * `permite_ingreso_directo = true`: la devolución entra DIRECTO acá. Es lo
 * mismo que el 07 (garantía de proveedor) y lo contrario del 02 y el 03, que
 * sólo se alcanzan por traslado — a averías y bajas no se descarga un
 * contenedor, se llega después de revisar.
 *
 * `comercial = false`: no se le vende a nadie desde acá. Es la casilla que
 * impide que una devolución sin revisar vuelva a salir en un pedido.
 *
 * `categoria_equivalente = garantia`: la misma del 07, y por la misma razón
 * —cuenta en el kardex pero no se ofrece—. No se usó `cuarentena` aunque
 * suene parecido: en este ERP esa categoría significa «mercancía en el piso,
 * sin entrar a estantería» (parqueadero, local, Arsutex) y quien lea el
 * catálogo entendería que la devolución está descargándose de un contenedor.
 * Garantía de proveedor y devolución de cliente se siguen distinguiendo por
 * el ALMACÉN, que es el eje fino: 07 contra 08.
 */
return new class extends Migration
{
    private const CODIGO = '08';

    public function up(): void
    {
        if (! Schema::hasTable('logistica_almacenes')) {
            return;
        }

        // Idempotente: si el área ya lo creó a mano desde el catálogo de
        // bodega, esta migración no le pisa lo que haya configurado.
        if (DB::table('logistica_almacenes')->where('codigo', self::CODIGO)->exists()) {
            return;
        }

        DB::table('logistica_almacenes')->insert([
            'codigo' => self::CODIGO,
            'nombre' => 'DEVOLUCIONES Y GARANTÍAS DE CLIENTES',
            // El campo es texto libre —lo valida `max:20`, no una lista—, así
            // que dice lo que de verdad pasa con esa mercancía.
            'estado_fisico' => 'POR REVISAR',
            'comercial' => false,
            'permite_ingreso_directo' => true,
            'categoria_equivalente' => 'garantia',
            'orden' => 80,
            'activo' => true,
            'observacion' => 'Entra la devolución del cliente y se revisa al día siguiente; de acá sale por traslado al almacén que corresponda.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistica_almacenes')) {
            return;
        }

        /*
         * Sólo se borra si está VACÍO.
         *
         * Una vez que entre la primera devolución habrá ubicaciones colgando
         * de este almacén y mercancía contada en ellas: borrarlo dejaría
         * stock apuntando a un almacén que no existe, que es peor que dejar
         * una fila de más.
         */
        $enUso = DB::table('inventario_ubicaciones')
            ->join('logistica_almacenes as a', 'a.id', '=', 'inventario_ubicaciones.almacen_id')
            ->where('a.codigo', self::CODIGO)
            ->exists();

        if (! $enUso) {
            DB::table('logistica_almacenes')->where('codigo', self::CODIGO)->delete();
        }
    }
};
