<?php

namespace App\Modules\Compras\Models;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

// Re-audit M2 R3 PATRÓN R · Auditable — trazabilidad DIAN de cambios post-recepción.
class RecepcionCompraItem extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'compras_recepcion_items';

    protected $fillable = [
        'recepcion_id', 'orden_item_id', 'producto_id', 'variante_id',
        // A dónde entró ESTA parte de la línea. Null = la bodega de la orden.
        // Sin esta clave acá el mass-assign la descartaba en silencio: la
        // recepción se confirmaba, el stock subía, y todo entraba al destino
        // de la orden como si nadie hubiera repartido nada.
        'ubicacion_id',
        'cantidad_recibida', 'costo_unit', 'subtotal', 'color_nombre',
        'lote', 'fecha_vencimiento', 'observaciones',
        // LOG-J8 · clasificación al descargar (apto|averia|cuarentena|revision|faltante)
        'clasificacion', 'motivo_clasificacion',
    ];

    protected $casts = [
        'cantidad_recibida' => 'decimal:3',
        'costo_unit' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'fecha_vencimiento' => 'date',
        'ubicacion_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (RecepcionCompraItem $item) {
            $item->subtotal = round((float) $item->cantidad_recibida * (float) $item->costo_unit, 2);
        });
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionCompra::class, 'recepcion_id');
    }

    /** A qué bodega entró esta parte de la línea. Null = la de la orden. */
    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Dropi\Models\InventarioUbicacion::class, 'ubicacion_id');
    }

    /**
     * La bodega donde de verdad quedó esta línea.
     *
     * Hay tres sitios que preguntan lo mismo —el kardex, el saldo sin
     * identificar y el traslado de e-commerce— y los tres leían el encabezado
     * de la recepción. Con el reparto eso dejó de ser cierto, y leer mal acá
     * deja movimientos en la bodega equivocada.
     */
    public function bodegaEfectiva(): ?int
    {
        return $this->ubicacion_id ?: $this->recepcion?->bodega_id;
    }

    /** ¿Esta línea fue a un destino distinto del de la orden? */
    public function fueRepartida(): bool
    {
        return $this->ubicacion_id !== null;
    }

    /**
     * La bodega donde de verdad quedó la mercancía de esta línea.
     *
     * `bodegaEfectiva()` contesta «a qué destino la mandaron», y eso NO es lo
     * mismo. Al confirmar, lo que no llegó apto no entra al destino elegido:
     * entra a la bodega de averías, porque sacar de la venta lo que llegó roto
     * pesa más que cualquier destino. Y lo marcado «faltante» no entra a
     * ninguna parte —no se recibió— pero la línea queda como constancia.
     *
     * Leer `bodegaEfectiva()` en una pantalla o en el PDF que se firma hacía
     * que la columna «Va para» dijera «Bodega principal» de unas cajas que
     * estaban en averías. Null = en ninguna bodega (faltante, o no hay bodega
     * de averías todavía).
     */
    public function bodegaDondeQuedo(): ?int
    {
        $clasificacion = $this->clasificacion ?: 'apto';

        if ($clasificacion === 'faltante') {
            return null;
        }

        if ($clasificacion === 'apto') {
            return $this->bodegaEfectiva();
        }

        return static::bodegaDeAveriasId();
    }

    /**
     * La bodega de averías, SIN crearla.
     *
     * `TrasRecepcion::ubicacionNoVendible()` la crea si no existe, que es lo
     * correcto al recibir pero no al pintar una pantalla: una consulta GET no
     * tiene por qué dejar filas nuevas en el inventario.
     *
     * Sin memoria estática a propósito: guardarla entre llamadas la deja
     * viciada en la suite de pruebas, donde la base se rehace entre caso y
     * caso y el id de ayer ya no existe.
     */
    public static function bodegaDeAveriasId(): ?int
    {
        $id = \App\Modules\Dropi\Models\InventarioUbicacion::query()
            ->where('categoria', \App\Modules\Dropi\Enums\CategoriaUbicacion::AveriaReparar->value)
            ->where('activa', true)
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    public function ordenItem(): BelongsTo
    {
        return $this->belongsTo(OrdenCompraItem::class, 'orden_item_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class);
    }
}
