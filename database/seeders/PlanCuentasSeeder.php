<?php

namespace Database\Seeders;

use App\Modules\Contabilidad\Models\PlanCuenta;
use Illuminate\Database\Seeder;

/**
 * PUC base (Decreto 2650) — subconjunto de arranque para GREAT BABY.
 * Incluye las cuentas que el ERP ya usa en asientos (1105, 1110, 1355, 1435,
 * 2365/2367/2368, 2408, 4135, 5195, 6135, 139535…) + sus mayores.
 * Silvia lo ajusta/reemplaza importando su propio plan. Idempotente.
 */
class PlanCuentasSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * [codigo, nombre, permite_movimiento]
         *
         * ───────────────────────────────────────────────────────────────────
         * `permite_movimiento` TIENE QUE DECIR LA VERDAD
         *
         * Este PUC base es el que usa la suite. Seis cuentas de cuatro
         * dígitos —1435, 2205, 2408, 4135— estaban sembradas como si
         * recibieran movimiento, y en el plan real de Great Baby (1.081
         * cuentas) son GRUPOS: no reciben nada.
         *
         * Esa mentira es la razón de que el problema no saliera nunca en
         * pruebas. Las reglas de cuenta apuntaban a 1435 y a 4135, los
         * asientos se escribían contra ellas, todo pasaba en verde — y en
         * producción el contador encontraba las cuentas a mano, de a una,
         * cuando un asiento no cuadraba. Eran 21 de 79.
         *
         * Si acá se vuelve a marcar `true` un grupo, el ERP deja de poder
         * avisar que una regla apunta a donde no debe.
         * ───────────────────────────────────────────────────────────────────
         */
        $cuentas = [
            // 1 · ACTIVO
            ['1', 'ACTIVO', false],
            ['11', 'DISPONIBLE', false],
            ['1105', 'CAJA', false],
            ['110505', 'Caja general', true],
            ['110510', 'Cajas menores', true],
            ['1110', 'BANCOS', false],
            ['111005', 'Moneda nacional — Bancolombia', true],
            ['111010', 'Moneda nacional — Davivienda', true],
            ['13', 'DEUDORES', false],
            ['1305', 'Clientes', false],
            ['130505', 'Clientes nacionales', true],
            ['1355', 'Anticipo de impuestos y contribuciones', false],
            ['135515', 'Retención en la fuente', true],
            ['135517', 'Impuesto a las ventas retenido', true],
            ['139535', 'Partida conciliatoria (ajustes por conciliar)', true],
            ['14', 'INVENTARIOS', false],
            ['1435', 'Mercancías no fabricadas por la empresa', false],
            ['143505', 'Mercancías para la venta', true],
            // 2 · PASIVO
            ['2', 'PASIVO', false],
            ['23', 'CUENTAS POR PAGAR', false],
            ['2205', 'PROVEEDORES', false],
            ['220505', 'Proveedores nacionales', true],
            ['2365', 'Retención en la fuente', true],
            ['2367', 'Impuesto a las ventas retenido', true],
            ['2368', 'Impuesto de industria y comercio retenido', true],
            ['24', 'IMPUESTOS, GRAVÁMENES Y TASAS', false],
            ['2408', 'Impuesto sobre las ventas por pagar', false],
            ['240805', 'IVA generado en ventas', true],
            ['240810', 'IVA descontable en compras', true],
            // 3 · PATRIMONIO
            ['3', 'PATRIMONIO', false],
            ['31', 'CAPITAL SOCIAL', false],
            ['3105', 'Capital suscrito y pagado', true],
            ['36', 'RESULTADOS DEL EJERCICIO', false],
            ['3605', 'Utilidad del ejercicio', true],
            // 4 · INGRESOS
            ['4', 'INGRESOS', false],
            ['41', 'OPERACIONALES', false],
            ['4135', 'Comercio al por mayor y al por menor', false],
            ['413505', 'Venta de mercancías', true],
            ['42', 'NO OPERACIONALES', false],
            ['4210', 'Financieros', true],
            // 5 · GASTOS
            ['5', 'GASTOS', false],
            ['51', 'OPERACIONALES DE ADMINISTRACIÓN', false],
            ['5105', 'Gastos de personal', false],
            ['510506', 'Sueldos', true],
            ['5195', 'Diversos', true],
            ['52', 'OPERACIONALES DE VENTAS', false],
            ['5205', 'Gastos de personal (ventas)', true],
            ['53', 'NO OPERACIONALES', false],
            ['5305', 'Financieros', true],
            // 6 · COSTOS DE VENTAS
            ['6', 'COSTOS DE VENTAS', false],
            ['61', 'COSTO DE VENTAS Y DE PRESTACIÓN DE SERVICIOS', false],
            ['6135', 'Comercio al por mayor y al por menor', true],
            ['613505', 'Venta de mercancías', true],
        ];

        foreach ($cuentas as [$codigo, $nombre, $mov]) {
            PlanCuenta::updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'permite_movimiento' => $mov, 'activa' => true],
            );
        }

        PlanCuenta::vincularPadres();
    }
}
