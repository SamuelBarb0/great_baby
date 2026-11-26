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
        'resultados' => 'array',
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

    /**
     * Qué pasó con la guía de cada pedido (26-nov-2026), por tipo: el ícono, lo
     * que se le dice a Aracely y qué tiene que hacer. Lo arma GenerarGuiasEnBloqueJob.
     */
    public const RESULTADOS = [
        'generada' => ['icono' => '✅', 'titulo' => 'Guía generada', 'hacer' => null],
        'ya_tenia' => ['icono' => '↪', 'titulo' => 'Ya tenía guía en Dropi', 'hacer' => 'Nada: se usó la que ya tenía, no se generó otra.'],
        'cancelado' => ['icono' => '🚫', 'titulo' => 'Cancelado o rechazado en Dropi', 'hacer' => 'No se despacha. Ya quedó cancelado en el ERP.'],
        'reemplazado' => ['icono' => '🔁', 'titulo' => 'Dropi ya no tiene esta orden (la reemplazó por otra)', 'hacer' => 'No despachar esta: la que vale es la nueva. Ya quedó cancelada en el ERP.'],
        'sin_cobertura' => ['icono' => '🚚', 'titulo' => 'La transportadora no llega a esa dirección', 'hacer' => 'Cambiar la transportadora o la dirección en Dropi, o cancelar el pedido. Después, incluirlo en el próximo corte.'],
        'en_otro_estado' => ['icono' => '⏸', 'titulo' => 'En Dropi no está pendiente', 'hacer' => 'Revisar el pedido en Dropi. Ya quedó actualizado en el ERP.'],
        'sin_inventario' => ['icono' => '📦', 'titulo' => 'No está listo para despachar en el ERP', 'hacer' => 'Revisar el inventario del pedido; cuando esté, incluirlo en el próximo corte.'],
        'error' => ['icono' => '⚠', 'titulo' => 'Dropi respondió con un error', 'hacer' => 'Incluirlo en el próximo corte para reintentar; si se repite, revisarlo en Dropi.'],
    ];

    /** Cuántos pedidos de cada tipo, en el orden de RESULTADOS. @return array<string, int> */
    public function conteoResultados(): array
    {
        $porTipo = collect($this->resultados ?? [])->countBy('tipo');

        return collect(array_keys(self::RESULTADOS))
            ->mapWithKeys(fn ($t) => [$t => (int) ($porTipo[$t] ?? 0)])
            ->filter()
            ->all();
    }

    /** Dónde se guarda lo del lote (stickers sueltos mientras se arma, y las partes). */
    public function carpeta(): string
    {
        return "dropi/guias/lote-{$this->id}";
    }
}
