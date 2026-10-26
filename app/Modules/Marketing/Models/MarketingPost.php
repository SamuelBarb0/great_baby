<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingPost extends Model
{
    use SoftDeletes;

    protected $table = 'marketing_posts';

    protected $fillable = [
        'fecha_publicacion', 'hora_publicacion', 'titulo', 'copy',
        'canal', 'tipo', 'estado', 'producto_id', 'creado_por',
        'url_publicacion', 'notas',
        'importacion_id', 'etapa', 'grabado_en', 'editado_en', 'entregado_en',
    ];

    /**
     * MKT-3 · Producción del contenido: el día de grabación, al otro la
     * edición y al tercero la entrega a las dos áreas (mayorista y dropship).
     */
    public const ETAPAS = ['grabacion', 'edicion', 'entregado'];

    protected $casts = [
        'fecha_publicacion' => 'date',
        'grabado_en' => 'datetime',
        'editado_en' => 'datetime',
        'entregado_en' => 'datetime',
    ];

    public function producto(): BelongsTo { return $this->belongsTo(Producto::class); }
    public function creador(): BelongsTo { return $this->belongsTo(User::class, 'creado_por'); }
    public function importacion(): BelongsTo { return $this->belongsTo(Importacion::class); }
    public function creativos(): HasMany { return $this->hasMany(MarketingCreativo::class, 'post_id'); }

    /** La etapa que sigue, o null si ya se entregó. */
    public function siguienteEtapa(): ?string
    {
        $i = $this->etapa === null ? -1 : array_search($this->etapa, self::ETAPAS, true);

        return self::ETAPAS[$i + 1] ?? null;
    }
}
