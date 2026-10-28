<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class DropiDevolucion extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'dropi_devoluciones';

    protected static function booted(): void
    {
        // Política: los fallos de LÓGICA de negocio (RuntimeException, LogicException) se tragan
        // via SafeAction — la devolución se registra igual y se loguea el error.
        //
        // Los fallos de BD (PDOException, QueryException, UniqueConstraintViolationException) SÍ
        // se re-lanzan por SafeAction — esto revierte la tx padre de RegistrarDevolucion.
        // Es CORRECTO contablemente: si no se puede escribir el asiento, tampoco se debe registrar
        // la devolución sin traza contable (política CxP: sin asiento no hay reconocimiento).
        static::created(function (DropiDevolucion $d) {
            \App\Support\SafeAction::run(\App\Modules\Cartera\Actions\ContabilizarDevolucionDropi::class, $d);
            \App\Support\SafeAction::run(\App\Modules\Cartera\Actions\EmitirNotaCreditoDropi::class, $d);
        });
    }

    protected $fillable = [
        'pedido_id', 'recibido_at', 'destino_inventario',
        'recibidos', 'unidades_esperadas', 'unidades_recibidas', 'reescaneada',
        'decision_por', 'genero_nota_credito', 'nota_credito_ari_id', 'notas',
    ];

    protected $casts = [
        'recibido_at' => 'datetime',
        'genero_nota_credito' => 'boolean',
        'recibidos' => 'array',
        'unidades_esperadas' => 'integer',
        'unidades_recibidas' => 'integer',
        'reescaneada' => 'boolean',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_id');
    }

    public function decidioAlistador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_por');
    }
}
