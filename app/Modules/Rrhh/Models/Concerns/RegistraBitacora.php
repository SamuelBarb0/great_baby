<?php

namespace App\Modules\Rrhh\Models\Concerns;

use App\Modules\Rrhh\Models\Bitacora;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Le da bitácora a cualquier modelo del módulo.
 *
 * La gracia es que anotar NO sea un paso que alguien pueda olvidar: el cambio
 * de estado y su registro salen de la misma llamada. Donde el código escriba
 * `$requisicion->cambiarEstado(...)`, la línea de tiempo se escribe sola.
 */
trait RegistraBitacora
{
    public function bitacora(): MorphMany
    {
        return $this->morphMany(Bitacora::class, 'registrable')->latest('id');
    }

    /** Anota algo que pasó, sin cambiar el estado. */
    public function anotar(string $evento, ?string $descripcion = null, array $datos = []): Bitacora
    {
        return $this->bitacora()->create([
            'evento' => $evento,
            'descripcion' => $descripcion,
            'datos' => $datos ?: null,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Cambia el estado y lo deja escrito, en una sola operación.
     *
     * Devuelve false cuando el estado no cambió: así quien llama puede evitar
     * notificar dos veces por un clic repetido, y la bitácora no se llena de
     * líneas que no dicen nada.
     */
    public function cambiarEstado(string $nuevo, ?string $descripcion = null, array $datos = [], string $columna = 'estado'): bool
    {
        // El atributo puede venir casteado a enum (`estado`, `etapa`), y un
        // enum no se convierte a string solo: hay que sacarle el `value`.
        $anterior = $this->{$columna} instanceof \BackedEnum
            ? (string) $this->{$columna}->value
            : (string) $this->{$columna};

        if ($anterior === $nuevo) {
            return false;
        }

        $this->{$columna} = $nuevo;
        $this->save();

        $this->bitacora()->create([
            'evento' => 'cambio_estado',
            'descripcion' => $descripcion,
            'de_estado' => $anterior ?: null,
            'a_estado' => $nuevo,
            'datos' => $datos ?: null,
            'user_id' => auth()->id(),
        ]);

        return true;
    }
}
