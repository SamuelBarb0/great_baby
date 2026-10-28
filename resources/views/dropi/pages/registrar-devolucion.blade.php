<x-filament-panels::page>
    <form wire:submit="registrar">
        {{-- Paso 1: escanear guía --}}
        {{ $this->form->getComponents()[0] }}

        @if($pedido)
            {{-- Paso 2: contexto ANTES de decidir destino (se decide viendo la info) --}}
            <x-filament::section style="margin-top:1rem;">
                <x-slot name="heading">2 · Contexto del pedido</x-slot>
                <x-slot name="description">Traído automáticamente por número de guía</x-slot>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
                    <div><strong>Cliente:</strong> {{ $pedido->cliente_nombre }}</div>
                    <div><strong>Ciudad:</strong> {{ $pedido->cliente_ciudad }}</div>
                    <div><strong>Transportadora:</strong> {{ $pedido->transportadora }}</div>
                    <div><strong>Estado:</strong> <x-filament::badge>{{ $pedido->estado->label() }}</x-filament::badge></div>
                    <div style="grid-column:1/-1;">
                        <strong>Productos:</strong>
                        <ul style="margin-top:.5rem;list-style:none;padding:0;">
                            @foreach($pedido->items as $it)
                                <li style="padding:.75rem;background:rgba(156,163,175,.1);border-radius:.5rem;margin-bottom:.375rem;">
                                    <span style="display:inline-block;min-width:2.75rem;height:2.75rem;line-height:2.75rem;text-align:center;border-radius:9999px;background:#f59e0b;color:#fff;font-weight:700;margin-right:.75rem;">{{ $it->cantidad }}</span>
                                    <span style="font-family:ui-monospace,monospace;font-size:.9rem;">{{ $it->sku_dropi }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @if($pedido->ari_factura_id)
                        <div style="grid-column:1/-1;padding:.75rem;background:rgba(59,130,246,.1);border-radius:.5rem;font-size:.875rem;">
                            <strong>Factura electrónica:</strong> {{ $pedido->ari_factura_id }} — al confirmar se genera la nota crédito.
                        </div>
                    @endif
                </div>
            </x-filament::section>

            {{-- 5.4 · Re-escaneo: vuelve a bodega lo que se escanea, no lo que decía el pedido --}}
            @php($renglones = $this->porEscanear())
            @if($renglones)
                @php($esp = array_sum(array_column($renglones, 'esperadas')))
                @php($rec = array_sum($recibidos))
                <x-filament::section style="margin-top:1rem;">
                    <x-slot name="heading">Escanear lo que llegó · {{ $rec }} / {{ $esp }} unidades</x-slot>
                    <x-slot name="description">Pistola o teclado + Enter. Solo vuelve al inventario lo escaneado; el faltante queda anotado.</x-slot>
                    <input type="text" wire:model="lectura" wire:keydown.enter.prevent="escanear" autocomplete="off"
                           placeholder="Código de barras o referencia" aria-label="Escanear producto devuelto"
                           style="width:100%;padding:.6rem .75rem;border:1px solid rgba(156,163,175,.4);border-radius:.5rem;font-family:ui-monospace,monospace;background:transparent;">
                    @if($ultimaLectura)
                        <div style="margin-top:.5rem;font-weight:600;color:{{ str_starts_with($ultimaLectura, '✓') ? '#059669' : (str_starts_with($ultimaLectura, '⚠') ? '#d97706' : '#dc2626') }};">{{ $ultimaLectura }}</div>
                    @endif
                    <ul style="margin-top:.75rem;list-style:none;padding:0;">
                        @foreach($renglones as $clave => $r)
                            <li style="display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(156,163,175,.15);">
                                <span style="flex:1;font-family:ui-monospace,monospace;">{{ $r['referencia'] }}</span>
                                <button type="button" wire:click="ajustar('{{ $clave }}', -1)" aria-label="Quitar una unidad" style="padding:.1rem .6rem;border:1px solid rgba(156,163,175,.4);border-radius:.375rem;">−</button>
                                <strong style="min-width:3.5rem;text-align:center;color:{{ ($recibidos[$clave] ?? 0) === $r['esperadas'] ? '#059669' : '#d97706' }};">{{ $recibidos[$clave] ?? 0 }}/{{ $r['esperadas'] }}</strong>
                                <button type="button" wire:click="ajustar('{{ $clave }}', 1)" aria-label="Sumar una unidad" style="padding:.1rem .6rem;border:1px solid rgba(156,163,175,.4);border-radius:.375rem;">+</button>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            {{-- Paso 3: decidir destino DESPUÉS de ver contexto --}}
            <div style="margin-top:1rem;">
                {{ $this->form->getComponents()[1] }}
            </div>
        @endif

        <div style="margin-top:1.25rem;display:flex;gap:.5rem;">
            @foreach($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>
</x-filament-panels::page>
