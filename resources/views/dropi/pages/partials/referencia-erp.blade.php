{{-- Nuestra referencia (26-nov-2026); sin vínculo al ERP, el SKU de Dropi con el aviso. --}}
@if(filled($g['ref_erp'] ?? null))
    <span title="Dropi: {{ $g['sku'] }}">{{ $g['ref_erp'] }}</span>
@else
    {{ $g['sku'] }} <span style="font-family:inherit;color:#f59e0b;font-size:.7rem;">· sin vincular al ERP</span>
@endif
