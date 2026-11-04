<?php

namespace App\Console\Commands;

use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Red de seguridad: encola TODO documento que debería estar en SIIGO y no está.
 *
 * Los observers empujan cada documento al crearlo, pero eso se pierde si en ese
 * momento la cola estaba caída, SIIGO no respondía, faltaba una cuenta por
 * mapear o el kill-switch estaba apagado. El documento queda en el ERP sin
 * `siigo_id` y nadie vuelve a intentarlo: así se acumularon 104 documentos.
 *
 * Este comando barre esos huecos y reencola. Es idempotente — cada job revisa
 * de nuevo si el documento ya tiene id — y corre a diario por el scheduler.
 *
 *   php artisan siigo:empujar-pendientes --dry-run
 *   php artisan siigo:empujar-pendientes --tipo=facturas
 *   php artisan siigo:empujar-pendientes --limite=50
 */
class SiigoEmpujarPendientesCommand extends Command
{
    protected $signature = 'siigo:empujar-pendientes
                            {--dry-run : Sólo muestra qué se encolaría}
                            {--tipo= : Un solo tipo (productos, facturas, notas-credito, notas-debito, recepciones, pagos-cliente, pagos-proveedor, asientos, movimientos, devoluciones)}
                            {--limite=200 : Máximo de documentos por tipo}';

    protected $description = 'Reencola a SIIGO todo documento que quedó sin enviar';

    /**
     * tipo => [tabla, columna que prueba que llegó, job, condición extra]
     *
     * Pública porque el Panel contable lee de acá qué documentos DEBERÍAN
     * estar en SIIGO para pintar su semáforo. Una sola lista: si mañana se
     * agrega un tipo, el panel lo cuenta sin que haya que acordarse.
     */
    public const FUENTES = [
        'facturas' => [
            'facturas_venta', 'siigo_id', \App\Jobs\ReintentarEmisionDian::class,
            "es_electronica = 1 AND estado <> 'anulada'",
        ],
        'notas-credito' => [
            'notas_credito', 'siigo_id', \App\Modules\Siigo\Jobs\PushNotaCreditoASiigo::class,
            // Las NC de devoluciones Dropi se excluyen a propósito: ese flujo no
            // está implementado y encolarlas sólo llenaría la cola de fallos.
            "estado <> 'borrador' AND devolucion_dropi_id IS NULL",
        ],
        'notas-debito' => [
            'notas_debito', 'siigo_id', \App\Modules\Siigo\Jobs\PushNotaDebitoASiigo::class,
            "estado <> 'borrador'",
        ],
        /*
          * Las compras marcadas INTERNAS no se declaran: ese es el sentido del
          * destino fiscal. Sin esta condicion se reencolaban todas las noches
          * para volver a no enviarse, y de paso llenaban la pantalla de
          * pendientes con documentos que nadie iba a resolver nunca.
          */
        'recepciones' => [
            'compras_recepciones', 'siigo_id', \App\Modules\Siigo\Jobs\PushRecepcionASiigo::class,
            "estado = 'confirmada' AND orden_id NOT IN "
                ."(SELECT id FROM compras_ordenes WHERE destino_fiscal = 'interno')",
        ],
        // Sólo los pagos cuya factura ya está en SIIGO. Sin esa condición, un
        // recibo de una factura no electrónica —que nunca va a subir— se
        // reencolaba todos los días para volver a fallar. Medido el
        // 2026-10-09: las 23 facturas sin `siigo_id` eran TODAS no
        // electrónicas, y 3 pagos llevaban días dando vueltas en ese bucle.
        'pagos-cliente' => [
            'pagos_venta', 'siigo_id', \App\Modules\Siigo\Jobs\PushVoucherASiigo::class,
            'deleted_at IS NULL AND factura_id IN '
                .'(SELECT id FROM facturas_venta WHERE siigo_id IS NOT NULL AND siigo_id <> \'\')',
        ],
        'pagos-proveedor' => [
            'pagos_proveedor', 'siigo_voucher_id', \App\Modules\Siigo\Jobs\PushPagoProveedorASiigo::class,
            "estado = 'confirmado' AND (recepcion_id IS NULL OR recepcion_id NOT IN "
                ."(SELECT id FROM compras_recepciones WHERE orden_id IN "
                ."(SELECT id FROM compras_ordenes WHERE destino_fiscal = 'interno')))",
        ],
        'asientos' => [
            'asientos_manuales', 'siigo_journal_id', \App\Modules\Siigo\Jobs\PushAsientoManualASiigo::class,
            "estado = 'aprobado'",
        ],
        // Movimientos de INVENTARIO (kardex). Son los únicos que necesitan un
        // asiento propio en SIIGO: ajustes de toma física, traslados y
        // devoluciones no nacen de ningún documento que SIIGO contabilice solo.
        //
        // OJO · `movimientos_contables` NO va acá aunque tenga `siigo_journal_id`:
        // sus filas son la contrapartida de facturas, pagos, recepciones y
        // devoluciones, y SIIGO ya genera ese asiento al crear cada documento.
        // Empujarlas duplicaría la contabilidad.
        'movimientos' => [
            'inventario_movimientos', 'siigo_journal_id', \App\Modules\Siigo\Jobs\PushAsientoASiigo::class,
            "tipo IN ('ajuste_toma_fisica','traslado_salida','traslado_entrada',"
                ."'traslado_reversa_salida','traslado_reversa_entrada')",
        ],
        'devoluciones' => [
            'devoluciones_proveedor', 'siigo_id', \App\Modules\Siigo\Jobs\PushDevolucionProveedorASiigo::class,
            "estado = 'confirmada' AND (recepcion_id IS NULL OR recepcion_id NOT IN "
                ."(SELECT id FROM compras_recepciones WHERE orden_id IN "
                ."(SELECT id FROM compras_ordenes WHERE destino_fiscal = 'interno')))",
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limite = max(1, (int) $this->option('limite'));
        $soloTipo = $this->option('tipo');

        // `productos` no está en FUENTES —se maneja aparte, ver abajo— pero
        // sí es un tipo válido.
        $tipos = array_merge(['productos'], array_keys(self::FUENTES));
        if ($soloTipo && ! in_array($soloTipo, $tipos, true)) {
            $this->error("Tipo desconocido: {$soloTipo}. Opciones: ".implode(', ', $tipos));
            return self::FAILURE;
        }

        if (! $dryRun && ! SiigoConfig::pushAutoActivo()) {
            $this->warn('El envío automático a SIIGO está apagado (FEATURE_SIIGO_PUSH_AUTO).');
            $this->warn('Los jobs se encolarían y se auto-omitirían. Activalo primero o usá --dry-run.');
            return self::FAILURE;
        }

        // Con la llave muerta no se encola: el middleware los aplazaría cada 5
        // minutos hasta que se les acabe el plazo de 12 horas, y lo único que
        // se consigue es churn en la tabla `jobs`. Corre solo todos los días a
        // las 04:15, así que apenas la credencial vuelva a servir los recoge
        // la pasada siguiente sin que nadie haga nada.
        if (! $dryRun && SiigoConfig::current()->credencialMuerta()) {
            $this->warn('SIIGO está rechazando la credencial; no se encola nada.');
            $this->warn('Pegá la llave vigente en /app/siigo y volvé a correr esto (o esperá la pasada de las 04:15).');
            return self::FAILURE;
        }

        $cola = config('siigo.queue', 'siigo');
        $totalEncolados = 0;
        $filas = [];

        foreach (self::FUENTES as $tipo => [$tabla, $columna, $job, $filtro]) {
            if ($soloTipo && $tipo !== $soloTipo) {
                continue;
            }
            if (! $this->fuenteUsable($tabla, $columna, $filtro)) {
                $filas[] = [$tipo, $tabla, '—', '—', 'no aplica en este esquema'];
                continue;
            }

            $ids = DB::table($tabla)
                ->whereRaw($filtro)
                ->where(fn ($q) => $q->whereNull($columna)->orWhere($columna, ''))
                ->orderBy('id')
                ->limit($limite)
                ->pluck('id');

            $pendientes = $ids->count();
            $encolados = 0;

            if (! $dryRun) {
                foreach ($ids as $id) {
                    try {
                        $job::dispatch((int) $id)->onQueue($cola);
                        $encolados++;
                    } catch (\Throwable $e) {
                        $this->warn("  {$tipo}#{$id}: ".$e->getMessage());
                    }
                }
            }

            $totalEncolados += $encolados;
            $filas[] = [$tipo, $tabla, $pendientes, $dryRun ? '(dry-run)' : $encolados, class_basename($job)];
        }

        // Productos · van aparte porque no encajan en la tabla de arriba: su
        // job recibe dos argumentos y la condición de «pendiente» cruza dos
        // tablas (un granular está en SIIGO cuando lo están sus variantes).
        if (! $soloTipo || $soloTipo === 'productos') {
            $filas[] = $this->empujarProductos($dryRun, $limite, $cola, $totalEncolados);
        }

        $this->table(['Tipo', 'Tabla', 'Pendientes', 'Encolados', 'Job'], $filas);

        if ($dryRun) {
            $this->info('Dry-run: no se encoló nada. Quitá --dry-run para enviarlos.');
            return self::SUCCESS;
        }

        $this->info("{$totalEncolados} documentos encolados en la cola «{$cola}».");
        $this->line('Procesalos con: php artisan queue:work --queue='.$cola);

        return self::SUCCESS;
    }

    /**
     * Productos que deberían estar en SIIGO y no están.
     *
     * Hueco encontrado el 2026-10-09: esta red de seguridad cubría documentos
     * pero **no productos**, y los productos eran 1029 de los 1115 trabajos
     * caídos. Un producto que no logró subir se queda sin `siigo_id` para
     * siempre: ningún observer lo reintenta porque el observer dispara al
     * guardar, y nadie vuelve a guardarlo.
     *
     * Y arrastra al resto. Los asientos de inventario fallaban literalmente
     * con «el producto X del movimiento N todavía no está en SIIGO,
     * sincronizalo primero»: sin el producto no hay asiento, no hay factura y
     * no hay devolución.
     *
     * Un granular está en SIIGO cuando lo están sus variantes —cada una viaja
     * como producto propio— y el PADRE no sube nunca: no tiene por qué tener
     * id propio. Preguntar por el id del padre «o» el de sus variantes metía
     * a los 28 granulares en la lista de pendientes para siempre, y los
     * reencolaba cada noche para que volvieran a fallar, tapando con 28
     * alarmas falsas las que eran de verdad.
     *
     * @return array{0: string, 1: string, 2: int|string, 3: int|string, 4: string}
     */
    private function empujarProductos(bool $dryRun, int $limite, string $cola, int &$totalEncolados): array
    {
        $job = \App\Modules\Siigo\Jobs\PushProductoASiigo::class;

        if (! \Schema::hasTable('productos') || ! \Schema::hasColumn('productos', 'siigo_id')) {
            return ['productos', 'productos', '—', '—', 'no aplica en este esquema'];
        }

        $ids = \App\Modules\Dropi\Models\Producto::query()
            ->where('activo', true)
            ->where(fn ($q) => $q
                // Sin variantes: sube él, así que pendiente si le falta su id.
                ->where(fn ($solo) => $solo
                    ->whereDoesntHave('variantes')
                    ->where(fn ($i) => $i->whereNull('siigo_id')->orWhere('siigo_id', '')))
                // Con variantes: suben ellas. Pendiente si a alguna le falta.
                ->orWhereHas('variantes', fn ($v) => $v->whereNull('siigo_id')->orWhere('siigo_id', '')))
            ->orderBy('id')
            ->limit($limite)
            ->pluck('id');

        $encolados = 0;
        if (! $dryRun) {
            foreach ($ids as $id) {
                try {
                    // `crear` y no `actualizar`: por definición no tienen id allá.
                    // El propio job revisa de nuevo antes de llamar a SIIGO, así
                    // que si alguno subió mientras tanto no se duplica.
                    $job::dispatch((int) $id, 'crear')->onQueue($cola);
                    $encolados++;
                } catch (\Throwable $e) {
                    $this->warn("  productos#{$id}: ".$e->getMessage());
                }
            }
        }

        $totalEncolados += $encolados;

        return ['productos', 'productos', $ids->count(), $dryRun ? '(dry-run)' : $encolados, class_basename($job)];
    }

    /** El esquema varía entre instalaciones; no asumimos que la columna exista. */
    private function fuenteUsable(string $tabla, string $columna, string $filtro): bool
    {
        if (! \Schema::hasTable($tabla) || ! \Schema::hasColumn($tabla, $columna)) {
            return false;
        }
        if ($filtro === '1=1') {
            return true;
        }
        // El filtro nombra columnas que pueden no existir (ej. devolucion_dropi_id).
        try {
            DB::table($tabla)->whereRaw($filtro)->limit(1)->exists();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
