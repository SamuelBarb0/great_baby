<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Compras\Enums\EtapaContenedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class ImportacionEtapa extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'compras_importacion_etapas';

    protected $guarded = ['id'];

    protected $casts = [
        'etapa' => EtapaContenedor::class,
        'fecha' => 'date',
        'ultima_alerta_en' => 'date',
    ];

    /** Avisar de un atraso no es un cambio que valga la pena auditar. */
    protected $auditExclude = ['ultima_alerta_en'];

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
