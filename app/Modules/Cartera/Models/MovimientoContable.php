<?php

namespace App\Modules\Cartera\Models;

use App\Models\User;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use RuntimeException;

class MovimientoContable extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'movimientos_contables';

    /**
     * Re-audit SEG N2 (landmine) · SoftDeletes obligatorio — DIAN exige
     * inmutabilidad del libro diario 5 años. Cualquier delete accidental
     * (Filament resource, cascade, artisan) queda como `deleted_at`, no
     * borra físicamente.
     * Re-audit SEG C2 · $guarded controlado.
     * Re-audit DATOS M15 · Auditable + $auditExclude reduce ruido en `audits`.
     */
    protected $guarded = ['id'];

    protected array $auditExclude = ['descripcion']; // texto largo, no aporta a auditoría

    protected $casts = [
        'fecha' => 'date',
        'debe' => 'decimal:2',
        'haber' => 'decimal:2',
        'linea_negocio' => LineaNegocio::class,
        'destino_fiscal' => DestinoFiscal::class,
    ];

    /**
     * F1 · Los dos ejes se estampan acá y no en cada sitio que asienta.
     *
     * Hay 18 llamadas a `MovimientoContable::create()` repartidas en cinco
     * módulos (Cartera, Compras, Dropi, Inventario). Poner el estampado en cada
     * una era garantizar que alguna se quedara sin él —y un asiento sin línea
     * de negocio ensucia el balance de los dos negocios a la vez—. Desde acá
     * lo hereda todo, incluido lo que se agregue mañana.
     *
     * Si quien crea el asiento ya los trae puestos, se respetan: esto sólo
     * rellena lo que viene vacío.
     */
    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            // El documento se lee UNA vez: los dos ejes salen de él.
            $origen = ($m->linea_negocio === null || $m->destino_fiscal === null)
                ? $m->origen
                : null;

            if ($m->linea_negocio === null) {
                /*
                 * Primero lo que el documento DIJO, después lo que se deduce.
                 *
                 * Deducirla del namespace del `origen_type` alcanza mientras
                 * cada línea de negocio tenga su propio módulo, pero una
                 * factura hecha a mano nace en Cartera aunque sea de Dropi:
                 * deducida, toda factura manual caía en Great Baby y le
                 * ensuciaba el balance a los dos negocios. Si el documento
                 * trae la columna, esa manda.
                 */
                $m->linea_negocio = ($origen && isset($origen->linea_negocio) && $origen->linea_negocio)
                    ? $origen->linea_negocio
                    : LineaNegocio::deOrigen($m->origen_type);
            }

            if ($m->destino_fiscal === null) {
                // Se hereda del documento de origen cuando éste ya tiene una
                // decisión tomada (una factura marcada «bolsita» no puede
                // producir asientos que se vayan a SIIGO). Si no la tiene,
                // queda el default, que reproduce el comportamiento de antes.
                $heredado = ($origen && isset($origen->destino_fiscal) && $origen->destino_fiscal)
                    ? $origen->destino_fiscal
                    : DestinoFiscal::porDefecto();

                /*
                 * «Reparto» es una decision sobre el DOCUMENTO, no un destino
                 * posible de una linea: una linea va al libro fiscal o no va.
                 * Quien reparte tiene que decir cuanto de cada linea es cual
                 * (lo hacen `repartirPorDestino()` y `pago()`). Si igual llega
                 * hasta aca, cae en interno, que es lo conservador: dejarlo
                 * fiscal seria declarar algo que nadie decidio declarar.
                 */
                $m->destino_fiscal = $heredado === DestinoFiscal::Reparto
                    ? DestinoFiscal::Interno
                    : $heredado;
            }
        });
    }

    /** Asientos de una línea de negocio · para balances separados. */
    public function scopeDeLinea($q, LineaNegocio|string|null $linea)
    {
        return $linea ? $q->where('linea_negocio', $linea instanceof LineaNegocio ? $linea->value : $linea) : $q;
    }

    /** Asientos de un destino · para separar lo fiscal de lo interno. */
    public function scopeDeDestino($q, DestinoFiscal|string|null $destino)
    {
        return $destino ? $q->where('destino_fiscal', $destino instanceof DestinoFiscal ? $destino->value : $destino) : $q;
    }

    /**
     * Lo que de verdad está pendiente de enviar a SIIGO.
     *
     * Antes esto era `whereNull('siigo_journal_id')` a secas, en siete lugares
     * distintos: todo lo que alguien decidiera no mandar quedaba apareciendo
     * como pendiente para siempre. Ahora pendiente = lo que SÍ debe ir y
     * todavía no fue.
     */
    public function scopePendienteDeSiigo($q)
    {
        return $q->where('destino_fiscal', DestinoFiscal::Siigo->value)
            ->whereNull('siigo_journal_id');
    }

    public function origen(): MorphTo
    {
        return $this->morphTo('origen', 'origen_type', 'origen_id');
    }

    public function tercero(): MorphTo
    {
        return $this->morphTo('tercero', 'tercero_type', 'tercero_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Raíz C · Registrador atómico de asientos con validación de partida doble.
     *
     * Re-audit DATOS #9 · valida también integridad por línea:
     *   - debe >= 0 y haber >= 0 (no negativos)
     *   - debe * haber === 0 (mutuamente exclusivos)
     * Cualquier línea que quiebra estas invariantes aborta antes de commit.
     */
    public static function registrarAsientoAtomico(array $lineas): int
    {
        if (empty($lineas)) return 0;

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($lineas as $idx => $l) {
            $debe = (float) ($l['debe'] ?? 0);
            $haber = (float) ($l['haber'] ?? 0);

            if ($debe < 0 || $haber < 0) {
                throw new RuntimeException(
                    "Asiento inválido (línea {$idx}): debe/haber negativos no permitidos (debe={$debe}, haber={$haber})"
                );
            }
            if ($debe > 0 && $haber > 0) {
                throw new RuntimeException(
                    "Asiento inválido (línea {$idx}): debe y haber en la misma línea (debe={$debe}, haber={$haber})"
                );
            }

            $totalDebe += $debe;
            $totalHaber += $haber;
        }

        if (abs($totalDebe - $totalHaber) > 0.01) {
            $origen = $lineas[0]['origen_type'] ?? '?';
            $origenId = $lineas[0]['origen_id'] ?? '?';
            throw new RuntimeException(sprintf(
                'Asiento desbalanceado (%s#%s): ΣDebe=%.2f ΣHaber=%.2f · diff=%.4f',
                class_basename((string) $origen), (string) $origenId,
                $totalDebe, $totalHaber, $totalDebe - $totalHaber,
            ));
        }

        return DB::transaction(function () use ($lineas) {
            foreach ($lineas as $l) {
                static::create($l);
            }
            return count($lineas);
        });
    }
}
