<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La orden de contratación que autoriza la Junta Directiva.
 *
 * No es la requisición. La requisición la hace el jefe de área y dice QUÉ se
 * necesita; esta dice que la empresa acepta el gasto. Elena lo dijo así:
 * «solo ingresa la empresa realmente a un contrato si viene autorizado por la
 * junta directiva; no es el líder el que toma la decisión final».
 *
 * Lo que se autoriza es un cargo y un salario, no una persona: por eso el
 * salario se congela acá, para que después nadie contrate por más de lo
 * autorizado sin que quede a la vista.
 */
class OrdenContratacion extends Model
{
    use RegistraBitacora;
    use SoftDeletes;

    protected $table = 'rrhh_ordenes_contratacion';

    protected $fillable = [
        'numero', 'candidato_id', 'requisicion_id', 'vacante_id',
        'cargo', 'salario_propuesto', 'tipo_contrato', 'justificacion',
        'estado', 'solicitada_por_id', 'solicitada_at',
        'decidida_por_id', 'decidida_at', 'motivo_decision',
        // Fase D1 · lo que pide el formato GH-F-018.
        'area', 'fecha_inicio', 'horario', 'ciudad', 'periodicidad_pago', 'observaciones',
    ];

    protected $casts = [
        'salario_propuesto' => 'decimal:2',
        'fecha_inicio' => 'date',
        'solicitada_at' => 'datetime',
        'decidida_at' => 'datetime',
    ];

    /** Las dos formas de pago que admite el formato. */
    public const PERIODICIDADES = ['mensual' => 'Mensual', 'quincenal' => 'Quincenal'];

    /**
     * Qué le falta al formato para poder imprimirse completo.
     *
     * El papel que firma la Junta tiene once casillas. Si alguna va en blanco,
     * alguien la completa a mano después de la firma — y entonces la Junta
     * autorizó una cosa y el contrato dice otra. Esto lo dice antes.
     *
     * @return array<string> en palabras
     */
    public function faltantesDelFormato(): array
    {
        $exigidos = [
            'area' => 'área',
            'fecha_inicio' => 'fecha de inicio',
            'horario' => 'horario de trabajo',
            'ciudad' => 'ciudad donde va a laborar',
            'periodicidad_pago' => 'tipo de pago',
            'salario_propuesto' => 'salario a devengar',
        ];

        return array_values(array_filter(
            $exigidos,
            fn (string $campo) => blank($this->{$campo}),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * Quién hace de «LÍDER DE ÁREA» en las dos firmas del formato.
     *
     * No se pide una firma nueva: el líder ya actuó dos veces antes de llegar
     * acá —pidió el personal en la requisición y eligió a esta persona de la
     * terna— y las dos quedaron con fecha y nombre. El impreso muestra ese
     * acto, que es el que de verdad ocurrió, en vez de inventar un tercer
     * botón que alguien tendría que apretar sin mirar nada nuevo.
     */
    public function liderDelArea(): ?User
    {
        return $this->requisicion?->solicitante;
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(Requisicion::class);
    }

    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    public function solicitadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por_id');
    }

    public function decididaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por_id');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->where('estado', 'pendiente');
    }

    /** Sólo una orden autorizada abre la puerta a la contratación. */
    public function autoriza(): bool
    {
        return $this->estado === 'autorizada';
    }

    public function etiquetaEstado(): string
    {
        return match ($this->estado) {
            'pendiente' => 'Esperando a la Junta',
            'autorizada' => 'Autorizada',
            'negada' => 'Negada',
            default => $this->estado,
        };
    }

    public function colorEstado(): string
    {
        return match ($this->estado) {
            'pendiente' => 'warning',
            'autorizada' => 'success',
            'negada' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Consecutivo del año: ODC-2026-0001.
     *
     * `ODC` y no `OC` a propósito: `OC-2026-000001` ya es una orden de COMPRA
     * en este mismo sistema, y dos documentos distintos con el mismo prefijo
     * se confunden en una conversación en cuanto alguien dice «la OC».
     *
     * Sobre el máximo y no sobre el conteo, igual que las requisiciones: con
     * el conteo, borrar una repetiría el número de la siguiente.
     */
    public static function siguienteNumero(): string
    {
        $anio = now()->year;
        $ultimo = static::withTrashed()
            ->where('numero', 'like', "ODC-{$anio}-%")
            ->selectRaw('MAX(CAST(SUBSTRING(numero, 10) AS UNSIGNED)) as n')
            ->value('n');

        return sprintf('ODC-%d-%04d', $anio, ((int) $ultimo) + 1);
    }
}
