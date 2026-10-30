<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una tarjeta del tablero = un entregable del proceso. La lista en la que está
 * es su estado; solo se mueve por `TableroLanzamiento`, que aplica las reglas.
 */
class LanzamientoTarjeta extends Model
{
    protected $table = 'marketing_lanzamiento_tarjetas';

    public const LISTAS = [
        'por_hacer' => 'Por hacer',
        'en_proceso' => 'En proceso',
        'pendiente_aprobacion' => 'Pendiente de aprobación',
        'bloqueado' => 'Bloqueado',
        'finalizado' => 'Finalizado',
    ];

    public const AREAS = [
        'compras' => 'Compras',
        'dropshipping' => 'Dropshipping (Araceli)',
        'marketing' => 'Marketing (Anny)',
        'bodega' => 'Bodega',
        'marketing_dropshipping' => 'Marketing + Dropshipping',
    ];

    protected $fillable = [
        'lanzamiento_id', 'clave', 'numero', 'titulo', 'area', 'lista', 'requiere_aprobacion',
        'responsable_id', 'vence_en',
        'bloqueo_motivo', 'bloqueo_responsable_id', 'bloqueo_accion',
        'aprobada_por', 'aprobada_en', 'finalizada_en',
    ];

    protected $casts = [
        'requiere_aprobacion' => 'boolean',
        'vence_en' => 'date',
        'aprobada_en' => 'datetime',
        'finalizada_en' => 'datetime',
    ];

    public function lanzamiento(): BelongsTo { return $this->belongsTo(Lanzamiento::class); }
    public function responsable(): BelongsTo { return $this->belongsTo(User::class, 'responsable_id'); }
    public function bloqueoResponsable(): BelongsTo { return $this->belongsTo(User::class, 'bloqueo_responsable_id'); }
    public function aprobador(): BelongsTo { return $this->belongsTo(User::class, 'aprobada_por'); }
    public function items(): HasMany { return $this->hasMany(LanzamientoItem::class, 'tarjeta_id')->orderBy('posicion'); }
    public function comentarios(): HasMany { return $this->hasMany(LanzamientoComentario::class, 'tarjeta_id')->latest('id'); }

    public function checklistCompleto(): bool
    {
        return $this->items->every(fn (LanzamientoItem $i) => $i->hecho_en !== null);
    }
}
