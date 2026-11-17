<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DropiCoreSeeder::class,
            /*
             * El catálogo de oficinas y almacenes.
             *
             * La migración crea las dos tablas VACÍAS, y vacías el reparto del
             * contenedor queda inerte: la pantalla del catálogo no ofrece
             * ningún par, la recepción no tiene a dónde mandar nada y todo
             * entra a la bodega de la orden como antes. Sin esta línea había
             * que saberse el comando de memoria.
             *
             * Es idempotente: no pisa lo que alguien haya editado a mano.
             */
            CatalogoOficinasAlmacenesSeeder::class,
        ]);
    }
}
