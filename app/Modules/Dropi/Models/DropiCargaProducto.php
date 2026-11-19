<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un lote de cambios a productos de Dropi hecho desde el ERP: un Excel o un
 * producto suelto. Sus operaciones crean productos o mueven stock.
 */
class DropiCargaProducto extends Model implements AuditableContract
{
    use Auditable;

    /** Recién subido: la pantalla está leyendo el catálogo de Dropi de a una página. */
    public const LEYENDO = 'leyendo';
    public const BORRADOR = 'borrador';
    public const PROCESANDO = 'procesando';
    public const TERMINADO = 'terminado';

    protected $table = 'dropi_cargas_productos';

    protected $guarded = ['id'];

    /** El avance de lectura cambia en cada página: no se audita (y las filas pesan). */
    protected $auditExclude = ['filas_excel', 'lectura'];

    protected $casts = [
        'defaults_crear' => 'array',
        'filas_excel' => 'array',
        'lectura' => 'array',
        'confirmado_at' => 'datetime',
        'terminado_at' => 'datetime',
    ];

    public function operaciones(): HasMany
    {
        return $this->hasMany(DropiCargaOperacion::class, 'carga_id')->orderBy('fila')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function editable(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    /** DC-AAAA-NNNNNN, atómico (mismo esquema que las cargas de inventario CI-). */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;

        return DB::transaction(function () use ($year) {
            $prefijoLen = strlen("DC-{$year}-");
            $max = static::query()
                ->where('numero', 'like', "DC-{$year}-%")
                ->lockForUpdate()
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(numero, ?) AS UNSIGNED)), 0) AS seq', [$prefijoLen + 1])
                ->value('seq');

            return sprintf('DC-%d-%06d', $year, ((int) $max) + 1);
        });
    }
}
