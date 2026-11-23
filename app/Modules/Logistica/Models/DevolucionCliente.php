<?php

namespace App\Modules\Logistica\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Lo que un cliente devolvió.
 *
 * El documento tiene dos estados y son del DOCUMENTO, no de la mercancía:
 * mientras está en **borrador** se arma la lista y el inventario no se ha
 * tocado; al **confirmar** entra al almacén 08 y ya no se edita.
 *
 * El estado de la MERCANCÍA —«por revisar»— no vive acá: es estar en el 08. Se
 * revisa, y sale de ahí con un traslado al almacén que corresponda. Por eso lo
 * único que esta tabla guarda de la inspección es CUÁNDO se hizo, que es lo
 * que permite vigilar el plazo que puso el área: a más tardar al día siguiente.
 */
class DevolucionCliente extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'devoluciones_cliente';

    protected $fillable = [
        'numero', 'contacto_id', 'pedido_id', 'factura_id',
        'ubicacion_origen_id', 'ubicacion_destino_id',
        'fecha', 'motivo', 'estado',
        'creada_por', 'confirmada_at', 'revisada_at', 'revisada_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'confirmada_at' => 'datetime',
        'revisada_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DevolucionClienteItem::class, 'devolucion_id');
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCliente::class, 'pedido_id');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_destino_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    public function esBorrador(): bool
    {
        return $this->estado === 'borrador';
    }

    /**
     * ¿Está esperando que alguien la revise?
     *
     * Confirmada —o sea, la mercancía ya está en el 08— y sin fecha de
     * revisión. Es la pregunta que abre la pantalla.
     */
    public function esperaRevision(): bool
    {
        return $this->estado === 'confirmada' && $this->revisada_at === null;
    }

    /**
     * Cuántos días lleva esperando, en días enteros.
     *
     * El plazo lo puso el área: «a más tardar al día siguiente a su ingreso».
     * Devuelve null si no está esperando — una devolución ya revisada no
     * lleva días de nada.
     *
     * El `copy()` no es adorno: `startOfDay()` MUTA el objeto y este proyecto
     * usa Carbon mutable, así que sin copiar, cualquier código que lea
     * `confirmada_at` después en la misma petición la vería movida a
     * medianoche.
     */
    public function diasEsperando(): ?int
    {
        if (! $this->esperaRevision() || ! $this->confirmada_at) {
            return null;
        }

        return (int) $this->confirmada_at->copy()->startOfDay()
            ->diffInDays(now()->copy()->startOfDay());
    }

    /** El consecutivo, atómico: dos registros simultáneos no repiten número. */
    public static function siguienteNumero(): string
    {
        $anio = now('America/Bogota')->year;

        return \Illuminate\Support\Facades\DB::transaction(function () use ($anio) {
            $prefijo = "DVC-{$anio}-";

            $max = static::withTrashed()
                ->where('numero', 'like', $prefijo.'%')
                ->lockForUpdate()
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(numero, ?) AS UNSIGNED)), 0) AS seq', [strlen($prefijo) + 1])
                ->value('seq');

            return sprintf('%s%06d', $prefijo, ((int) $max) + 1);
        });
    }
}
