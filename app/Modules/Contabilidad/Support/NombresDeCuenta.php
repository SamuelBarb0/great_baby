<?php

namespace App\Modules\Contabilidad\Support;

use App\Modules\Contabilidad\Models\PlanCuenta;

/**
 * El nombre real de una cuenta, tomado del plan de cuentas.
 *
 * Las pantallas contables venían pintando el nombre con `pucLabels.js`, una
 * tabla de apodos escrita a mano cuando el ERP todavía no tenía el PUC del
 * cliente cargado. Resolvía por prefijo: cualquier cosa que empezara por 4135
 * salía como «Ingresos comercio al por mayor».
 *
 * Eso dejó de ser una aproximación y pasó a ser un error el 2026-11-05, cuando
 * el asiento de venta empezó a partir el ingreso por categoría: `41350110`
 * (Vestuario bebé) y `41350104` (Juguetería) aparecían con el MISMO nombre, una
 * encima de la otra, como si fueran la misma cuenta.
 *
 * El apodo sigue existiendo como respaldo en el front para una cuenta que no
 * esté en el catálogo; cuando sí está, manda el nombre de verdad.
 */
final class NombresDeCuenta
{
    /**
     * @param  iterable<int,string|null>  $codigos
     * @return array<string,string> codigo => nombre
     */
    public static function para(iterable $codigos): array
    {
        $limpios = [];
        foreach ($codigos as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $limpios[$c] = true;
            }
        }

        if ($limpios === []) {
            return [];
        }

        return PlanCuenta::whereIn('codigo', array_keys($limpios))
            ->pluck('nombre', 'codigo')
            ->all();
    }
}
