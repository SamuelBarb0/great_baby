<?php

namespace Database\Seeders;

use App\Modules\Tesoreria\Models\FlujoConcepto;
use Illuminate\Database\Seeder;

/**
 * Los renglones del flujo de caja, tomados del Excel que ya usan.
 *
 * Se respetan los nombres y el orden de `flujo_de_caja_semanal_V8.xlsx` para
 * que quien lo llena hoy reconozca la pantalla. Lo que se agrega es el
 * `origen`: de dónde sale la columna «Real» sin que nadie la teclee.
 *
 * Los renglones sin origen se capturan a mano, como hoy — no todo lo que
 * mueve la caja pasa por el ERP: un préstamo de un socio, un sobregiro.
 *
 * Es idempotente: `updateOrCreate` por código. Volver a correrlo no duplica
 * ni pisa el responsable que Aracely haya asignado después.
 */
class FlujoDeCajaConceptosSeeder extends Seeder
{
    public function run(): void
    {
        $renglones = [
            // ── El saldo con que arranca el período ──────────────────────
            ['saldo_caja', 'Caja', 'saldo_inicial', 'V', 'movimientos_caja', 10],
            ['saldo_bancos', 'Bancos', 'saldo_inicial', 'V', null, 20],

            // ── Lo que entra por operación ───────────────────────────────
            ['recaudo_cartera', 'Recaudo de cartera y ventas', 'ingreso_operacional', 'V', 'recaudo_cartera', 10],
            ['anticipos_clientes', 'Anticipos de clientes', 'ingreso_operacional', 'V', null, 20],
            ['reintegro_prestamos', 'Reintegro de préstamos', 'ingreso_operacional', 'V', null, 30],
            ['reintegro_seguridad', 'Reintegro de seguridad social', 'ingreso_operacional', 'V', null, 40],

            // ── Lo que entra por fuera de la operación ───────────────────
            ['otras_fuentes', 'Otras fuentes', 'otro_ingreso', 'V', null, 10],
            ['prestamos_corrientes', 'Préstamos corrientes', 'otro_ingreso', 'V', null, 20],
            ['sobregiros', 'Sobregiros', 'otro_ingreso', 'V', null, 30],

            // ── Lo que sale ──────────────────────────────────────────────
            ['nomina_internos', 'Nómina (internos)', 'egreso_operacional', 'F', 'nomina', 10],
            ['pagos_proveedores', 'Pagos a proveedores', 'egreso_operacional', 'V', 'pagos_proveedor', 20],
            ['gastos_operativos', 'Gastos operativos y reembolsos', 'egreso_operacional', 'V', 'gastos_caja', 30],
            ['arriendos', 'Arriendos', 'egreso_operacional', 'F', null, 40],
            ['servicios_publicos', 'Servicios públicos', 'egreso_operacional', 'F', null, 50],
            ['impuestos', 'Impuestos', 'egreso_operacional', 'V', null, 60],

            ['inversiones', 'Inversiones y activos', 'otro_egreso', 'V', null, 10],
            ['abonos_obligaciones', 'Abonos a obligaciones financieras', 'otro_egreso', 'F', null, 20],
        ];

        foreach ($renglones as [$codigo, $nombre, $grupo, $tipo, $origen, $orden]) {
            FlujoConcepto::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre' => $nombre,
                    'grupo' => $grupo,
                    'tipo' => $tipo,
                    'origen' => $origen,
                    'orden' => $orden,
                    'activo' => true,
                ],
            );
        }

        $this->command?->info('Flujo de caja: '.count($renglones).' renglones · '
            .FlujoConcepto::whereNotNull('origen')->count().' calculan su real desde el ERP.');
    }
}
