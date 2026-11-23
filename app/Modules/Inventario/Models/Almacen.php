<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * En qué estado y en qué condición comercial está la mercancía: principal,
 * averías leves, bajas, outlet, repuestos, saldos o garantía de proveedor.
 *
 * Es el segundo eje de la bodega. El primero es la oficina —de quién es—.
 *
 * Una advertencia que importa: `comercial` NO es lo mismo que «está en buen
 * estado». Las averías leves están con desperfecto y aun así se venden, con
 * descuento. El outlet está bueno y también se vende, con descuento por baja
 * rotación. Quien decide si entra al inventario disponible es `comercial`, no
 * `estado_fisico`.
 */
class Almacen extends Model
{
    protected $table = 'logistica_almacenes';

    protected $guarded = ['id'];

    protected $casts = [
        'comercial' => 'boolean',
        'permite_ingreso_directo' => 'boolean',
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    public function ubicaciones(): HasMany
    {
        return $this->hasMany(InventarioUbicacion::class, 'almacen_id');
    }

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true);
    }

    /**
     * Los que admiten mercancía sin pasar por un traslado.
     *
     * Hoy: principal, garantía de proveedor y devoluciones de cliente.
     *
     * El `orderBy` NO es cosmético. Sin él, el orden lo decidía el id —o sea,
     * en qué orden se insertaron las filas—, así que un almacén agregado por
     * migración sobre una base nueva aparecía ANTES que el principal en todo
     * selector que use este scope. La tabla tiene columna `orden` justamente
     * para que el catálogo se lea como lo pensó el área.
     */
    public function scopeDeIngreso(Builder $q): Builder
    {
        return $q->where('activo', true)
            ->where('permite_ingreso_directo', true)
            ->orderBy('orden')
            ->orderBy('codigo');
    }

    /**
     * Cómo lo nombra el motor viejo de inventario.
     *
     * `CategoriaUbicacion` decide cosas que ya están construidas —qué cuenta
     * como vendible en el kardex, dónde cae lo que llegó mal—, así que cada
     * almacén declara a cuál de esas categorías corresponde. Si alguien
     * escribe una categoría que no existe, se cae a `venta` en vez de reventar:
     * el catálogo lo edita el área desde una pantalla y un dedo mal puesto no
     * puede tumbar una recepción.
     */
    public function categoria(): CategoriaUbicacion
    {
        return CategoriaUbicacion::tryFrom((string) $this->categoria_equivalente)
            ?? CategoriaUbicacion::Venta;
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} · {$this->nombre}";
    }
}
