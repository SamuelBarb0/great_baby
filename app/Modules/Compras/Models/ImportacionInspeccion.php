<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Lo que le sale al contenedor entero: la inspección forzosa en puerto o el
 * aleatorio de la DUTA. Liz lo marca y todo Compras se entera.
 */
class ImportacionInspeccion extends Model implements AuditableContract
{
    use Auditable;

    public const TIPOS = [
        'forzosa_puerto' => 'Inspección forzosa en puerto',
        'duta_fisica' => 'DUTA: inspección física',
        'duta_documental' => 'DUTA: revisión documental',
        'otra' => 'Otra novedad',
    ];

    protected $table = 'compras_importacion_inspecciones';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'resuelta_en' => 'date',
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
