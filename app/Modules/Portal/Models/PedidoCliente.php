<?php

namespace App\Modules\Portal\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Catalogo\Models\ListaPrecios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class PedidoCliente extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'pedidos_cliente';

    protected $fillable = [
        // LOG-J1 · si el pedido lo levantó un vendedor en terreno,
        //   queda su user_id en vendedor_id para comisiones y auditoría.
        'vendedor_id',
        // Multiempresa · razón social que factura el pedido (NULL = Great Baby).
        'empresa_id',
        // LOG-J4 · ubicación origen auto-asignada por la regla de ruteo.
        'ubicacion_origen_id',
        'numero', 'contacto_id', 'lista_precios_id', 'estado',
        'subtotal', 'iva', 'total', 'notas_cliente', 'notas_internas',
        'enviado_at', 'aprobado_at', 'rechazado_at',
        'facturado_por_id', 'facturado_at', 'factura_id', 'motivo_rechazo',
        // LOG-J2 · si cae en retenido por cartera, guardamos el porqué.
        'motivo_retencion',
        // LOG-J7 · columnas de despacho con huella.
        'despachado_at', 'despachado_por_id', 'guia_transportadora', 'transportadora',
        // LOG-J5 · Cola Jorge · huella de alistamiento.
        'alistador_id', 'alistado_asignado_at', 'alistado_inicio_at', 'alistado_fin_at', 'alistado_notas',
        // LOG-J5-fix · novedad del alistamiento (faltante/avería/revisión).
        'alistado_con_novedad', 'alistado_tipo_novedad',
        'novedad_resuelta_at', 'novedad_resuelta_por_id', 'novedad_resolucion',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
        'enviado_at' => 'datetime',
        'aprobado_at' => 'datetime',
        'rechazado_at' => 'datetime',
        'facturado_at' => 'datetime',
        'despachado_at' => 'datetime',
        'alistado_asignado_at' => 'datetime',
        'alistado_inicio_at' => 'datetime',
        'alistado_fin_at' => 'datetime',
        'alistado_con_novedad' => 'boolean',
        'novedad_resuelta_at' => 'datetime',
    ];

    public function despachadoPor(): BelongsTo { return $this->belongsTo(User::class, 'despachado_por_id'); }
    public function alistador(): BelongsTo { return $this->belongsTo(User::class, 'alistador_id'); }
    public function vendedor(): BelongsTo { return $this->belongsTo(User::class, 'vendedor_id'); }
    public function ubicacionOrigen(): BelongsTo { return $this->belongsTo(\App\Modules\Dropi\Models\InventarioUbicacion::class, 'ubicacion_origen_id'); }

    public function contacto(): BelongsTo { return $this->belongsTo(Contacto::class); }
    public function lista(): BelongsTo { return $this->belongsTo(ListaPrecios::class, 'lista_precios_id'); }
    public function items(): HasMany { return $this->hasMany(PedidoClienteItem::class, 'pedido_id'); }
    public function facturadoPor(): BelongsTo { return $this->belongsTo(User::class, 'facturado_por_id'); }
    public function factura(): BelongsTo { return $this->belongsTo(FacturaVenta::class, 'factura_id'); }
}
