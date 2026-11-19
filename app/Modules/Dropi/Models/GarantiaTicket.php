<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarantiaTicket extends Model
{
    protected $table = 'garantia_tickets';

    protected $fillable = [
        'numero', 'pedido_original_id', 'origen_type', 'origen_id',
        'variante_id', 'producto_id', 'contacto_id', 'cantidad',
        'cliente_nombre', 'cliente_telefono', 'descripcion_falla',
        'notas_decision', 'fotos_evidencia', 'valor_reposicion', 'pedido_reposicion_id',
        'estado', 'creado_por', 'decision_por',
        'plazo_concepto_at', 'decision_at',
        'ubicacion_reserva_id', 'closed_at',
        'costo_producto', 'costo_flete_recoger', 'costo_flete_enviar',
        'costo_nota_credito', 'costo_otros', 'costo_nota', 'costo_cargado_at',
    ];

    /** Los cinco renglones en los que se va la plata de una garantía. */
    public const RENGLONES_COSTO = [
        'costo_producto', 'costo_flete_recoger', 'costo_flete_enviar',
        'costo_nota_credito', 'costo_otros',
    ];

    protected $casts = [
        'plazo_concepto_at' => 'datetime',
        'decision_at' => 'datetime',
        'closed_at' => 'datetime',
        'costo_cargado_at' => 'datetime',
        'cantidad' => 'integer',
        'fotos_evidencia' => 'array',
        'valor_reposicion' => 'decimal:2',
        'costo_producto' => 'decimal:2',
        'costo_flete_recoger' => 'decimal:2',
        'costo_flete_enviar' => 'decimal:2',
        'costo_nota_credito' => 'decimal:2',
        'costo_otros' => 'decimal:2',
    ];

    public function pedidoOriginal(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_original_id');
    }

    /**
     * La venta de la que sale la garantía.
     *
     * Polimórfica porque el reclamo puede venir de una guía de Dropi o de una
     * factura B2B. Antes sólo cabía lo primero, y un cliente B2B —que es el
     * que compra a crédito— no tenía por dónde entrar: su ticket quedaba sin
     * venta de respaldo y no había contra qué validar nada.
     */
    public function origen(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('origen', 'origen_type', 'origen_id');
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Contacto::class, 'contacto_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Dropi\Models\Producto::class, 'producto_id');
    }

    /** Cómo se llama la venta de origen, para mostrarla sin preguntar de qué tipo es. */
    public function referenciaDeOrigen(): ?string
    {
        $o = $this->origen;
        if (! $o) {
            return null;
        }

        return $o instanceof DropiPedido
            ? 'Guía '.$o->guia
            : 'Factura '.($o->numero ?? '#'.$o->getKey());
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function decidioPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_por');
    }

    public function ubicacionReserva(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_reserva_id');
    }

    /**
     * Lo que esta garantía le costó a la empresa.
     *
     * No confundir con `valor_reposicion`, que es lo que paga el CLIENTE —y en
     * una garantía es cero—. Esto es lo que sale de la caja: el producto al
     * costo, los dos fletes y, si se devolvió plata, la nota crédito.
     */
    public function costoTotal(): float
    {
        return round(array_sum(array_map(
            fn (string $c) => (float) $this->{$c},
            self::RENGLONES_COSTO
        )), 2);
    }

    /** El desglose, que es lo que dice DÓNDE se fue la plata. */
    public function desgloseCosto(): array
    {
        return [
            'producto' => (float) $this->costo_producto,
            'flete_recoger' => (float) $this->costo_flete_recoger,
            'flete_enviar' => (float) $this->costo_flete_enviar,
            'nota_credito' => (float) $this->costo_nota_credito,
            'otros' => (float) $this->costo_otros,
            'total' => $this->costoTotal(),
        ];
    }

    public function tieneCostoCargado(): bool
    {
        return $this->costo_cargado_at !== null;
    }

    /**
     * Las garantías a las que todavía no se les cargó el costo.
     *
     * Una garantía cerrada sin costo cargado no es gratis: es un costo que
     * nadie midió. El flete llega facturado semanas después, así que el ticket
     * se cierra antes de saber cuánto costó, y si nadie vuelve, el informe de
     * posventa queda contando de menos.
     */
    public function scopeSinCosto($q)
    {
        return $q->whereNull('costo_cargado_at')
            ->whereIn('estado', ['en_reposicion', 'cerrada']);
    }

    public function scopeConCostoEntre($q, string $desde, string $hasta)
    {
        return $q->whereNotNull('costo_cargado_at')
            ->whereBetween('costo_cargado_at', [$desde.' 00:00:00', $hasta.' 23:59:59']);
    }
}
