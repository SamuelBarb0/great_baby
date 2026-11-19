<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F8 · La inducción de alguien que entra.
 *
 * Antes era un campo de texto libre en la ficha, y de siete personas una sola
 * lo tenía lleno. Ahora es un temario con casillas: lo que se explicó, lo que
 * no, y quién lo explicó.
 *
 * Cada inducción guarda SU temario, copiado de la regla al momento de darla.
 * Si sólo guardara las casillas marcadas, el día que el área agregue un tema
 * las inducciones del año pasado aparecerían incompletas por algo que no
 * existía cuando se dieron.
 */
class Induccion extends Model
{
    protected $table = 'rrhh_inducciones';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'firmada_at' => 'date',
        'temas' => 'array',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por_id');
    }

    /**
     * El temario vigente, leído de la regla.
     *
     * Separado por barra vertical porque es una lista de frases con comas
     * adentro —«Horario, marcación y manejo de permisos»— y una coma como
     * separador la partiría en pedazos.
     *
     * @return array<string>
     */
    public static function temarioVigente(): array
    {
        $crudo = (string) setting('rrhh.temario_induccion');

        return array_values(array_filter(array_map('trim', explode('|', $crudo))));
    }

    /** El temario vigente listo para marcar, para una inducción nueva. */
    public static function temarioEnBlanco(): array
    {
        return array_map(
            fn (string $tema) => ['tema' => $tema, 'cubierto' => false, 'responsable' => '', 'observacion' => ''],
            self::temarioVigente(),
        );
    }

    /** @return array<array{tema:string, cubierto:bool, responsable:string, observacion:string}> */
    public function temario(): array
    {
        return array_map(fn ($t) => [
            'tema' => (string) ($t['tema'] ?? ''),
            'cubierto' => (bool) ($t['cubierto'] ?? false),
            'responsable' => (string) ($t['responsable'] ?? ''),
            'observacion' => (string) ($t['observacion'] ?? ''),
        ], (array) ($this->temas ?? []));
    }

    public function cubiertos(): int
    {
        return count(array_filter($this->temario(), fn (array $t) => $t['cubierto']));
    }

    /**
     * Los temas que quedaron sin ver.
     *
     * Se avisan pero no se frenan: una inducción puede quedar a medias y
     * terminarse al día siguiente. Lo que no puede pasar es que nadie sepa
     * cuáles faltan.
     *
     * @return array<string>
     */
    public function pendientes(): array
    {
        return array_values(array_map(
            fn (array $t) => $t['tema'],
            array_filter($this->temario(), fn (array $t) => ! $t['cubierto']),
        ));
    }

    public function completa(): bool
    {
        return $this->temario() !== [] && $this->pendientes() === [];
    }

    /**
     * ¿Sirve como prueba?
     *
     * Una inducción completa pero sin firmar demuestra que la empresa dice
     * que la dio. Firmada demuestra que la persona dice que la recibió, que
     * es lo que vale el día que haya que mostrarla.
     */
    public function firmada(): bool
    {
        return $this->firmada_at !== null;
    }
}
