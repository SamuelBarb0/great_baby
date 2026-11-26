<?php

namespace App\Modules\Compras\Actions;

use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\OrdenCompra;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Con o sin IVA, para toda una orden en borrador (25-nov-2026: «debes poder
 * seleccionar si llevan o si no llevan IVA»). Sin IVA deja cada línea en 0 %;
 * con IVA vuelve al 19 % sólo las que estaban en 0, para no pisar una línea
 * que alguien dejó en otro porcentaje.
 *
 * Aprobada la orden ya está comprometida con el proveedor: no se toca. En una
 * importación el IVA se liquida en aduana, no en la orden.
 */
class CambiarIvaOrdenCompra
{
    use AsAction;

    public const IVA_GENERAL = 19.0;

    public function handle(OrdenCompra $orden, bool $llevaIva): OrdenCompra
    {
        if ($orden->estado !== EstadoOrdenCompra::Borrador) {
            throw new InvalidArgumentException('El IVA sólo se cambia mientras la orden está en borrador.');
        }
        if ($orden->tipo === 'importacion') {
            throw new InvalidArgumentException('En una importación el IVA se liquida en aduana, no en la orden.');
        }

        return DB::transaction(function () use ($orden, $llevaIva) {
            foreach ($orden->items()->get() as $item) {
                if (! $llevaIva) {
                    $item->iva_pct = 0;
                } elseif ((float) $item->iva_pct <= 0) {
                    $item->iva_pct = self::IVA_GENERAL;
                }
                // Al guardar, la línea recalcula su IVA y su total.
                if ($item->isDirty('iva_pct')) {
                    $item->save();
                }
            }

            return RecalcularTotalesOC::make()->handle($orden->unsetRelation('items'));
        });
    }

    /** ¿La orden lleva IVA? Lo lleva si alguna línea lo tiene. */
    public static function llevaIva(OrdenCompra $orden): bool
    {
        return $orden->items->contains(fn ($i) => (float) $i->iva_pct > 0);
    }
}
