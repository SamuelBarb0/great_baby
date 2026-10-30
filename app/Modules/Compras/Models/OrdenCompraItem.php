<?php

namespace App\Modules\Compras\Models;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

// Re-audit M2 R3 PATRÓN R (DATOS-A4) · Auditable en items — DIAN exige
//   trazabilidad de cambios en cantidad/precio/costo por producto.
class OrdenCompraItem extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'compra_orden_items';

    protected $fillable = [
        'orden_id', 'producto_id', 'variante_id',
        'descripcion', 'cantidad', 'cantidad_recibida',
        'precio_unit', 'descuento_pct', 'iva_pct',
        'subtotal', 'iva', 'total', 'costo_prorrateado_unit',
        'cajas', 'unidades_por_caja', 'caja_largo_cm', 'caja_ancho_cm', 'caja_alto_cm',
        'cbm_caja', 'peso_caja_kg', 'empresa_destino_id',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'cantidad_recibida' => 'decimal:3',
        'precio_unit' => 'decimal:4',
        'descuento_pct' => 'decimal:2',
        'iva_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
        'costo_prorrateado_unit' => 'decimal:4',
        'cajas' => 'integer',
        'unidades_por_caja' => 'integer',
        'caja_largo_cm' => 'decimal:2',
        'caja_ancho_cm' => 'decimal:2',
        'caja_alto_cm' => 'decimal:2',
        'cbm_caja' => 'decimal:6',
        'peso_caja_kg' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (OrdenCompraItem $item) {
            // Importación por cajas: lo que se pide son cajas, las unidades salen solas.
            if ($item->cajas && $item->unidades_por_caja) {
                $item->cantidad = $item->cajas * $item->unidades_por_caja;
            }
            // El CBM escrito manda (así trabaja su Excel); si no hay, sale de las medidas.
            if ((float) $item->cbm_caja <= 0 && (float) $item->caja_largo_cm > 0 && (float) $item->caja_ancho_cm > 0 && (float) $item->caja_alto_cm > 0) {
                $item->cbm_caja = round(
                    (float) $item->caja_largo_cm * (float) $item->caja_ancho_cm * (float) $item->caja_alto_cm / 1_000_000,
                    6,
                );
            }

            $bruto = (float) $item->cantidad * (float) $item->precio_unit;
            $descuento = $bruto * ((float) $item->descuento_pct / 100);
            $sub = round($bruto - $descuento, 2);
            $iva = round($sub * ((float) $item->iva_pct / 100), 2);

            $item->subtotal = $sub;
            $item->iva = $iva;
            $item->total = round($sub + $iva, 2);
        });
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class);
    }

    public function empresaDestino(): BelongsTo
    {
        return $this->belongsTo(\App\Models\EmpresaConfig::class, 'empresa_destino_id');
    }

    /** m³ que ocupa la línea en el contenedor. */
    public function cbmTotal(): float
    {
        return (float) $this->cbm_caja * (int) $this->cajas;
    }

    public function pendiente(): float
    {
        return max(0, (float) $this->cantidad - (float) $this->cantidad_recibida);
    }
}
