<?php

namespace App\Console\Commands;

use App\Modules\Catalogo\Actions\LeerCatalogoMimitos;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trae el catálogo comercial de Mimitos al ERP.
 *
 * Los 336 productos del catálogo web no están en el ERP: hay que teclearlos a
 * mano para poder venderlos. Esto los trae.
 *
 * NO ESCRIBE NADA DE SIIGO, y eso es lo importante. El producto se crea con lo
 * que el catálogo sabe —referencia, nombre, descripción, posición de bodega— y
 * con `siigo_id` en nulo. Cuando el sync de SIIGO traiga ese mismo producto, su
 * propio código lo adopta: busca un local SIN `siigo_id` cuya referencia
 * coincida y lo enlaza en vez de duplicarlo. De ahí en adelante SIIGO llena sus
 * columnas —cuentas, impuestos, clasificación fiscal— que acá ni se tocan.
 *
 * El PRECIO tampoco va al producto. El catálogo trae precio de VENTA y en el
 * ERP la venta vive en las listas de precios; el producto sólo guarda
 * `precio_proveedor`, que es el COSTO. Confundirlos le vendería al mayorista a
 * precio de compra.
 *
 * Arranca en seco: sin `--aplicar` no escribe una sola fila. Trescientos
 * productos mal importados se deshacen a mano, uno por uno.
 */
class ImportarCatalogoMimitosCommand extends Command
{
    protected $signature = 'catalogo:importar-mimitos
        {--aplicar : escribir de verdad (sin esto sólo informa)}
        {--lista= : código de la lista de precios donde cargar el precio de venta}';

    protected $description = 'Trae los productos del catálogo comercial de Mimitos al ERP';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $codigoLista = trim((string) $this->option('lista'));

        $this->info($aplicar ? 'Importando de verdad.' : 'ENSAYO · no se escribe nada.');
        $this->newLine();

        $catalogo = LeerCatalogoMimitos::run();
        $this->line("Leídos {$catalogo['total']} productos del catálogo.");

        $lista = $codigoLista !== '' ? ListaPrecios::where('codigo', $codigoLista)->first() : null;

        if ($codigoLista !== '' && ! $lista) {
            $this->error("No existe la lista de precios «{$codigoLista}». Creala primero en /app/catalogo/listas-precios.");

            return self::FAILURE;
        }

        $nuevos = [];
        $yaEstaban = [];
        $aRevisar = [];

        // Una sola consulta: traer 336 productos de a uno son 336 consultas.
        $existentes = Producto::withTrashed()
            ->whereIn('referencia', array_column($catalogo['productos'], 'referencia'))
            ->get(['id', 'referencia', 'nombre', 'siigo_id', 'deleted_at'])
            ->keyBy('referencia');

        foreach ($catalogo['productos'] as $p) {
            if ($p['revisar']) {
                $aRevisar[] = $p;

                continue;
            }

            $ya = $existentes[$p['referencia']] ?? null;

            if ($ya) {
                $yaEstaban[] = $p + ['_id' => $ya->id, '_siigo' => (bool) $ya->siigo_id, '_borrado' => (bool) $ya->deleted_at];

                continue;
            }

            $nuevos[] = $p;
        }

        $this->resumen($nuevos, $yaEstaban, $aRevisar, $lista);

        if (! $aplicar) {
            $this->newLine();
            $this->comment('Para escribirlo: php artisan catalogo:importar-mimitos --aplicar'
                .($codigoLista !== '' ? " --lista={$codigoLista}" : ''));

            return self::SUCCESS;
        }

        $creados = $this->crear($nuevos, $lista);

        $this->newLine();
        $this->info("Listo · {$creados} producto(s) creados"
            .($lista ? " con precio en la lista {$lista->nombre}" : ' sin precio (no se indicó lista)'));

        return self::SUCCESS;
    }

    /** @param list<array<string,mixed>> $nuevos */
    private function crear(array $nuevos, ?ListaPrecios $lista): int
    {
        $creados = 0;

        foreach (array_chunk($nuevos, 50) as $tanda) {
            DB::transaction(function () use ($tanda, $lista, &$creados) {
                foreach ($tanda as $p) {
                    /*
                     * `sinKardexAutomatico` porque crear el producto no es
                     * recibir mercancía: no hay una sola unidad contada todavía
                     * y el kardex no puede decir que sí.
                     */
                    $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
                        'referencia' => $p['referencia'],
                        'nombre' => $p['nombre'],
                        'descripcion' => $p['descripcion'],
                        'categoria' => $p['categoria'],
                        'posicion_bodega' => $p['posicion_bodega'],
                        /*
                         * Agregado, no granular: el catálogo no trae color ni
                         * talla para ninguno de los 336. Marcarlos granulares
                         * obligaría a inventarles variantes que no existen.
                         */
                        'desglose_stock' => false,
                        'activo' => true,
                        // `siigo_id` queda NULO a propósito: es lo que permite
                        // que el sync de SIIGO lo adopte por referencia.
                    ]));

                    if ($lista) {
                        PrecioProducto::create([
                            'producto_id' => $producto->id,
                            'lista_id' => $lista->id,
                            'precio' => $p['precio_venta'],
                            'vigente_desde' => now()->toDateString(),
                        ]);
                    }

                    $creados++;
                }
            });

            $this->output->write('.');
        }

        return $creados;
    }

    /**
     * @param  list<array<string,mixed>>  $nuevos
     * @param  list<array<string,mixed>>  $yaEstaban
     * @param  list<array<string,mixed>>  $aRevisar
     */
    private function resumen(array $nuevos, array $yaEstaban, array $aRevisar, ?ListaPrecios $lista): void
    {
        $this->newLine();
        $this->table(['', 'Cuántos'], [
            ['Se crearían', count($nuevos)],
            ['Ya existen por referencia', count($yaEstaban)],
            ['  · de esos, ya enlazados a SIIGO', count(array_filter($yaEstaban, fn ($p) => $p['_siigo']))],
            ['  · de esos, borrados (soft delete)', count(array_filter($yaEstaban, fn ($p) => $p['_borrado']))],
            ['Para revisar a mano', count($aRevisar)],
            ['Columnas de SIIGO que se tocan', 0],
        ]);

        if (! $lista) {
            $this->warn('Sin --lista no se carga ningún precio: los productos entran sin precio de venta.');
        }

        if ($aRevisar !== []) {
            $this->newLine();
            $this->warn('ESTOS NO LOS TOCA NADIE AUTOMÁTICAMENTE:');
            foreach ($aRevisar as $p) {
                $this->line(sprintf('  · %-22s %s', $p['referencia'], $p['nombre_original']));
                $this->line(sprintf('    %s', $p['revisar']));
            }
        }

        if ($yaEstaban !== []) {
            $this->newLine();
            $this->line('Ya estaban (no se tocan):');
            foreach (array_slice($yaEstaban, 0, 10) as $p) {
                $this->line(sprintf('  · %-22s %s', $p['referencia'], $p['nombre']));
            }
            if (count($yaEstaban) > 10) {
                $this->line('  … y '.(count($yaEstaban) - 10).' más.');
            }
        }
    }
}
