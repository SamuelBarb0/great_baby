<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use App\Modules\Rrhh\Enums\ConceptoPsicotecnico;
use App\Modules\Rrhh\Enums\InstrumentoPsicotecnico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase C · El resultado de una prueba psicotécnica, no la prueba.
 *
 * Acá vive el concepto del profesional y el informe firmado. Las respuestas no
 * se guardan —ver la migración y el enum `InstrumentoPsicotecnico` para el
 * porqué— y no es un pendiente: es la forma del módulo.
 */
class PruebaPsicotecnica extends Model
{
    protected $table = 'rrhh_pruebas_psicotecnicas';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_aplicacion' => 'date',
        'instrumento' => InstrumentoPsicotecnico::class,
        'concepto' => ConceptoPsicotecnico::class,
    ];

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_id');
    }

    /** Cómo se llama la prueba, con el caso «otro» resuelto. */
    public function comoSeLlama(): string
    {
        return $this->instrumento === InstrumentoPsicotecnico::Otro
            ? ($this->instrumento_otro ?: 'Otra prueba')
            : $this->instrumento->label();
    }

    /** Quién la firma, con tarjeta si la dejó. */
    public function quienFirma(): string
    {
        return $this->profesional_tarjeta
            ? "{$this->profesional_nombre} · T.P. {$this->profesional_tarjeta}"
            : $this->profesional_nombre;
    }

    public function tieneSoporte(): bool
    {
        return filled($this->archivo_path);
    }

    /**
     * Lo que falta para que el resultado sirva de sustento.
     *
     * Se avisa, no se frena: el concepto puede llegar por teléfono y el
     * informe firmado un día después, y obligar a tenerlo todo de una
     * terminaría con la gente registrando la prueba cuando ya no sirve.
     *
     * @return array<string>
     */
    public function loQueFalta(): array
    {
        $falta = [];

        if (! $this->tieneSoporte()) {
            $falta[] = 'el informe firmado';
        }

        if ($this->instrumento->exigeProfesional() && blank($this->profesional_tarjeta)) {
            $falta[] = 'la tarjeta profesional de quien la aplicó';
        }

        return $falta;
    }

    /**
     * El resumen que ve el jefe que pidió el personal.
     *
     * Es a propósito lo más corto posible: el concepto y, si las hay, las
     * observaciones. El jefe decide a quién contrata, no lee un informe
     * psicológico — para eso está el profesional que lo firmó.
     */
    public function paraElJefe(): array
    {
        return [
            'prueba' => $this->comoSeLlama(),
            'concepto' => $this->concepto->label(),
            'color' => $this->concepto->color(),
            'observaciones' => $this->observaciones,
            'continua' => $this->concepto->continuaElProceso(),
        ];
    }
}
