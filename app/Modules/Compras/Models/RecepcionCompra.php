<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class RecepcionCompra extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'compras_recepciones';

    // Re-audit M2 R3 PATRÓN Q (DATOS-A3) · $guarded + guard post-confirmada.
    //   Antes $rc->update(['estado'=>'borrador']) revertía sin reversar el
    //   InventarioMovimiento ni el MovimientoContable → stock/asiento huérfanos.
    protected $guarded = ['id'];

    protected $casts = [
        // Los dos ejes. Sin el cast vuelven como string y cualquier
        // `->label()` revienta con «Call to a member function label() on string».
        'linea_negocio' => \App\Modules\Contabilidad\Enums\LineaNegocio::class,
        'destino_fiscal' => \App\Modules\Contabilidad\Enums\DestinoFiscal::class,
        'fecha_recepcion' => 'date',
        'confirmada_at' => 'datetime',
        'total_recibido' => 'decimal:2',
        // Sin este cast llegaba como texto y el badge SIIGO de /app/compras
        // reventaba con "Call to a member function diffInHours() on string".
        // No se veía porque ninguna recepción había llegado a SIIGO todavía.
        'siigo_sync_at' => 'datetime',
    ];

    /**
     * Re-audit M2 R3 PATRÓN Q · una vez confirmada, sólo `observaciones` puede
     *   cambiar. Sin esto un update revertía estado sin reversar kardex/contab.
     */
    protected static function booted(): void
    {
        /*
         * La recepcion hereda los dos ejes de su orden. Se decide al comprar
         * —que es cuando se sabe si el proveedor va a facturar— y la recepcion
         * lo arrastra sin que nadie tenga que acordarse de copiarlo.
         *
         * Importa que quede EN LA FILA y no se deduzca al consultar, porque
         * `RecepcionCompra` es el `origen` de los asientos de compra: el
         * estampado de `MovimientoContable` lo lee de aca.
         *
         * Si alguien ya los puso a mano, se respetan.
         */
        static::creating(function (RecepcionCompra $rc) {
            if ($rc->linea_negocio !== null && $rc->destino_fiscal !== null) {
                return;
            }

            $orden = $rc->orden_id ? OrdenCompra::find($rc->orden_id) : null;
            if (! $orden) {
                return;
            }

            $rc->linea_negocio ??= $orden->linea_negocio;
            $rc->destino_fiscal ??= $orden->destino_fiscal;
            $rc->motivo_destino ??= $orden->motivo_destino;
        });

        static::saving(function (RecepcionCompra $rc) {
            if (! $rc->exists) return;
            if ($rc->getOriginal('estado') !== 'confirmada') return;

            // Los campos de integración se escriben DESPUÉS de confirmar, que es
            // cuando la recepción viaja a SIIGO. Dejarlos fuera de la lista hacía
            // que ninguna recepción confirmada pudiera guardar su `siigo_id`: la
            // factura de compra se creaba en SIIGO, el ERP no podía marcarla y el
            // job la reintentaba para siempre, con riesgo de duplicarla allá.
            // No tocan importes ni estado, así que no abren la puerta que este
            // guard vino a cerrar.
            // La decision fiscal se puede corregir despues de confirmar: la
            // factura del proveedor a veces aparece tarde. No toca importes ni
            // estado, y quien la cambia rehace el asiento, asi que no abre la
            // puerta que este guard vino a cerrar.
            $mutables = [
                'observaciones', 'updated_at',
                'siigo_id', 'siigo_number', 'siigo_sync_at', 'siigo_response', 'siigo_error',
                'destino_fiscal', 'motivo_destino', 'linea_negocio',
            ];
            foreach ($rc->getDirty() as $campo => $_) {
                if (! in_array($campo, $mutables, true)) {
                    throw new \RuntimeException(sprintf(
                        'Recepción %s (confirmada): campo "%s" es inmutable. Crea una nueva recepción o anula.',
                        $rc->getOriginal('numero'), $campo,
                    ));
                }
            }
        });
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(InventarioUbicacion::class, 'bodega_id');
    }

    public function receptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecepcionCompraItem::class, 'recepcion_id');
    }

    /**
     * Re-audit M2 PATRÓN C + I · consecutivo atómico en TZ Colombia.
     */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($year) {
            $ultimo = static::query()
                ->where('numero', 'like', "REC-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('numero');
            $seq = $ultimo ? ((int) substr($ultimo, -6)) + 1 : 1;
            return sprintf('REC-%d-%06d', $year, $seq);
        });
    }
}
