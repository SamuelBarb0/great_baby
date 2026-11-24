<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un manifiesto de Dropi: la entrega de paquetes a una transportadora, con su
 * PDF y Excel guardados en el ERP. Lo llena SincronizarManifiestosDropi.
 */
class DropiManifiesto extends Model
{
    protected $table = 'dropi_manifiestos';

    protected $guarded = ['id'];

    protected $casts = [
        'guias' => 'array',
        'dropi_creado_at' => 'datetime',
        'revisado_at' => 'datetime',
    ];

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /** Las guías del manifiesto, para buscar en cuál salió un paquete. */
    public function scopeConGuia($query, string $guia)
    {
        return $query->whereJsonContains('guias', ['guia' => strtoupper(trim($guia))]);
    }
}
