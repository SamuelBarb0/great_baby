<?php

namespace App\Modules\Compras\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Una cuota del plan de pagos de una orden de importación. La auditoría guarda la
 * historia de los seguimientos: aquí sólo vive el último.
 */
class CompromisoPago extends Model implements AuditableContract
{
    use Auditable;

    public const BASES = [
        'emision' => 'la fecha de la orden',
        'fabricacion' => 'el fin de la fabricación (cargue)',
        'zarpe' => 'el zarpe del buque',
    ];

    protected $table = 'compras_compromisos_pago';

    protected $guarded = ['id'];

    protected $casts = [
        'porcentaje' => 'decimal:2',
        'pagado_en' => 'date',
        'ultimo_seguimiento_en' => 'date',
        'aviso_naranja_en' => 'date',
        'aviso_rojo_en' => 'date',
        'aviso_vencida_en' => 'date',
    ];

    /** Los avisos enviados no son cambios que valga la pena auditar. */
    protected $auditExclude = ['aviso_naranja_en', 'aviso_rojo_en', 'aviso_vencida_en'];

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_id');
    }
}
