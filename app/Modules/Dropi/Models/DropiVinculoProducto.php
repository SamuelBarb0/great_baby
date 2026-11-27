<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Llave de Dropi (`DV-<variación>`, SKU o `DP-<producto>`) → catálogo GB.
 *
 * Apunta a UNA de dos cosas: una variante (producto con desglose de stock) o
 * un producto agregado (`desglose_stock = false`, sin variantes; todas las
 * variaciones de Dropi descuentan del mismo total). Ver VincularProductoDropi
 * y ResolverEstadoInicial::destinoPara().
 */
class DropiVinculoProducto extends Model
{
    protected $table = 'dropi_vinculos_producto';

    protected $fillable = ['sku_dropi', 'variante_id', 'producto_id', 'producto_nombre', 'variacion', 'unidades', 'vinculado_por'];

    protected $casts = ['unidades' => 'integer'];

    /**
     * Cuántas unidades del ERP trae una variación de Dropi según su nombre
     * (27-nov-2026): «X 2 CAJAS», «x3», «PACK 2», «2 UNIDADES», «KIT X 4» → 2,
     * 3, 2, 2, 4. Lo que no lo dice es 1. Las tallas («18-20», «Talla 2») no
     * cuentan: sólo el número que acompaña a X, pack, kit o unidades/cajas.
     */
    public static function detectarUnidades(?string $variacion): int
    {
        $v = mb_strtoupper(trim((string) $variacion));
        if ($v === '') {
            return 1;
        }
        $patrones = [
            '/(?:^|[\s\/(-])X\s*(\d{1,2})\b/u',                         // «X 2 CAJAS», «x3»
            '/\b(?:PACK|KIT|COMBO|SET)\s*(?:X|DE|POR)?\s*(\d{1,2})\b/u',  // «PACK 2», «KIT X 4»
            '/\b(\d{1,2})\s*(?:UNIDADES|UNDS?|UDS?|CAJAS|PARES|PIEZAS|PZAS?)\b/u', // «2 UNIDADES», «3 CAJAS»
        ];
        foreach ($patrones as $patron) {
            if (preg_match($patron, $v, $m) && (int) $m[1] >= 1) {
                return min(99, (int) $m[1]);
            }
        }

        return 1;
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
