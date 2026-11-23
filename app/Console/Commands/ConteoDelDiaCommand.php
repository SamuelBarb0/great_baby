<?php

namespace App\Console\Commands;

use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTomaFisica;
use App\Modules\Inventario\Models\ConteoCiclicoZona;
use App\Modules\Inventario\Models\TomaFisica;
use App\Modules\Logistica\Support\ZonaDeLaBodega as Zona;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * EL CONTEO DEL DÍA.
 *
 * Una toma física de la bodega entera son 436 posiciones. Nadie la termina, así
 * que en la práctica no se contaba: el inventario se revisaba cuando ya había
 * un problema, y entonces la diferencia ya no se podía explicar.
 *
 * Esto lo da vuelta: unas pocas posiciones cada día, sin cerrar la bodega. La
 * zona rota —la que lleva más tiempo sin tanda va primera— y dentro de cada
 * zona se avanza por los estantes en orden, recordando dónde se quedó. Con diez
 * al día la bodega se recorre entera en unos dos meses, y vuelve a empezar.
 *
 * Crea UNA TOMA POR POSICIÓN, no una toma de la zona. Es a propósito:
 * `tomas_fisicas_items` no tiene columna de ubicación, así que una toma que
 * abarcara varios estantes no sabría a cuál devolverle su ajuste —caerían todos
 * en la cabecera y se descuadrarían dos sitios de una vez—. Una toma por
 * posición es como el módulo ya sabe trabajar, y deja cada ajuste en su sitio.
 *
 * Sólo manda posiciones CON SALDO. Contar un estante vacío no dice nada y
 * gastaría la tanda del día.
 *
 * Las tomas nacen SIN `creada_por`, y eso tiene un efecto que conviene saber:
 * la segregación de funciones de `CerrarTomaFisica` —quien crea una toma no la
 * cierra— se salta cuando no hay autor, así que la misma persona puede contar
 * y cerrar. Es correcto acá: esa regla existe para que nadie se arme una toma a
 * su medida, y en el conteo cíclico quien elige qué se cuenta es el sistema,
 * que es un control más fuerte, no uno más débil.
 *
 *   php artisan inventario:conteo-del-dia --dry-run     ← qué haría, sin tocar
 *   php artisan inventario:conteo-del-dia --zona=2
 *   php artisan inventario:conteo-del-dia --posiciones=25
 */
class ConteoDelDiaCommand extends Command
{
    protected $signature = 'inventario:conteo-del-dia
        {--zona= : contar esta zona en vez de la que toca por rotación}
        {--posiciones= : cuántas posiciones mandar (por defecto, la regla inventario.posiciones_por_conteo)}
        {--forzar : corre aunque la regla inventario.conteo_ciclico_activo esté apagada}
        {--dry-run : muestra qué se contaría y no crea ninguna toma}';

    protected $description = 'Arma la tanda de conteo cíclico del día: unas pocas posiciones de una zona, sin cerrar la bodega';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');

        /*
         * El interruptor del área.
         *
         * Está agendado todos los días, pero nace apagado: crear conteos solos
         * llena la pantalla de tomas que alguien tiene que contar y cerrar, y
         * las que nadie trabaja bloquean su estante para cualquier otro
         * conteo. Lo enciende Gerencia desde Reglas el día que decidan
         * arrancar.
         *
         * `--forzar` y `--dry-run` pasan igual: son las dos formas de probarlo
         * antes de encenderlo.
         */
        if (! $seco && ! $this->option('forzar') && ! setting('inventario.conteo_ciclico_activo')) {
            $this->line('El conteo cíclico automático está apagado. Se enciende en Reglas · Logística '
                .'(«Conteo cíclico automático»), o se corre a mano con --forzar.');

            return self::SUCCESS;
        }
        /*
         * `--posiciones=0` y `--posiciones=abc` no se caen en silencio al
         * valor de la regla: el `?:` de un cero los confundiría con «no me
         * pasaron nada», y quien teclea un cero merece que se lo digan.
         */
        $pedidas = $this->option('posiciones');
        $cuantas = $pedidas === null || $pedidas === ''
            ? (int) setting('inventario.posiciones_por_conteo')
            : (int) $pedidas;

        if ($cuantas < 1) {
            $this->error('«posiciones» tiene que ser al menos 1'
                .($pedidas === null || $pedidas === '' ? '. Revisá la regla inventario.posiciones_por_conteo.' : ", y pediste «{$pedidas}»."));

            return self::FAILURE;
        }

        // El reparto de zonas primero: sin él no hay nada que rotar, y es un
        // problema distinto a pedir una zona que no existe.
        if (array_map('strval', Zona::letras()) === []) {
            $this->error('No hay zonas configuradas. Se reparten en Reglas · Logística (logistica.zonas_bodega).');

            return self::FAILURE;
        }

        $zona = $this->zonaDelDia($this->option('zona'));

        if ($zona === null) {
            // `zonaDelDia` ya dijo cuáles son las zonas configuradas: repetir
            // «no hay zonas» acá sería mandar al operario a arreglar algo que
            // está bien puesto.
            return self::FAILURE;
        }

        $this->line("Zona del día: <info>{$zona}</info>".($seco ? '  <comment>(simulacro)</comment>' : ''));

        /*
         * En simulacro NO se crea la fila de la zona.
         *
         * `firstOrCreate` escribía igual, y un `--dry-run` que deja rastro es
         * peor que no tenerlo: la fila recién nacida cambia a quién le toca el
         * turno la próxima vez, así que mirar qué se contaría alteraba el
         * orden del recorrido.
         */
        $estado = ConteoCiclicoZona::firstWhere('zona', $zona)
            ?? ($seco ? new ConteoCiclicoZona(['zona' => $zona]) : ConteoCiclicoZona::create(['zona' => $zona]));

        $candidatas = $this->posicionesConSaldo($zona);

        if ($candidatas->isEmpty()) {
            $this->warn("La zona {$zona} no tiene ninguna posición con saldo. Nada que contar.");

            return self::SUCCESS;
        }

        /*
         * Dónde arranca la tanda: en el primer estante DESPUÉS del último
         * contado, por código.
         *
         * Se compara por código y no por id a propósito. Buscar la posición
         * exacta del cursor dentro de las candidatas parece lo natural, pero
         * las candidatas son sólo las que TIENEN SALDO, y un estante se vacía
         * todos los días: en cuanto el del cursor quedaba en cero desaparecía
         * de la lista, la búsqueda fallaba y la zona volvía a empezar desde el
         * principio, repitiendo estantes recién contados.
         *
         * Si el cursor apunta a una ubicación que ya no existe —se borró— sí
         * se empieza por el principio: no queda contra qué comparar.
         */
        $desde = 0;

        if ($estado->cursor_posicion_id) {
            $codigoCursor = InventarioUbicacion::whereKey($estado->cursor_posicion_id)->value('codigo');

            if ($codigoCursor !== null) {
                $siguiente = $candidatas->search(fn ($u) => strcmp((string) $u->codigo, (string) $codigoCursor) > 0);

                // `false` significa que no queda ninguna después: la zona se
                // recorrió entera y toca dar la vuelta.
                $desde = $siguiente === false ? $candidatas->count() : $siguiente;
            }
        }

        $vuelta = false;

        if ($desde >= $candidatas->count()) {
            $desde = 0;
            $vuelta = true;
            $this->line('  <comment>Se completó una vuelta a la zona: vuelve a empezar.</comment>');
        }

        /*
         * Las que ya tienen una toma abierta se saltan: dos conteos sobre la
         * misma mercancía ajustan dos veces el mismo faltante.
         *
         * Y «la misma mercancía» incluye el caso jerárquico: una toma abierta
         * sobre la BODEGA cuenta también sus racks, así que saca de la tanda a
         * todas sus posiciones. Mirando sólo el id exacto, el conteo del día
         * abriría tomas de estantes que la toma de la bodega ya está contando.
         */
        $ocupadas = TomaFisica::query()
            ->whereIn('estado', [EstadoTomaFisica::Borrador, EstadoTomaFisica::EnConteo])
            ->pluck('ubicacion_id')
            ->flatMap(fn ($id) => [
                (int) $id,
                ...InventarioUbicacion::where('bodega_id', (int) $id)->pluck('id')->map(fn ($x) => (int) $x),
            ])
            ->flip();

        $tanda = $candidatas->slice($desde)
            ->reject(fn ($u) => $ocupadas->has((int) $u->id))
            ->take($cuantas)
            ->values();

        if ($tanda->isEmpty()) {
            $this->warn('Todas las posiciones que tocaban ya tienen una toma abierta. Primero hay que cerrarlas.');

            return self::SUCCESS;
        }

        $this->table(
            ['Posición', 'Rack', 'Referencias con saldo'],
            $tanda->map(fn ($u) => [$u->codigo, $u->pasillo, $u->referencias])->all()
        );

        if ($seco) {
            $this->info("Simulacro: se crearían {$tanda->count()} tomas. No se tocó nada.");

            // El simulacro corre con el interruptor apagado a propósito —es
            // como se mira antes de encenderlo—, pero sin este renglón parece
            // que el cron de mañana va a crearlas, y no va a crear ninguna.
            if (! setting('inventario.conteo_ciclico_activo')) {
                $this->warn('Ojo: el conteo automático está APAGADO, así que el cron de las 6:30 no creará nada. '
                    .'Se enciende en Reglas · Logística.');
            }

            return self::SUCCESS;
        }

        $creadas = DB::transaction(function () use ($tanda, $zona, $estado, $vuelta) {
            $numeros = [];

            foreach ($tanda as $u) {
                /*
                 * Se vuelve a mirar JUSTO ANTES de crear.
                 *
                 * La lista de ocupadas se leyó arriba, y entre esa lectura y
                 * este insert cabe otra corrida: el cron a las 6:30 y alguien
                 * probando con `--forzar` un segundo después eligen la misma
                 * zona, leen el mismo cursor y la misma lista. El segundo
                 * chequeo, ya dentro de la transacción, cierra casi toda esa
                 * ventana sin pedir un candado que la tabla no tiene.
                 */
                if (TomaFisica::abiertaQueChoqueCon((int) $u->id)) {
                    continue;
                }

                $toma = TomaFisica::create([
                    'numero' => TomaFisica::siguienteNumero(),
                    'ubicacion_id' => $u->id,
                    'fecha_conteo' => now('America/Bogota')->toDateString(),
                    'tipo' => 'ciclico',
                    'estado' => EstadoTomaFisica::Borrador,
                    'observaciones' => "Conteo cíclico de la zona {$zona} · {$u->codigo}",
                ]);

                $numeros[] = $toma->numero;
            }

            // El cursor avanza hasta donde se MIRÓ, no hasta donde se creó: si
            // alguna posición se saltó por tener ya una toma abierta, dejarla
            // antes del cursor la volvería a ofrecer mañana, y la que siguió
            // quedaría contada dos veces.
            $estado->forceFill([
                'cursor_posicion_id' => $tanda->last()->id,
                'ultima_tanda_at' => now(),
                'posiciones_mandadas' => $estado->posiciones_mandadas + count($numeros),
                'vueltas' => $estado->vueltas + ($vuelta ? 1 : 0),
                'ultima_vuelta_at' => $vuelta ? now() : $estado->ultima_vuelta_at,
            ])->save();

            return $numeros;
        });

        if ($creadas === []) {
            $this->warn('Otra corrida se adelantó: las posiciones de esta tanda ya tienen toma abierta.');

            return self::SUCCESS;
        }

        $this->info(count($creadas).' tomas creadas en borrador: '.implode(', ', $creadas));
        $this->line('Se preparan y se cuentan desde <info>/app/inventario/conteos</info>.');

        return self::SUCCESS;
    }

    /**
     * Cuál zona toca.
     *
     * La que lleva más tiempo sin tanda, y las que nunca han tenido una van
     * primero: si se eligiera al azar o siempre la primera del plano, una zona
     * podría pasar meses sin que nadie la mire, que es justo lo que este
     * comando existe para evitar.
     */
    protected function zonaDelDia(?string $pedida): ?string
    {
        $zonas = array_map('strval', Zona::letras());

        if ($zonas === []) {
            return null;
        }

        if ($pedida !== null && $pedida !== '') {
            $pedida = (string) $pedida;

            if (! in_array($pedida, $zonas, true)) {
                $this->error("La zona «{$pedida}» no existe. Las configuradas son: ".implode(', ', $zonas).'.');

                return null;
            }

            return $pedida;
        }

        /*
         * UNA ZONA SIN FILA ES LA QUE MÁS TIEMPO LLEVA SIN CONTARSE.
         *
         * Ordenar sólo las filas existentes dejaba fuera justo a ésas: la
         * primera zona que corría se creaba su fila y volvía a salir elegida
         * cada día, mientras las otras dos —que nunca se habían contado— no
         * aparecían en la consulta y no les llegaba el turno nunca.
         */
        $corridas = ConteoCiclicoZona::whereIn('zona', $zonas)
            ->orderByRaw('ultima_tanda_at IS NULL DESC')
            ->orderBy('ultima_tanda_at')
            ->pluck('zona')
            ->map(fn ($z) => (string) $z)
            ->all();

        $nuevas = array_values(array_diff($zonas, $corridas));

        // Primero las que no se han tocado nunca, en el orden del plano; luego
        // las corridas, de la más vieja a la más reciente.
        return $nuevas[0] ?? $corridas[0] ?? $zonas[0];
    }

    /**
     * Las posiciones de la zona que tienen algo que contar, en el orden del
     * recorrido.
     *
     * El saldo se mira sobre el kardex y se exige mayor que cero: un estante
     * vacío no aporta nada y gastaría la tanda del día. Las anuladas no son
     * stock (INV-A6), igual que en la hoja de picking.
     */
    protected function posicionesConSaldo(string $zona): \Illuminate\Support\Collection
    {
        $racks = Zona::racksDe($zona);

        if ($racks === []) {
            return collect();
        }

        return InventarioUbicacion::query()
            ->where('inventario_ubicaciones.activa', true)
            ->whereIn('inventario_ubicaciones.pasillo', $racks)
            ->join('inventario_movimientos as m', 'm.ubicacion_id', '=', 'inventario_ubicaciones.id')
            ->whereNull('m.deleted_at')
            ->groupBy('inventario_ubicaciones.id', 'inventario_ubicaciones.codigo', 'inventario_ubicaciones.pasillo')
            ->havingRaw('SUM(m.cantidad) > 0')
            ->orderBy('inventario_ubicaciones.codigo')
            ->get([
                'inventario_ubicaciones.id',
                'inventario_ubicaciones.codigo',
                'inventario_ubicaciones.pasillo',
                DB::raw('COUNT(DISTINCT COALESCE(m.variante_id, m.producto_id)) as referencias'),
            ]);
    }
}
