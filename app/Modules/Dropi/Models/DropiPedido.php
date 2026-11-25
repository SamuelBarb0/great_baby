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
        'guia_impresa_at' => 'datetime',
    ];

    /** Lo último que el sync vio de este pedido en Dropi (estatus tal cual, guía descargada). */
    public function huella(): HasOne
    {
        return $this->hasOne(DropiWebHuella::class, 'dropi_orden_id', 'dropi_orden_id');
    }

    /**
     * Guía ya impresa: desde el ERP (`guia_impresa_at`) o descargada en Dropi.
     * Es el «IMPRESAS / NO IMPRESAS» con que Aracely separa un corte del otro.
     */
    public function scopeGuiaImpresa($query, bool $impresa = true)
    {
        $marca = fn ($q) => $q->whereNotNull('guia_impresa_at')
            ->orWhereHas('huella', fn ($h) => $h->where('guia_descargada', true));

        return $impresa ? $query->where($marca) : $query->whereNot($marca);
    }

    /**
     * Los estados que se pueden despachar (pedir guía, empacar). Lo «pendiente
     * por inventario» también, mientras el inventario del ERP se termina de
     * cargar (la señora Lahida, 24-nov-2026: «que podamos hacer todo»): la
     * mercancía está en la bodega aunque el ERP aún no la tenga. Con la regla
     * `dropi.exigir_stock_erp` prendida, se saltan hasta que haya stock.
     *
     * @return list<\App\Modules\Dropi\Enums\EstadoPedidoDropi>
     */
    public static function estadosDespachables(): array
    {
        $e = [\App\Modules\Dropi\Enums\EstadoPedidoDropi::Pending, \App\Modules\Dropi\Enums\EstadoPedidoDropi::Alistando];

        return setting('dropi.exigir_stock_erp', false) ? $e : [...$e, \App\Modules\Dropi\Enums\EstadoPedidoDropi::PendienteInventario];
    }

    /**
     * Lo que ya salió de la bodega (o va saliendo): si está sin corte, no entra
     * a las remisiones ni a las facturas del cierre (25-nov-2026).
     *
     * @return list<\App\Modules\Dropi\Enums\EstadoPedidoDropi>
     */
    public static function estadosYaDespachados(): array
    {
        $e = \App\Modules\Dropi\Enums\EstadoPedidoDropi::class;

        return [$e::Empacado, $e::Despachado, $e::Entregado, $e::Pagado, $e::DevolucionEnCamino, $e::Devuelto];
    }

    public function listoParaDespachar(): bool
    {
        return in_array($this->estado, self::estadosDespachables(), true);
    }

    public function sinStockErp(): bool
    {
        return $this->estado === \App\Modules\Dropi\Enums\EstadoPedidoDropi::PendienteInventario;
    }

    /**
     * ¿Se abrió en empaque viniendo de «pendiente por inventario»? Al abrirlo
     * pasa a «Alistando» y el estado ya no lo dice; la bitácora sí.
     */
    public function abiertoSinStockErp(): bool
    {
        return \Illuminate\Support\Facades\DB::table('dropi_estados_bitacora')
            ->where('pedido_id', $this->id)->where('estado_hasta', \App\Modules\Dropi\Enums\EstadoPedidoDropi::Alistando->value)
            ->latest('id')->value('estado_desde') === \App\Modules\Dropi\Enums\EstadoPedidoDropi::PendienteInventario->value;
    }

    public function tieneGuiaImpresa(): bool
    {
        return $this->guia_impresa_at !== null || (bool) $this->huella?->guia_descargada;
    }

    /** ¿Ya tiene la guía de la transportadora? (no la provisional SIN-GUIA-<orden> del sync). */
    public function tieneGuiaReal(): bool
    {
        return filled($this->guia)
            && ! str_starts_with((string) $this->guia, \App\Modules\Dropi\Services\MapearPedidoDropiWeb::PREFIJO_SIN_GUIA);
    }

    /** El PDF del sticker de la guía, publicado por Dropi (ver GenerarGuiaDropi). */
    public function stickerUrl(): ?string
    {
        return $this->sticker_path
            ? rtrim((string) config('dropi.web.stickers_url'), '/') . '/' . ltrim($this->sticker_path, '/')
            : null;
    }

    /** El lote de «Imprimir guías» del que salió su sticker. */
    public function loteImpresion(): BelongsTo
    {
        return $this->belongsTo(DropiLoteImpresion::class, 'lote_impresion_id');
    }

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
