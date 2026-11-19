<?php

namespace App\Modules\Dropi\Filament\Support;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\InventarioUbicacion;

/**
 * Reglas compartidas de las pantallas de compras del panel de Dropi.
 *
 * Los permisos son los de /app (ComprasGestionController), para que una
 * persona pueda lo mismo en los dos lados.
 */
final class ComprasDropi
{
    /** Armar, duplicar y anular una OC. */
    public static function puedeArmar(?User $u): bool
    {
        return (bool) $u?->puedeArmarOrdenes();
    }

    /** Aprobar compromete plata con el proveedor: `compras_oc` o Aracely. */
    public static function puedeAprobar(?User $u): bool
    {
        return $u !== null && (Permisos::puede($u, 'compras_oc') || $u->esAracely());
    }

    /** Recibir mercancía tiene su propia casilla. */
    public static function puedeRecibir(?User $u): bool
    {
        return $u !== null && Permisos::puede($u, 'compras_recepcion');
    }

    /** Contenedores: la casilla «Importaciones», la misma de /app. */
    public static function puedeImportar(?User $u): bool
    {
        return $u !== null && Permisos::puede($u, 'compras_importacion');
    }

    /** El contenedor pedido, sólo si es de Dropi (como `orden()`). */
    public static function importacion(int $id): Importacion
    {
        return Importacion::query()
            ->whereKey($id)
            ->where('linea_negocio', LineaNegocio::Dropi->value)
            ->firstOrFail();
    }

    /**
     * La OC pedida, sólo si es de Dropi: desde este panel no se abre una orden
     * de Great Baby cambiando el número en la dirección.
     */
    public static function orden(int $id): OrdenCompra
    {
        return OrdenCompra::query()
            ->whereKey($id)
            ->where('linea_negocio', LineaNegocio::Dropi->value)
            ->firstOrFail();
    }

    /**
     * Las bodegas a donde puede llegar la compra, con la de e-commerce primero.
     *
     * Son BODEGAS, no posiciones ni espejos de SIIGO. Esto listaba las 491
     * ubicaciones activas, así que se podía crear una orden cuyo destino fuera
     * «Rack 1 · Sección 1 · Nivel 1 · Derecha» o una bodega de otra empresa
     * del sandbox de SIIGO. Ese id viaja a la recepción y de ahí al kardex: el
     * contenedor quedaba asentado en un estante, y las posiciones no tienen
     * oficina ni almacén, así que el par línea de negocio × destino fiscal
     * tampoco resolvía.
     *
     * Es el mismo filtro que ya quedó escrito en el formulario de OC del ERP.
     */
    public static function bodegas(): array
    {
        $ecommerce = self::bodegaPorDefecto();

        return InventarioUbicacion::query()
            ->where('activa', true)
            ->whereNull('bodega_id')
            ->where('codigo', 'not like', 'SIIGO-%')
            ->orderByRaw('id = ? desc', [$ecommerce ?? 0])->orderBy('nombre')
            ->pluck('nombre', 'id')->all();
    }

    /** La ubicación de e-commerce de las Reglas, si está configurada. */
    public static function bodegaPorDefecto(): ?int
    {
        $id = (int) setting('compras.ubicacion_ecommerce', 0);

        return $id > 0 && InventarioUbicacion::whereKey($id)->exists() ? $id : null;
    }
}
