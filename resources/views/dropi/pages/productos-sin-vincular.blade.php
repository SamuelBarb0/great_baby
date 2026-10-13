<x-filament-panels::page>
    @php $filas = $this->sinVincular; @endphp

    <x-filament::section>
        <x-slot name="heading">Sin vincular</x-slot>
        <x-slot name="description">
            Productos que llegan de Dropi con una llave que el catálogo no conoce. Sus pedidos quedan en
            «pendiente por inventario» hasta que se vinculan. Se hace una sola vez por producto.
        </x-slot>

        <div style="margin-bottom:1rem;max-width:24rem;">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="buscar" placeholder="Buscar por nombre o llave de Dropi" />
            </x-filament::input.wrapper>
        </div>

        @if($filas->isEmpty())
            <p style="color:#9ca3af;">No hay productos sin vincular{{ $buscar !== '' ? ' con esa búsqueda' : '' }}.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                    <thead>
                        <tr style="text-align:left;color:#9ca3af;border-bottom:1px solid rgba(148,163,184,.25);">
                            <th style="padding:.5rem;">Producto en Dropi</th>
                            <th style="padding:.5rem;">Variación</th>
                            <th style="padding:.5rem;">Llave</th>
                            <th style="padding:.5rem;text-align:right;">Pedidos</th>
                            <th style="padding:.5rem;text-align:right;">Pendientes</th>
                            <th style="padding:.5rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($filas as $f)
                            <tr wire:key="sv-{{ $f->sku_dropi }}" style="border-bottom:1px solid rgba(148,163,184,.12);">
                                <td style="padding:.5rem;font-weight:500;">{{ $f->producto_nombre ?? '—' }}</td>
                                <td style="padding:.5rem;">{{ $f->variacion ?? '—' }}</td>
                                <td style="padding:.5rem;font-family:monospace;color:#9ca3af;">{{ $f->sku_dropi }}</td>
                                <td style="padding:.5rem;text-align:right;">{{ $f->pedidos }}</td>
                                <td style="padding:.5rem;text-align:right;{{ $f->pendientes ? 'color:#ef4444;font-weight:600;' : '' }}">{{ $f->pendientes }}</td>
                                <td style="padding:.5rem;text-align:right;">
                                    {{ ($this->vincularAction)(['sku' => $f->sku_dropi, 'nombre' => trim(($f->producto_nombre ?? '') . ' ' . ($f->variacion ?? ''))]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if($this->vinculados->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Vinculados recientemente</x-slot>

            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <tbody>
                    @foreach($this->vinculados as $v)
                        <tr wire:key="v-{{ $v->id }}" style="border-bottom:1px solid rgba(148,163,184,.12);">
                            <td style="padding:.5rem;">{{ trim(($v->producto_nombre ?? '') . ' ' . ($v->variacion ?? '')) ?: $v->sku_dropi }}</td>
                            <td style="padding:.5rem;color:#9ca3af;">→</td>
                            <td style="padding:.5rem;">{{ \App\Modules\Dropi\Filament\Pages\ProductosSinVincular::etiqueta($v->variante) }}</td>
                            <td style="padding:.5rem;text-align:right;">
                                {{ ($this->vincularAction)(['sku' => $v->sku_dropi, 'nombre' => $v->producto_nombre, 'cambiar' => true]) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
