<?php

namespace App\Support;

use App\Models\EmpresaConfig;
use App\Models\User;

/**
 * En qué empresa está parada la persona: el ERP general o el e-commerce (Dropi).
 *
 * La pidió quien opera el e-commerce: «no sé por dónde paso de una empresa a
 * otra; quiero estar sólo en Dropi y que me salga todo ahí». El `/admin` ya
 * tenía un interruptor «Modo: Empresa / DROPI» que guardaba `gb_canal` en la
 * sesión, pero no cambiaba nada más que el color de la barra. Este es el mismo
 * interruptor —misma llave de sesión, así los dos paneles dicen lo mismo— y en
 * `/app` sí decide qué menú se ve.
 *
 * Lo que NO hace: separar datos. El catálogo es compartido (decisión del
 * 6-nov) y la contabilidad del e-commerce se ve con el eje `linea=dropi` que
 * ya existía en el libro, el balance y el estado de resultados.
 */
final class EspacioTrabajo
{
    public const GENERAL = 'empresa';
    public const ECOMMERCE = 'dropi';

    private const SESION = 'gb_canal';

    /**
     * Puede cambiar quien opera Dropi en `/app`: Aracely/Gerencia y el
     * alistador. Es la puerta de las pantallas `/app/dropi/*`; el Gerente o el
     * Contador entran al /admin de Dropi pero aquí no tendrían nada que abrir,
     * y un selector que lleva a un menú vacío confunde más que ayuda.
     */
    public static function puedeCambiar(?User $u): bool
    {
        return $u !== null && ($u->esAracely() || $u->esAlistador());
    }

    /**
     * El espacio vigente. Si alguien sin Dropi quedó con la sesión en «dropi»
     * (la ruta vieja `/set-canal` la escribe para cualquiera), se le trata
     * como general: un menú de Dropi vacío no le sirve a nadie.
     */
    public static function actual(?User $u): string
    {
        return session(self::SESION) === self::ECOMMERCE && self::puedeCambiar($u)
            ? self::ECOMMERCE
            : self::GENERAL;
    }

    public static function esEcommerce(?User $u): bool
    {
        return self::actual($u) === self::ECOMMERCE;
    }

    public static function fijar(string $espacio): void
    {
        session([self::SESION => $espacio === self::ECOMMERCE ? self::ECOMMERCE : self::GENERAL]);
    }

    /**
     * La razón social con la que factura Dropi. Es la misma búsqueda que usa
     * `FacturarPedidoDropi`, para que la pantalla nombre a quien de verdad
     * aparece en las facturas.
     */
    public static function empresaEcommerce(): ?EmpresaConfig
    {
        return EmpresaConfig::porCodigo((string) setting('dropi.empresa', 'ecommerce'));
    }

    /** Lo que viaja a todas las pantallas para pintar el selector. */
    public static function paraPantalla(?User $u): ?array
    {
        if (! self::puedeCambiar($u)) {
            return null;
        }

        $ecommerce = self::empresaEcommerce();

        return [
            'actual' => self::actual($u),
            'opciones' => [
                ['valor' => self::GENERAL, 'label' => EmpresaConfig::current()->nombreCorto().' · todo'],
                ['valor' => self::ECOMMERCE, 'label' => 'E-commerce · Dropi'],
            ],
            'empresa_ecommerce' => $ecommerce?->nombreCorto(),
        ];
    }
}
