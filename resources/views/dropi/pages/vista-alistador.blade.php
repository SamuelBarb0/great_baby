<x-filament-panels::page>
    @php
        $lote = $this->lote;
        $lotes = $this->lotes;
        $cifras = $this->cifras;
        $recoleccion = $this->recoleccion;
        $empaque = $this->empaque;
        $ubicacionesEmpaque = $modo === 'empaque' ? app(\App\Modules\Dropi\Support\RecoleccionDelCorte::class)->ubicacionPorItem($empaque) : [];
        $progreso = $cifras['total'] > 0 ? round(($cifras['empacados'] / $cifras['total']) * 100) : 0;
    @endphp

    {{-- El lote impreso que se trabaja: la misma hoja que la encargada tiene en la mano (24-nov-2026). --}}
    <x-filament::section>
        @if($lote)
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
                <div>
                    <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;">Lote impreso</div>
                    <div style="font-size:1.75rem;font-weight:700;margin-top:.25rem;">{{ $lote->titulo() }}</div>
                    @if($lotes->count() > 1)
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem;">
                            @foreach($lotes as $l)
                                <button type="button" wire:click="escogerLote({{ $l->id }})"
                                        style="padding:.3rem .7rem;border-radius:9999px;font-size:.8rem;font-weight:600;cursor:pointer;border:1px solid {{ $l->id === $lote->id ? '#f59e0b' : 'rgba(156,163,175,.4)' }};background:{{ $l->id === $lote->id ? 'rgba(245,158,11,.15)' : 'transparent' }};">
                                    Lote {{ $l->id }} · {{ $l->created_at->timezone('America/Bogota')->format('g:i a') }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="alistador-cifras" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;text-align:center;">
                    <div style="padding:.75rem 1rem;border-radius:.75rem;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);">
                        <div style="font-size:1.5rem;font-weight:700;">{{ $cifras['total'] }}</div>
                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.25rem;text-transform:uppercase;">Guías</div>
                    </div>
                    <div style="padding:.75rem 1rem;border-radius:.75rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);">
                        <div style="font-size:1.5rem;font-weight:700;color:#f87171;">{{ $cifras['por_empacar'] }}</div>
                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.25rem;text-transform:uppercase;">Por empacar</div>
                    </div>
                    <div style="padding:.75rem 1rem;border-radius:.75rem;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);">
                        <div style="font-size:1.5rem;font-weight:700;color:#4ade80;">{{ $cifras['empacados'] }}</div>
                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.25rem;text-transform:uppercase;">Empacados</div>
                    </div>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:.25rem;">
                    <span>Progreso del lote</span>
                    <span style="font-weight:700;">{{ $progreso }}%</span>
                </div>
                <div style="height:.5rem;border-radius:9999px;background:rgba(156,163,175,.2);overflow:hidden;">
                    <div style="height:100%;background:linear-gradient(90deg,#f59e0b,#ea580c);width:{{ $progreso }}%;transition:width .5s ease;"></div>
                </div>
            </div>
        @else
            {{-- Aquí se trabaja DESPUÉS de Aracely: sin guías impresas no hay nada que recoger. --}}
            <div style="text-align:center;padding:2.5rem 1rem;">
                <div style="font-size:2.5rem;">🖨</div>
                <div style="font-size:1.25rem;font-weight:700;margin-top:.5rem;">Todavía no hay guías impresas</div>
                <div style="color:#9ca3af;margin-top:.5rem;">Aracely: en Pedidos Dropi selecciona los pedidos → <strong>Generar guías</strong> → <strong>Imprimir guías</strong>. Cuando el lote esté impreso, aparece aquí.</div>
            </div>
        @endif
    </x-filament::section>

    {{-- Celular: las cifras del corte en una columna y las acciones del pedido a lo ancho. --}}
    <style>
        @media (max-width: 480px) {
            /* Las tres cifras siguen en fila, más compactas: apiladas empujaban la lista fuera de la pantalla. */
            .alistador-cifras { gap: .5rem !important; width: 100%; }
            .alistador-cifras > div { padding: .5rem .25rem !important; }
            .alistador-acciones { width: 100%; flex-direction: row !important; justify-content: space-between; align-items: center !important; }
            .alistador-acciones > div:first-child { text-align: left !important; }
            .alistador-sufijo { display: none; }
            /* El botón del PDF baja debajo del texto en vez de apretarlo contra una columna. */
            .alistador-seccion .fi-section-header { flex-wrap: wrap; }
            .alistador-seccion .fi-section-header-after-ctn { width: 100%; }
        }
        /* Lo ya recogido se apaga, pero el chulito queda vivo para que se lea de lejos. */
        .alistador-hecha td:not(.alistador-chulito), .alistador-hecha > div > div:not(.alistador-chulito), .alistador-hecha > div > span { opacity: .55; }
        .alistador-hecha > div:last-child { opacity: .55; }
    </style>

    @if($lote)
    {{-- Selector de modo con tabs --}}
    <x-filament::tabs style="margin-top:1.5rem;">
        <x-filament::tabs.item :active="$modo === 'recoleccion'" wire:click="cambiarModo('recoleccion')" icon="heroicon-o-clipboard-document-list">
            Recolección · {{ count($recoleccion) }}<span class="alistador-sufijo"> refs</span>
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$modo === 'empaque'" wire:click="cambiarModo('empaque')" icon="heroicon-o-cube">
            Empaque · {{ $empaque->count() }}<span class="alistador-sufijo"> pedidos</span>
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- MODO RECOLECCIÓN --}}
    @if($modo === 'recoleccion')
        <x-filament::section class="alistador-seccion" style="margin-top:1rem;">
            <x-slot name="heading">Modo Recolección</x-slot>
            <x-slot name="description">Ve el total de unidades por referencia, en el orden del recorrido de la bodega, para pasar una sola vez. Toca cada referencia cuando la recojas: queda marcada para todos. Cuando termines, cambia a Empaque.</x-slot>
            {{-- La hoja del lote: la lista de alistamiento abre la parte 1. --}}
            @if($lote->partes)
                <x-slot name="afterHeader">
                    <x-filament::button tag="a" :href="route('dropi.guias.parte', [$lote, 1])" target="_blank" icon="heroicon-o-printer" color="gray">
                        Hoja del lote
                    </x-filament::button>
                </x-slot>
            @endif

            @if(count($recoleccion) === 0)
                <div style="text-align:center;padding:2rem;color:#9ca3af;">Todo lo de este lote ya se empacó.</div>
            @else
                @php
                    $recogidas = collect($recoleccion)->where('marcada', true)->count();
                    $pctRecogido = round($recogidas / count($recoleccion) * 100);
                @endphp
                {{-- Progreso del «chulito»: cuántas referencias ya se sacaron de la bodega. --}}
                <div style="margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem;">
                        <span><strong>{{ $recogidas }}</strong> de {{ count($recoleccion) }} referencias recogidas</span>
                        <span style="font-weight:700;">{{ $pctRecogido }}%</span>
                    </div>
                    <div style="height:.5rem;border-radius:9999px;background:rgba(156,163,175,.2);overflow:hidden;">
                        <div style="height:100%;background:#22c55e;width:{{ $pctRecogido }}%;transition:width .3s ease;"></div>
                    </div>
                </div>
                {{-- Desktop / tablet horizontal --}}
                <div class="alistador-tabla-desktop" style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                                <th style="width:9.5rem;text-align:center;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Recogido</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">SKU</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Producto</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Variante</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Ubicación</th>
                                <th style="text-align:left;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Zona</th>
                                <th style="text-align:right;padding:.75rem;font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Unidades</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recoleccion as $g)
                                <tr wire:key="rec-{{ $g['sku'] }}" wire:click="marcarRecogido(@js($g['sku']))"
                                    class="{{ $g['marcada'] ? 'alistador-hecha' : '' }}" style="border-bottom:1px solid rgba(156,163,175,.1);cursor:pointer;{{ $g['marcada'] ? 'background:rgba(34,197,94,.08);' : '' }}">
                                    <td class="alistador-chulito" style="padding:.75rem;text-align:center;">@include('dropi.pages.partials.chulito-recoleccion', ['g' => $g])</td>
                                    <td style="padding:.75rem;font-family:ui-monospace,monospace;font-size:.8rem;">{{ $g['sku'] }}</td>
                                    <td style="padding:.75rem;font-weight:600;">{{ $g['producto'] }}</td>
                                    <td style="padding:.75rem;font-size:.85rem;color:#9ca3af;">{{ $g['variante'] ?: '—' }}</td>
                                    <td style="padding:.75rem;">
                                        <span style="font-family:ui-monospace,monospace;font-size:.75rem;padding:.25rem .5rem;background:rgba(156,163,175,.15);border-radius:.25rem;">{{ $g['ubicacion_sugerida'] }}</span>
                                    </td>
                                    <td style="padding:.75rem;font-size:.85rem;color:#9ca3af;">{{ $g['zona'] ?? '—' }}</td>
                                    <td style="padding:.75rem;text-align:right;">
                                        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:2.75rem;height:2.75rem;padding:0 .5rem;border-radius:9999px;background:#f59e0b;color:white;font-weight:700;font-size:1rem;">{{ $g['total_unidades'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Móvil y tablet vertical (<1024px): cards apiladas; la tabla de 7 columnas no cabe --}}
                <div class="alistador-tabla-movil" style="display:none;">
                    @foreach($recoleccion as $g)
                        <div wire:key="rec-movil-{{ $g['sku'] }}" wire:click="marcarRecogido(@js($g['sku']))"
                             style="padding:1rem;border:1px solid {{ $g['marcada'] ? 'rgba(34,197,94,.5)' : 'rgba(156,163,175,.2)' }};border-radius:.75rem;margin-bottom:.5rem;cursor:pointer;{{ $g['marcada'] ? 'background:rgba(34,197,94,.08);' : '' }}" class="{{ $g['marcada'] ? 'alistador-hecha' : '' }}">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                                <div class="alistador-chulito" style="flex-shrink:0;">@include('dropi.pages.partials.chulito-recoleccion', ['g' => $g, 'compacto' => true])</div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:700;font-size:1rem;">{{ $g['producto'] }}</div>
                                    <div style="font-size:.8rem;color:#9ca3af;margin-top:.25rem;">{{ $g['variante'] ?: '—' }}</div>
                                    <div style="font-family:ui-monospace,monospace;font-size:.7rem;color:#6b7280;margin-top:.25rem;word-break:break-all;">{{ $g['sku'] }}</div>
                                    @if($g['marcada'])
                                        <div style="font-size:.75rem;color:#16a34a;font-weight:600;margin-top:.35rem;">✓ {{ $g['marcada_por'] ?? '—' }} · {{ $g['marcada_at'] }}</div>
                                    @elseif($g['faltan'] > 0)
                                        <div style="font-size:.75rem;color:#f59e0b;font-weight:600;margin-top:.35rem;">Faltan {{ $g['faltan'] }} por recoger</div>
                                    @endif
                                </div>
                                <span style="display:inline-flex;align-items:center;justify-content:center;min-width:3rem;height:3rem;border-radius:9999px;background:#f59e0b;color:white;font-weight:700;font-size:1.25rem;flex-shrink:0;">{{ $g['total_unidades'] }}</span>
                            </div>
                            <div style="margin-top:.5rem;">
                                <span style="font-family:ui-monospace,monospace;font-size:.8rem;padding:.375rem .75rem;background:rgba(156,163,175,.15);border-radius:.375rem;">📍 {{ $g['ubicacion_sugerida'] }}@if($g['zona']) · Zona {{ $g['zona'] }}@endif</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <style>
                    @media (max-width: 1023px) {
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
            <x-slot name="description">En el orden de la pila de stickers, igual que la Estación de Empaque. Al tomar un pedido queda bloqueado para ti — nadie más puede empacarlo hasta que termines o pasen 10 minutos sin actividad.</x-slot>

            @if($empaque->isEmpty())
                <div style="text-align:center;padding:2rem;color:#9ca3af;">Cola vacía. Revisa Recolección o vuelve luego.</div>
            @else
                <div style="display:grid;gap:.75rem;">
                    @foreach($empaque as $pedido)
                        <div style="padding:1rem;border-radius:.75rem;border:1px solid rgba(156,163,175,.2);background:rgba(255,255,255,.02);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                                <div style="flex:1 1 15rem;min-width:0;">
                                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                                        <span style="font-family:ui-monospace,monospace;font-weight:700;font-size:1.05rem;word-break:break-all;">{{ $pedido->guia }}</span>
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
                                            @php
                                                // El nombre es lo que reconoce quien empaca; el SKU de Dropi queda de apoyo.
                                                $prodItem = $it->variante?->producto ?? $it->producto;
                                                $varItem = $it->nombreVariante();
                                            @endphp
                                            <span title="{{ $it->sku_dropi }}" style="display:inline-block;font-size:.75rem;padding:.25rem .5rem;background:rgba(156,163,175,.15);border-radius:.25rem;margin-right:.25rem;margin-bottom:.25rem;">
                                                <strong>{{ $it->cantidad }}×</strong> {{ $prodItem?->nombre ?? $it->sku_dropi }}@if($varItem !== '') <span style="color:#9ca3af;">· {{ $varItem }}</span>@endif
                                                @if($ubic = $ubicacionesEmpaque[$it->id] ?? null)<span style="color:#9ca3af;white-space:nowrap;"> · 📍 <span style="font-family:ui-monospace,monospace;">{{ $ubic }}</span></span>@endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="alistador-acciones" style="display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;">
                                    <div style="text-align:right;">
                                        <div style="font-size:.7rem;color:#9ca3af;">Esperado</div>
                                        <div style="font-weight:700;font-size:1.05rem;">${{ number_format((float) $pedido->monto_esperado_proveedor, 0, ',', '.') }}</div>
                                    </div>
                                    <div style="display:flex;gap:.5rem;">
                                        @if($pedido->estado->value !== 'alistando')
                                            <x-filament::button size="lg" color="info" wire:click="tomarPedido({{ $pedido->id }})">Tomar</x-filament::button>
                                        @else
                                            <x-filament::button size="lg" color="success" wire:click="empacarPedido({{ $pedido->id }})">Empacar</x-filament::button>
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
    @endif
</x-filament-panels::page>
