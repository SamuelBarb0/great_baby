<?php

namespace App\Modules\Contabilidad\Support;

use App\Console\Commands\SiigoEmpujarPendientesCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos documentos deberían estar en SIIGO y cuántos están de verdad.
 *
 * Vive acá y no dentro de un controlador porque DOS pantallas lo muestran —el
 * panel contable y Discrepancias— y el 2026-11-05, en producción, decían cosas
 * opuestas sobre los mismos datos: el panel «0 %» y Discrepancias «VERDE,
 * 100 % sincronizado». Un indicador que se contradice consigo mismo no es un
 * indicador.
 *
 * El defecto era el mismo en las dos: contaban SÓLO `asientos_manuales`, y
 * además devolvían 100 cuando no había ninguno:
 *
 *     $pct = $totalAprobados > 0 ? round(...) : 100;
 *
 * Como casi nadie hace asientos a mano, la cuenta daba 0/0 y la pantalla
 * pintaba verde. En producción hay 21 facturas, una nómina y una liquidación
 * sin subir, y ninguna entraba en la cuenta. El indicador que existía para
 * avisar era justamente el que tapaba el problema.
 *
 * Ahora cuenta las mismas diez familias que reencola `siigo:empujar-
 * pendientes`, leyendo su lista para que no se puedan separar.
 *
 * A propósito NO se filtra por período: una factura de octubre sin subir sigue
 * pendiente hoy, y acotarla al rango de la pantalla la escondería — que es el
 * error que se está corrigiendo.
 */
final class CoberturaSiigo
{
    /**
     * @return array{deberian:int,estan:int,faltan:int,pct:float,semaforo:string,etiqueta:string}
     */
    public static function calcular(): array
    {
        $deberian = 0;
        $estan = 0;

        foreach (SiigoEmpujarPendientesCommand::FUENTES as [$tabla, $columna, , $condicion]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $base = DB::table($tabla)->whereRaw($condicion);

            $deberian += (clone $base)->count();
            $estan += (clone $base)->whereNotNull($columna)->where($columna, '<>', '')->count();
        }

        // Sin documentos que subir no hay nada atrasado: es 100 % de cero, y
        // decirlo con todas las letras evita el verde mentiroso de antes.
        $pct = $deberian > 0 ? round(($estan / $deberian) * 100, 1) : 100.0;
        $faltan = $deberian - $estan;

        return [
            'deberian' => $deberian,
            'estan' => $estan,
            'faltan' => $faltan,
            'pct' => $pct,
            'semaforo' => $pct >= 95 ? 'verde' : ($pct >= 80 ? 'amarillo' : 'rojo'),
            'etiqueta' => $deberian === 0
                ? 'Sin documentos pendientes de subir'
                : "{$estan} / {$deberian} documentos en SIIGO · faltan {$faltan}",
        ];
    }
}
