<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un «Imprimir guías» de Aracely (24-nov-2026): los pedidos en el orden de la
 * pila de stickers, el avance de la descarga y las partes del PDF. Lo arma
 * ImprimirGuiasDropi en segundo plano; la Vista del Alistador lo recoge.
 */
class DropiLoteImpresion extends Model
{
    public const DESCARGANDO = 'descargando';

    public const ARMANDO = 'armando';

    public const LISTO = 'listo';

    public const ERROR = 'error';

    protected $table = 'dropi_lotes_impresion';

    protected $guarded = ['id'];

    protected $casts = [
        'pedidos' => 'array',
        'sin_sticker' => 'array',
        'partes' => 'array',
        'total' => 'integer',
        'descargados' => 'integer',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pedidosImpresos(): HasMany
    {
        return $this->hasMany(DropiPedido::class, 'lote_impresion_id');
    }

    /** «Lote 7 · 24/11 9:05 am · 40 guías», como sale en el PDF y en la Vista del Alistador. */
    public function titulo(): string
    {
        return "Lote {$this->id} · " . $this->created_at->timezone('America/Bogota')->format('d/m g:i a') . " · {$this->total} guía(s)";
    }

    /** Dónde se guarda lo del lote (stickers sueltos mientras se arma, y las partes). */
    public function carpeta(): string
    {
        return "dropi/guias/lote-{$this->id}";
    }
}
