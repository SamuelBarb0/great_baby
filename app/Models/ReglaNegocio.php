<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Registro clave/valor de reglas configurables por Aracely.
 * Uso: setting('empaque.min_foto_bytes')
 *
 * SIN segundo argumento cuando la clave está en `Reglas::DEFAULTS`: el default
 * que se escribe en la llamada GANA sobre el registrado, así que pasarlo anula
 * la regla mientras no exista la fila en esta tabla. El ejemplo de acá decía
 * `, 100000` contra un valor registrado de 30000.
 *
 * Cache: usa un `updated_at` global (versioning key). Cualquier proceso o server
 * que lea las reglas revalida cuando ese timestamp cambia — evita ver reglas viejas
 * hasta que expire el TTL (bug S-08 con multi-server / workers separados).
 */
class ReglaNegocio extends Model
{
    protected $table = 'reglas_negocio';
    protected $guarded = ['id'];

    public const CACHE_KEY_DATA = 'gb.reglas_negocio.data';
    public const CACHE_KEY_VERSION = 'gb.reglas_negocio.version';

    protected static function booted(): void
    {
        static::saved(fn () => self::bumpVersion());
        static::deleted(fn () => self::bumpVersion());
    }

    private static function bumpVersion(): void
    {
        Cache::forever(self::CACHE_KEY_VERSION, microtime(true));
        Cache::forget(self::CACHE_KEY_DATA);
    }

    /** @return array<string,mixed> [clave => valor casteado] */
    public static function todas(): array
    {
        $version = Cache::get(self::CACHE_KEY_VERSION, 0);
        $cached = Cache::get(self::CACHE_KEY_DATA);
        if (is_array($cached) && ($cached['_v'] ?? null) === $version) {
            return $cached['data'];
        }

        $data = static::all()->mapWithKeys(fn ($r) => [$r->clave => $r->valorCasteado()])->all();
        Cache::put(self::CACHE_KEY_DATA, ['_v' => $version, 'data' => $data], 3600);
        return $data;
    }

    public function valorCasteado(): mixed
    {
        return match ($this->tipo) {
            'int' => (int) $this->valor,
            'float' => (float) $this->valor,
            'bool' => filter_var($this->valor, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->valor, true),
            default => (string) ($this->valor ?? ''),
        };
    }

    public function setValorAttribute($v): void
    {
        $this->attributes['valor'] = $this->tipo === 'json' && ! is_string($v)
            ? json_encode($v, JSON_UNESCAPED_UNICODE)
            : (string) $v;
    }
}
