{{-- Avance del corte en la estación de empaque (AvanceCorteEmpaque): se refresca solo,
     con su búsqueda por guía, orden o cliente (27-nov-2026). --}}
@php
    $tonos = [
        'listo' => ['bg' => 'rgba(22,163,74,.16)', 'fg' => '#86efac'],
        'trabajo' => ['bg' => 'rgba(2,132,199,.18)', 'fg' => '#7dd3fc'],
        'pendiente' => ['bg' => 'rgba(100,116,139,.22)', 'fg' => '#e2e8f0'],
        'atencion' => ['bg' => 'rgba(217,119,6,.18)', 'fg' => '#fcd34d'],
        'neutro' => ['bg' => 'rgba(100,116,139,.15)', 'fg' => '#cbd5e1'],
    ];
@endphp
<div wire:poll.5s style="display:grid;gap:.75rem;">
    {{-- La búsqueda: no le devuelve el foco a la pistola mientras se escribe aquí. --}}
    <div data-gb-propio-foco style="position:relative;">
        <label for="buscar-pedido-corte" style="position:absolute;left:-9999px;">Buscar pedido</label>
        <input id="buscar-pedido-corte" type="search" wire:model.live.debounce.300ms="buscar"
               autocomplete="off" placeholder="🔎 Buscar guía, orden o cliente…"
               x-on:keydown.escape="$wire.set('buscar', ''); document.getElementById('scan-input')?.focus()"
               style="width:100%;min-height:48px;padding:.6rem .9rem;font-size:1.05rem;background:#0f172a;color:#f1f5f9;border:1px solid rgba(148,163,184,.45);border-radius:.6rem;outline:none;">
    </div>

    @if($resultados !== null)
        <div role="status" aria-live="polite" style="padding:.6rem;border-radius:.75rem;background:#111827;border:1px solid rgba(148,163,184,.3);display:grid;gap:.4rem;">
            @forelse($resultados as $r)
                <div style="padding:.55rem .65rem;border-radius:.5rem;background:{{ $tonos[$r['tono']]['bg'] }};">
                    <div style="display:flex;justify-content:space-between;gap:.5rem;align-items:baseline;flex-wrap:wrap;">
                        <span style="font-family:ui-monospace,monospace;font-weight:700;color:#f1f5f9;font-size:1rem;">{{ $r['guia'] }}</span>
                        <span style="font-weight:800;font-size:.9rem;color:{{ $tonos[$r['tono']]['fg'] }};text-transform:uppercase;letter-spacing:.03em;">{{ $r['estado'] }}</span>
                    </div>
                    <div style="font-size:.9rem;color:#cbd5e1;">{{ $r['cliente'] }} · orden {{ $r['orden'] }}</div>
                    @if($r['detalle'] !== '')
                        <div style="font-size:.85rem;color:{{ $tonos[$r['tono']]['fg'] }};">{{ $r['detalle'] }}</div>
                    @endif
                </div>
            @empty
                <div style="font-size:.95rem;color:#cbd5e1;padding:.25rem;">Ningún pedido con «{{ $buscar }}».</div>
            @endforelse
        </div>
    @endif

    {{-- Lo que quedó de cortes anteriores (28-nov-2026): cuántos, qué productos y
         «Empacar →» para abrir cada pedido en la estación. --}}
    @if($anteriores->isNotEmpty())
        <div style="padding:1rem;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.55);border-radius:1rem;display:grid;gap:.6rem;">
            <div style="font-weight:800;color:#fcd34d;font-size:1.05rem;">⚠ Quedaron de cortes anteriores · {{ $anteriores->sum('total') }}</div>
            @foreach($anteriores as $a)
                <details @if($loop->first) open @endif style="border-radius:.6rem;background:#111827;border:1px solid rgba(148,163,184,.25);">
                    <summary style="cursor:pointer;padding:.6rem .75rem;list-style:revert;color:#f1f5f9;">
                        <strong>{{ $a['etiqueta'] }}</strong>
                        <span style="color:#fcd34d;font-weight:700;"> · faltan {{ $a['total'] }} {{ $a['total'] === 1 ? 'pedido' : 'pedidos' }}</span>
                        <div style="font-size:.85rem;color:#cbd5e1;margin-top:.2rem;">{{ $a['productos'] }}</div>
                    </summary>
                    <div style="display:grid;gap:.3rem;max-height:16rem;overflow-y:auto;padding:0 .6rem .6rem;">
                        @forelse($a['pedidos'] as $f)
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;font-size:.9rem;padding:.4rem .6rem;border-radius:.4rem;background:rgba(100,116,139,.16);color:#f1f5f9;">
                                <span>
                                    <span style="font-family:ui-monospace,monospace;font-weight:700;">{{ $f['guia'] }}</span>
                                    <span style="color:#cbd5e1;"> · {{ $f['cliente'] }} · {{ $f['productos'] }}</span>
                                    @if($f['empacando'])<span style="color:#7dd3fc;font-weight:600;"> · 📦 {{ $f['empacando'] }}</span>@endif
                                </span>
                                @unless($f['empacando'])
                                    <button type="button" wire:click="$dispatch('empaque-abrir-pedido', { pedidoId: {{ $f['id'] }} })"
                                            style="flex:none;min-height:40px;padding:.35rem .8rem;border-radius:.5rem;border:none;background:#d97706;color:#111827;font-weight:800;cursor:pointer;">
                                        Empacar →
                                    </button>
                                @endunless
                            </div>
                        @empty
                            <div style="font-size:.9rem;color:#cbd5e1;">Nada con esa búsqueda.</div>
                        @endforelse
                    </div>
                </details>
            @endforeach
        </div>
    @endif

    @foreach($cortes as $c)
        <div style="padding:1rem;background:#111827;border:1px solid rgba(148,163,184,.25);border-radius:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:.5rem;flex-wrap:wrap;">
                <div style="font-weight:800;color:#f1f5f9;">{{ $c['etiqueta'] }}</div>
                <div style="font-size:.95rem;color:#cbd5e1;font-variant-numeric:tabular-nums;">
                    <strong style="color:#86efac;">{{ $c['empacados_total'] }}</strong> de {{ $c['total'] }} empacados
                </div>
            </div>
            <div style="height:.6rem;border-radius:9999px;background:rgba(148,163,184,.2);overflow:hidden;margin:.6rem 0 .9rem;">
                <div style="height:100%;width:{{ $c['pct'] }}%;background:#16a34a;transition:width .5s;"></div>
            </div>

            {{-- Arriba lo que falta, en el orden de la pila. --}}
            <div style="font-size:.8rem;color:#e2e8f0;text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-bottom:.35rem;">⏳ Por empacar · {{ $c['faltan_total'] }}</div>
            <div style="display:grid;gap:.3rem;max-height:16rem;overflow-y:auto;margin-bottom:.9rem;">
                @forelse($c['faltan'] as $f)
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;font-size:.9rem;padding:.45rem .6rem;border-radius:.4rem;background:rgba(100,116,139,.16);color:#f1f5f9;">
                        <span>
                            <span style="font-family:ui-monospace,monospace;font-weight:700;">{{ $f['guia'] }}</span>
                            <span style="color:#cbd5e1;"> · {{ $f['productos'] }}</span>
                            @if($f['empacando'])<span style="color:#7dd3fc;font-weight:600;"> · 📦 {{ $f['empacando'] }}</span>@endif
                        </span>
                        @unless($f['empacando'])
                            <button type="button" wire:click="$dispatch('empaque-abrir-pedido', { pedidoId: {{ $f['id'] }} })"
                                    style="flex:none;min-height:36px;padding:.3rem .7rem;border-radius:.5rem;border:1px solid rgba(148,163,184,.5);background:none;color:#f1f5f9;font-weight:700;cursor:pointer;">
                                Empacar →
                            </button>
                        @endunless
                    </div>
                @empty
                    <div style="font-size:.95rem;color:#86efac;">{{ $buscar !== '' ? 'Nada pendiente con esa búsqueda.' : '¡Nada por empacar en este corte!' }}</div>
                @endforelse
            </div>

            {{-- Lo empacado baja aquí, lo último arriba. --}}
            <div style="font-size:.8rem;color:#86efac;text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-bottom:.35rem;">✅ Empacados · {{ $c['empacados_total'] }}</div>
            <div style="display:grid;gap:.3rem;max-height:16rem;overflow-y:auto;">
                @php $visibles = $buscar !== '' ? $c['empacados'] : $c['empacados']->take(\App\Livewire\AvanceCorteEmpaque::EMPACADOS_VISIBLES); @endphp
                @forelse($visibles as $e)
                    <div style="font-size:.9rem;padding:.45rem .6rem;border-radius:.4rem;background:rgba(22,163,74,.1);color:#cbd5e1;">
                        <span style="color:#86efac;">✔</span>
                        <span style="font-family:ui-monospace,monospace;color:#f1f5f9;">{{ $e['guia'] }}</span>
                        · {{ $e['productos'] }}
                        @if($e['hora'])<span> · {{ $e['hora'] }}</span>@endif
                        @if($e['operario'])<span> · {{ $e['operario'] }}</span>@endif
                        @if($e['a_mano'] > 0)<span style="color:#fcd34d;"> · ✋ {{ $e['a_mano'] }} sin escanear</span>@endif
                    </div>
                @empty
                    <div style="font-size:.95rem;color:#cbd5e1;">{{ $buscar !== '' ? 'Nada empacado con esa búsqueda.' : 'Todavía nada.' }}</div>
                @endforelse
                @if($buscar === '' && $c['empacados_total'] > \App\Livewire\AvanceCorteEmpaque::EMPACADOS_VISIBLES)
                    <div style="font-size:.85rem;color:#cbd5e1;">y {{ $c['empacados_total'] - \App\Livewire\AvanceCorteEmpaque::EMPACADOS_VISIBLES }} más · búscalos arriba</div>
                @endif
            </div>
        </div>
    @endforeach
</div>
