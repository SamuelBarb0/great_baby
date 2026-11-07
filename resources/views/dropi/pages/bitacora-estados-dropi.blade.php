<x-filament-panels::page>
    @php
        $total = $this->getTotal();
        $filas = $this->getFilas();
        $campo = 'padding:.45rem .6rem;border:1px solid rgba(156,163,175,.35);border-radius:.5rem;background:transparent;font-size:.85rem;';
        $fuenteColor = ['api' => '#3b82f6', 'sistema' => '#9ca3af', 'manual' => '#f59e0b'];
    @endphp

    <x-filament::section>
        <x-slot name="heading">Filtros</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;">
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Guía u orden
                <input type="search" wire:model.live.debounce.400ms="guia" placeholder="Ej. 99360074748" style="{{ $campo }}min-width:14rem;">
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Origen
                <select wire:model.live="fuente" style="{{ $campo }}">
                    <option value="">Todos</option>
                    @foreach($this->getFuentes() as $f)
                        <option value="{{ $f }}">{{ ucfirst($f) }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Pasó a
                <select wire:model.live="estado" style="{{ $campo }}">
                    <option value="">Cualquier estado</option>
                    @foreach($this->getEstados() as $valor => $nombre)
                        <option value="{{ $valor }}">{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Desde
                <input type="date" wire:model.live="desde" style="{{ $campo }}">
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Hasta
                <input type="date" wire:model.live="hasta" style="{{ $campo }}">
            </label>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ number_format($total, 0, ',', '.') }} cambio(s) de estado</x-slot>
        <x-slot name="description">Cada vez que un pedido cambia de estado queda aquí: de dónde vino el cambio (Dropi, el sistema o una persona), quién y cuándo.</x-slot>

        @if($filas->isEmpty())
            <div style="color:#9ca3af;">No hay cambios con esos filtros.</div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;font-size:.88rem;border-collapse:collapse;">
                    <thead><tr style="border-bottom:1px solid rgba(156,163,175,.25);text-align:left;">
                        <th style="padding:.5rem;">Fecha</th>
                        <th style="padding:.5rem;">Guía</th>
                        <th style="padding:.5rem;">Cambio</th>
                        <th style="padding:.5rem;">Origen</th>
                        <th style="padding:.5rem;">Quién</th>
                    </tr></thead>
                    <tbody>
                    @foreach($filas as $b)
                        <tr wire:key="bit-{{ $b->id }}" style="border-bottom:1px solid rgba(156,163,175,.1);">
                            <td style="padding:.5rem;white-space:nowrap;color:#6b7280;">{{ $b->created_at?->timezone('America/Bogota')->format('Y-m-d H:i:s') }}</td>
                            <td style="padding:.5rem;font-family:ui-monospace,monospace;font-weight:600;">
                                {{ $b->pedido?->guia ?? '#'.$b->pedido_id }}
                                @if($b->pedido?->dropi_orden_id)<div style="font-weight:400;font-size:.72rem;color:#9ca3af;">orden {{ $b->pedido->dropi_orden_id }}</div>@endif
                            </td>
                            <td style="padding:.5rem;">
                                <span style="color:#9ca3af;">{{ \App\Modules\Dropi\Filament\Pages\BitacoraEstadosDropi::nombreEstado($b->estado_desde) }}</span>
                                → <b>{{ \App\Modules\Dropi\Filament\Pages\BitacoraEstadosDropi::nombreEstado($b->estado_hasta) }}</b>
                            </td>
                            <td style="padding:.5rem;">
                                <span style="font-size:.75rem;font-weight:600;padding:.1rem .45rem;border-radius:999px;color:{{ $fuenteColor[$b->fuente] ?? '#9ca3af' }};border:1px solid currentColor;">{{ ucfirst($b->fuente ?? '—') }}</span>
                            </td>
                            <td style="padding:.5rem;">
                                {{ $b->user?->name ?? 'Automático' }}
                                @if($b->gps_estado)<div style="font-size:.72rem;color:#9ca3af;">GPS: {{ $b->gps_estado }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($filas->count() < $total)
                <div style="margin-top:.8rem;display:flex;align-items:center;gap:.75rem;">
                    <x-filament::button color="gray" wire:click="verMas">Ver más</x-filament::button>
                    <span style="font-size:.8rem;color:#9ca3af;">Mostrando {{ $filas->count() }} de {{ number_format($total, 0, ',', '.') }}</span>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
