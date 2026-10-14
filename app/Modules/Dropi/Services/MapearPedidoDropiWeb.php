<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\DTOs\ItemDropiDTO;
use App\Modules\Dropi\DTOs\PedidoDropiDTO;
use Carbon\CarbonImmutable;

/**
 * Convierte el detalle de un pedido de la API web de Dropi
 * (GET bff/orders/myorders/v2/{id} → data.objects) a PedidoDropiDTO.
 *
 * Política de estados: Dropi no puede pisar el trabajo de bodega. Los estados
 * previos al despacho (PENDIENTE, GUIA_GENERADA, …) llegan como null, así un
 * pedido nuevo toma su estado del stock (ResolverEstadoInicial) y uno existente
 * se queda donde lo tiene la bodega (alistando, empacado…).
 */
class MapearPedidoDropiWeb
{
    /** Prefijo de la guía provisional de los pedidos que Dropi aún no despacha. */
    public const PREFIJO_SIN_GUIA = 'SIN-GUIA-';

    /** Estatus Dropi → valor canónico que entiende SincronizarPedidosDropi::mapearEstadoDropi(). */
    private const MAPA_ESTADO = [
        'ENTREGADO' => 'entregado',
        'DEVOLUCION' => 'devolucion',
        'EN REEXPEDICION' => 'devolucion',
        'CANCELADO' => 'cancelado',
        'RECHAZADO' => 'cancelado',
        // La tienda corrigió el pedido y Dropi creó uno nuevo (mismo cliente y
        //   valor); el viejo ya no sale. Verificado con pedidos reales el 24-sep-2026.
        'REEMPLAZADA' => 'cancelado',
    ];

    /** Todavía no sale de la bodega de Great Baby. */
    private const PREVIOS_AL_DESPACHO = [
        'PENDIENTE', 'PENDIENTE CONFIRMACION', 'PENDIENTE DE CONFIRMACION', 'PENDIENTE REABIERTO',
        'CONFIRMADO (PASARÁ A PENDIENTE)', 'EN PROCESAMIENTO', 'TELEMERCADEO',
        'GUIA_GENERADA', 'ERROR_GENERANDO_GUIA', 'GUIA_ANULADA',
        'PENDIENTE RECOLECCION', 'FALLO RECOLECCION', 'RECOLECCION ANULADA',
    ];

    /** Estados que no cambian nada en el ERP (se guardan en raw para trazabilidad). */
    private const SIN_EFECTO = ['INDEMNIZADO', 'INDEMNIZADA', 'INDEMNIZADA POR DROPI', 'EN PROCESO DE INDEMNIZACION', 'SINIESTRADO'];

    public function desdeDetalle(array $o): PedidoDropiDTO
    {
        $estatus = mb_strtoupper(trim((string) ($o['status'] ?? '')));
        $guia = strtoupper(trim((string) ($o['shipping_guide'] ?? '')));
        if ($guia === '') {
            $guia = self::PREFIJO_SIN_GUIA . $o['id'];
        }

        $items = [];
        $monto = 0.0;
        foreach ($o['orderdetails'] ?? [] as $d) {
            $cant = (int) ($d['quantity'] ?? 0);
            $sku = self::skuLinea($d);
            if ($cant <= 0 || $sku === null) {
                continue;
            }
            $precio = (float) ($d['supplier_price'] ?? $d['product']['sale_price'] ?? 0);
            $items[] = new ItemDropiDTO(
                skuDropi: $sku,
                cantidad: $cant,
                precioProveedorUnit: $precio,
                productoNombre: $d['product']['name'] ?? null,
                variacion: self::nombreVariacion($d['variation'] ?? null),
            );
            $monto += $precio * $cant;
        }

        $historial = collect($o['history'] ?? []);
        $fechaDe = function (array $estados) use ($historial): ?CarbonImmutable {
            $h = $historial->first(fn ($h) => in_array(mb_strtoupper((string) ($h['status'] ?? '')), $estados, true));

            return $h ? self::fecha($h['created_at'] ?? null) : null;
        };

        return new PedidoDropiDTO(
            guia: $guia,
            dropiOrdenId: (string) $o['id'],
            transportadora: self::txt($o['shipping_company'] ?? $o['distribution_company']['name'] ?? null),
            tienda: self::txt($o['shop']['name'] ?? null),
            vendedorNombre: self::txt(trim(($o['user']['name'] ?? '') . ' ' . ($o['user']['surname'] ?? ''))),
            vendedorIdentificacion: null,
            clienteNombre: self::txt(trim(($o['name'] ?? '') . ' ' . ($o['surname'] ?? ''))) ?? 'SIN NOMBRE',
            clienteDoc: null,
            clienteTelefono: self::txt($o['phone'] ?? null),
            clienteDireccion: self::txt(mb_substr((string) ($o['dir'] ?? ''), 0, 200)),
            clienteCiudad: self::txt($o['city'] ?? null),
            clienteDepto: self::txt($o['state'] ?? null),
            // '' = sin estado: mapearEstadoDropi() lo toma como null.
            estadoDropi: self::estadoCanonico($estatus) ?? '',
            montoEsperadoProveedor: $monto,
            montoClienteFinal: isset($o['total_order']) ? (float) $o['total_order'] : null,
            gananciaVendedor: isset($o['dropshipper_amount_to_win']) ? (float) $o['dropshipper_amount_to_win'] : null,
            fleteTransportadora: isset($o['shipping_amount']) ? (float) $o['shipping_amount'] : null,
            items: $items,
            creadoAt: self::fecha($o['created_at'] ?? null) ?? CarbonImmutable::now(),
            despachadoAt: $fechaDe(['DESPACHADA', 'EN TRANSITO', 'RECOLECTADO', 'EN BODEGA TRANSPORTADORA', 'EN REPARTO']),
            entregadoAt: $fechaDe(['ENTREGADO']),
            devueltoAt: $fechaDe(['DEVOLUCION']),
            raw: [
                'estatus_dropi' => $estatus,
                'origen' => 'web',
                'shop_order_number' => $o['shop_order_number'] ?? null,
                'rate_type' => $o['rate_type'] ?? null,
            ],
        );
    }

    /** null = el ERP decide (pedido nuevo) o conserva lo que tiene (existente). */
    public static function estadoCanonico(string $estatus): ?string
    {
        if (isset(self::MAPA_ESTADO[$estatus])) {
            return self::MAPA_ESTADO[$estatus];
        }
        if ($estatus === '' || in_array($estatus, self::PREVIOS_AL_DESPACHO, true) || in_array($estatus, self::SIN_EFECTO, true)) {
            return null;
        }

        // Todo lo demás son estados de la transportadora: el paquete ya salió.
        return 'despachado';
    }

    /** Misma llave que MapearProductosDropiExcel, para que Excel y web no dupliquen ítems. */
    private static function skuLinea(array $d): ?string
    {
        if (! empty($d['variation_id'])) {
            return 'DV-' . $d['variation_id'];
        }
        $sku = trim((string) ($d['product']['sku'] ?? ''));
        if ($sku !== '' && ! in_array($sku, ["'-0", '-0', '-'], true)) {
            return $sku;
        }

        return ! empty($d['product_id']) ? 'DP-' . $d['product_id'] : null;
    }

    private static function nombreVariacion(mixed $v): ?string
    {
        if (! is_array($v)) {
            return null;
        }
        $atributos = collect($v['attribute_values'] ?? [])->map(fn ($a) => $a['value'] ?? null)->filter()->implode(' / ');

        return self::txt($atributos ?: ($v['sku'] ?? null));
    }

    /** Dropi manda hora local de Colombia sin zona. */
    private static function fecha(?string $v): ?CarbonImmutable
    {
        if (! $v) {
            return null;
        }
        try {
            return CarbonImmutable::parse($v, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function txt(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
