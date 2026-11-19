<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Una declaración de importación y lo que le salió en la selectividad de la DIAN.
 */
class ImportacionDeclaracion extends Model implements AuditableContract
{
    use Auditable;

    public const SELECTIVIDAD = [
        'pendiente' => 'Pendiente',
        'automatica' => 'Levante automático',
        'fisica' => 'Inspección física',
        'documental' => 'Inspección documental',
    ];

    protected $table = 'compras_importacion_declaraciones';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_cita' => 'date',
        'fecha_levante' => 'date',
    ];

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
