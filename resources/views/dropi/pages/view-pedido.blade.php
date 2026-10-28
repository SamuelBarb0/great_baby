<x-filament-panels::page>
    @php
        /** @var \App\Modules\Dropi\Models\DropiPedido $pedido */
        $pedido = $this->record;
        $eventos = \App\Modules\Dropi\Support\Trazabilidad::lineaDeTiempo($pedido);
    @endphp

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;">
        {{-- Columna izquierda: datos del pedido --}}
        <div>
            <x-filament::section>
                <x-slot name="heading">Pedido {{ $pedido->guia }}</x-slot>
                <x-slot name="description">
                    Corte: {{ $pedido->corte?->etiqueta() ?? '—' }} · Transportadora: {{ $pedido->transportadora }}
                </x-slot>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
                    <div>
                        <div style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;">Estado</div>
                        <div style="margin-top:.25rem;"><x-filament::badge :color="$pedido->estado->color()">{{ $pedido->estado->label() }}</x-filament::badge></div>
                    </div>
                    <div>
                        <div style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;">Cliente</div>
                        <div style="margin-top:.25rem;font-weight:600;">{{ $pedido->cliente_nombre }}</div>
                        <div style="font-size:.85rem;color:#9ca3af;">{{ $pedido->cliente_ciudad }}</div>
                    </div>
                    <div>
                        <div style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;">Vendedor dropshipping</div>
                        <div style="margin-top:.25rem;">{{ $pedido->vendedor_nombre ?? '—' }}</div>
                        @if($pedido->requiere_factura_b2b)
                            <x-filament::badge color="info">Factura B2B</x-filament::badge>
                        @endif
                    </div>
                    <div>
                        <div style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;">Monto proveedor GB</div>
                        <div style="margin-top:.25rem;font-weight:700;font-size:1.25rem;">${{ number_format((float) $pedido->monto_esperado_proveedor, 0, ',', '.') }}</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section style="margin-top:1rem;">
                <x-slot name="heading">Productos ({{ $pedido->items->count() }})</x-slot>
                <div style="display:flex;flex-direction:column;gap:.5rem;">
                    @foreach($pedido->items as $it)
                        <div style="display:flex;align-items:center;gap:1rem;padding:.75rem;border:1px solid rgba(156,163,175,.2);border-radius:.5rem;">
                            <div style="width:2.75rem;height:2.75rem;border-radius:9999px;background:#f59e0b;color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;">{{ $it->cantidad }}</div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-family:ui-monospace,monospace;font-weight:600;">{{ $it->sku_dropi }}</div>
                                <div style="font-size:.85rem;color:#9ca3af;">${{ number_format((float) $it->precio_proveedor_unit, 0, ',', '.') }} c/u</div>
                            </div>
                            <div style="font-weight:700;">${{ number_format($it->subtotal(), 0, ',', '.') }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>

        {{-- Columna derecha: trazabilidad del envío --}}
        <x-filament::section>
            <x-slot name="heading">Trazabilidad del envío</x-slot>
            <x-slot name="description">Quién, cuándo, desde dónde y con qué equipo · empaque, foto e inventario</x-slot>
            <div style="position:relative;padding-left:1.5rem;">
                {{-- Línea vertical de la timeline --}}
                <div style="position:absolute;left:.375rem;top:.5rem;bottom:.5rem;width:2px;background:rgba(156,163,175,.25);"></div>

                @forelse($eventos as $ev)
                    @php
                        $color = match (true) {
                            $ev['tipo'] === 'inventario' => '#8b5cf6',
                            $ev['tipo'] === 'foto' => '#ec4899',
                            $ev['fuente'] === 'api' => '#3b82f6',
                            $ev['fuente'] === 'sistema' => '#10b981',
                            default => '#f59e0b',
                        };
                        $gps = $ev['gps'];
                    @endphp
                    <div style="position:relative;margin-bottom:1rem;">
                        <div style="position:absolute;left:-1.5rem;top:.375rem;width:.875rem;height:.875rem;border-radius:9999px;background:{{ $color }};border:2px solid rgba(0,0,0,.9);"></div>
                        <div>
                            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                                <span style="font-weight:600;font-size:.9rem;">{{ $ev['titulo'] }}</span>
                                @if($ev['fuente'])
                                    <span style="font-size:.7rem;padding:.125rem .375rem;background:rgba(156,163,175,.15);border-radius:.25rem;text-transform:uppercase;letter-spacing:.05em;">{{ $ev['fuente'] }}</span>
                                @endif
                            </div>
                            <div style="font-size:.75rem;color:#9ca3af;margin-top:.25rem;">
                                {{ $ev['at'] ? \Illuminate\Support\Carbon::parse($ev['at'])->timezone('America/Bogota')->format('Y-m-d H:i:s') : '—' }}
                                @if($ev['quien']) · {{ $ev['quien'] }} @endif
                            </div>
                            @if($ev['detalle'])
                                <div style="font-size:.75rem;color:#6b7280;margin-top:.125rem;">{{ $ev['detalle'] }}</div>
                            @endif
                            @if($gps)
                                <div style="font-size:.75rem;margin-top:.125rem;">
                                    @if($gps['mapa'])
                                        📍 <a href="{{ $gps['mapa'] }}" target="_blank" rel="noopener" style="color:#3b82f6;text-decoration:underline;">Ver en el mapa</a>
                                        @if($gps['precision']) <span style="color:#9ca3af;">(± {{ $gps['precision'] }} m)</span> @endif
                                    @else
                                        <span style="color:#ef4444;">📍 Sin ubicación: {{ ['denegado' => 'permiso negado', 'no_disponible' => 'GPS no disponible', 'sin_dato' => 'no se envió'][$gps['estado']] ?? $gps['estado'] }}</span>
                                    @endif
                                </div>
                            @endif
                            @if($ev['equipo'] || $ev['ip'])
                                <div style="font-size:.7rem;color:#9ca3af;margin-top:.125rem;">💻 {{ $ev['equipo'] }}@if($ev['ip']) · IP {{ $ev['ip'] }}@endif</div>
                            @endif
                            @if($ev['foto_url'])
                                <div style="font-size:.75rem;margin-top:.125rem;">📸 <a href="{{ $ev['foto_url'] }}" target="_blank" rel="noopener" style="color:#3b82f6;text-decoration:underline;">Ver foto</a></div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div style="color:#9ca3af;font-size:.85rem;">Sin registros todavía.</div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
