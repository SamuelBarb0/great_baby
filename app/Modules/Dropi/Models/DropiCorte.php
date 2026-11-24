<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use App\Modules\Dropi\Enums\EstadoCorte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class DropiCorte extends Model implements AuditableContract
{
    // Re-audit DR-η (DATOS-C2) · SoftDeletes por retención DIAN 5 años.
    use Auditable, SoftDeletes;

    protected $table = 'dropi_cortes';

    // Re-audit DR-β (DATOS-C1) · $guarded. Antes: `$corte->update(['estado'=>'abierto'])`
    //   REABRÍA un corte cerrado bypasseando el hash. Ahora estado/cerrado_*/
    //   manifiesto_hash sólo se escriben por Action canónica (CerrarCorte).
    //   cortado_* (24-nov-2026) igual: sólo los escribe CortarEntradaCorte.
    protected $guarded = ['id', 'estado', 'cortado_por', 'cortado_at', 'cerrado_por', 'cerrado_at', 'manifiesto_hash', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = [
        'fecha' => 'date',
        'estado' => EstadoCorte::class,
        'cortado_at' => 'datetime',
        'cerrado_at' => 'datetime',
        'pedidos_totales' => 'integer',
        'pedidos_pendientes_inv' => 'integer',
        'pedidos_despachados' => 'integer',
        'snapshot_json' => 'array',
    ];

    public function pedidos(): HasMany
    {
        return $this->hasMany(DropiPedido::class, 'corte_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function cortadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cortado_por');
    }

    /**
     * El corte que se está trabajando en bodega: el más viejo que ya cerró la
     * entrada y no se ha finalizado; si no hay, el último. Después de «Cerrar
     * corte» los pedidos nuevos abren otro corte, pero lo que hay que recolectar
     * y empacar es lo del cerrado.
     */
    public static function enTrabajo(): ?self
    {
        return static::query()->where('estado', EstadoCorte::EnDespacho->value)
            ->orderBy('fecha')->orderBy('numero')->first()
            ?? static::query()->orderByDesc('fecha')->orderByDesc('numero')->first();
    }

    public function etiqueta(): string
    {
        return $this->fecha->format('Y-m-d') . ' — Corte ' . $this->numero;
    }
}
