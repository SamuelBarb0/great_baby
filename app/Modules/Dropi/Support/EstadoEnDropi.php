<?php

namespace App\Modules\Dropi\Support;

/**
 * El estado del pedido tal como lo dice Dropi, para mostrarlo en el ERP
 * (25-nov-2026: «que quede igual a la plataforma»). Dropi lo manda en
 * mayúsculas (`GUIA_GENERADA`, `EN BODEGA DESTINO`); aquí sale legible y con
 * tildes («Guía generada», «En bodega destino»), con un color parecido al de
 * Dropi y agrupado en las pestañas de «Pedidos Dropi».
 */
class EstadoEnDropi
{
    /** Palabras que Dropi escribe sin tilde. */
    private const TILDES = [
        'GUIA' => 'GUÍA', 'DEVOLUCION' => 'DEVOLUCIÓN', 'REEXPEDICION' => 'REEXPEDICIÓN',
        'DOMESTICA' => 'DOMÉSTICA', 'TRANSITO' => 'TRÁNSITO',
    ];

    /**
     * Las pestañas, en el orden de un pedido: clave => [nombre, estados de Dropi].
     * Un estado nuevo que Dropi agregue sale en «Todos» hasta ubicarlo aquí.
     */
    public const PESTANAS = [
        'pendiente' => ['Pendiente', ['PENDIENTE']],
        'guia_generada' => ['Guía generada', ['GUIA_GENERADA']],
        'preparado' => ['Preparado para transportadora', ['PREPARADO PARA TRANSPORTADORA']],
        'en_transito' => ['En tránsito', [
            'EN DESPACHO', 'DESPACHADA', 'EN PROCESAMIENTO', 'EN BODEGA TRANSPORTADORA', 'EN TERMINAL ORIGEN',
            'EN TRANSPORTE', 'EN TERMINAL DESTINO', 'EN BODEGA DESTINO', 'EN ESPERA DE RUTA DOMESTICA',
            'EN REPARTO', 'EN REEXPEDICION', 'RECLAME EN OFICINA', 'INTENTO DE ENTREGA',
        ]],
        'novedad' => ['Novedad', ['NOVEDAD', 'NOVEDAD SOLUCIONADA']],
        'entregado' => ['Entregado', ['ENTREGADO']],
        'devolucion' => ['Devolución', ['DEVOLUCION', 'RECHAZADO']],
        'cancelado' => ['Cancelado', ['CANCELADO', 'REEMPLAZADA']],
    ];

    /** «GUIA_GENERADA» → «Guía generada». */
    public static function etiqueta(?string $estado): ?string
    {
        if ($estado === null || trim($estado) === '') {
            return null;
        }
        $palabras = array_map(fn ($p) => self::TILDES[$p] ?? $p, preg_split('/[\s_]+/', mb_strtoupper(trim($estado))));
        $texto = mb_strtolower(implode(' ', $palabras));

        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }

    /** El color de la etiqueta, parecido al de Dropi. */
    public static function color(?string $estado): string
    {
        return match (self::pestana($estado)) {
            'pendiente' => 'warning',
            'guia_generada', 'preparado' => 'info',
            'en_transito' => 'primary',
            'novedad' => 'warning',
            'entregado' => 'success',
            'devolucion', 'cancelado' => 'danger',
            default => 'gray',
        };
    }

    /** En qué pestaña cae un estado de Dropi; null si es uno que todavía no conocemos. */
    public static function pestana(?string $estado): ?string
    {
        $estado = mb_strtoupper(trim((string) $estado));
        foreach (self::PESTANAS as $clave => [, $estados]) {
            if (in_array($estado, $estados, true)) {
                return $clave;
            }
        }

        return null;
    }
}
