{{-- «Kardex» de Dropi (28-nov-2026): las entradas y salidas de un producto, en palabras. --}}
<x-filament-panels::page>
    @php
        $money = fn ($v) => '$' . number_format((float) $v, 0, ',', '.');
        $card = 'padding:1rem;border-radius:.75rem;border:1px solid rgba(148,163,184,.3);';
        $label = 'font-size:.8rem;color:#6b7280;font-weight:600;';
        $num = 'font-size:1.6rem;font-weight:800;margin-top:.2rem;font-variant-numeric:tabular-nums;';
        $p = $this->productoElegido;
        $k = $this->kardex;
    @endphp

    <div style="display:grid;gap:1rem;">
        <div style="font-size:.95rem;opacity:.85;max-width:60rem;">
            Cada vez que entra o sale mercancía queda una línea aquí. El inventario <b>baja cuando se confirma el empaque</b>
            de un pedido; si el pedido se desempaca o vuelve como devolución, <b>entra de nuevo</b>.
        </div>

        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
            <x-filament::input type="search" wire:model.live.debounce.400ms="buscar"
                placeholder="Busca el producto: nombre, referencia, código de barras o nombre en Dropi" autofocus />
        </x-filament::input.wrapper>

        @if(! $p)
            @if(mb_strlen(trim($buscar)) >= 2)
                @if($this->coincidencias->isEmpty())
                    <div style="{{ $card }}font-size:.95rem;">No encontramos «{{ $buscar }}». Prueba con parte del nombre o la referencia.</div>
                @else
                    <div style="{{ $card }}display:grid;gap:.4rem;">
                        <div style="{{ $label }}">¿Cuál producto?</div>
                        @foreach($this->coincidencias as $c)
                            <button type="button" wire:click="elegir({{ $c->id }})"
                                    style="text-align:left;padding:.6rem .75rem;border-radius:.5rem;border:1px solid rgba(148,163,184,.3);background:none;cursor:pointer;font-size:.95rem;min-height:44px;">
                                <b>{{ $c->nombre }}</b> <span style="opacity:.7;">· {{ $c->referencia }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @else
                <div style="{{ $card }}font-size:.95rem;opacity:.8;">Escribe arriba el producto que quieres revisar.</div>
            @endif
        @else
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;">
                <div>
                    <div style="font-size:1.15rem;font-weight:800;">{{ $p->nombre }}</div>
                    <div style="font-size:.9rem;opacity:.75;">Referencia {{ $p->referencia }}</div>
                </div>
                @if(count($this->variantes) > 0)
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="variante">
                            <option value="">Todas las variantes</option>
                            @foreach($this->variantes as $id => $nombre)
                                <option value="{{ $id }}">{{ $nombre }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.75rem;">
                <div style="{{ $card }}">
                    <div style="{{ $label }}">Unidades hoy</div>
                    <div style="{{ $num }}{{ $k['saldo'] < 0 ? 'color:#dc2626;' : '' }}">{{ number_format($k['saldo'], 0, ',', '.') }}</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }}">Costo promedio por unidad</div>
                    <div style="{{ $num }}">{{ $k['costo_promedio'] > 0 ? $money($k['costo_promedio']) : 'Sin costo' }}</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }}">Valor del inventario</div>
                    <div style="{{ $num }}">{{ $money($k['valor']) }}</div>
                </div>
            </div>

            @if($k['total'] === 0)
                <div style="{{ $card }}font-size:.95rem;">Este producto todavía no tiene entradas ni salidas registradas.</div>
            @else
                <div style="overflow-x:auto;border-radius:.75rem;border:1px solid rgba(148,163,184,.3);">
                    <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                        <thead>
                            <tr style="text-align:left;background:rgba(148,163,184,.1);">
                                <th style="padding:.6rem .75rem;">Fecha</th>
                                <th style="padding:.6rem .75rem;">Qué pasó</th>
                                <th style="padding:.6rem .75rem;">Documento</th>
                                <th style="padding:.6rem .75rem;">Bodega</th>
                                <th style="padding:.6rem .75rem;text-align:right;">Entra</th>
                                <th style="padding:.6rem .75rem;text-align:right;">Sale</th>
                                <th style="padding:.6rem .75rem;text-align:right;">Saldo</th>
                                <th style="padding:.6rem .75rem;text-align:right;">Costo unidad</th>
                                <th style="padding:.6rem .75rem;text-align:right;">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($k['movimientos'] as $m)
                                <tr style="border-top:1px solid rgba(148,163,184,.2);vertical-align:top;">
                                    <td style="padding:.55rem .75rem;white-space:nowrap;">{{ $m['fecha'] }}</td>
                                    <td style="padding:.55rem .75rem;">
                                        <b>{{ $m['que'] }}</b>
                                        @if($m['variante'])<div style="font-size:.8rem;opacity:.75;">{{ $m['variante'] }}</div>@endif
                                    </td>
                                    <td style="padding:.55rem .75rem;white-space:nowrap;">
                                        @if($m['documento'] && $m['url'])
                                            <a href="{{ $m['url'] }}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;">{{ $m['documento'] }}</a>
                                        @else
                                            {{ $m['documento'] ?? '—' }}
                                        @endif
                                    </td>
                                    <td style="padding:.55rem .75rem;">{{ $m['bodega'] ?: '—' }}</td>
                                    <td style="padding:.55rem .75rem;text-align:right;color:#059669;font-weight:700;font-variant-numeric:tabular-nums;">{{ $m['entra'] ? '+' . $m['entra'] : '' }}</td>
                                    <td style="padding:.55rem .75rem;text-align:right;color:#dc2626;font-weight:700;font-variant-numeric:tabular-nums;">{{ $m['sale'] ? '−' . $m['sale'] : '' }}</td>
                                    <td style="padding:.55rem .75rem;text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">{{ $m['saldo'] }}</td>
                                    <td style="padding:.55rem .75rem;text-align:right;font-variant-numeric:tabular-nums;">{{ $m['costo'] > 0 ? $money($m['costo']) : '—' }}</td>
                                    <td style="padding:.55rem .75rem;text-align:right;font-variant-numeric:tabular-nums;">{{ $m['valor'] > 0 ? $money($m['valor']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;font-size:.9rem;opacity:.85;">
                    <span>Mostrando {{ count($k['movimientos']) }} de {{ $k['total'] }} movimientos, el más reciente arriba.</span>
                    @if(count($k['movimientos']) < $k['total'])
                        <x-filament::button color="gray" wire:click="verMas">Ver más</x-filament::button>
                    @endif
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
