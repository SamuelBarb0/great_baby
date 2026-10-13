{{-- Información del pedido tal como está AHORA en Dropi (la lupa de su web). Solo lectura. --}}
@if($error)
    <div style="padding:.75rem 1rem;border-radius:.5rem;background:rgba(220,38,38,.08);color:#ef4444;font-size:.9rem;">{{ $error }}</div>
@else
    @php
        $o = $detalle;
        $fmt = fn ($v) => $v !== null ? '$' . number_format((float) $v, 0, ',', '.') : '—';
        $fecha = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y H:i') : '—';
    @endphp
    <div style="display:flex;flex-direction:column;gap:1.25rem;font-size:.9rem;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;">
            @foreach([
                'Estatus en Dropi' => $o['status'] ?? '—',
                'Guía' => $o['shipping_guide'] ?? 'Sin guía',
                'Transportadora' => $o['shipping_company'] ?? '—',
                'Tipo' => $o['rate_type'] ?? '—',
                'Creado' => $fecha($o['created_at'] ?? null),
                'Tienda' => $o['shop']['name'] ?? '—',
            ] as $k => $v)
                <div>
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;">{{ $k }}</div>
                    <div style="font-weight:600;">{{ $v }}</div>
                </div>
            @endforeach
        </div>

        <div>
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:.35rem;">Cliente</div>
            <div>{{ trim(($o['name'] ?? '') . ' ' . ($o['surname'] ?? '')) ?: '—' }} · {{ $o['phone'] ?? '—' }}</div>
            <div style="color:#6b7280;">{{ $o['dir'] ?? '' }} — {{ $o['city'] ?? '' }}, {{ $o['state'] ?? '' }}</div>
        </div>

        <div>
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:.35rem;">Productos</div>
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="text-align:left;color:#9ca3af;font-size:.75rem;">
                    <th style="padding:.3rem 0;">Producto</th><th>SKU</th><th style="text-align:right;">Cant.</th><th style="text-align:right;">Precio proveedor</th><th style="text-align:right;">Precio venta</th>
                </tr></thead>
                <tbody>
                @foreach($o['orderdetails'] ?? [] as $d)
                    <tr style="border-top:1px solid rgba(148,163,184,.25);">
                        <td style="padding:.35rem 0;">{{ $d['product']['name'] ?? '—' }}</td>
                        <td>{{ $d['product']['sku'] ?? '—' }}</td>
                        <td style="text-align:right;">{{ $d['quantity'] ?? '—' }}</td>
                        <td style="text-align:right;">{{ $fmt($d['supplier_price'] ?? null) }}</td>
                        <td style="text-align:right;">{{ $fmt($d['price'] ?? null) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div style="margin-top:.4rem;text-align:right;color:#6b7280;">
                Total de la orden {{ $fmt($o['total_order'] ?? null) }} · Flete {{ $fmt($o['shipping_amount'] ?? null) }}
            </div>
        </div>

        <div>
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:.35rem;">Historial en Dropi</div>
            @forelse(collect($o['history'] ?? [])->sortByDesc('created_at') as $h)
                <div style="display:flex;gap:.75rem;padding:.3rem 0;border-top:1px solid rgba(148,163,184,.2);">
                    <span style="min-width:8.5rem;color:#6b7280;font-variant-numeric:tabular-nums;">{{ $fecha($h['created_at'] ?? null) }}</span>
                    <span style="font-weight:600;">{{ $h['status'] ?? '—' }}</span>
                    <span style="color:#6b7280;">{{ trim(($h['user']['name'] ?? '') . ' ' . ($h['user']['surname'] ?? '')) }}</span>
                    @if(!empty($h['notes']))<span style="color:#6b7280;">· {{ $h['notes'] }}</span>@endif
                </div>
            @empty
                <div style="color:#6b7280;">Sin movimientos.</div>
            @endforelse
        </div>
    </div>
@endif
