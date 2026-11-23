<?php

namespace App\Modules\Portal\Models;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoClienteItem extends Model
{
    protected $table = 'pedidos_cliente_items';

    protected $fillable = [
        'pedido_id', 'variante_id', 'producto_id',
        'sku_snapshot', 'descripcion_snapshot',
        'cantidad', 'cantidad_despachada', 'precio_unitario', 'iva_porcentaje',
        'subtotal', 'iva_valor', 'total',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        /*
         * CUÁNTO DE ESTA LÍNEA YA SALIÓ. Y EL NULL SE RESPETA.
         *
         * La columna nació con el despacho parcial y nadie la declaró acá.
         * Funcionaba de casualidad: el único que escribe usa `forceFill`, que
         * se salta el `$fillable`. Por cualquier otro camino —un seeder, un
         * comando de corrección, un `update()`— el valor se descartaba EN
         * SILENCIO y el parcial dejaba de cuadrar sin un solo error.
         *
         * El cast `integer` es a propósito y no un `default 0`: Laravel no
         * castea los nulos, así que NULL sigue llegando como NULL. Eso importa
         * porque NULL acá tiene dos significados opuestos según quién lea —
         * «sale completo» para `DescontarStockPorDespacho`, cero para
         * cualquier pantalla—, y es lo único que mantiene andando todo lo
         * viejo que nunca se tocó. Un accessor que devolviera 0 dejaría de
         * descontar stock a esos pedidos.
         */
        'cantidad_despachada' => 'integer',
        'precio_unitario' => 'decimal:2',
        'iva_porcentaje' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'iva_valor' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function pedido(): BelongsTo { return $this->belongsTo(PedidoCliente::class, 'pedido_id'); }
    // C-F6 · item apunta a variante (granular) O a producto (agregado).
    public function variante(): BelongsTo { return $this->belongsTo(ProductoVariante::class, 'variante_id'); }
    public function producto(): BelongsTo { return $this->belongsTo(Producto::class, 'producto_id'); }
    public function esAgregado(): bool { return $this->variante_id === null && $this->producto_id !== null; }
}
