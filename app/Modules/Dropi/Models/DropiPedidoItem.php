<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Re-audit DR-β + η + ο (DATOS-C1, C2, C3, SEG-M1) ·
 *   - $guarded reemplaza $fillable: `cantidad` y `precio_proveedor_unit`
 *     ya no pueden mass-asignarse desde payload de Filament/form.
 *   - SoftDeletes por retención DIAN 5 años.
 *   - Auditable: cambios a cantidad/precio DESPUÉS de cierre de corte
 *     ya no quedan sin traza (el corte tiene manifiesto_hash pero antes
 *     el line-item se editaba sin registro).
 */
class DropiPedidoItem extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'dropi_pedido_items';

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = [
        'cantidad' => 'integer',
        'cantidad_pickeada' => 'integer',
        'precio_proveedor_unit' => 'decimal:2',
        'despachado' => 'boolean',
        'pickeado_at' => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    // C-F-QA1 · Item polimórfico: variante granular O producto agregado.
    public function producto(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Dropi\Models\Producto::class, 'producto_id');
    }

    public function esAgregado(): bool
    {
        return $this->variante_id === null && $this->producto_id !== null;
    }

    /**
     * Qué variante sacar del estante: la del ERP si el ítem apunta a una; si
     * no, la variación que mandó Dropi («ROJO», «SALMÓN / 20-21»).
     *
     * Casi todo lo de Dropi se vincula a un producto SIN variantes, así que la
     * lista de recolección y el empaque, que sólo miraban la variante del ERP,
     * salían con «—» en todas las filas: dos cuatrimotos sin saber cuál era la
     * verde y cuál la roja (bodega, 23-nov-2026). Cadena vacía si no hay nada.
     */
    public function nombreVariante(): string
    {
        $erp = $this->variante
            ? collect([$this->variante->color_nombre, $this->variante->diseno_nombre, $this->variante->talla])->filter()->implode(' ')
            : '';

        return $erp !== '' ? $erp : trim((string) $this->variacion);
    }

    /**
     * Nuestra referencia (26-nov-2026: bodega no reconoce «DV-922933», reconoce
     * «2504-17»): el código de la variante del ERP o, si el ítem es de un
     * producto sin variantes, la referencia del producto. Null sin vínculo.
     */
    public function referenciaErp(): ?string
    {
        $producto = $this->variante?->producto ?? $this->producto;

        return ($this->variante?->codigo_barras ?: $producto?->referencia) ?: null;
    }

    public function ubicacionAsignada(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_asignada_id');
    }

    public function subtotal(): float
    {
        return (float) $this->precio_proveedor_unit * $this->cantidad;
    }

    /**
     * Fix QA E2E re-audit · auto-poblar producto_id desde variante_id.
     *   SincronizarPedidosDropi hace updateOrCreate seteando sólo variante_id;
     *   sin este hook cada nuevo item importado desde Dropi entraba con
     *   producto_id NULL → ProcesarEscaneoEmpaque no matcheaba por producto,
     *   y los reportes de ventas por producto perdían filas.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if ($item->producto_id === null && $item->variante_id !== null) {
                $item->producto_id = ProductoVariante::whereKey($item->variante_id)->value('producto_id');
            }
        });
    }
}
