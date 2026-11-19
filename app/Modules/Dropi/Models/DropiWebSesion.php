<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sesión única del driver `web` de Dropi. Ver DropiWebAuth.
 */
class DropiWebSesion extends Model
{
    public const DESCONECTADO = 'desconectado';
    public const ESPERANDO_CODIGO = 'esperando_codigo';
    /** La cuenta tiene 2FA: el código sale de la app de autenticación, no del correo. */
    public const ESPERANDO_APP = 'esperando_app';
    public const CONECTADO = 'conectado';
    public const EXPIRADO = 'expirado';

    protected $table = 'dropi_web_sesion';

    protected $fillable = [
        'email', 'token', 'expira_at', 'estado', 'ultimo_error',
        'conectado_por', 'conectado_at', 'ultimo_uso_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expira_at' => 'datetime',
            'conectado_at' => 'datetime',
            'ultimo_uso_at' => 'datetime',
        ];
    }

    public static function actual(string $email): self
    {
        return static::firstOrCreate(['email' => $email], ['estado' => self::DESCONECTADO]);
    }

    public function tokenVigente(): ?string
    {
        if ($this->estado !== self::CONECTADO || ! $this->token) {
            return null;
        }
        // Margen de 2 min para no mandar un token que muere en vuelo.
        if ($this->expira_at && $this->expira_at->lte(now()->addMinutes(2))) {
            return null;
        }

        return $this->token;
    }
}
