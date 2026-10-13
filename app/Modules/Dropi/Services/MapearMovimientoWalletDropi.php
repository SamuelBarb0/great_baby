<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\DTOs\PagoWalletDTO;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use Carbon\CarbonImmutable;

/**
 * Movimiento crudo del historial de la wallet de Dropi (GET /historywallet) →
 * PagoWalletDTO.
 *
 * El tipo sale de `identification_code`, el catálogo de Dropi
 * (/wallet/consultconstantshistorywallets, 95 códigos). Los que importan para
 * Great Baby como proveedor se nombran abajo; el resto cae en «otro» con su
 * código en la categoría, para no perder de qué se trataba.
 */
class MapearMovimientoWalletDropi
{
    /** código de Dropi => tipo del ERP */
    private const TIPOS = [
        // Pago de la orden al proveedor, y su reverso cuando Dropi corrige el
        // estado de la guía (llega en negativo y resta del mismo pedido).
        '1006' => TipoMovimientoWallet::PagoGuia,
        '1009' => TipoMovimientoWallet::PagoGuia,

        '1020' => TipoMovimientoWallet::RetiroBanco, // salida por retiro de saldo
        '1021' => TipoMovimientoWallet::RetiroBanco, // retiro negado: vuelve a entrar

        '1041' => TipoMovimientoWallet::Indemnizacion,
        '1044' => TipoMovimientoWallet::Indemnizacion,
        '1046' => TipoMovimientoWallet::Indemnizacion,
        '1052' => TipoMovimientoWallet::Indemnizacion,
        '1056' => TipoMovimientoWallet::Indemnizacion,
        '1057' => TipoMovimientoWallet::Indemnizacion,

        '1040' => TipoMovimientoWallet::FleteGarantia,
        '1042' => TipoMovimientoWallet::FleteGarantia,
        '1043' => TipoMovimientoWallet::FleteGarantia,
        '1047' => TipoMovimientoWallet::FleteGarantia,

        '1038' => TipoMovimientoWallet::Tarjeta,
        '1039' => TipoMovimientoWallet::Tarjeta,
        '1045' => TipoMovimientoWallet::Tarjeta,
        '1058' => TipoMovimientoWallet::Tarjeta,
    ];

    public static function map(array $m): PagoWalletDTO
    {
        $codigo = (string) ($m['identification_code'] ?? '');
        $monto = abs((float) ($m['amount'] ?? 0));
        // `type` es ENTRADA o SALIDA; el monto siempre llega positivo.
        if (strtoupper((string) ($m['type'] ?? '')) === 'SALIDA') {
            $monto = -$monto;
        }

        $descripcion = (string) ($m['description'] ?? '');
        preg_match('/GUIA:\s*\*?([A-Z0-9-]+)\*?/i', $descripcion, $guia);

        return new PagoWalletDTO(
            tipo: (self::TIPOS[$codigo] ?? TipoMovimientoWallet::Otro)->value,
            monto: $monto,
            // Dropi manda UTC; la wallet se lee por día local.
            fecha: CarbonImmutable::parse((string) $m['created_at'])->setTimezone(config('app.timezone')),
            guia: $guia[1] ?? null,
            // Columna de 40: el código y el comienzo de la descripción.
            categoria: mb_substr(trim($codigo . ' ' . strtok($descripcion, ':')), 0, 40) ?: null,
            raw: $m,
            dropiMovimientoId: isset($m['id']) ? 'web:' . $m['id'] : null,
            ordenId: ! empty($m['order_id']) ? (string) $m['order_id'] : null,
        );
    }
}
