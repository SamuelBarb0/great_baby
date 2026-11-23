<x-filament-panels::page>
    @php($f = $this->getFactura())
    @php($cop = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <x-filament::section>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem;font-size:.875rem;">
            <div><div style="color:#9ca3af;font-size:.75rem;">Cliente</div>{{ $f->contacto?->nombre_completo ?? '—' }}<br><span style="color:#9ca3af;">{{ $f->contacto?->tipo_documento }} {{ $f->contacto?->numero_documento }}</span></div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Fecha</div>{{ $f->fecha_emision?->format('Y-m-d') }}</div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Estado</div>{{ is_object($f->estado) && method_exists($f->estado, 'label') ? $f->estado->label() : (is_object($f->estado) ? $f->estado->value : $f->estado) }}</div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Número en SIIGO</div>{{ $f->numero_siigo ?: 'Sin enviar' }}</div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Total</div><strong>{{ $cop($f->total) }}</strong></div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Saldo</div><strong style="{{ (float) $f->saldo > 0 ? 'color:#ef4444;' : '' }}">{{ $cop($f->saldo) }}</strong></div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Productos">
        <table style="width:100%;font-size:.875rem;border-collapse:collapse;">
            <thead><tr style="text-align:left;color:#9ca3af;font-size:.75rem;">
                <th style="padding:.25rem;">Descripción</th><th style="padding:.25rem;text-align:right;">Cant.</th>
                <th style="padding:.25rem;text-align:right;">Precio</th><th style="padding:.25rem;text-align:right;">IVA</th>
                <th style="padding:.25rem;text-align:right;">Subtotal</th>
            </tr></thead>
            <tbody>
            @foreach ($f->items as $it)
                <tr style="border-top:1px solid rgba(156,163,175,.2);">
                    <td style="padding:.25rem;">{{ $it->descripcion }}</td>
                    <td style="padding:.25rem;text-align:right;">{{ rtrim(rtrim(number_format((float) $it->cantidad, 2, ',', '.'), '0'), ',') }}</td>
                    <td style="padding:.25rem;text-align:right;">{{ $cop($it->precio_unit) }}</td>
                    <td style="padding:.25rem;text-align:right;">{{ (float) $it->impuesto_pct }}%</td>
                    <td style="padding:.25rem;text-align:right;">{{ $cop($it->subtotal) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot style="font-size:.875rem;">
                <tr><td colspan="4" style="padding:.25rem;text-align:right;color:#9ca3af;">Subtotal</td><td style="padding:.25rem;text-align:right;">{{ $cop($f->subtotal) }}</td></tr>
                @if ((float) $f->descuento > 0)
                    <tr><td colspan="4" style="padding:.25rem;text-align:right;color:#9ca3af;">Descuento</td><td style="padding:.25rem;text-align:right;">− {{ $cop($f->descuento) }}</td></tr>
                @endif
                <tr><td colspan="4" style="padding:.25rem;text-align:right;color:#9ca3af;">IVA</td><td style="padding:.25rem;text-align:right;">{{ $cop($f->impuestos) }}</td></tr>
                <tr><td colspan="4" style="padding:.25rem;text-align:right;font-weight:700;">Total</td><td style="padding:.25rem;text-align:right;font-weight:700;">{{ $cop($f->total) }}</td></tr>
            </tfoot>
        </table>
    </x-filament::section>

    <x-filament::section heading="Pagos">
        @forelse ($f->pagos as $p)
            <div style="display:flex;justify-content:space-between;font-size:.875rem;padding:.25rem 0;border-top:1px solid rgba(156,163,175,.2);">
                <span>{{ $p->fecha?->format('Y-m-d') }} · {{ $p->medio_pago === 'wallet_dropi' ? 'Wallet Dropi' : $p->medio_pago }}{{ $p->referencia ? ' · '.$p->referencia : '' }}</span>
                <strong>{{ $cop($p->monto_aplicado) }}</strong>
            </div>
        @empty
            <div style="font-size:.875rem;color:#9ca3af;">Sin pagos registrados.</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
