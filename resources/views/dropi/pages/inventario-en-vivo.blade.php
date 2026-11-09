<x-filament-panels::page>
    @php
        $saldos = $this->getSaldos();
        // Variantes (llave «v-») y productos sin desglose (llave «p-»), con la
        // misma llave que usan los saldos.
        $filas = $this->getFilas();
        $ubicaciones = $this->getUbicaciones();
        $categorias = $this->getCategorias();
        $totalGeneral = array_sum(array_map('array_sum', $saldos));
    @endphp

    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem;">
        <div style="flex:2;min-width:220px;">
            <label style="font-size:.85rem;font-weight:600;">Buscar SKU / producto / referencia</label>
            <input type="text" wire:model.live.debounce.400ms="busqueda" placeholder="Ej: AND2512-79/154-02LEÓ-6M" style="width:100%;padding:.55rem .75rem;background:rgba(255,255,255,.05);border:1px solid rgba(156,163,175,.3);border-radius:.5rem;color:inherit;margin-top:.25rem;">
        </div>
        <div style="flex:1;min-width:180px;">
            <label style="font-size:.85rem;font-weight:600;">Filtrar categoría de ubicación</label>
            <select wire:model.live="filtroCategoria" style="width:100%;padding:.55rem .75rem;background:rgba(255,255,255,.05);border:1px solid rgba(156,163,175,.3);border-radius:.5rem;color:inherit;margin-top:.25rem;">
                @foreach($categorias as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="padding:.75rem 1rem;background:rgba(245,158,11,.12);border-radius:.5rem;">
            <div style="font-size:.7rem;color:#9ca3af;text-transform:uppercase;">Unidades totales</div>
            <div style="font-size:1.5rem;font-weight:700;color:#f59e0b;">{{ number_format($totalGeneral) }}</div>
        </div>
    </div>

    {{-- La columna fija tapa las celdas al desplazarse: necesita fondo sólido,
         y del tema (antes era negro fijo y en tema claro los nombres no se leían). --}}
    <style>
        .inv-fija { background: #fff; }
        .dark .inv-fija { background: rgb(24, 24, 27); }
    </style>

    <div style="overflow-x:auto;border:1px solid rgba(156,163,175,.2);border-radius:.75rem;">
        <table style="width:100%;border-collapse:collapse;min-width:900px;">
            <thead>
                <tr style="background:rgba(156,163,175,.08);">
                    <th style="text-align:left;padding:.75rem;font-size:.75rem;text-transform:uppercase;color:#9ca3af;position:sticky;left:0;z-index:2;" class="inv-fija">Producto · Variante</th>
                    @foreach($ubicaciones as $u)
                        <th style="text-align:center;padding:.5rem .375rem;font-size:.65rem;color:#9ca3af;min-width:80px;">
                            <div style="font-weight:700;color:inherit;">{{ $u->codigo }}</div>
                            <div style="font-size:.65rem;text-transform:none;color:#6b7280;margin-top:.125rem;">{{ $u->categoria->label() }}</div>
                        </th>
                    @endforeach
                    <th style="text-align:right;padding:.75rem;font-size:.75rem;text-transform:uppercase;color:#f59e0b;">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($filas as $f)
                    @php
                        $filaSaldos = $saldos[$f['key']] ?? [];
                        $totalFila = array_sum($filaSaldos);
                    @endphp
                    <tr wire:key="inv-{{ $f['key'] }}" style="border-top:1px solid rgba(156,163,175,.1);">
                        <td style="padding:.75rem;position:sticky;left:0;" class="inv-fija">
                            <div style="font-weight:600;">{{ $f['nombre'] }}</div>
                            <div style="font-family:ui-monospace,monospace;font-size:.75rem;color:#9ca3af;">
                                {{ $f['ref'] }} · {{ $f['etiqueta'] }}
                            </div>
                        </td>
                        @foreach($ubicaciones as $u)
                            @php $s = $filaSaldos[$u->id] ?? 0; @endphp
                            <td style="text-align:center;padding:.5rem;font-size:.9rem;">
                                @if($s > 0)
                                    <span style="display:inline-block;min-width:2rem;padding:.25rem .5rem;background:{{ $u->disponible_para_venta ? 'rgba(16,185,129,.15)' : 'rgba(245,158,11,.15)' }};color:{{ $u->disponible_para_venta ? '#10b981' : '#f59e0b' }};border-radius:.25rem;font-weight:600;">{{ $s }}</span>
                                @elseif($s < 0)
                                    <span style="color:#ef4444;font-weight:600;">{{ $s }}</span>
                                @else
                                    <span style="color:#4b5563;">·</span>
                                @endif
                            </td>
                        @endforeach
                        <td style="text-align:right;padding:.75rem;font-weight:700;color:{{ $totalFila > 0 ? '#f59e0b' : '#4b5563' }};">{{ $totalFila }}</td>
                    </tr>
                @empty
                    <tr><td colspan="99" style="text-align:center;padding:2rem;color:#9ca3af;">Sin productos que coincidan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
