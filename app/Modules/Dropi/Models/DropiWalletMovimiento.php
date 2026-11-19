<?php

namespace App\Modules\Dropi\Models;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Enums\TipoMovimientoWallet;
use App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class DropiWalletMovimiento extends Model implements AuditableContract
{
    // Re-audit DR-η (DATOS-C2) · SoftDeletes por retención DIAN.
    use Auditable, SoftDeletes;

    protected static function booted(): void
    {
        // P4 · Cada wallet movement contabiliza automáticamente.
        // Re-audit DR-θ (FUNC-M2) · listener ya no hard-delete asientos —
        //   ahora genera ajustes signo inverso. Aquí igual re-lanzamos si
        //   cambian los campos que impactan contabilidad.
        static::created(function (self $m) {
            (new RegistrarAsientoWalletDropi())->handle($m);
        });
        static::updated(function (self $m) {
            // Ampliado (DATOS-M9) para incluir `dropi_movimiento_id` y `categoria`
            //   — antes no cubiertos y podían desfasar el asiento.
            if ($m->wasChanged(['monto', 'tipo', 'fecha', 'pedido_id', 'dropi_movimiento_id', 'categoria'])) {
                (new RegistrarAsientoWalletDropi())->handle($m);
            }
        });
    }

    protected $table = 'dropi_wallet_movimientos';

    // Re-audit DR-β (DATOS-C1) · $guarded. Antes: mass-assign de `monto`, `tipo`
    //   permitía inflar saldo desde payload de form. Ahora sólo por Actions
    //   (ConciliarWalletDropi, controller walletCrear con validación).
    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = [
        'fecha' => 'date',
        'tipo' => TipoMovimientoWallet::class,
        'monto' => 'decimal:2',
        'fuente' => 'array',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_id');
    }

    /** Los asientos que generó este movimiento (RegistrarAsientoWalletDropi), reversas incluidas. */
    public function asientos(): MorphMany
    {
        return $this->morphMany(MovimientoContable::class, 'origen');
    }

    /*
     * Estado contable de un movimiento: cómo llegó (o no) al libro.
     *
     *  · asentado          el movimiento tiene su propio asiento vivo.
     *  · por_recibo        pago de un pedido facturado: lo asienta el recibo de
     *                      caja (PagoVenta `wallet_dropi`) y no el movimiento,
     *                      para no acreditar la CxC dos veces. Un reverso
     *                      (monto <= 0) borra ese recibo: también es «por recibo».
     *  · recibo_pendiente  pago de un pedido facturado SIN su recibo: la plata
     *                      entró a la wallet pero ni la cuenta 111015 ni la
     *                      cartera lo saben.
     *  · sin_asiento       tipo «Otro», o un movimiento cuyo asiento se reversó.
     */
    public const CONTABLE_ASENTADO = 'asentado';

    public const CONTABLE_POR_RECIBO = 'por_recibo';

    public const CONTABLE_RECIBO_PENDIENTE = 'recibo_pendiente';

    public const CONTABLE_SIN_ASIENTO = 'sin_asiento';

    /** @return array<string, array{0:string,1:string,2:string}> estado => [etiqueta, color, explicación] */
    public static function estadosContables(): array
    {
        return [
            self::CONTABLE_ASENTADO => ['Asentado', 'success', 'Tiene su asiento en el libro contable.'],
            self::CONTABLE_POR_RECIBO => ['Por recibo de caja', 'info', 'Pedido facturado: lo asienta el recibo de caja de su factura.'],
            self::CONTABLE_RECIBO_PENDIENTE => ['Recibo pendiente', 'danger', 'Pedido facturado sin recibo de caja: el pago no ha llegado a la contabilidad.'],
            self::CONTABLE_SIN_ASIENTO => ['Sin asiento', 'warning', 'No genera asiento (tipo «Otro») o su asiento se reversó.'],
        ];
    }

    /**
     * El estado contable en SQL, para poder mostrarlo y filtrar por él sin una
     * consulta por fila. Repite las reglas de RegistrarAsientoWalletDropi.
     *
     * @return array{0:string,1:array<int,string>} [sql, bindings]
     */
    public static function sqlEstadoContable(): array
    {
        $t = (new self)->getTable();
        $asientos = fn (string $op) => "(SELECT COUNT(*) FROM movimientos_contables mc
            WHERE mc.deleted_at IS NULL AND mc.origen_type = ? AND mc.origen_id = {$t}.id
            AND COALESCE(mc.descripcion, '') {$op} '[REVERSA%')";

        $sql = "CASE
            WHEN {$t}.tipo = 'pago_guia' AND EXISTS (SELECT 1 FROM dropi_pedidos p
                WHERE p.id = {$t}.pedido_id AND p.deleted_at IS NULL AND p.factura_venta_id IS NOT NULL)
            THEN CASE
                WHEN {$t}.monto <= 0 OR EXISTS (SELECT 1 FROM pagos_venta pv WHERE pv.deleted_at IS NULL
                    AND pv.medio_pago = 'wallet_dropi' AND pv.referencia = SUBSTR({$t}.dropi_movimiento_id, 1, 60))
                THEN '".self::CONTABLE_POR_RECIBO."'
                ELSE '".self::CONTABLE_RECIBO_PENDIENTE."' END
            WHEN {$asientos('NOT LIKE')} > {$asientos('LIKE')} THEN '".self::CONTABLE_ASENTADO."'
            ELSE '".self::CONTABLE_SIN_ASIENTO."' END";

        return [$sql, [self::class, self::class]];
    }

    /** Agrega la columna `estado_contable`. */
    public function scopeConEstadoContable(Builder $q): Builder
    {
        [$sql, $bindings] = self::sqlEstadoContable();

        if ($q->getQuery()->columns === null) {
            $q->select($q->getModel()->getTable().'.*');
        }

        return $q->selectRaw("({$sql}) AS estado_contable", $bindings);
    }

    /** @param  string|array<int,string>  $estados */
    public function scopeEstadoContable(Builder $q, string|array $estados): Builder
    {
        [$sql, $bindings] = self::sqlEstadoContable();
        $estados = (array) $estados;
        $marcas = implode(',', array_fill(0, count($estados), '?'));

        return $q->whereRaw("({$sql}) IN ({$marcas})", [...$bindings, ...$estados]);
    }

    /** El estado de este movimiento; usa la columna si la consulta ya la trajo. */
    public function estadoContable(): string
    {
        return $this->attributes['estado_contable']
            ?? (string) static::query()->whereKey($this->getKey())->conEstadoContable()->value('estado_contable');
    }
}
