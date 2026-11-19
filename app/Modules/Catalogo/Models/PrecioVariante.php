<?php

namespace App\Modules\Catalogo\Models;

use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecioVariante extends Model
{
    protected $table = 'precios_variante';
    protected $fillable = ['variante_id', 'lista_id', 'precio', 'vigente_desde', 'vigente_hasta'];
    protected $casts = [
        'precio' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function variante(): BelongsTo { return $this->belongsTo(ProductoVariante::class, 'variante_id'); }
    public function lista(): BelongsTo { return $this->belongsTo(ListaPrecios::class, 'lista_id'); }

    /**
     * Sólo los precios que rigen hoy.
     *
     * `PrecioProducto` lo tenía y éste no, así que cada consulta de precios por
     * variante repetía el `where` a mano —el panel del vendedor todavía lo
     * hace—. Dos escrituras de la misma regla se separan tarde o temprano, y
     * cuando se separen una de las dos va a cobrar un precio vencido.
     */
    public function scopeVigentes($q)
    {
        return $q->where(fn ($w) => $w->whereNull('vigente_hasta')
            ->orWhere('vigente_hasta', '>=', now()->toDateString()));
    }
}
