<?php

namespace App\Modules\Contabilidad\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * El arqueo del día: lo que dicen los libros contra lo que hay en el cajón.
 *
 * `saldo_esperado` = apertura + ingresos − gastos. `saldo_contado` sale de
 * contar billetes y monedas uno por uno. La diferencia es el dato que
 * importa, y los `conceptos` son lo que la explica.
 *
 * En el Excel que usan hoy, el arqueo del 1 de octubre dio $229.400 de
 * descuadre y los conceptos anotados sumaban $229.800. Cuatrocientos pesos
 * sueltos que sobrevivieron porque una hoja de cálculo no obliga a que las
 * dos cifras coincidan. Acá `descuadreSinExplicar()` lo dice en la cara.
 */
class Arqueo extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'arqueos';

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'date',
        'saldo_apertura' => 'decimal:2',
        'total_ingresos' => 'decimal:2',
        'total_gastos' => 'decimal:2',
        'saldo_esperado' => 'decimal:2',
        'saldo_contado' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'cerrado_at' => 'datetime',
    ];

    /** Lo que circula en Colombia. El orden es el del formato en papel. */
    public const BILLETES = [100000, 50000, 20000, 10000, 5000, 2000];
    public const MONEDAS = [1000, 500, 200, 100, 50];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class);
    }

    public function denominaciones(): HasMany
    {
        return $this->hasMany(ArqueoDenominacion::class);
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(ArqueoConcepto::class);
    }

    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /** exacto | sobrante | faltante, deducido de la diferencia. */
    public static function resultadoDe(float $diferencia): string
    {
        if (abs($diferencia) < 0.01) return 'exacto';

        return $diferencia > 0 ? 'sobrante' : 'faltante';
    }

    /**
     * Consecutivo del formato: OCT-001-2026.
     *
     * Es el que ya usan en el papel, y lo conservamos porque los soportes
     * físicos que archivan llevan ese número escrito.
     */
    public static function siguienteNumero(\DateTimeInterface $fecha): string
    {
        static $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN',
            'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

        $mes = $meses[((int) $fecha->format('n')) - 1];
        $anio = $fecha->format('Y');
        $prefijo = "{$mes}-";

        $ultimo = static::where('numero', 'like', "{$prefijo}%-{$anio}")
            ->lockForUpdate()->orderByDesc('id')->value('numero');

        $sig = $ultimo ? ((int) explode('-', $ultimo)[1]) + 1 : 1;

        return $prefijo.str_pad((string) $sig, 3, '0', STR_PAD_LEFT)."-{$anio}";
    }

    /** Lo contado, reconstruido desde las denominaciones. */
    public function totalContado(): float
    {
        return (float) $this->denominaciones->sum(fn ($d) => $d->denominacion * $d->cantidad);
    }

    /**
     * Cuánto del descuadre quedó sin explicar.
     *
     * Cero significa que los conceptos cuadran con la diferencia. Cualquier
     * otra cosa es el agujero que en papel pasaba inadvertido.
     */
    public function descuadreSinExplicar(): float
    {
        return round((float) $this->diferencia - (float) $this->conceptos->sum('valor'), 2);
    }

    public function estaCerrado(): bool
    {
        return in_array($this->estado, ['cerrado', 'aprobado'], true);
    }
}
