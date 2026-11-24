<?php

namespace App\Modules\Dropi\Models;

use App\Models\User;
use App\Modules\Dropi\Enums\EstadoEmpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpaqueRegistro extends Model
{
    protected $table = 'empaques_registro';

    protected $fillable = [
        'pedido_id', 'operario_id', 'inicio_at', 'fin_at',
        'items_totales', 'items_escaneados', 'duracion_segundos',
        'estado', 'notas', 'foto_path', 'foto_at',
        'foto_purga_intentos', 'foto_purga_ultimo_fallo_at',
    ];

    protected $casts = [
        'inicio_at' => 'datetime',
        'fin_at' => 'datetime',
        'foto_at' => 'datetime',
        'items_totales' => 'integer',
        'items_escaneados' => 'integer',
        'duracion_segundos' => 'integer',
        'foto_purga_intentos' => 'integer',
        'foto_purga_ultimo_fallo_at' => 'datetime',
        'estado' => EstadoEmpaque::class,
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(DropiPedido::class, 'pedido_id');
    }

    public function operario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operario_id');
    }

    /**
     * «Mis stickers de hoy» de las dos estaciones: lo que este operario empacó
     * hoy, con la guía de Dropi y su sticker, para imprimirlo o reintentar la
     * guía aunque se haya recargado la página (recorrido del 23-nov-2026).
     *
     * @return list<array{pedido_id:int, orden:?string, guia:?string, transportadora:?string, ciudad:?string, hora:?string, sticker_url:?string}>
     */
    public static function stickersDeHoy(int $operarioId): array
    {
        $registros = static::query()
            ->where('operario_id', $operarioId)
            ->where('estado', EstadoEmpaque::Completado->value)
            ->where('fin_at', '>=', today())
            ->orderByDesc('fin_at')
            ->limit(30)
            ->get(['pedido_id', 'fin_at']);

        $pedidos = DropiPedido::whereIn('id', $registros->pluck('pedido_id'))
            ->whereNotNull('dropi_orden_id')
            ->get(['id', 'dropi_orden_id', 'guia', 'sticker_path', 'transportadora', 'cliente_ciudad'])
            ->keyBy('id');

        return $registros->unique('pedido_id')
            ->filter(fn ($r) => $pedidos->has($r->pedido_id))
            ->map(function ($r) use ($pedidos) {
                $p = $pedidos[$r->pedido_id];

                return [
                    'pedido_id' => $p->id,
                    'orden' => $p->dropi_orden_id,
                    'guia' => $p->tieneGuiaReal() ? $p->guia : null,
                    'transportadora' => $p->transportadora,
                    'ciudad' => $p->cliente_ciudad,
                    'hora' => $r->fin_at?->format('g:i a'),
                    'sticker_url' => $p->stickerUrl(),
                ];
            })->values()->all();
    }
}
