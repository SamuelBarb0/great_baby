{{-- El «chulito» de una referencia en el modo Recolección: tocar la fila la marca o la desmarca. --}}
@if($g['marcada'])
    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:.15rem;">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px;background:#22c55e;color:white;font-weight:700;font-size:1.1rem;">✓</span>
        <span style="font-size:.65rem;color:#9ca3af;white-space:nowrap;">{{ $g['marcada_por'] ?? '—' }} · {{ $g['marcada_at'] }}</span>
    </div>
@elseif($g['faltan'] > 0)
    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:.15rem;">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px;border:2px solid #f59e0b;color:#f59e0b;font-weight:700;font-size:.8rem;">+{{ $g['faltan'] }}</span>
        <span style="font-size:.65rem;color:#f59e0b;white-space:nowrap;">faltan {{ $g['faltan'] }}</span>
    </div>
@else
    <span style="display:inline-block;width:2.25rem;height:2.25rem;border-radius:9999px;border:2px solid rgba(156,163,175,.5);"></span>
@endif
