<?php

namespace Database\Seeders;

use App\Support\Reglas;
use Illuminate\Database\Seeder;

/**
 * Todo lo que el área de logística entregó por escrito, en un solo comando.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * PARA QUÉ EXISTE
 *
 * El módulo de logística necesita tres cosas sembradas antes de servir de
 * algo, y hasta hoy eran tres comandos distintos que había que acordarse de
 * correr en orden. En un despliegue eso se olvida, y lo que queda es un
 * módulo que abre pero no hace nada: la cola vacía, la hoja de picking sin
 * ubicaciones y las reglas sin existir.
 *
 * Acá van los tres, en el orden que importa, diciendo en voz alta qué hizo
 * cada uno. Es idempotente: se puede correr todas las veces que haga falta y
 * no pisa nada que el área haya editado a mano.
 *
 *     php artisan db:seed --class=LogisticaDeLaBodegaSeeder --force
 *
 * ───────────────────────────────────────────────────────────────────────────
 * QUÉ SIEMBRA, Y DE DÓNDE SALE
 *
 *   1. LAS REGLAS — los parámetros configurables que el área mueve sin
 *      despliegue: el reparto de racks por zona, los días de aviso del
 *      contenedor, la cuadrícula de la zona de alistamiento y el código de la
 *      bodega principal.
 *
 *   2. EL CATÁLOGO — las 5 oficinas (10 GREAT BABY … 14 EXHIBICIÓN) y los 7
 *      almacenes (01 PRINCIPAL … 07 GARANTÍA), con la regla de quién admite
 *      ingreso directo y quién sólo traslados. Sale del documento
 *      «Recepción de mercancías» y del listado que mandó el área.
 *
 *   3. LAS POSICIONES — las 435 del plano: 17 racks con sus secciones,
 *      niveles y lateralidad, más el parqueadero, Arsutex, el local y la zona
 *      de alistamiento. Sale del levantamiento del 17-nov y del plano del
 *      19-nov.
 *
 * El paso 3 necesita que exista la bodega física. Si no la encuentra, lo dice
 * y propone las candidatas en vez de fallar callado; los pasos 1 y 2 ya
 * quedaron hechos, así que se arregla y se vuelve a correr.
 */
class LogisticaDeLaBodegaSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->newLine();
        $this->command?->info('LOGÍSTICA · sembrando lo que entregó el área');
        $this->command?->newLine();

        // 1 · Las reglas primero: el sembrador de posiciones lee de ellas el
        //     reparto de zonas y el código de la bodega.
        $this->command?->line('<fg=cyan>1/3</> Reglas de negocio');
        Reglas::seedDefaults();
        Reglas::limpiarRequestCache();

        $this->command?->line('    <fg=gray>·</> reparto de racks por zona: '
            .implode(', ', \App\Modules\Logistica\Support\ZonaDeLaBodega::letras()));
        $this->command?->line('    <fg=gray>·</> casillas de la zona de alistamiento: '
            .\App\Modules\Logistica\Support\ZonaDeAlistamiento::cuantasCaben());
        $this->command?->line('    <fg=gray>·</> bodega de los racks: '
            .UbicacionesDeLaBodegaSeeder::codigoDeLaBodega());
        $this->command?->newLine();

        // 2 · El catálogo: sin pares oficina+almacén la recepción no tiene a
        //     dónde repartir el contenedor.
        $this->command?->line('<fg=cyan>2/3</> Catálogo de oficinas y almacenes');
        $this->call(CatalogoOficinasAlmacenesSeeder::class);
        $this->command?->newLine();

        // 3 · El plano de la bodega.
        $this->command?->line('<fg=cyan>3/3</> Posiciones del plano');
        $this->call(UbicacionesDeLaBodegaSeeder::class);

        $this->command?->newLine();
        $this->command?->comment(
            'Todo esto se edita después desde las pantallas: las reglas en /app/reglas, '
            .'el catálogo en /app/inventario/catalogo-bodega y los códigos, nombres y '
            .'responsables de cada posición en /app/inventario/ubicaciones.'
        );
    }
}
