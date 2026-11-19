<?php

namespace App\Modules\Contabilidad\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del extracto bancario dentro de una conciliación.
 *
 * El valor va con signo, como lo trae el banco: negativo es cargo. Guardar el
 * signo en vez de un campo «tipo» evita que los dos se contradigan.
 */
class ConciliacionLinea extends Model
{
    protected $table = 'conciliacion_lineas';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:2',
        'saldo' => 'decimal:2',
    ];

    public function conciliacion(): BelongsTo
    {
        return $this->belongsTo(ConciliacionBancaria::class, 'conciliacion_id');
    }

    public function esCargo(): bool
    {
        return (float) $this->valor < 0;
    }

    /** Lo que quedó sin explicar: ni casó con un pago, ni tiene cuenta asignada. */
    public function pideAtencion(): bool
    {
        return $this->estado !== 'conciliada' && blank($this->cuenta_puc);
    }
}
