<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\ProductoVariante;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Procesa un escaneo desde la pistola de la estación de empaque.
 * Retorna un resultado tipado con qué hacer en el UI: abrir pedido, marcar item, error.
 */
class ProcesarEscaneoEmpaque
{
    use AsAction;

    /**
     * @return array{
     *   tipo: 'pedido_abierto'|'variante_marcada'|'pedido_no_encontrado'|'variante_sin_pedido'|'variante_ya_marcada'|'error',
     *   mensaje: string,
     *   pedido_id?: int,
     *   variante_id?: int,
     *   sonido: 'ok'|'warn'|'error',
     * }
     */
    public function handle(string $codigo, ?int $pedidoActivoId, int $operarioId): array
    {
        // Re-audit DR-ε (FUNC-M5) · normalizar (upper+trim) — sync guarda UPPER.
        //   Pistolas mixed-case no matcheaban con `where('guia', ...)`.
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return ['tipo' => 'error', 'mensaje' => 'Código vacío', 'sonido' => 'error'];
        }

        return DB::transaction(function () use ($codigo, $pedidoActivoId, $operarioId) {
            // 1. ¿Es una guía de pedido?
            $pedido = DropiPedido::query()
                ->with(['items.variante.producto'])
                ->where(function ($q) use ($codigo) {
                    $q->where('guia', $codigo)->orWhere('dropi_orden_id', $codigo);
                })
                ->lockForUpdate()
                ->first();

            if ($pedido) {
                // Bloquear pedidos ya empacados/despachados/entregados/pagados (evita reapertura y duplicación de métricas).
                if (in_array($pedido->estado, [
                    EstadoPedidoDropi::Empacado,
                    EstadoPedidoDropi::Despachado,
                    EstadoPedidoDropi::Entregado,
                    EstadoPedidoDropi::Pagado,
                ], true)) {
                    return [
                        'tipo' => 'error',
                        'mensaje' => "Pedido {$codigo} ya fue empacado/despachado.",
                        'sonido' => 'error',
                    ];
                }

                // Rechazar guía sin ítems (empaque fantasma).
                $totalUnidades = (int) $pedido->items->sum(fn ($it) => (int) ($it->cantidad ?? 1));
                if ($totalUnidades === 0) {
                    return [
                        'tipo' => 'error',
                        'mensaje' => "Pedido {$codigo} no tiene ítems — reportá a Aracely",
                        'sonido' => 'error',
                    ];
                }

                // Bloqueo de carrera: si ya hay un registro en curso por otro operario, aviso
                $enCurso = EmpaqueRegistro::where('pedido_id', $pedido->id)
                    ->where('estado', 'en_curso')
                    ->lockForUpdate()
                    ->first();

                if ($enCurso && $enCurso->operario_id !== $operarioId) {
                    return [
                        'tipo' => 'error',
                        'mensaje' => "Pedido {$codigo} lo está empacando otro operario.",
                        'sonido' => 'error',
                    ];
                }

                // firstOrCreate para blindaje: aunque el lockForUpdate sobre dropi_pedidos
                // ya serializa, esto garantiza idempotencia si alguien bypassa el lock.
                EmpaqueRegistro::firstOrCreate(
                    ['pedido_id' => $pedido->id, 'estado' => 'en_curso'],
                    [
                        'operario_id' => $operarioId,
                        'inicio_at' => now(),
                        'items_totales' => $totalUnidades,
                        'items_escaneados' => 0,
                    ]
                );

                if ($pedido->estado !== EstadoPedidoDropi::Alistando) {
                    $pedido->transicionar(
                        EstadoPedidoDropi::Alistando,
                        'manual',
                        $operarioId,
                        ['origen' => 'estacion_pistola'],
                    );
                }

                return [
                    'tipo' => 'pedido_abierto',
                    'mensaje' => "Pedido {$codigo} abierto — {$pedido->items->count()} " . ($pedido->items->count() === 1 ? 'ítem' : 'ítems') . ' para empacar',
                    'pedido_id' => $pedido->id,
                    'sonido' => 'ok',
                ];
            }

            // 2. ¿Es un código de variante? (necesita pedido activo)
            $variante = ProductoVariante::where('codigo_barras', $codigo)->first();

            // C-F2 R2 · Si no coincide con variante, intentar como REFERENCIA de
            //   producto agregado. Los productos agregados no tienen barcode
            //   propio en producto_variantes; el operador escanea/tipea la ref.
            $productoAgregado = null;
            if (! $variante) {
                $productoAgregado = \App\Modules\Dropi\Models\Producto::query()
                    ->where('desglose_stock', false)
                    ->where('activo', true)
                    ->where('referencia', $codigo)
                    ->first();
            }

            if (! $variante && ! $productoAgregado) {
                return [
                    'tipo' => 'error',
                    'mensaje' => "Código {$codigo} no reconocido (ni variante ni producto agregado). ¿Está bien impresa la etiqueta?",
                    'sonido' => 'error',
                ];
            }

            if (! $pedidoActivoId) {
                $tipoSujeto = $variante ? 'variante' : 'producto agregado';
                return [
                    'tipo' => 'variante_sin_pedido',
                    'mensaje' => "Escaneaste un {$tipoSujeto} pero no hay pedido activo. Escanea primero la guía.",
                    'sonido' => 'warn',
                ];
            }

            $pedido = DropiPedido::with('items.variante')->lockForUpdate()->find($pedidoActivoId);

            // Ownership: si hay un registro en_curso de OTRO operario, bloquear
            $registroActivo = EmpaqueRegistro::where('pedido_id', $pedidoActivoId)
                ->where('estado', 'en_curso')
                ->first();
            if ($registroActivo && $registroActivo->operario_id !== $operarioId) {
                return [
                    'tipo' => 'error',
                    'mensaje' => "Otro operario está empacando este pedido.",
                    'sonido' => 'error',
                ];
            }

            // C-F2 R2 · Match polimórfico: buscar item por variante_id O producto_id.
            if ($variante) {
                $item = $pedido?->items->firstWhere('variante_id', $variante->id);
                $etiquetaSujeto = "La variante {$codigo}";
            } else {
                $item = $pedido?->items->first(fn ($i) => $i->variante_id === null && $i->producto_id === $productoAgregado->id);
                $etiquetaSujeto = "El producto agregado {$productoAgregado->referencia}";
            }

            if (! $item) {
                return [
                    'tipo' => 'error',
                    'mensaje' => "{$etiquetaSujeto} no está en el pedido actual.",
                    'sonido' => 'error',
                ];
            }

            // Recargar el item con lock para evitar race en el increment.
            $itemLocked = \App\Modules\Dropi\Models\DropiPedidoItem::where('id', $item->id)
                ->lockForUpdate()->first();
            $cantidadRequerida = (int) ($itemLocked->cantidad ?? 1);
            $cantidadYa = (int) ($itemLocked->cantidad_pickeada ?? 0);

            // C-F2 R2 · Etiqueta polimórfica según el sujeto detectado.
            $nombreItem = $variante
                ? ($variante->producto?->nombre ?? 'producto')
                : ($productoAgregado?->nombre ?? 'producto agregado');

            if ($cantidadYa >= $cantidadRequerida) {
                return [
                    'tipo' => 'variante_ya_marcada',
                    'mensaje' => "{$nombreItem} ya está completo ({$cantidadYa}/{$cantidadRequerida}).",
                    'variante_id' => $variante?->id,
                    'producto_id' => $productoAgregado?->id,
                    'sonido' => 'warn',
                ];
            }

            $itemLocked->cantidad_pickeada = $cantidadYa + 1;
            if ($itemLocked->cantidad_pickeada >= $cantidadRequerida) {
                $itemLocked->pickeado_at = now();
                $itemLocked->pickeado_por = $operarioId;
            }
            $itemLocked->save();
            $item = $itemLocked;

            EmpaqueRegistro::where('pedido_id', $pedido->id)
                ->where('estado', 'en_curso')
                ->where('operario_id', $operarioId)
                ->increment('items_escaneados');

            $restanteItem = $cantidadRequerida - $item->cantidad_pickeada;
            $sufijo = $restanteItem > 0 ? " (faltan {$restanteItem})" : '';

            // C-F2 R2 · Mensaje polimórfico para el operario en pantalla + audio.
            // En un agregado, el color que pidió el cliente en Dropi (la variación).
            $descColor = match (true) {
                (bool) $variante => " — {$variante->color_nombre}",
                filled(trim((string) $item->variacion)) => ' — ' . trim((string) $item->variacion),
                default => ' — AGREGADO (colores surtidos)',
            };
            return [
                'tipo' => 'variante_marcada',
                'mensaje' => "✓ {$nombreItem}{$descColor}{$sufijo}",
                'variante_id' => $variante?->id,
                'producto_id' => $productoAgregado?->id,
                'es_agregado' => (bool) $productoAgregado,
                'sonido' => 'ok',
            ];
        });
    }
}
