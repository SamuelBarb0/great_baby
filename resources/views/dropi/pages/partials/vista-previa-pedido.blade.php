{{-- Vista previa de un pedido desde la pantalla del corte (26-nov-2026):
     lo que hace falta para saber qué es y cómo va, sin salir del corte. --}}
@php
    /** @var \App\Modules\Dropi\Models\DropiPedido $pedido */
    $pedido->loadMissing(['items.variante.producto', 'items.producto', 'corte']);
    $t = $resultado ? (\App\Modules\Dropi\Models\DropiLoteImpresion::RESULTADOS[$resultado['tipo']] ?? null) : null;
    $eventos = array_slice(array_reverse(\App\Modules\Dropi\Support\Trazabilidad::lineaDeTiempo($pedido)), 0, 8);
    $u = auth()->user();
    $verSticker = $pedido->stickerUrl()
        && (\App\Auth\Permisos::puede($u, 'estacion_empaque') || \App\Auth\Permisos::puede($u, 'dropi_cambiar_en_dropi'));
    $caja = 'border:1px solid rgba(156,163,175,.3);border-radius:.6rem;padding:.75rem .9rem;';
    $rotulo = 'font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#6b7280;';
@endphp

<div style="display:flex;flex-direction:column;gap:1rem;font-size:.9rem;">
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
        <x-filament::badge :color="$pedido->estado?->color() ?? 'gray'">{{ $pedido->estado?->label() ?? '—' }}</x-filament::badge>
        @if($pedido->corte)<span style="color:#6b7280;">Corte {{ $pedido->corte->etiqueta() }}</span>@endif
        @if($pedido->dropi_creado_at)<span style="color:#6b7280;">· creado {{ $pedido->dropi_creado_at->timezone('America/Bogota')->format('d/m g:i a') }}</span>@endif
    </div>

    @if($t)
        <div style="{{ $caja }}border-left:4px solid {{ $t['color'] }};">
            <div style="font-weight:600;">{{ $t['icono'] }} {{ $t['titulo'] }}</div>
            @if(filled($resultado['detalle'] ?? null))<div style="color:#4b5563;margin-top:.2rem;">{{ $resultado['detalle'] }}</div>@endif
            @if($t['hacer'])<div style="margin-top:.35rem;font-size:.85rem;"><strong>Qué hacer:</strong> {{ $t['hacer'] }}</div>@endif
        </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
        <div style="{{ $caja }}">
            <div style="{{ $rotulo }}">Cliente</div>
            <div style="font-weight:600;margin-top:.2rem;">{{ $pedido->cliente_nombre }}</div>
            @if($pedido->cliente_telefono)<div>{{ $pedido->cliente_telefono }}</div>@endif
            <div style="color:#4b5563;">{{ $pedido->cliente_direccion }}</div>
            <div style="color:#4b5563;">{{ collect([$pedido->cliente_ciudad, $pedido->cliente_depto])->filter()->implode(', ') }}</div>
        </div>
        <div style="{{ $caja }}">
            <div style="{{ $rotulo }}">Envío</div>
            <div style="margin-top:.2rem;"><span style="color:#6b7280;">Orden Dropi:</span> <span style="font-family:ui-monospace,monospace;">{{ $pedido->dropi_orden_id }}</span></div>
            <div><span style="color:#6b7280;">Guía:</span> <span style="font-family:ui-monospace,monospace;">{{ $pedido->tieneGuiaReal() ? $pedido->guia : 'todavía sin guía' }}</span></div>
            <div><span style="color:#6b7280;">Transportadora:</span> {{ $pedido->transportadora ?: '—' }}</div>
            <div><span style="color:#6b7280;">Tienda:</span> {{ $pedido->tienda ?: '—' }}</div>
        </div>
    </div>

    <div style="{{ $caja }}">
        <div style="{{ $rotulo }}margin-bottom:.35rem;">Productos</div>
        @foreach($pedido->items as $i)
            <div style="display:flex;gap:.75rem;align-items:baseline;padding:.3rem 0;border-top:1px solid rgba(156,163,175,.2);">
                <span style="font-weight:700;min-width:2.5rem;">×{{ $i->cantidad }}</span>
                <span style="font-family:ui-monospace,monospace;min-width:7rem;">{{ $i->referenciaErp() ?? $i->sku_dropi }}</span>
                <span style="flex:1;">{{ $i->producto_nombre }}@if(filled($i->variacion))<span style="color:#6b7280;"> · {{ $i->variacion }}</span>@endif</span>
            </div>
        @endforeach
    </div>

    @if($verSticker)
        <div style="{{ $caja }}">
            <div style="{{ $rotulo }}margin-bottom:.35rem;">Sticker de la guía</div>
            <iframe src="{{ route('dropi.pedido.sticker', $pedido) }}" loading="lazy" title="Sticker de la guía"
                    style="width:100%;height:22rem;border:0;border-radius:.4rem;background:#fff;"></iframe>
        </div>
    @endif

    @if($eventos)
        <div style="{{ $caja }}">
            <div style="{{ $rotulo }}margin-bottom:.35rem;">Lo último que pasó</div>
            @foreach($eventos as $ev)
                <div style="padding:.3rem 0;border-top:1px solid rgba(156,163,175,.2);">
                    <span style="font-weight:600;">{{ $ev['titulo'] }}</span>
                    @if(filled($ev['detalle'] ?? null))<span style="color:#4b5563;"> · {{ $ev['detalle'] }}</span>@endif
                    <div style="font-size:.78rem;color:#6b7280;">
                        {{ $ev['at'] ? \Illuminate\Support\Carbon::parse($ev['at'])->timezone('America/Bogota')->format('d/m g:i a') : '' }}
                        @if(filled($ev['quien'] ?? null)) · {{ $ev['quien'] }}@endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
