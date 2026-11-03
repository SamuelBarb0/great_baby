<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Qué facturas se pagan, cuándo, y quién lo autorizó.
 *
 * Reemplaza la hoja impresa que marcan con resaltador y firman a lápiz al
 * margen. Lo que el papel no puede dar y esto sí: quién autorizó, cuándo, y
 * a qué facturas corresponde exactamente esa autorización.
 */
class ProgramacionPago extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'programaciones_pago';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha_programada' => 'date',
        'monto_autorizado' => 'decimal:2',
        'autorizada_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProgramacionPagoItem::class, 'programacion_id');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizada_por');
    }

    /** Consecutivo PP-2026-001. */
    public static function siguienteNumero(): string
    {
        $anio = now('America/Bogota')->format('Y');
        $prefijo = "PP-{$anio}-";

        $ultimo = static::withTrashed()->where('numero', 'like', "{$prefijo}%")
            ->lockForUpdate()->orderByDesc('id')->value('numero');

        $sig = $ultimo ? ((int) substr($ultimo, -3)) + 1 : 1;

        return $prefijo.str_pad((string) $sig, 3, '0', STR_PAD_LEFT);
    }

    public function total(): float
    {
        return round((float) $this->items->sum('monto'), 2);
    }

    /**
     * Diferencia entre lo autorizado y lo repartido.
     *
     * No es un error: en el papel autorizan una cifra redonda y después la
     * reparten. Mostrarla es lo que permite ver que sobran o faltan millones
     * por asignar antes de que alguien pague de más.
     */
    public function sinRepartir(): float
    {
        if ($this->monto_autorizado === null) return 0.0;

        return round((float) $this->monto_autorizado - $this->total(), 2);
    }

    public function estaAutorizada(): bool
    {
        return in_array($this->estado, ['autorizada', 'ejecutada'], true);
    }
}
