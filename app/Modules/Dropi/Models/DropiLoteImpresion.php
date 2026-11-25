<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El reporte de un corte (25-nov-2026): lo que Aracely seleccionó con
 * «Generar corte», en el orden de la pila de stickers; espera a que terminen
 * sus guías, baja los stickers y arma el PDF (hoja de alistamiento + stickers).
 * Lo arma ImprimirGuiasDropi en segundo plano. Si en la misma franja genera
 * otra vez, el corte suma un segundo reporte con sólo lo nuevo.
 */
class DropiLoteImpresion extends Model
{
    public const GENERANDO_GUIAS = 'generando_guias';

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
        'guias_pendientes' => 'integer',
    ];

    public function corte(): BelongsTo
    {
        return $this->belongsTo(DropiCorte::class, 'corte_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pedidosImpresos(): HasMany
    {
        return $this->hasMany(DropiPedido::class, 'lote_impresion_id');
    }

    /** «Corte 1 · 25/11 · reporte de las 9:05 am · 40 guías», como sale en el PDF y en pantalla. */
    public function titulo(): string
    {
        $hora = $this->created_at->timezone('America/Bogota');
        $corte = $this->corte ? "Corte {$this->corte->numero} · " . $this->corte->fecha->format('d/m') : "Lote {$this->id} · " . $hora->format('d/m');

        return "{$corte} · reporte de las " . $hora->format('g:i a') . " · {$this->total} guía(s)";
    }

    /** Dónde se guarda lo del lote (stickers sueltos mientras se arma, y las partes). */
    public function carpeta(): string
    {
        return "dropi/guias/lote-{$this->id}";
    }
}
