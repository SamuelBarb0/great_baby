<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que el colaborador avisa sobre algo que le entregaron.
 *
 * «Las chicas se acercan y me dicen que les está fallando el teclado… y pues
 * no quedó el registro, compramos finalmente el teclado y se lo dimos. Y a
 * veces así: que la mesa, que la silla. Después vemos cómo la trazabilidad de
 * toda esa gestión» (Elena, 19-oct).
 *
 * El punto es justamente la trazabilidad: que el aviso quede escrito con
 * fecha, y que se vea si alguien lo atendió o no.
 */
class ReporteAsignacion extends Model
{
    protected $table = 'rrhh_reportes_asignacion';

    protected $fillable = [
        'asignacion_id', 'reportado_por_id', 'novedad', 'detalle',
        'estado', 'respuesta', 'atendido_por_id', 'atendido_at',
    ];

    protected $casts = ['atendido_at' => 'datetime'];

    /**
     * Quién resuelve depende de qué pasó, y eso lo dijo Elena: «si es un caso
     * de deterioro y es un tema de mantenimiento, lo maneja el líder del área.
     * Pero si es un tema ya de un hurto o algo, eso pasa a gestión humana
     * porque yo tengo que llamar a procesos disciplinarios».
     */
    public const NOVEDADES = [
        'falla' => 'Está fallando',
        'dano' => 'Se dañó',
        'perdida' => 'Se perdió',
        'hurto' => 'Me lo robaron',
        'devolucion' => 'Quiero devolverlo',
        'otro' => 'Otra cosa',
    ];

    /** Lo que no se arregla con mantenimiento: va a Gestión Humana. */
    public const VAN_A_GESTION_HUMANA = ['perdida', 'hurto'];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id');
    }

    public function reportadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportado_por_id');
    }

    public function atendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendido_por_id');
    }

    public function novedadLabel(): string
    {
        return self::NOVEDADES[$this->novedad] ?? $this->novedad;
    }

    public function esDeGestionHumana(): bool
    {
        return in_array($this->novedad, self::VAN_A_GESTION_HUMANA, true);
    }
}
