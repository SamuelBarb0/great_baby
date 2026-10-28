<?php

namespace App\Modules\Dropi\Models;

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventarioUbicacion extends Model
{
    protected $table = 'inventario_ubicaciones';

    protected $fillable = [
        // Jerarquía: una ubicación cuelga de una bodega. Una bodega es la que
        // no tiene `bodega_id`. Pasillo/estante/nivel dicen dónde está parada
        // físicamente la mercancía dentro de esa bodega.
        'bodega_id', 'pasillo', 'estante', 'nivel',
        'codigo', 'nombre', 'categoria',
        'disponible_para_venta', 'activa', 'notas',
        // UBIC-3 · datos de operación / SIIGO por ubicación.
        'direccion', 'ciudad', 'responsable_user_id',
        'siigo_id', 'siigo_resolution_id', 'siigo_resolution_name', 'siigo_resolution_prefix', 'siigo_last_error',
        'cta_inventario', 'cta_costo',
    ];

    protected $casts = [
        'categoria' => CategoriaUbicacion::class,
        'disponible_para_venta' => 'boolean',
        'activa' => 'boolean',
        'siigo_resolution_id' => 'integer',
        'responsable_user_id' => 'integer',
        'bodega_id' => 'integer',
    ];

    /** La bodega (sede) a la que pertenece. Null = esto ES una bodega. */
    public function bodega(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'bodega_id');
    }

    /** Las ubicaciones que viven dentro de esta bodega. */
    public function ubicaciones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'bodega_id');
    }

    /** ¿Es una bodega (sede) y no una posición dentro de otra? */
    public function esBodega(): bool
    {
        return $this->bodega_id === null;
    }

    /**
     * Dónde está parada la mercancía, en palabras:
     * «Pasillo 4 · Estante 6 · Nivel 3».
     */
    public function posicion(): string
    {
        $partes = array_filter([
            filled($this->pasillo) ? "Pasillo {$this->pasillo}" : null,
            filled($this->estante) ? "Estante {$this->estante}" : null,
            filled($this->nivel) ? "Nivel {$this->nivel}" : null,
        ]);

        return $partes ? implode(' · ', $partes) : '';
    }

    /** «Bodega Principal Bogotá → Pasillo 4 · Estante 6 · Nivel 3» */
    public function rutaCompleta(): string
    {
        $pos = $this->posicion() ?: $this->nombre;

        return $this->bodega_id
            ? trim(($this->bodega?->nombre ?? 'Bodega').' → '.$pos)
            : $this->nombre;
    }

    /**
     * UBIC-3 · Fallbacks a la configuración global cuando la ubicación no tiene
     * cuenta propia. Centralizar aquí evita tener que `?? setting()` en cada
     * call-site del SiigoEmisionService / RegistrarAsientoCompra.
     */
    public function ctaInventarioEfectiva(): string
    {
        return $this->cta_inventario ?: (string) setting('contable.cta_inventario_default');
    }

    public function ctaCostoEfectiva(): string
    {
        return $this->cta_costo ?: (string) setting('contable.cta_costo_default');
    }

    protected static function booted(): void
    {
        // Mantiene consistencia: la disponibilidad para venta la dicta la categoría.
        static::saving(function (InventarioUbicacion $u) {
            if ($u->categoria instanceof CategoriaUbicacion) {
                $u->disponible_para_venta = $u->categoria->disponibleParaVenta();
            }
        });
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class, 'ubicacion_id');
    }
}
