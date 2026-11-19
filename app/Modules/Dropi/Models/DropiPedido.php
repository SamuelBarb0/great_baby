<?php

namespace App\Modules\Dropi\Models;

use App\Modules\Dropi\Concerns\HasEstadoDropi;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class DropiPedido extends Model implements AuditableContract
{
    use Auditable, HasEstadoDropi, SoftDeletes;

    protected $table = 'dropi_pedidos';

    // Re-audit DR-β (DATOS-C1, SEG-M1) · $guarded reemplaza $fillable.
    //   Todo se asigna explícito por Actions/Sync/transicionar. Un
    //   `->update(['estado'=>...])` ya era no-op por $fillable, pero seguía
    //   dejando bitácora mentida en VistaAlistador (SEG-M1). Ahora también
    //   protege `corte_id` (evita reasignar pedido a otro corte), `guia`
    //   (identidad estable), `monto_esperado_proveedor` (financiero).
    //
    //   El sync (SincronizarPedidosDropi) usa asignación por propiedad
    //   ($p->campo = ...) para bypasar $guarded correctamente; controllers
    //   deben pasar por Actions.
    protected $guarded = ['id', 'estado', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = [
        'estado' => EstadoPedidoDropi::class,
        'despachado_at' => 'datetime',
        'entregado_at' => 'datetime',
        'devuelto_at' => 'datetime',
        'pagado_at' => 'datetime',
        'dropi_creado_at' => 'datetime',
        'alertas_enviadas' => 'array',
        'ari_enviado_at' => 'datetime',
        'notificado_despacho_at' => 'datetime',
        'requiere_factura_b2b' => 'boolean',
        'monto_esperado_proveedor' => 'decimal:2',
        'monto_cliente_final' => 'decimal:2',
        'ganancia_vendedor' => 'decimal:2',
        'flete_transportadora' => 'decimal:2',
    ];

    public function corte(): BelongsTo
    {
        return $this->belongsTo(DropiCorte::class, 'corte_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DropiPedidoItem::class, 'pedido_id');
    }

    public function bitacoraEstados(): HasMany
    {
        return $this->hasMany(DropiEstadoBitacora::class, 'pedido_id');
    }

    public function devolucion(): HasOne
    {
        return $this->hasOne(DropiDevolucion::class, 'pedido_id');
    }

    public function remision(): HasOne
    {
        return $this->hasOne(DropiRemision::class, 'pedido_id');
    }

    public function pagoWallet(): HasOne
    {
        return $this->hasOne(DropiWalletMovimiento::class, 'pedido_id')
            ->where('tipo', 'pago_guia');
    }

    public function sanciones(): HasMany
    {
        return $this->hasMany(DropiSancion::class, 'pedido_id');
    }
}
