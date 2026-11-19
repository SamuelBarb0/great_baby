<?php

namespace App\Modules\Inventario\Models;

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\MotivoCargaInventario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Una carga masiva de inventario: el documento que dice por qué entró la
 * mercancía. El kardex y el asiento contable apuntan a esta fila.
 */
class CargaInventario extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'cargas_inventario';

    protected $guarded = ['id'];

    protected $casts = [
        'motivo' => MotivoCargaInventario::class,
        'total_unidades' => 'decimal:4',
        'total_valor' => 'decimal:2',
        'referencias' => 'integer',
        'referencias_sin_costo' => 'integer',
    ];

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'tercero_id');
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'ubicacion_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientosKardex(): MorphMany
    {
        return $this->morphMany(InventarioMovimiento::class, 'referencia', 'referencia_tipo', 'referencia_id');
    }

    public function asiento(): MorphMany
    {
        return $this->morphMany(MovimientoContable::class, 'origen', 'origen_type', 'origen_id');
    }

    /** CI-2026-000001 · mismo esquema atómico que los traslados. */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;

        return DB::transaction(function () use ($year) {
            $prefijoLen = strlen("CI-{$year}-");
            $max = static::query()
                ->where('numero', 'like', "CI-{$year}-%")
                ->lockForUpdate()
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(numero, ?) AS UNSIGNED)), 0) AS seq', [$prefijoLen + 1])
                ->value('seq');

            return sprintf('CI-%d-%06d', $year, ((int) $max) + 1);
        });
    }
}
