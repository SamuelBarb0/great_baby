<?php

namespace Database\Seeders;

use App\Models\EmpresaConfig;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use Illuminate\Database\Seeder;

/**
 * El catálogo de oficinas y almacenes, tal como lo entregó el área.
 *
 * Viene del «Listado de oficinas y almacenes» y del procedimiento logístico
 * que Don Jorge envió el 17-nov-2026. Los códigos y los nombres son los de él,
 * sin traducir: cuando la bodega dice «eso va al 02» tiene que poder buscar
 * 02 en la pantalla y encontrarlo.
 *
 * Es idempotente —se puede correr cuantas veces haga falta— y NO pisa lo que
 * alguien haya editado desde la pantalla: sólo crea lo que falta y completa
 * los campos que estén vacíos. El catálogo es editable a propósito, porque el
 * área avisó que va a cambiar.
 *
 * Correr:
 *   php artisan db:seed --class=CatalogoOficinasAlmacenesSeeder
 */
class CatalogoOficinasAlmacenesSeeder extends Seeder
{
    /**
     * Las cinco oficinas.
     *
     * Sólo 10 y 11 reciben mercancía de un contenedor. Marketing, la Sra.
     * Tania y Exhibición existen, pero se llega a ellas por traslado.
     *
     * [código, nombre, ingreso directo, código de empresa, respaldo de surtido, observación]
     */
    private const OFICINAS = [
        ['10', 'GREAT BABY', true,  'great_baby', false, 'Ingreso inicial de mercancía al sistema.'],
        ['11', 'DROPI',      true,  'ecommerce',  false, 'Ingreso inicial de mercancía al sistema.'],
        ['12', 'MARKETING',  false, null,         false, 'Sólo traslados internos posteriores al ingreso.'],
        ['13', 'SRA. TANIA', false, null,         false, 'Sólo traslados internos posteriores al ingreso.'],
        ['14', 'EXHIBICIÓN', false, null,         true,  'Sólo traslados. De aquí se completa el surtido al 100% en picking.'],
    ];

    /**
     * Los siete almacenes.
     *
     * Ojo con el 02, el 04 y el 06: están marcados comerciales. El área los
     * vende con descuento y por eso su categoría es `venta`, no `averia`.
     * Ponerlos como no vendibles los sacaría del inventario disponible y nadie
     * pidió eso.
     *
     * [código, nombre, estado físico, comercial, ingreso directo, categoría del motor, observación]
     */
    private const ALMACENES = [
        ['01', 'PRINCIPAL',             'BUENO',       true,  true,  'venta',             'Mercancía comercial regular, sin descuento.'],
        ['02', 'AVERÍAS LEVES',         'DESPERFECTO', true,  false, 'venta',             'Comercializable con descuento. Sólo por traslado.'],
        ['03', 'BAJAS',                 'AVERÍA',      false, false, 'averia_baja',       'Para chatarrizar. Sólo por traslado.'],
        ['04', 'OUTLET',                'BUENO',       true,  false, 'venta',             'Descuento por baja rotación. Sólo por traslado.'],
        ['05', 'REPUESTOS POR DESPIECE','DESPERFECTO', false, false, 'averia_reparar',    'Uso interno como repuesto de piezas. Sólo por traslado.'],
        ['06', 'SALDOS POR UNIDAD',     'BUENO',       true,  false, 'venta',             'Venta por unidad con descuento, siendo mayoristas. Sólo por traslado.'],
        ['07', 'GARANTÍA DE PROVEEDOR', 'AVERÍA',      false, true,  'garantia',          'Ingreso directo desde cuarentena, para el reclamo al proveedor.'],
    ];

    public function run(): void
    {
        $this->oficinas();
        $this->almacenes();
    }

    private function oficinas(): void
    {
        foreach (self::OFICINAS as $i => [$codigo, $nombre, $ingreso, $empresaCodigo, $respaldo, $obs]) {
            /*
             * Si el área le cambió el CÓDIGO a una fila, no se resucita la vieja.
             *
             * `firstOrNew(['codigo' => ...])` busca por código, así que renombrar
             * «11» a «11A» hacía que la siguiente corrida creara otra vez la «11»:
             * dos oficinas para el mismo lugar físico, las dos admitiendo ingreso
             * directo. El reparto ofrece el par con el id NUEVO y `UbicacionDelPar`
             * busca por id, así que no encuentra la bodega mapeada y crea otra —y
             * el stock queda partido en dos, que es el daño que todo este catálogo
             * existe para evitar. Si ya hay una fila con ese nombre, es la misma.
             */
            $o = Oficina::firstOrNew(['codigo' => $codigo]);

            if (! $o->exists) {
                $renombrada = Oficina::where('nombre', $nombre)->first();
                if ($renombrada) {
                    $this->command?->line("  <fg=yellow>~</> oficina «{$nombre}» ya existe con código {$renombrada->codigo}: no se recrea como {$codigo}.");

                    continue;
                }
            }

            $nueva = ! $o->exists;

            // En una fila nueva se escribe todo. En una que ya existe sólo se
            // completa lo que esté vacío: si el área le cambió el nombre o le
            // quitó el ingreso directo, esa decisión manda sobre el seeder.
            $o->nombre = $nueva ? $nombre : ($o->nombre ?: $nombre);
            $o->observacion = $o->observacion ?: $obs;
            /*
             * `isset` y no `?:` para el orden.
             *
             * `?:` trata el 0 como vacío, así que un orden puesto a mano en 0
             * —que es exactamente cómo se fija «ésta va primera»— se lo
             * repisaba el seeder en cada corrida. El orden sólo se escribe si
             * nadie lo ha decidido.
             */
            if (! isset($o->orden)) {
                $o->orden = ($i + 1) * 10;
            }

            if ($nueva) {
                $o->permite_ingreso_directo = $ingreso;
                $o->es_respaldo_surtido = $respaldo;
                $o->activa = true;
            }

            if ($empresaCodigo && ! $o->empresa_config_id) {
                $o->empresa_config_id = EmpresaConfig::where('codigo', $empresaCodigo)->value('id');
            }

            $o->save();
            $this->command?->line(($nueva ? '  <fg=green>+</>' : '  <fg=gray>·</>')." oficina {$codigo} {$nombre}");
        }
    }

    private function almacenes(): void
    {
        foreach (self::ALMACENES as $i => [$codigo, $nombre, $estado, $comercial, $ingreso, $categoria, $obs]) {
            // Ídem: un almacén renombrado no se vuelve a crear con el código viejo.
            $a = Almacen::firstOrNew(['codigo' => $codigo]);

            if (! $a->exists) {
                $renombrado = Almacen::where('nombre', $nombre)->first();
                if ($renombrado) {
                    $this->command?->line("  <fg=yellow>~</> almacén «{$nombre}» ya existe con código {$renombrado->codigo}: no se recrea como {$codigo}.");

                    continue;
                }
            }

            $nueva = ! $a->exists;

            $a->nombre = $nueva ? $nombre : ($a->nombre ?: $nombre);
            $a->observacion = $a->observacion ?: $obs;
            // Ídem: un orden en 0 es una decisión, no un hueco.
            if (! isset($a->orden)) {
                $a->orden = ($i + 1) * 10;
            }

            if ($nueva) {
                $a->estado_fisico = $estado;
                $a->comercial = $comercial;
                $a->permite_ingreso_directo = $ingreso;
                $a->categoria_equivalente = $categoria;
                $a->activo = true;
            }

            $a->save();
            $this->command?->line(($nueva ? '  <fg=green>+</>' : '  <fg=gray>·</>')." almacén {$codigo} {$nombre}");
        }
    }
}
