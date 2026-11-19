<?php

namespace App\Modules\Portal\Models;

use App\Models\Contacto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lo que se le prometió a un cliente, antes de que haya pedido.
 *
 * Un cliente B2B casi nunca compra en la primera conversación: pide precios,
 * los compara y vuelve a la semana. Ese paso no existía en el ERP —sólo se
 * podían levantar pedidos—, así que se cotizaba por WhatsApp y se transcribía
 * a mano. El precio prometido no quedaba en ninguna parte.
 *
 * Una cotización no compromete inventario y vence. Las dos cosas son
 * deliberadas: reservar stock por algo que el cliente no aceptó congelaría
 * mercancía por conversaciones que no van a ningún lado, y un precio sin fecha
 * es una promesa indefinida.
 */
class Cotizacion extends Model
{
    use SoftDeletes;

    protected $table = 'cotizaciones';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'vigente_hasta' => 'date',
        'subtotal' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
        'enviada_at' => 'datetime',
        'respondida_at' => 'datetime',
        'convertida_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class, 'cotizacion_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCliente::class, 'pedido_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * Quién apretó «Convertir», que no es de quién es la venta.
     *
     * Gerencia puede convertirle la cotización a un vendedor que está en la
     * calle: el pedido queda de él —la comisión es suya— y acá queda el rastro
     * de quién lo cerró.
     */
    public function convertidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convertida_por');
    }

    /**
     * Si todavía vale lo que dice.
     *
     * Se compara contra el final del día: una cotización que vence hoy vale
     * hoy. Vencerla a las 00:00 del día que dice «vigente hasta» sería
     * quitarle un día al cliente sin avisarle.
     */
    public function estaVigente(): bool
    {
        return $this->vigente_hasta !== null
            && $this->vigente_hasta->endOfDay()->isFuture();
    }

    public function diasParaVencer(): ?int
    {
        return $this->vigente_hasta
            ? (int) now()->startOfDay()->diffInDays($this->vigente_hasta->startOfDay(), false)
            : null;
    }

    /**
     * Si se puede convertir en pedido.
     *
     * Tres condiciones, y las tres por separado para poder decir cuál falló:
     * que el cliente haya dicho que sí, que todavía esté vigente, y que no se
     * haya convertido antes —dos clics seguidos crearían dos pedidos iguales.
     */
    public function porQueNoSeConvierte(): ?string
    {
        if ($this->pedido_id) {
            return 'Esta cotización ya se convirtió en el pedido '
                .($this->pedido?->numero ?? '#'.$this->pedido_id).'.';
        }

        if ($this->estado === 'rechazada') {
            return 'El cliente rechazó esta cotización.';
        }

        if ($this->estado !== 'aceptada') {
            return 'Falta que el cliente la acepte.';
        }

        if (! $this->estaVigente()) {
            return 'La cotización venció el '.$this->vigente_hasta->format('Y-m-d')
                .'. Hay que hacer una nueva con los precios de hoy.';
        }

        return null;
    }

    public function sePuedeConvertir(): bool
    {
        return $this->porQueNoSeConvierte() === null;
    }

    /**
     * El estado que se le muestra a la gente.
     *
     * `vencida` no se guarda en la columna a propósito: habría que correr un
     * proceso todos los días para mantenerla al día, y una cotización que
     * venció anoche aparecería como vigente hasta que ese proceso corriera.
     * Se calcula contra la fecha, que es la única fuente que no se desactualiza.
     */
    public function estadoVisible(): string
    {
        if (in_array($this->estado, ['convertida', 'rechazada'], true)) {
            return $this->estado;
        }

        return $this->estaVigente() ? $this->estado : 'vencida';
    }

    public function scopeVigentes($q)
    {
        return $q->whereDate('vigente_hasta', '>=', now()->toDateString())
            ->whereNotIn('estado', ['convertida', 'rechazada']);
    }

    /** Las aceptadas que están a punto de vencerse sin que nadie las convierta. */
    public function scopePorVencer($q, int $dias)
    {
        return $q->where('estado', 'aceptada')
            ->whereNull('pedido_id')
            ->whereDate('vigente_hasta', '>=', now()->toDateString())
            ->whereDate('vigente_hasta', '<=', now()->addDays($dias)->toDateString());
    }
}
