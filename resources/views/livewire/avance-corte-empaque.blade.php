{{-- Avance del corte en la estación de empaque (AvanceCorteEmpaque): se refresca solo. --}}
<div wire:poll.5s style="margin-top:1rem;display:grid;gap:1rem;">
    @foreach($cortes as $c)
        <div style="padding:1rem 1.25rem;background:#111827;border:1px solid rgba(156,163,175,.25);border-radius:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:.75rem;flex-wrap:wrap;">
                <div style="font-weight:800;color:#e5e7eb;">{{ $c['etiqueta'] }}</div>
                <div style="font-size:.95rem;color:#d1d5db;">
                    <strong style="color:#4ade80;">{{ $c['empacados']->count() }}</strong> de {{ $c['total'] }} empacados
                    · <strong style="color:#f87171;">{{ $c['faltan']->count() }}</strong> por empacar
                </div>
            </div>
            <div style="height:.6rem;border-radius:9999px;background:rgba(156,163,175,.2);overflow:hidden;margin:.6rem 0 .9rem;">
                <div style="height:100%;width:{{ $c['pct'] }}%;background:linear-gradient(90deg,#10b981,#059669);transition:width .5s;"></div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem;">
                {{-- Arriba lo que falta, en el orden de la pila. --}}
                <div>
                    <div style="font-size:.75rem;color:#f87171;text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-bottom:.4rem;">⏳ Por empacar · {{ $c['faltan']->count() }}</div>
                    <div style="display:grid;gap:.3rem;max-height:18rem;overflow-y:auto;">
                        @forelse($c['faltan'] as $f)
                            <div style="font-size:.82rem;padding:.4rem .6rem;border-radius:.4rem;background:rgba(248,113,113,.08);color:#e5e7eb;">
                                <span style="font-family:ui-monospace,monospace;font-weight:700;">{{ $f['guia'] }}</span>
                                <span style="color:#9ca3af;"> · {{ $f['productos'] }}</span>
                                @if($f['empacando'])<span style="color:#fbbf24;"> · 📦 {{ $f['empacando'] }}</span>@endif
                            </div>
                        @empty
                            <div style="font-size:.85rem;color:#4ade80;">¡Nada por empacar en este corte!</div>
                        @endforelse
                    </div>
                </div>
                {{-- Lo empacado baja aquí, lo último arriba. --}}
                <div>
                    <div style="font-size:.75rem;color:#4ade80;text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-bottom:.4rem;">✅ Empacados · {{ $c['empacados']->count() }}</div>
                    <div style="display:grid;gap:.3rem;max-height:18rem;overflow-y:auto;">
                        @forelse($c['empacados']->take(\App\Livewire\AvanceCorteEmpaque::EMPACADOS_VISIBLES) as $e)
                            <div style="font-size:.82rem;padding:.4rem .6rem;border-radius:.4rem;background:rgba(74,222,128,.07);color:#9ca3af;">
                                <span style="color:#4ade80;">✔</span>
                                <span style="font-family:ui-monospace,monospace;color:#d1d5db;">{{ $e['guia'] }}</span>
                                · {{ $e['productos'] }}
                                @if($e['hora'])<span> · {{ $e['hora'] }}</span>@endif
                                @if($e['operario'])<span> · {{ $e['operario'] }}</span>@endif
                                @if($e['a_mano'] > 0)<span style="color:#fbbf24;"> · ✋ {{ $e['a_mano'] }} sin escanear</span>@endif
                            </div>
                        @empty
                            <div style="font-size:.85rem;color:#9ca3af;">Todavía nada.</div>
                        @endforelse
                        @if($c['empacados']->count() > \App\Livewire\AvanceCorteEmpaque::EMPACADOS_VISIBLES)
                            <div style="font-size:.75rem;color:#6b7280;">y {{ $c['empacados']->count() - \App\Livewire\AvanceCorteEmpaque::EMPACADOS_VISIBLES }} más</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
