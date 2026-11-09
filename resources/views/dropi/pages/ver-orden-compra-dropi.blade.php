<x-filament-panels::page>
    @php
        $o = $this->getOrden();
        // Una OC de importación está en su moneda (CNY, USD): con «$» parecía en pesos.
        $cop = $o->moneda && $o->moneda !== 'COP'
            ? fn ($v) => $o->moneda . ' ' . number_format((float) $v, 2, ',', '.')
            : fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
        $contenedor = $o->esImportacion()
            ? \App\Modules\Compras\Models\Importacion::whereHas('ordenes', fn ($q) => $q->whereKey($o->id))->first()
            : null;
        $estado = $o->estado instanceof \App\Modules\Compras\Enums\EstadoOrdenCompra ? $o->estado->label() : (string) $o->estado;
        $celda = 'padding:.5rem .6rem;border-bottom:1px solid rgba(156,163,175,.2);';
        $num = $celda . 'text-align:right;white-space:nowrap;';
    @endphp

    <x-filament::section>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem;font-size:.9rem;">
            <div><div style="color:#9ca3af;font-size:.75rem;">Proveedor</div>{{ $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo }}<div style="color:#9ca3af;font-size:.75rem;">{{ $o->proveedor?->numero_documento }}</div></div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Estado</div><x-filament::badge>{{ $estado }}</x-filament::badge></div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Fecha · esperada</div>{{ $o->fecha_emision?->format('Y-m-d') }} · {{ $o->fecha_esperada?->format('Y-m-d') ?? '—' }}</div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Bodega</div>{{ $o->bodega?->nombre ?? '—' }}</div>
            <div><div style="color:#9ca3af;font-size:.75rem;">Total</div><strong style="font-size:1.2rem;">{{ $cop($o->total) }}</strong></div>
            @if ($o->esImportacion())
                <div><div style="color:#9ca3af;font-size:.75rem;">Importación · tasa</div>{{ $o->moneda }} a {{ number_format((float) $o->tasa_cambio, 2, ',', '.') }}</div>
                <div><div style="color:#9ca3af;font-size:.75rem;">Contenedor</div>
                    @if ($contenedor)
                        <a href="{{ \App\Modules\Dropi\Filament\Pages\VerImportacionDropi::getUrl(['importacion' => $contenedor->id]) }}" style="color:#3b82f6;">{{ $contenedor->numero }}</a>
                    @else
                        <span style="color:#9ca3af;">Sin contenedor todavía</span>
                    @endif
                </div>
            @endif
        </div>
        @if ($o->observaciones)
            <div style="margin-top:.75rem;font-size:.85rem;color:#9ca3af;white-space:pre-line;">{{ $o->observaciones }}</div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Productos</x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead><tr style="color:#9ca3af;text-align:left;">
                    <th style="{{ $celda }}">Descripción</th><th style="{{ $num }}">Pedido</th><th style="{{ $num }}">Recibido</th>
                    <th style="{{ $num }}">Pendiente</th><th style="{{ $num }}">Costo unit.</th><th style="{{ $num }}">IVA</th><th style="{{ $num }}">Total</th>
                </tr></thead>
                <tbody>
                @foreach ($o->items as $i)
                    @php $pendiente = max(0, (float) $i->cantidad - (float) $i->cantidad_recibida); @endphp
                    <tr>
                        <td style="{{ $celda }}">{{ $i->descripcion }}</td>
                        <td style="{{ $num }}">{{ rtrim(rtrim(number_format((float) $i->cantidad, 3, ',', '.'), '0'), ',') }}</td>
                        <td style="{{ $num }}">{{ rtrim(rtrim(number_format((float) $i->cantidad_recibida, 3, ',', '.'), '0'), ',') }}</td>
                        <td style="{{ $num }}{{ $pendiente > 0 ? 'color:#f59e0b;' : '' }}">{{ rtrim(rtrim(number_format($pendiente, 3, ',', '.'), '0'), ',') }}</td>
                        <td style="{{ $num }}">{{ $cop($i->precio_unit) }}</td>
                        <td style="{{ $num }}">{{ (float) $i->iva_pct }}%</td>
                        <td style="{{ $num }}">{{ $cop($i->total ?? ((float) $i->cantidad * (float) $i->precio_unit)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:1.5rem;margin-top:.75rem;font-size:.85rem;">
            <span>Subtotal {{ $cop($o->subtotal) }}</span><span>IVA {{ $cop($o->iva) }}</span><strong>Total {{ $cop($o->total) }}</strong>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recepciones</x-slot>
        @forelse ($o->recepciones as $rc)
            <div style="display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;{{ $celda }}font-size:.85rem;">
                <strong>{{ $rc->numero }}</strong>
                <span>{{ $rc->fecha_recepcion?->format('Y-m-d') }}</span>
                <span>Factura: {{ $rc->factura_proveedor ?: '—' }}</span>
                <span>{{ ucfirst((string) $rc->estado) }}</span>
                <span>{{ $cop($rc->total_recibido) }}</span>
            </div>
        @empty
            <div style="color:#9ca3af;font-size:.85rem;">Todavía no se ha recibido nada de esta orden.</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
