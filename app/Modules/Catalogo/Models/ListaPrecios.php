<?php

namespace App\Modules\Catalogo\Models;

use Illuminate\Database\Eloquent\Model;

class ListaPrecios extends Model
{
    protected $table = 'listas_precios';
    protected $fillable = ['codigo', 'nombre', 'canal', 'activa', 'predeterminada'];
    protected $casts = ['activa' => 'boolean', 'predeterminada' => 'boolean'];

    /**
     * Precios por variante de esta lista.
     *
     * Sirve para saber cuántos productos puede comprar de verdad un cliente
     * con esta lista asignada: varias listas existen con nombre y sin un solo
     * precio cargado, y eso sólo se descubría cuando el vendedor le buscaba
     * mercancía al cliente y no le aparecía nada.
     */
    public function precios(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrecioVariante::class, 'lista_id');
    }

    /**
     * Precios de los productos AGREGADOS de esta lista.
     *
     * Los granulares tarifan por variante (`precios_variante`) y los agregados
     * —colores surtidos, una sola existencia por referencia— por producto
     * (`precios_producto`). Son dos tablas y hay que mirar las dos.
     */
    public function preciosProducto(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrecioProducto::class, 'lista_id');
    }

    /**
     * Cuántos productos puede pedir de verdad un cliente con esta lista.
     *
     * Contaba sólo los precios por variante, así que una lista con precios
     * únicamente de productos agregados figuraba como «sin precios cargados» y
     * quedaba bloqueada en el alta de contactos: no se le podía asignar a
     * nadie, y sin lista no hay venta. Medido el 2026-10-13 cargándole un
     * precio a «Mayorista» y viéndola seguir en cero.
     */
    public function scopeConConteoDePrecios($q)
    {
        $vigente = fn ($w) => $w->whereNull('vigente_hasta')
            ->orWhere('vigente_hasta', '>=', now()->toDateString());

        return $q->withCount([
            'precios as precios_variante_count' => fn ($x) => $x->where($vigente),
            'preciosProducto as precios_producto_count' => fn ($x) => $x->where($vigente),
        ]);
    }

    /** Total de productos tarifados, de los dos tipos. */
    public function getProductosTarifadosAttribute(): int
    {
        return (int) ($this->precios_variante_count ?? 0)
            + (int) ($this->precios_producto_count ?? 0);
    }
}
