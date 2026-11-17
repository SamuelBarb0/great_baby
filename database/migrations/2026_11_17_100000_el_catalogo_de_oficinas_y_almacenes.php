<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo de oficinas y almacenes con el que trabaja la bodega.
 *
 * Don Jorge entregó el procedimiento escrito (17-nov-2026) y ahí quedó claro
 * que la bodega no clasifica la mercancía por UNA cosa sino por DOS, y que son
 * independientes entre sí:
 *
 *   · la OFICINA dice de quién es la mercancía —Great Baby, Dropi, Marketing,
 *     la Sra. Tania, Exhibición—;
 *   · el ALMACÉN dice en qué estado y en qué condición comercial está
 *     —principal, averías leves, bajas, outlet, repuestos, saldos, garantía—.
 *
 * El ERP venía mezclando las dos en una sola idea: `inventario_ubicaciones`
 * tenía `categoria`, que es más o menos el almacén, y la oficina sólo existía
 * a medias como `empresa_destino_id` en la línea de la orden de compra. Con
 * eso no se puede decir «estas 30 unidades son de Dropi y van al principal, y
 * estas 20 son de Great Baby y van a garantía», que es exactamente lo que la
 * bodega hace todos los días cuando descarga un contenedor.
 *
 * LA REGLA QUE MANDA, y por la que estas tablas existen en vez de ser un enum
 * en código: al RECIBIR sólo se puede ingresar a la oficina 10 u 11 y al
 * almacén 01 ó 07. Las demás combinaciones existen, pero sólo se alcanzan por
 * TRASLADO, después de que la mercancía ya entró al sistema. Esa regla viaja
 * en la fila —`permite_ingreso_directo`— y no en un `if`, porque el área dijo
 * que la tabla va a cambiar y no queremos un despliegue por cada cambio.
 *
 * Sobre `categoria`: se conserva para no romper nada. Hay código viejo que
 * busca la ubicación de avería por `categoria` —`TrasRecepcion::
 * ubicacionNoVendible()`— y el enum `CategoriaUbicacion` decide lo que el
 * kardex considera vendible. Lo que manda para el negocio es la columna
 * `comercial` de esta tabla; `categoria` es sólo cómo lo nombra el motor
 * viejo. Por eso «averías leves», «outlet» y «saldos» quedan mapeados a
 * `venta`: el área los VENDE, con descuento, y marcarlos como no vendibles
 * los sacaría del inventario disponible sin que nadie lo hubiera pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * De quién es la mercancía.
         *
         * Dos de las cinco son empresas de verdad —Great Baby y el e-commerce—
         * y por eso pueden apuntar a `empresa_config`. Las otras tres son
         * áreas internas: no facturan, no tienen NIT, y sólo reciben mercancía
         * por traslado. Forzarlas a ser empresas habría ensuciado la
         * facturación con tres razones sociales que no existen.
         */
        Schema::create('logistica_oficinas', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 4)->unique();
            $t->string('nombre', 80);

            /*
             * Si la mercancía puede ENTRAR directamente acá desde una
             * recepción, o si sólo llega por traslado. Hoy: 10 y 11 sí, el
             * resto no.
             */
            $t->boolean('permite_ingreso_directo')->default(false);

            /*
             * La empresa con la que se factura lo de esta oficina, cuando la
             * hay. Null en las áreas internas.
             */
            $t->foreignId('empresa_config_id')->nullable()
                ->constrained('empresa_config')->nullOnDelete();

            /*
             * Exhibición es de donde el alistador saca lo que le falta para
             * completar un pedido al 100%. Es la única con ese papel y la
             * marca va acá para que la pantalla de picking la encuentre sin
             * tener el código quemado.
             */
            $t->boolean('es_respaldo_surtido')->default(false);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activa')->default(true);
            $t->string('observacion', 200)->nullable();
            $t->timestamps();
        });

        /*
         * En qué estado y en qué condición comercial está la mercancía.
         */
        Schema::create('logistica_almacenes', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 4)->unique();
            $t->string('nombre', 80);

            // BUENO · DESPERFECTO · AVERÍA. Tal como lo escribió el área.
            $t->string('estado_fisico', 20);

            /*
             * Si se puede vender. Ojo: «averías leves», «outlet» y «saldos»
             * son comercial = true. El área los vende con descuento y tratarlos
             * como no vendibles sería cambiarle el negocio.
             */
            $t->boolean('comercial')->default(true);

            // Misma idea que en oficinas: 01 y 07 sí, el resto sólo traslado.
            $t->boolean('permite_ingreso_directo')->default(false);

            /*
             * Cómo lo llama el motor viejo (`CategoriaUbicacion`). No es la
             * verdad del negocio, es compatibilidad: el kardex y la búsqueda
             * de la ubicación no vendible siguen leyendo `categoria`.
             */
            $t->string('categoria_equivalente', 30)->default('venta');

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->string('observacion', 200)->nullable();
            $t->timestamps();
        });

        /*
         * Cada bodega del inventario declara qué par (oficina, almacén)
         * representa.
         *
         * Aditivo y nullable: las 55 ubicaciones que ya existen —incluidas las
         * 43 importadas de SIIGO— siguen funcionando igual, sin par asignado.
         * El par se usa para resolver «oficina 11 + almacén 01» a una fila
         * concreta cuando alguien recibe mercancía.
         */
        Schema::table('inventario_ubicaciones', function (Blueprint $t) {
            $t->foreignId('oficina_id')->nullable()->after('bodega_id')
                ->constrained('logistica_oficinas')->nullOnDelete();
            $t->foreignId('almacen_id')->nullable()->after('oficina_id')
                ->constrained('logistica_almacenes')->nullOnDelete();

            // Resolver el par a una ubicación es la consulta más repetida del
            // flujo de recepción.
            $t->index(['oficina_id', 'almacen_id'], 'ubic_oficina_almacen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventario_ubicaciones', function (Blueprint $t) {
            $t->dropIndex('ubic_oficina_almacen_idx');
            $t->dropConstrainedForeignId('oficina_id');
            $t->dropConstrainedForeignId('almacen_id');
        });

        Schema::dropIfExists('logistica_almacenes');
        Schema::dropIfExists('logistica_oficinas');
    }
};
