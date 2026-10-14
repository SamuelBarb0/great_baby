<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Importacion extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'compras_importaciones';

    // Re-audit M2 R3 PATRÓN N (SEG-C1 / DATOS-C3) · $guarded + booted::saving
    //   con inmutabilidad post-Liquidada — antes el $fillable dejaba tasa/moneda/
    //   fecha_liquidacion mutables tras liquidar y Aracely podía cambiar la tasa
    //   post-cierre, o retro-fechar la liquidación a un periodo cerrado.
    protected $guarded = ['id'];

    protected $casts = [
        'fecha_zarpe' => 'date',
        'eta' => 'date',
        'fecha_llegada' => 'date',
        'fecha_nacionalizacion' => 'date',
        'fecha_liquidacion' => 'date',
        'estado' => EstadoImportacion::class,
        'tasa_cambio_liquidacion' => 'decimal:6',
        'valor_fob' => 'decimal:2',
        'valor_gastos' => 'decimal:2',
        'valor_arancel' => 'decimal:2',
        'valor_iva_importacion' => 'decimal:2',
        'valor_total_costo' => 'decimal:2',
    ];

    /**
     * Re-audit M2 R3 PATRÓN N · inmutabilidad post-Liquidada.
     *   Bloquea manipulación de tasa/moneda/fecha_liquidacion tras liquidar.
     */
    protected static function booted(): void
    {
        static::saving(function (Importacion $imp) {
            if (! $imp->exists) return;

            $original = $imp->getOriginal('estado');
            $estadoAnterior = $original instanceof EstadoImportacion
                ? $original
                : ($original ? EstadoImportacion::tryFrom($original) : null);

            // Guard: si ya está Liquidada, congelamos casi todo. Solo `observaciones`
            // (para agregar notas post-facto) puede cambiar.
            if ($estadoAnterior === EstadoImportacion::Liquidada) {
                // Los campos de SIIGO tienen que poder escribirse DESPUÉS de
                // liquidar: el comprobante se manda a SIIGO justamente cuando
                // la importación ya quedó liquidada, y al volver hay que anotar
                // el id del journal. Con el guard cerrado pasaba lo peor: el
                // comprobante SÍ se creaba en SIIGO, el ERP no podía marcarlo,
                // el job se reintentaba y duplicaba el asiento allá. Es el
                // mismo caso que ya se arregló en RecepcionCompra.
                $mutablesPostLiquidacion = [
                    'observaciones', 'updated_at',
                    'siigo_journal_id', 'siigo_sync_at',
                ];
                foreach ($imp->getDirty() as $campo => $_) {
                    if (! in_array($campo, $mutablesPostLiquidacion, true)) {
                        throw new \RuntimeException(sprintf(
                            'Importación %s (liquidada): campo "%s" es inmutable.',
                            $imp->getOriginal('numero'), $campo,
                        ));
                    }
                }
            }
        });
    }

    public function ordenes(): BelongsToMany
    {
        return $this->belongsToMany(OrdenCompra::class, 'compras_importacion_ordenes', 'importacion_id', 'orden_id')
            ->withPivot('peso_kg', 'volumen_m3')
            ->withTimestamps();
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(GastoImportacion::class, 'importacion_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ImportacionLinea::class, 'importacion_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function liquidador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liquidada_por');
    }

    /**
     * Re-audit M2 PATRÓN C + I · consecutivo atómico en TZ Colombia
     *   (mismo patrón que OrdenCompra::siguienteNumero — evita race y salto de año).
     */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($year) {
            $ultimo = static::query()
                ->where('numero', 'like', "IMP-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('numero');
            $seq = $ultimo ? ((int) substr($ultimo, -6)) + 1 : 1;
            return sprintf('IMP-%d-%06d', $year, $seq);
        });
    }

    public function diasEnTransito(): ?int
    {
        if (! $this->fecha_zarpe) {
            return null;
        }
        $fin = $this->fecha_llegada ?? now();

        // Entero: `diffInDays` es flotante en Carbon 3 y el contenedor en
        // tránsito se mostraba con decimales («12.7083 días»).
        return (int) round($this->fecha_zarpe->diffInDays($fin));
    }
}
