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
        // MKT-6 · fuente del contacto al momento de crear el pedido.
        'fuente',
        'subtotal', 'iva', 'total', 'notas_cliente', 'notas_internas',
        'enviado_at', 'aprobado_at', 'aprobado_por_id', 'rechazado_at',
        'facturado_por_id', 'facturado_at', 'factura_id', 'motivo_rechazo',
        // LOG-J2 · si cae en retenido por cartera, guardamos el porqué.
        'motivo_retencion',
        // LOG-J7 · columnas de despacho con huella.
        'despachado_at', 'despachado_por_id', 'guia_transportadora', 'transportadora',
        'entregado_at', 'entregado_por_id', 'entrega_nota',
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
        'entregado_at' => 'datetime',
    ];

    public function despachadoPor(): BelongsTo { return $this->belongsTo(User::class, 'despachado_por_id'); }
    public function alistador(): BelongsTo { return $this->belongsTo(User::class, 'alistador_id'); }
    public function vendedor(): BelongsTo { return $this->belongsTo(User::class, 'vendedor_id'); }
    public function ubicacionOrigen(): BelongsTo { return $this->belongsTo(\App\Modules\Dropi\Models\InventarioUbicacion::class, 'ubicacion_origen_id'); }

    /**
     * MKT-6 · El pedido nace con la fuente que tenía su contacto. Se copia (y
     * no se lee en vivo) para que el reporte por fuente no cambie cuando
     * alguien corrige después la ficha del contacto.
     */
    protected static function booted(): void
    {
        static::creating(function (self $pedido) {
            if (blank($pedido->fuente) && $pedido->contacto_id) {
                $pedido->fuente = Contacto::whereKey($pedido->contacto_id)->value('fuente');
            }
        });
    }

    public function contacto(): BelongsTo { return $this->belongsTo(Contacto::class); }
    public function lista(): BelongsTo { return $this->belongsTo(ListaPrecios::class, 'lista_precios_id'); }
    public function items(): HasMany { return $this->hasMany(PedidoClienteItem::class, 'pedido_id'); }
    public function aprobadoPor(): BelongsTo { return $this->belongsTo(User::class, 'aprobado_por_id'); }

    public function facturadoPor(): BelongsTo { return $this->belongsTo(User::class, 'facturado_por_id'); }
    public function factura(): BelongsTo { return $this->belongsTo(FacturaVenta::class, 'factura_id'); }

    // ─────────────────────────────────────────────────────────
    // SEGUIMIENTO DE LA GUÍA
    // ─────────────────────────────────────────────────────────

    /**
     * En qué va la guía, con los plazos que configure logística.
     *
     * Se calcula, no se guarda: guardarlo obligaría a recalcularlo todas las
     * noches, y el día que cambien el plazo lo viejo quedaría mintiendo.
     */
    public function estadoGuia(): \App\Modules\Portal\Enums\EstadoGuia
    {
        $E = \App\Modules\Portal\Enums\EstadoGuia::class;

        if ($this->entregado_at) {
            return $E::Entregada;
        }
        if (! $this->despachado_at) {
            return $E::Pendiente;
        }

        $dias = $this->diasEnTransito();

        if ($dias > (int) setting('logistica.dias_sin_entregar')) {
            return $E::NoEntregada;
        }
        if ($dias > (int) setting('logistica.dias_transito')) {
            return $E::Tardia;
        }

        return $E::ATiempo;
    }

    /** Días enteros desde que salió de bodega. Null si no ha salido. */
    public function diasEnTransito(): ?int
    {
        if (! $this->despachado_at) {
            return null;
        }

        return (int) $this->despachado_at->startOfDay()
            ->diffInDays(($this->entregado_at ?? now())->startOfDay());
    }

    /**
     * El enlace para rastrear la guía en la página de la transportadora.
     *
     * Sale de la regla `logistica.rastreo_urls`, para que agregar una
     * transportadora nueva no sea tocar código. El nombre se compara sin
     * tildes, sin espacios y sin mayúsculas: en los pedidos viene escrito de
     * todas las formas posibles.
     */
    public function urlDeRastreo(): ?string
    {
        if (! $this->guia_transportadora || ! $this->transportadora) {
            return null;
        }

        // La regla es de tipo `json`, así que `setting()` ya la devuelve como
        // arreglo. Se contempla el texto por si alguien la guardó a mano.
        $crudo = setting('logistica.rastreo_urls');
        $urls = is_array($crudo) ? $crudo : (json_decode((string) $crudo, true) ?: []);
        $normalizar = fn (string $t) => strtoupper(preg_replace('/[^a-zA-Z0-9]/', '',
            iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: $t));

        $buscado = $normalizar($this->transportadora);
        foreach ($urls as $nombre => $url) {
            if ($normalizar((string) $nombre) === $buscado) {
                return str_replace('{guia}', rawurlencode($this->guia_transportadora), (string) $url);
            }
        }

        return null;
    }

    public function entregadoPor(): BelongsTo { return $this->belongsTo(User::class, 'entregado_por_id'); }
}
