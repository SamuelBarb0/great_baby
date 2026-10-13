<?php

namespace App\Modules\Dropi\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Cuenta de Dropi configurada desde el CRM. Si no existe, DropiWebAuth usa la
 * del .env. Ver DropiWebAuth::cuenta().
 *
 * La auditoría deja quién cambió la cuenta y cuándo, pero nunca la clave.
 */
class DropiWebCuenta extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'dropi_web_cuenta';

    protected $fillable = ['email', 'password', 'configurada_por'];

    protected $hidden = ['password'];

    protected array $auditExclude = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public static function actual(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
