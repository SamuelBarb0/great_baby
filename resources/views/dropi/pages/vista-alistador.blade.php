<x-filament-panels::page>
    @php
        $corte = $this->corte;
        $recoleccion = $this->recoleccion;
        $empaque = $this->empaque;
        $progreso = $corte && $corte->pedidos_totales > 0
            ? round(($corte->pedidos_despachados / $corte->pedidos_totales) * 100)
            : 0;
    @endphp

    {{-- Header del corte activo --}}
    <x-filament::section>
        @if($corte)
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
                <div>
                    <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;">Corte activo</div>
                    <div style="font-size:1.75rem;font-weight:700;margin-top:.25rem;">{{ $corte->etiqueta() }}</div>
                    <div style="margin-top:.5rem;">
                        <x-filament::badge color="warning">{{ $corte->estado->label() }}</x-filament::badge>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;text-align:center;">
                    <div style="padding:.75rem 1rem;border-radius:.75rem;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);">
                        <div style="font-size:1.5rem;font-weight:700;">{{ $corte->pedidos_totales }}</div>
                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.25rem;text-transform:uppercase;">Total</div>
                    </div>
                    <div style="padding:.75rem 1rem;border-radius:.75rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);">
                        <div style="font-size:1.5rem;font-weight:700;color:#f87171;">{{ $corte->pedidos_pendientes_inv }}</div>
                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.25rem;text-transform:uppercase;">Pend. inv.</div>
                    </div>
                    <div style="padding:.75rem 1rem;border-radius:.75rem;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);">
                        <div style="font-size:1.5rem;font-weight:700;color:#4ade80;">{{ $corte->pedidos_despachados }}</div>
                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.25rem;text-transform:uppercase;">Despachados</div>
                    </div>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:.25rem;">
                    <span>Progreso del corte</span>
                    <span style="font-weight:700;">{{ $progreso }}%</span>
                </div>
                <div style="height:.5rem;border-radius:9999px;background:rgba(156,163,175,.2);overflow:hidden;">
                    <div style="height:100%;background:linear-gradient(90deg,#f59e0b,#ea580c);width:{{ $progreso }}%;transition:width .5s ease;"></div>
                </div>
            </div>
        @else
            <div style="text-align:center;padding:2rem;color:#9ca3af;">
                No hay cortes activos. Sincronice pedidos desde Dropi para crear el primero.
            </div>
        @endif
    </x-filament::section>

    {{-- Selector de modo con tabs --}}
    <x-filament::tabs style="margin-top:1.5rem;">
        <x-filament::tabs.item :active="$modo === 'recoleccion'" wire:click="cambiarModo('recoleccion')" icon="heroicon-o-clipboard-document-list">
            Recolección · {{ count($recoleccion) }} refs
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$modo === 'empaque'" wire:click="cambiarModo('empaque')" icon="heroicon-o-cube">
            Empaque · {{ $empaque->count() }} pedidos
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- MODO RECOLECCIÓN --}}
    @if($modo === 'recoleccion')
        <x-filament::section style="margin-top:1rem;">
            <x-slot name="heading">Modo Recolección</x-slot>
            <x-slot name="description">Ve el total de unidades por referencia para recorrer la bodega una sola vez. Cuando termines, cambia a Empaque.</x-slot>

            @if(count($recoleccion) === 0)
                <div style="text-align:center;padding:2rem;color:#9ca3af;">Nada por recoger en este corte.</div>
            @else
                {{-- Desktop / tablet horizontal --}}
                <div class="alistador-tabla-desktop" style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">SKU</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Producto</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Variante</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Ubicación</th>
                                <th style="text-align:right;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Unidades</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recoleccion as $g)
                                <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                                    <td style="padding:.75rem;font-family:ui-monospace,monospace;font-size:.8rem;">{{ $g['sku'] }}</td>
                                    <td style="padding:.75rem;font-weight:600;">{{ $g['producto'] }}</td>
                                    <td style="padding:.75rem;font-size:.85rem;color:#9ca3af;">{{ $g['variante'] ?: '—' }}</td>
                                    <td style="padding:.75rem;">
                                        <span style="font-family:ui-monospace,monospace;font-size:.75rem;padding:.25rem .5rem;background:rgba(156,163,175,.15);border-radius:.25rem;">{{ $g['ubicacion_sugerida'] }}</span>
                                    </td>
                                    <td style="padding:.75rem;text-align:right;">
                                        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:2.75rem;height:2.75rem;padding:0 .5rem;border-radius:9999px;background:#f59e0b;color:white;font-weight:700;font-size:1rem;">{{ $g['total_unidades'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Móvil (<768px): cards apiladas para leer sin scroll horizontal --}}
                <div class="alistador-tabla-movil" style="display:none;">
                    @foreach($recoleccion as $g)
                        <div style="padding:1rem;border:1px solid rgba(156,163,175,.2);border-radius:.75rem;margin-bottom:.5rem;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:700;font-size:1rem;">{{ $g['producto'] }}</div>
                                    <div style="font-size:.8rem;color:#9ca3af;margin-top:.25rem;">{{ $g['variante'] ?: '—' }}</div>
                                    <div style="font-family:ui-monospace,monospace;font-size:.7rem;color:#6b7280;margin-top:.25rem;word-break:break-all;">{{ $g['sku'] }}</div>
                                </div>
                                <span style="display:inline-flex;align-items:center;justify-content:center;min-width:3rem;height:3rem;border-radius:9999px;background:#f59e0b;color:white;font-weight:700;font-size:1.25rem;flex-shrink:0;">{{ $g['total_unidades'] }}</span>
                            </div>
                            <div style="margin-top:.5rem;">
                                <span style="font-family:ui-monospace,monospace;font-size:.8rem;padding:.375rem .75rem;background:rgba(156,163,175,.15);border-radius:.375rem;">📍 {{ $g['ubicacion_sugerida'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <style>
                    @media (max-width: 767px) {
                        .alistador-tabla-desktop { display: none !important; }
                        .alistador-tabla-movil { display: block !important; }
                    }
                </style>
            @endif
        </x-filament::section>
    @endif

    {{-- MODO EMPAQUE --}}
    @if($modo === 'empaque')
        <x-filament::section style="margin-top:1rem;">
            <x-slot name="heading">Modo Empaque · Cola compartida</x-slot>
            <x-slot name="description">Al tomar un pedido queda bloqueado para ti — nadie más puede empacarlo hasta que termines o pasen 10 minutos sin actividad.</x-slot>

            @if($empaque->isEmpty())
                <div style="text-align:center;padding:2rem;color:#9ca3af;">Cola vacía. Revisa Recolección o vuelve luego.</div>
            @else
                <div style="display:grid;gap:.75rem;">
                    @foreach($empaque as $pedido)
                        <div style="padding:1rem;border-radius:.75rem;border:1px solid rgba(156,163,175,.2);background:rgba(255,255,255,.02);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                                <div style="flex:1;min-width:250px;">
                                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                                        <span style="font-family:ui-monospace,monospace;font-weight:700;font-size:1.05rem;">{{ $pedido->guia }}</span>
                                        <x-filament::badge :color="$pedido->estado->value === 'pending' ? 'warning' : 'info'">
                                            {{ $pedido->estado->label() }}
                                        </x-filament::badge>
                                    </div>
                                    <div style="margin-top:.5rem;font-size:.9rem;">
                                        <strong>{{ $pedido->cliente_nombre }}</strong>
                                        <span style="color:#9ca3af;"> · {{ $pedido->cliente_ciudad }} · {{ $pedido->transportadora }}</span>
                                    </div>
                                    <div style="margin-top:.5rem;">
                                        @foreach($pedido->items as $it)
                                            <span style="display:inline-block;font-size:.75rem;padding:.25rem .5rem;background:rgba(156,163,175,.15);border-radius:.25rem;margin-right:.25rem;margin-bottom:.25rem;">
                                                {{ $it->cantidad }}× <span style="font-family:ui-monospace,monospace;">{{ $it->sku_dropi }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;">
                                    <div style="text-align:right;">
                                        <div style="font-size:.7rem;color:#9ca3af;">Esperado</div>
                                        <div style="font-weight:700;font-size:1.05rem;">${{ number_format((float) $pedido->monto_esperado_proveedor, 0, ',', '.') }}</div>
                                    </div>
                                    <div style="display:flex;gap:.5rem;">
                                        @if($pedido->estado->value === 'pending')
                                            <x-filament::button size="sm" color="info" wire:click="tomarPedido({{ $pedido->id }})">Tomar</x-filament::button>
                                        @elseif($pedido->estado->value === 'alistando')
                                            <x-filament::button size="sm" color="success" wire:click="empacarPedido({{ $pedido->id }})">Empacar</x-filament::button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
