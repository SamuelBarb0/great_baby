<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificacionErp extends Model
{
    protected $table = 'notificaciones_erp';

    protected $guarded = ['id'];

    protected $casts = [
        'leida_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crea una notificación (para un usuario o broadcast si user_id=null).
     *
     * @param array{tipo:string, titulo:string, mensaje?:string, url?:string, icono?:string, color?:string, user_id?:?int} $data
     */
    public static function crear(array $data): self
    {
        $aviso = static::create(array_merge([
            'color' => 'gray',
            'icono' => 'heroicon-o-bell',
        ], $data));

        if (self::vaTambienPorCorreo($aviso->tipo)) {
            \App\Jobs\EnviarAvisoPorCorreo::dispatch($aviso->id);
        }

        return $aviso;
    }

    /**
     * ¿Este aviso además se manda por correo?
     *
     * Dos condiciones, y las dos las decide Gerencia desde Reglas: que el
     * correo esté prendido —hoy el servidor manda al log, así que nace
     * apagado— y que el tipo esté en la lista.
     *
     * La lista es explícita a propósito. Mandar por correo todo lo que pasa
     * por la campana es la forma más rápida de que la gente lo mande a spam y
     * deje de leer también lo importante.
     */
    private static function vaTambienPorCorreo(?string $tipo): bool
    {
        if (! $tipo || ! setting('notificaciones.correo_activo')) {
            return false;
        }

        $crudo = setting('notificaciones.tipos_por_correo');
        $tipos = is_array($crudo) ? $crudo : (json_decode((string) $crudo, true) ?: []);

        return in_array($tipo, $tipos, true);
    }

    public static function noLeidasPara(int $userId): int
    {
        return static::where(function ($q) use ($userId) {
            $q->whereNull('user_id')->orWhere('user_id', $userId);
        })->whereNull('leida_at')->count();
    }
}
