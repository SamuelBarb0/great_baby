<?php

namespace App\Modules\Rrhh\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La entrega de cargo de quien se va.
 *
 * Es lo que evita que el conocimiento se vaya con la persona: qué hacía, qué
 * deja a medias y dónde están sus cosas. Hasta ahora el sistema sabía qué
 * equipos le habían entregado y nada más.
 */
class EntregaCargo extends Model
{
    protected $table = 'rrhh_entregas_cargo';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'jefe_confirmo_at' => 'datetime',
        'gh_confirmo_at' => 'datetime',
        'tesoreria_confirmo_at' => 'datetime',
    ];

    /**
     * Las seis listas del formato, con quién las llena y qué significa el
     * segundo campo en cada una.
     *
     * Están acá y no repartidas entre la pantalla y el impreso para que no
     * puedan pedir cosas distintas.
     *
     * @var array<string, array{titulo:string, area:string, detalle:string}>
     */
    public const BLOQUES = [
        'funcion' => [
            'titulo' => 'Actividades principales (funciones o responsabilidades)',
            'area' => 'jefe',
            'detalle' => 'Frecuencia',
        ],
        'pendiente' => [
            'titulo' => 'Actividades o proyectos pendientes o por terminar',
            'area' => 'jefe',
            'detalle' => 'Estado / a quién queda',
        ],
        'archivo_digital' => [
            'titulo' => 'Archivo digital',
            'area' => 'jefe',
            'detalle' => 'Ubicación',
        ],
        'archivo_fisico' => [
            'titulo' => 'Documentos o archivo físico (AZ)',
            'area' => 'jefe',
            'detalle' => 'Ubicación',
        ],
        'paz_salvo' => [
            'titulo' => 'Paz y salvo',
            'area' => 'tesoreria',
            'detalle' => 'Observaciones',
        ],
        'contacto' => [
            'titulo' => 'Contactos y proveedores (si aplica)',
            'area' => 'jefe',
            'detalle' => 'Datos de contacto',
        ],
    ];

    /** Las tres firmas, con el permiso que las habilita. */
    public const AREAS = [
        'jefe' => ['titulo' => 'Jefe inmediato', 'permiso' => 'rrhh_requisiciones'],
        'gh' => ['titulo' => 'Gestión Humana', 'permiso' => 'rrhh'],
        'tesoreria' => ['titulo' => 'Tesorería', 'permiso' => 'contabilidad'],
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function recibe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibe_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EntregaCargoItem::class, 'entrega_id')->orderBy('orden');
    }

    public function jefeConfirmoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jefe_confirmo_por_id');
    }

    public function ghConfirmoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gh_confirmo_por_id');
    }

    public function tesoreriaConfirmoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tesoreria_confirmo_por_id');
    }

    /** Quién recibe, venga del sistema o escrito a mano. */
    public function quienRecibe(): ?string
    {
        return $this->recibe?->name ?: $this->recibe_nombre;
    }

    /**
     * Qué bloques puede ESCRIBIR este usuario.
     *
     * Firmar lo suyo no servía de nada si cualquiera de las tres áreas podía
     * reescribir el formato entero: el jefe cambiaba el paz y salvo de
     * tesorería, tesorería cambiaba las funciones del jefe, y la conformidad
     * quedaba puesta sobre un texto que quien firmó nunca vio.
     *
     * @return array<string> claves de BLOQUES
     */
    public static function bloquesQuePuedeEscribir(?\App\Models\User $u): array
    {
        if (\App\Auth\Permisos::esRoot($u)) {
            return array_keys(self::BLOQUES);
        }

        $suyas = [];

        foreach (self::AREAS as $clave => $a) {
            if (\App\Auth\Permisos::puede($u, $a['permiso'])) {
                $suyas[] = $clave;
            }
        }

        return array_keys(array_filter(
            self::BLOQUES,
            fn (array $b) => in_array($b['area'], $suyas, true),
        ));
    }

    /** El área dueña de un bloque. */
    public static function areaDelBloque(string $bloque): ?string
    {
        return self::BLOQUES[$bloque]['area'] ?? null;
    }

    /** Los renglones de un bloque. */
    public function de(string $bloque)
    {
        return $this->items->where('bloque', $bloque)->values();
    }

    /**
     * ¿Está firmada por las tres áreas?
     *
     * Las tres y no una: el jefe no sabe si tesorería tiene algo pendiente, y
     * tesorería no sabe si la persona dejó documentado dónde están sus
     * archivos.
     */
    public function completa(): bool
    {
        return $this->jefe_confirmo_at && $this->gh_confirmo_at && $this->tesoreria_confirmo_at;
    }

    /**
     * Qué áreas faltan por firmar, en palabras.
     *
     * @return array<string>
     */
    public function faltanFirmas(): array
    {
        $faltan = [];

        foreach (self::AREAS as $clave => $a) {
            if (! $this->{self::campoConfirmacion($clave)}) {
                $faltan[] = $a['titulo'];
            }
        }

        return $faltan;
    }

    public static function campoConfirmacion(string $area): string
    {
        return $area.'_confirmo_at';
    }

    /**
     * Quién dio más de una de las tres conformidades.
     *
     * El control del formato son tres revisiones INDEPENDIENTES, y los
     * permisos no alcanzan a garantizarlo: Gestión Humana también lleva
     * requisiciones, así que puede firmar el bloque del jefe; y Gerencia
     * puede firmar los tres.
     *
     * No se bloquea —en una empresa de este tamaño una misma persona lleva
     * dos sombreros de verdad, y la dueña los lleva todos—, pero sí se dice
     * en el impreso. Un control que no se cumplió y además no se ve es peor
     * que no tenerlo: el papel firmado afirma algo que nadie revisó.
     *
     * @return array<string> Los nombres, o vacío si cada quien firmó lo suyo.
     */
    public function firmoVariosBloques(): array
    {
        $cuantas = [];

        foreach (array_keys(self::AREAS) as $area) {
            if ($quien = $this->{"{$area}_confirmo_por_id"}) {
                $cuantas[$quien] = ($cuantas[$quien] ?? 0) + 1;
            }
        }

        $repetidos = array_keys(array_filter($cuantas, fn (int $n) => $n > 1));

        return $repetidos === []
            ? []
            : User::whereIn('id', $repetidos)->orderBy('name')->pluck('name')->all();
    }

    /** Para mandar a la pantalla. */
    public static function catalogoBloques(): array
    {
        return array_map(
            fn (string $k) => ['clave' => $k] + self::BLOQUES[$k],
            array_keys(self::BLOQUES),
        );
    }
}
