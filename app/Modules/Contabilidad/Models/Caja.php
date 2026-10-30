<?php

namespace App\Modules\Contabilidad\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Una caja o un banco: dónde está la plata y quién responde por ella.
 *
 * El `tipo` no es una etiqueta, decide el comportamiento:
 *
 *   · `menor`   — la caja del día. Se arquea a diario contando billete por
 *                 billete, y al cerrar su saldo se traslada a otra caja.
 *   · `general` — recibe esos cierres y se queda quieta hasta que alguien
 *                 retira o gasta.
 *   · `banco`   — se concilia contra el extracto, no se cuenta a mano. No
 *                 tiene arqueo físico.
 *
 * `cuenta_puc` es contra qué cuenta contabiliza lo que entra y sale. El
 * default de la tabla es '1105', que es agrupadora y no admite movimiento:
 * la pantalla sólo ofrece auxiliares, así que una caja creada desde el ERP
 * nace bien aunque el default de la columna esté mal.
 */
class Caja extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'cajas';

    protected $guarded = ['id'];

    protected $casts = [
        'saldo_inicial' => 'decimal:2',
        'activa' => 'boolean',
    ];

    /** Los tres tipos, con el nombre que se ve en pantalla. */
    public const TIPOS = [
        'menor' => 'Caja menor · se arquea y se cierra a diario',
        'general' => 'Caja general · recibe los cierres',
        'banco' => 'Banco · se concilia, no se cuenta a mano',
    ];

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** A qué caja se traslada el saldo cuando ésta cierra. */
    public function destinoCierre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'caja_destino_cierre_id');
    }

    public function arqueos(): HasMany
    {
        return $this->hasMany(Arqueo::class);
    }

    public function scopeActivas(Builder $q): Builder
    {
        return $q->where('activa', true);
    }

    /**
     * Sólo la caja menor se arquea.
     *
     * Un banco no se cuenta a mano y una caja general se mueve de a poco: si
     * se pidiera arqueo diario de todas, el que hay que hacer de verdad se
     * perdería entre los que nadie hace.
     */
    public function seArquea(): bool
    {
        return $this->tipo === 'menor';
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
