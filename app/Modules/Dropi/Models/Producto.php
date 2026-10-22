<?php

namespace App\Modules\Dropi\Models;

use App\Modules\Catalogo\Models\Categoria;
use App\Modules\Catalogo\Models\Coleccion;
use App\Modules\Catalogo\Models\Impuesto;
use App\Modules\Catalogo\Models\Marca;
use App\Modules\Catalogo\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Producto extends Model implements AuditableContract, HasMedia
{
    use Auditable, InteractsWithMedia, SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'referencia', 'nombre', 'categoria', 'descripcion',
        'precio_proveedor', 'activo', 'requiere_talla', 'es_set',
        'marca_id', 'categoria_id', 'coleccion_id', 'unidad_medida_id', 'impuesto_id',
        'neto', 'peso_gr', 'alto_cm', 'ancho_cm', 'largo_cm',
        // REU-1 · Parametrización contable
        'cta_ingreso', 'cta_iva_venta', 'cta_costo', 'cta_inventario',
        'cta_devolucion', 'cta_descuento', 'centro_costo', 'notas_contables',
        // M8 · Marketing rich
        'copy_comercial', 'specs_json', 'keywords_seo', 'beneficios',
        // C-F1 · Desglose dual de stock
        'desglose_stock', 'stock_directo',
        // Sprint 4 · G.1 · Campos SIIGO Kardex Referencias
        'linea_id', 'grupo_id', 'subgrupo_id', 'clase_id',
        'posicion_arancelaria', 'unidad_compra_id', 'factor_conversion',
        'rentabilidad_pct', 'reposicion_max_dias', 'descuento_default_pct',
        'descripcion_ampliada', 'ficha_tecnica',
        'proteger_precio', 'maneja_lotes', 'maneja_seriales', 'es_estadistico',
        'valor_gasto_venta_niif', 'valor_neto_realizable_niif',
        // Sprint SIIGO Paridad · campos nuevos que viajan al payload SIIGO
        'tipo_siigo', 'stock_control', 'tax_classification', 'tax_included',
        'tax_consumption_value', 'modelo_siigo', 'barcode_padre', 'unit_label',
        // FASE H · Paridad 1:1 con el form oficial de SIIGO
        'visible_en_facturas', 'retencion_siigo_id', 'impuesto_cargo_dos_id',
        'reference_fabrica', 'stock_minimo',
        // FASE F2.A4 · override por producto del grupo SIIGO (si vacío → categoría)
        'siigo_account_group_override',
        // MKT-1/4 · Publicación (el «listo para publicar» y la visibilidad para
        //   Ventas) y las piezas de contenido que lleva el producto.
        'publico_objetivo', 'listo_publicar', 'publicado', 'publicado_cambiado_en', 'publicado_cambiado_por',
        'piezas_video', 'piezas_reel', 'piezas_imagenes',
    ];

    protected $casts = [
        'precio_proveedor' => 'decimal:2',
        'activo' => 'boolean',
        'requiere_talla' => 'boolean',
        'es_set' => 'boolean',
        'neto' => 'boolean',
        'peso_gr' => 'decimal:2',
        'alto_cm' => 'decimal:2',
        'ancho_cm' => 'decimal:2',
        'largo_cm' => 'decimal:2',
        // C-F1 · Desglose dual
        'desglose_stock' => 'boolean',
        'stock_directo' => 'decimal:4',
        // Sprint 4 · G.1 SIIGO
        'factor_conversion' => 'decimal:4',
        'rentabilidad_pct' => 'decimal:2',
        'descuento_default_pct' => 'decimal:2',
        'valor_gasto_venta_niif' => 'decimal:2',
        'valor_neto_realizable_niif' => 'decimal:2',
        'proteger_precio' => 'boolean',
        'maneja_lotes' => 'boolean',
        'maneja_seriales' => 'boolean',
        'es_estadistico' => 'boolean',
        // Sprint SIIGO Paridad
        'stock_control' => 'boolean',
        'tax_included' => 'boolean',
        'tax_consumption_value' => 'decimal:2',
        // FASE H · Paridad SIIGO form
        'visible_en_facturas' => 'boolean',
        'stock_minimo' => 'decimal:4',
        'listo_publicar' => 'boolean',
        'publicado' => 'boolean',
        'publicado_cambiado_en' => 'datetime',
        'piezas_video' => 'boolean',
        'piezas_reel' => 'boolean',
        'piezas_imagenes' => 'integer',
    ];

    /**
     * Lo que ve Ventas (vendedores y portal de clientes): activo y publicado.
     * Marketing lo apaga mientras prepara el contenido; el catálogo interno lo
     * sigue mostrando, marcado como oculto.
     */
    public function scopeVisibleParaVentas($q)
    {
        return $q->where('activo', true)->where('publicado', true);
    }

    /** MKT-2 · Creativos de marketing del producto (fotos, videos, reels). */
    public function creativos(): HasMany
    {
        return $this->hasMany(\App\Modules\Marketing\Models\MarketingCreativo::class);
    }

    public function variantes(): HasMany { return $this->hasMany(ProductoVariante::class); }

    /** FASE H · Imágenes del producto (hasta 5 slots · PNG/JPG · 1 MB c/u). */
    public function imagenes(): HasMany
    {
        return $this->hasMany(ProductoImagen::class)->orderBy('orden');
    }

    /** FASE H · Impuesto cargo dos (secundario) · relación directa. */
    public function impuestoCargoDos()
    {
        return $this->belongsTo(\App\Modules\Catalogo\Models\Impuesto::class, 'impuesto_cargo_dos_id');
    }

    /** FASE H · Retención aplicable (filtra impuestos tipo retención). */
    public function retencion()
    {
        return $this->belongsTo(\App\Modules\Catalogo\Models\Impuesto::class, 'retencion_siigo_id');
    }

    /** Impuestos múltiples (SIIGO acepta N por producto). */
    public function impuestos(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            \App\Modules\Catalogo\Models\Impuesto::class,
            'producto_impuestos', 'producto_id', 'impuesto_id'
        )->withTimestamps();
    }

    // Sprint 4 · G.1 · Maestras SIIGO Kardex Referencias.
    public function linea() { return $this->belongsTo(\App\Modules\Catalogo\Models\CatalogoLinea::class, 'linea_id'); }
    public function grupo() { return $this->belongsTo(\App\Modules\Catalogo\Models\CatalogoGrupo::class, 'grupo_id'); }
    public function subgrupo() { return $this->belongsTo(\App\Modules\Catalogo\Models\CatalogoSubgrupo::class, 'subgrupo_id'); }
    public function clase() { return $this->belongsTo(\App\Modules\Catalogo\Models\CatalogoClase::class, 'clase_id'); }
    public function unidadCompra() { return $this->belongsTo(\App\Modules\Catalogo\Models\UnidadMedida::class, 'unidad_compra_id'); }

    // M:M para accesorios (facturados con el producto) y sustitutos (alternativas).
    public function accesorios()
    {
        return $this->belongsToMany(self::class, 'producto_accesorios', 'producto_id', 'accesorio_id')
            ->withPivot('cantidad_default')->withTimestamps();
    }
    public function sustitutos()
    {
        return $this->belongsToMany(self::class, 'producto_sustitutos', 'producto_id', 'sustituto_id')
            ->withTimestamps();
    }

    /**
     * C-F1 · Movimientos de kardex del producto (granular O agregado).
     * Después del creating hook en InventarioMovimiento que auto-populate
     * producto_id desde variante_id, esta relación cubre ambos tipos:
     * los movs granulares tienen producto_id + variante_id, los agregados
     * solo producto_id. Usado por el toggle desglose_stock para bloquear
     * el cambio si hay historia (política enforced también en BD por trigger).
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(\App\Modules\Dropi\Models\InventarioMovimiento::class);
    }

    /**
     * ¿Quién escribe el kardex del stock inicial?
     *
     * Por defecto lo escribe el observer de abajo. Los importadores que ya
     * escriben su propio movimiento —y que además saben en qué bodega y en qué
     * posición va— lo apagan con `sinKardexAutomatico()`.
     *
     * No es una optimización: el stock de un producto agregado se calcula
     * sumando el kardex (`StockService::saldoFisicoProducto`), así que dos
     * movimientos por la misma mercancía la duplican, y el del observer queda
     * en «la primera ubicación activa», que no es la que eligió quien importó.
     */
    protected static bool $omitirKardexInicial = false;

    /**
     * Ubicación donde debe entrar el stock inicial del próximo producto.
     *
     * Null = la primera activa, que es como se comportaba antes y es lo que
     * necesitan los importadores y seeders que no eligen lugar.
     */
    protected static ?int $ubicacionStockInicial = null;

    /**
     * Corre `$fn` dejando el stock inicial en la ubicación indicada.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function conStockInicialEn(?int $ubicacionId, callable $fn)
    {
        $previo = self::$ubicacionStockInicial;
        self::$ubicacionStockInicial = $ubicacionId;

        try {
            return $fn();
        } finally {
            self::$ubicacionStockInicial = $previo;
        }
    }

    /**
     * Corre `$fn` sin que la creación de productos genere kardex automático.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function sinKardexAutomatico(callable $fn)
    {
        $previo = self::$omitirKardexInicial;
        self::$omitirKardexInicial = true;

        try {
            return $fn();
        } finally {
            self::$omitirKardexInicial = $previo;
        }
    }

    /**
     * C-F3 FIX ALTO auditor · Observer que inserta el kardex inicial cuando se crea
     * un producto agregado con stock_directo > 0 desde un formulario. Sin esto, el
     * campo guardaba el número pero NO generaba movimiento → los reportes
     * mostraban 0. Es de raíz: cualquier flujo (pantalla de catálogo, API,
     * comando genérico Producto::create) queda cubierto.
     *
     * Los importadores de Excel escriben su propio movimiento, en la bodega y la
     * posición que se eligieron en la pantalla, así que lo apagan con
     * `Producto::sinKardexAutomatico()`. Antes este docblock decía que el
     * observer «detecta y no duplica», pero no podía: corre en `created`, o sea
     * antes de que el importador escriba su marker, así que lo único que miraba
     * era su propio marker y nunca encontraba nada. En una base nueva cada
     * referencia importada entraba dos veces —la mitad del stock en la bodega
     * equivocada— y no se notó porque en la base de dev los 134 productos ya
     * existían y `updateOrCreate` solo los actualizaba.
     */
    protected static function booted(): void
    {
        static::created(function (Producto $p) {
            if (self::$omitirKardexInicial) return;
            if ($p->desglose_stock || (int) $p->stock_directo <= 0) return;

            /*
             * Dónde entra ese stock.
             *
             * Antes se tomaba SIEMPRE la primera ubicación activa por id, sin
             * preguntar. Medido el 2026-10-13 montando una bodega nueva: se
             * crea «BOG-DEMO», se escriben 60 unidades y las 60 aparecen en
             * «R-A-01», una bodega vieja. El formulario pide la cantidad y
             * nunca pregunta el lugar, así que el error es invisible hasta que
             * alguien va a despachar y no encuentra la mercancía.
             *
             * Ahora manda la ubicación que eligió la persona; si no eligió
             * ninguna se conserva el comportamiento anterior, para no romper
             * las importaciones y los seeders que no la envían.
             */
            $bodega = self::$ubicacionStockInicial
                ? \App\Modules\Dropi\Models\InventarioUbicacion::find(self::$ubicacionStockInicial)
                : null;

            $bodega ??= \App\Modules\Dropi\Models\InventarioUbicacion::query()
                ->where(fn ($q) => $q->where('activa', true)->orWhereNull('activa'))
                ->orderBy('id')
                ->first();
            if (! $bodega) return; // sin bodegas no podemos crear el mov

            $notaMarker = "C-F3 · Stock inicial desde form Filament · producto #{$p->id}";
            $existe = \App\Modules\Dropi\Models\InventarioMovimiento::query()
                ->where('producto_id', $p->id)
                ->whereNull('variante_id')
                ->where('notas', $notaMarker)
                ->exists();
            if ($existe) return;

            \App\Modules\Dropi\Models\InventarioMovimiento::create([
                'producto_id' => $p->id,
                'variante_id' => null,
                'ubicacion_id' => $bodega->id,
                'tipo' => 'stock_inicial_form',
                'cantidad' => (int) $p->stock_directo,
                // El stock inicial entraba SIN costo. Como el kardex valoriza
                // con el promedio de las entradas, la bodega entera valía $0 y
                // la venta salía a costo cero: ningún despacho causaba costo de
                // ventas, así que el margen bruto del Estado de resultados daba
                // 100% siempre. Medido el 2026-10-13 en el reporte de stock por
                // bodega, con mercancía adentro y la bodega en $0.
                'costo_unit' => $p->precio_proveedor ?: null,
                'notas' => $notaMarker,
            ]);
        });
    }

    /**
     * C-F2 · ¿Este producto lleva stock granular (por variante) o agregado (directo)?
     * Wrapper con nombre semántico sobre `desglose_stock` para código más legible.
     */
    public function esGranular(): bool
    {
        return (bool) $this->desglose_stock;
    }

    /** C-F-QA6 · alias semántico para consistencia con items polimórficos */
    public function esAgregado(): bool
    {
        return ! $this->esGranular();
    }

    /**
     * C-F2 · Saldo físico del producto en una ubicación (o en toda la BD si null).
     *
     *   Granular  → SUM(variantes[*].saldoFisico(ubi))
     *   Agregado  → StockService::saldoFisicoProducto($this->id, $ubi)
     *
     * Es el punto de entrada preferido para código nuevo que necesita stock.
     * Evita duplicar la bifurcación granular/agregado en cada caller.
     */
    public function stockEn(?int $ubicacionId = null): int
    {
        $svc = app(\App\Modules\Inventario\Services\StockService::class);

        // Fix COD3 CRÍTICA re-audit · lookup polimórfico SIEMPRE funcional.
        //   Antes la rama agregada requería feature('desglose_dual')=true; si el
        //   flag venía OFF y el producto era agregado, caía al branch granular,
        //   `$this->variantes()->pluck` daba [] y devolvía 0 en silencio → los
        //   134 productos importados por Aracely desaparecían del inventario
        //   visible pese a tener movimientos vivos. El flag debe ser kill-switch
        //   quirúrgico (bloquear NUEVOS agregados en UI), no borrador de datos.
        if (! $this->esGranular()) {
            return $svc->saldoFisicoProducto($this->id, $ubicacionId);
        }

        // C-F-QA6 · Granular: usa saldosMasivos (1 query) en vez de N queries.
        $ids = $this->variantes()->pluck('id')->all();
        if (empty($ids)) return 0;
        $saldos = $svc->saldosMasivos($ids);
        $total = 0;
        foreach ($saldos as $key => $row) {
            if ($ubicacionId !== null) {
                [$vid, $uid] = explode('-', $key);
                if ((int) $uid !== $ubicacionId) continue;
            }
            $total += (int) $row->saldo;
        }
        return $total;
    }
    public function marca(): BelongsTo { return $this->belongsTo(Marca::class); }
    public function categoriaMaestra(): BelongsTo { return $this->belongsTo(Categoria::class, 'categoria_id'); }
    public function coleccion(): BelongsTo { return $this->belongsTo(Coleccion::class); }
    public function unidadMedida(): BelongsTo { return $this->belongsTo(UnidadMedida::class); }
    public function impuesto(): BelongsTo { return $this->belongsTo(Impuesto::class); }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('imagenes')->useDisk('public');
    }

    /**
     * Cuenta contable efectiva: la del producto si tiene, sino el default global.
     * REU-1: para asientos, PDFs contables y exportación a SIIGO.
     */
    public function cta(string $tipo): ?string
    {
        $col = 'cta_' . $tipo;
        $valor = $this->{$col} ?? null;
        // Explícito: null/'' = usar default. "0" o "0000" son válidos.
        if ($valor !== null && $valor !== '') return (string) $valor;
        $default = setting('contable.cta_' . $tipo . '_default');
        return ($default !== null && $default !== '') ? (string) $default : null;
    }

    public function centroCosto(): ?string
    {
        if ($this->centro_costo !== null && $this->centro_costo !== '') return (string) $this->centro_costo;
        $default = setting('contable.centro_costo_default');
        return ($default !== null && $default !== '') ? (string) $default : null;
    }
}
