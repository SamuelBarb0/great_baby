<?php

namespace App\Modules\Siigo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SiigoConfig extends Model
{
    protected $table = 'siigo_config';

    protected $fillable = [
        'username', 'access_key', 'partner_id',
        'ambiente', 'activo', 'push_auto',
        'nit_emisor', 'tipo_documento_id', 'seller_id', 'payment_type_id',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'sync_catalogos_at' => 'datetime',
        'sync_productos_at' => 'datetime',
        'sync_clientes_at' => 'datetime',
        'sync_stock_at' => 'datetime',
        'push_auto_updated_at' => 'datetime',
        // Sin el cast a datetime, el `diffForHumans()` de la tarjeta de estado
        // revienta la pantalla entera (ya nos pasó con recepciones).
        // `ultimo_auth_ok` se deja en null mientras nadie haya probado nunca:
        // la vista distingue true / false / «sin probar».
        'ultimo_auth_at' => 'datetime',
        'ultimo_auth_ok' => 'bool',
        'activo' => 'bool',
        'push_auto' => 'bool',
        'tipo_documento_id' => 'integer',
        'seller_id' => 'integer',
        'payment_type_id' => 'integer',
    ];

    // Access key SIEMPRE encriptado en BD, transparente al leer/escribir
    public function setAccessKeyAttribute(?string $value): void
    {
        $this->attributes['access_key'] = ($value === null || $value === '')
            ? null : Crypt::encryptString($value);
    }

    public function getAccessKeyAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function current(): self
    {
        $config = self::query()->first();
        if ($config === null) {
            $config = self::create(['ambiente' => 'sandbox', 'activo' => false]);
        }
        return $config;
    }

    /**
     * ¿La credencial está muerta —vencida, revocada o mal escrita—?
     *
     * Vive acá y no suelta en cada sitio porque la usan dos cosas que tienen
     * que coincidir: el middleware que aplaza los push y el aviso de cola
     * atascada de `/app/siigo`. Si difieren, la pantalla manda a prender el
     * worker mientras el problema real es la llave.
     *
     * Sólo un `false` explícito cuenta: `null` es «todavía no se probó», y ahí
     * lo que corresponde es intentar. Y se exige que el último fallo sea de
     * credencial (401/403/sin configurar): un 500 pasajero de SIIGO también
     * apaga la casilla, y no es motivo para frenar la cola.
     */
    public function credencialMuerta(): bool
    {
        /*
         * Sin credenciales escritas no hay nada que intentar.
         *
         * Esto devolvía `false` en una instalación recién montada: nadie probó
         * la conexión todavía, así que `ultimo_auth_ok` es NULL —no `false`— y
         * la guarda no disparaba. El resultado es justo lo que el middleware
         * existe para evitar: los push salen a pegarle a SIIGO sin usuario y
         * queman las 12 h de `retryUntil` antes de morir en `failed_jobs`.
         *
         * Comprobado el 2026-10-14 con la base reconstruida: `siigo_config`
         * vacía, `SIIGO_DRIVER=real` y 30 `PushProductoASiigo` esperando.
         *
         * Se mira el valor CRUDO para no descifrar la llave sólo para saber si
         * hay algo guardado.
         */
        if (blank($this->username) || blank($this->getRawOriginal('access_key'))) {
            return true;
        }

        if ($this->ultimo_auth_ok !== false) {
            return false;
        }

        $error = (string) $this->ultimo_auth_error;

        return str_contains($error, 'HTTP 401')
            || str_contains($error, 'HTTP 403')
            || str_contains($error, 'no configuradas');
    }

    /**
     * F8 · kill-switch efectivo del push automático.
     * Precedencia: valor persistido en BD (`push_auto` no NULL) > env `FEATURE_SIIGO_PUSH_AUTO`.
     * Cache 60s para no golpear la BD en cada job encolado.
     */
    public static function pushAutoActivo(): bool
    {
        return Cache::remember('siigo:push_auto', 60, function () {
            try {
                $db = self::query()->value('push_auto');
                if ($db !== null) return (bool) $db;
            } catch (\Throwable) {
                // BD no disponible (tests unit sin migraciones) → cae al env.
            }
            return (bool) config('siigo.push_auto', false);
        });
    }

    /** F8 · invalidar el cache tras un toggle desde UI (Vue o Filament). */
    public static function invalidarPushAutoCache(): void
    {
        Cache::forget('siigo:push_auto');
    }
}
