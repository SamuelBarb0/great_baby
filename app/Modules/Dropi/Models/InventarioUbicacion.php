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
        // Los dos ejes con los que clasifica la bodega: de quién es la
        // mercancía (oficina) y en qué estado está (almacén). Sin ellos acá,
        // `create()` los descartaba en silencio y el resolvedor del par creaba
        // una bodega nueva en cada recepción.
        'oficina_id', 'almacen_id',
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
        'oficina_id' => 'integer',
        'almacen_id' => 'integer',
    ];

    /** De quién es la mercancía que vive acá. Null en las ubicaciones sin mapear. */
    public function oficina(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventario\Models\Oficina::class, 'oficina_id');
    }

    /** En qué estado y condición comercial está lo que vive acá. */
    public function almacen(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventario\Models\Almacen::class, 'almacen_id');
    }

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
     * Una sede Y sus estantes: donde hay que buscar el saldo de esa bodega.
     *
     * ───────────────────────────────────────────────────────────────────────
     * POR QUÉ ESTO VIVE ACÁ Y NO ESCRITO EN CADA SITIO
     *
     * Una bodega y sus racks son el mismo lugar físico. Desde que la
     * mercancía puede entrar directo a `R12-S1-N1`, preguntarle el saldo
     * SÓLO a la fila de la sede devuelve cero sobre mercancía que está ahí, a
     * tres metros.
     *
     * Ese mismo descuido apareció cuatro veces en sitios distintos —el
     * descuento por despacho, el cálculo del costo, la deducción de la bodega
     * de salida y el buscador de la devolución a proveedor— y cada vez con su
     * propia consulta. Acá hay una sola, para que la quinta no exista.
     *
     * Las posiciones van PRIMERO, igual que en la hoja de picking: se toma
     * del estante y lo que quedó suelto en la sede es el último recurso.
     * ───────────────────────────────────────────────────────────────────────
     *
     * ───────────────────────────────────────────────────────────────────────
     * POR DEFECTO SÓLO LO VENDIBLE, Y ESO NO ES UN DETALLE
     *
     * Dentro de la bodega principal hay cuatro posiciones que NO son
     * estantería: el local, el parqueadero, Arsutex y la zona de
     * alistamiento. Las tres primeras son donde se descarga un contenedor
     * antes de subirlo a los racks; la cuarta es donde se juntan los pedidos
     * a medio armar.
     *
     * La mercancía está ahí de verdad —se ve en el kardex— pero no se puede
     * ofrecer: está sin contar, o ya está apartada para un pedido. Por eso
     * nacen con categoría `cuarentena` y `disponible_para_venta` en falso.
     *
     * La primera versión de este helper las devolvía todas, y con eso el
     * despacho podía sacar cajas del montón que acababa de bajar del camión.
     * Ahora hay que PEDIR explícitamente las no vendibles: el caso peligroso
     * es el que cuesta escribir, no el que sale solo.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @param  bool  $sóloVendibles  false trae también cuarentena, avería y garantía
     * @return list<int>
     */
    public static function laSedeYSusPosiciones(int $sedeId, bool $sóloVendibles = true): array
    {
        $hijas = static::query()
            ->where('bodega_id', $sedeId)
            ->where('activa', true)
            ->when($sóloVendibles, fn ($q) => $q->where('disponible_para_venta', true))
            ->pluck('id')
            ->map(fn ($x) => (int) $x)
            ->all();

        return [...$hijas, $sedeId];
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
