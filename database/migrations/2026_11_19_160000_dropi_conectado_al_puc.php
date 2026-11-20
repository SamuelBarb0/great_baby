<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dropi conectado al PUC (19-nov-2026) · SÓLO DROPI.
 *
 * El método de pago «Wallet Dropi». Los cobros desde la wallet ya se guardaban
 * con `medio_pago = wallet_dropi`, pero el método no existía: el recibo de
 * caja salía a SIIGO con el tipo de pago general, o sea que en SIIGO la plata
 * entraba al BANCO y no a la billetera. Con el método creado, en Cartera →
 * Métodos de pago se le elige el tipo de pago de SIIGO que apunta a la
 * 11100501; mientras no lo tenga, el recibo no sale (ver
 * SiigoEmisionService::tipoPagoSiigoDe).
 *
 * No toca nada del ERP: ni reglas, ni categorías, ni el plan de cuentas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metodos_pago') || DB::table('metodos_pago')->where('codigo', 'wallet_dropi')->exists()) {
            return;
        }

        DB::table('metodos_pago')->insert([
            'codigo' => 'wallet_dropi',
            'nombre' => 'Wallet Dropi',
            'tipo' => 'billetera',
            'requiere_referencia' => false,
            'requiere_banco' => false,
            'requiere_comprobante' => false,
            'cuenta_puc' => '11100501',
            'siigo_payment_type_id' => null,
            // Apagado A PROPÓSITO: así no aparece en «Registrar pago» del ERP
            // (ese formulario sólo ofrece los activos). Lo usa sólo el cobro
            // automático de la wallet, y se puede mapear igual en la pantalla.
            'activo' => false,
            'orden' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // No se borra: puede tener cobros apuntándolo.
    }
};
