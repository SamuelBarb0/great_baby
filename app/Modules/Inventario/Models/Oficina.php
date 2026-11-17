<?php

namespace App\Modules\Inventario\Models;

use App\Models\EmpresaConfig;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * De quién es la mercancía: Great Baby, Dropi, Marketing, la Sra. Tania o
 * Exhibición.
 *
 * Es uno de los dos ejes con los que clasifica la bodega. El otro es el
 * almacén —en qué estado está—, y son independientes: la misma caja puede ser
 * de Dropi y estar en garantía.
 *
 * Sólo las que tienen `permite_ingreso_directo` pueden recibir mercancía de un
 * contenedor. A las demás se llega por traslado, nunca por recepción.
 */
class Oficina extends Model
{
    protected $table = 'logistica_oficinas';

    protected $guarded = ['id'];

    protected $casts = [
        'permite_ingreso_directo' => 'boolean',
        'es_respaldo_surtido' => 'boolean',
        'activa' => 'boolean',
        'orden' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(EmpresaConfig::class, 'empresa_config_id');
    }

    public function ubicaciones(): HasMany
    {
        return $this->hasMany(InventarioUbicacion::class, 'oficina_id');
    }

    public function scopeActivas(Builder $q): Builder
    {
        return $q->where('activa', true);
    }

    /** Las que pueden recibir mercancía directamente de una recepción. */
    public function scopeDeIngreso(Builder $q): Builder
    {
        return $q->where('activa', true)->where('permite_ingreso_directo', true);
    }

    /**
     * De dónde saca el alistador lo que le falta para completar un pedido.
     *
     * El área lo llama «surtido integral»: antes de cerrar con faltante hay
     * que mirar en Exhibición. Se busca por la marca y no por el código 14,
     * para que mover el catálogo no obligue a tocar código.
     */
    public static function respaldoDeSurtido(): ?self
    {
        return self::query()->where('activa', true)->where('es_respaldo_surtido', true)
            ->orderBy('orden')->orderBy('id')->first();
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} · {$this->nombre}";
    }
}
