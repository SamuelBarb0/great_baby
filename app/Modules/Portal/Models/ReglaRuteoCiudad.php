<?php

namespace App\Modules\Portal\Models;

use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOG-J4 · Regla de ruteo pedido → bodega por ciudad del cliente.
 *
 * Reglas se ordenan por prioridad ascendente al evaluar un pedido:
 * la primera activa cuya ciudad matchee (case-insensitive) decide la
 * ubicación origen. Si ninguna matchea, cae a la regla con `ciudad='*'`
 * (catch-all) si existe; si tampoco, el pedido queda sin asignación y
 * logística decide a mano.
 */
class ReglaRuteoCiudad extends Model
{
    protected $table = 'reglas_ruteo_ciudad';

    protected $fillable = [
        'ciudad', 'ubicacion_id', 'prioridad', 'activa', 'notas',
    ];

    protected $casts = [
        'activa' => 'boolean',
        'prioridad' => 'integer',
    ];

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_id');
    }

    /**
     * Cómo se compara el nombre de una ciudad: sin mayúsculas y SIN TILDES.
     *
     * Dos motivos, y los dos se vieron en los datos reales:
     *
     *   · `strtolower` trabaja byte por byte y sólo sabe de la A a la Z, así
     *     que «BOGOTÁ» le quedaba en «bogotÁ» mientras la ciudad del cliente,
     *     «Bogotá», le quedaba en «bogotá». No matcheaban.
     *
     *   · y la tilde la escribe quien carga el cliente, no el sistema: en la
     *     base conviven «Medellin» y «Medellín», «Monteria» y «Montería».
     *     Comparando con tilde hay que crear la regla dos veces, nadie lo
     *     sabe, y la mitad de los pedidos de esa ciudad nacen sin bodega: no
     *     se les aparta mercancía y bodega nunca los ve en su cola.
     *
     * Pasa por los dos sitios que comparan ciudades —este resolvedor y la
     * pantalla de reglas— para que no puedan volver a opinar distinto.
     */
    public static function normalizar(?string $ciudad): string
    {
        return mb_strtolower(trim((string) \Illuminate\Support\Str::ascii((string) $ciudad)));
    }

    /**
     * Resuelve la ubicación origen para una ciudad de cliente.
     * Devuelve el id o null si no hay match (ni catch-all).
     */
    public static function resolver(?string $ciudad): ?int
    {
        $ciudadLow = static::normalizar($ciudad);

        // Catch-all siempre al final, ordenamos por prioridad ascendente.
        $reglas = static::query()->where('activa', true)
            ->orderBy('prioridad')->orderBy('id')
            ->get(['id', 'ciudad', 'ubicacion_id']);

        foreach ($reglas as $r) {
            if ($r->ciudad === '*') continue;
            if (static::normalizar($r->ciudad) === $ciudadLow) {
                return (int) $r->ubicacion_id;
            }
        }
        // Catch-all como fallback.
        $catchAll = $reglas->firstWhere('ciudad', '*');
        return $catchAll ? (int) $catchAll->ubicacion_id : null;
    }
}
