<x-filament-panels::page>
    @php
        $previa = $this->previa;
        $r = $previa['resumen'];
        $filas = $this->filas;
        $money = fn ($v) => $v === null ? '—' : '$' . number_format((float) $v, 0, ',', '.');
        $grupos = [
            'enlazar' => ['Se vinculan', '#059669'],
            'vinculado' => ['Ya vinculados', '#0284c7'],
            'revisar' => ['Revisar a mano', '#d97706'],
            'nuevo' => ['No están en el ERP', '#6b7280'],
            'sin_catalogo' => ['Fuera del catálogo', '#7c3aed'],
        ];
        $tarjeta = fn ($activa) => 'padding:.85rem;border-radius:.75rem;text-align:left;cursor:pointer;background:transparent;border:'
            . ($activa ? '2px solid #f59e0b' : '1px solid rgba(148,163,184,.25)') . ';';
    @endphp

    @if($this->leyendo)
        <div wire:poll.10s style="padding:.75rem 1rem;border-radius:.75rem;border:1px solid #38bdf8;background:rgba(56,189,248,.08);font-size:.9rem;">
            Leyendo el catálogo de Dropi en segundo plano. Tarda unos minutos; esta página se actualiza sola.
        </div>
    @elseif(($this->lectura['estado'] ?? null) === 'error')
        <div style="padding:.75rem 1rem;border-radius:.75rem;border:1px solid #ef4444;background:rgba(239,68,68,.08);font-size:.9rem;">
            La última lectura falló: {{ $this->lectura['mensaje'] ?? '' }}
        </div>
    @endif

        <div style="font-size:.85rem;color:#9ca3af;">
            @if($previa['leido_at'])
                Tu catálogo en Dropi, leído por la API, cruzado con el del ERP. Última lectura:
                <b>{{ \Illuminate\Support\Carbon::parse($previa['leido_at'])->timezone('America/Bogota')->locale('es')->isoFormat('D MMM YYYY, h:mm a') }}</b>.
                {{ $r['productos'] }} productos · {{ $r['llaves'] }} llaves (una por variación).
            @else
                Todavía no se ha leído el catálogo de Dropi (<b>Leer de Dropi</b>, arriba). Mientras tanto se ven
                las llaves que esperan en pedidos y se pueden vincular igual.
            @endif
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.75rem;">
            <button type="button" wire:click="verGrupo(null)" style="{{ $tarjeta($this->filtro === null) }}">
                <div style="font-size:.7rem;text-transform:uppercase;color:#ea580c;">⏳ Con pedidos esperando</div>
                <div style="font-size:1.5rem;font-weight:700;margin-top:.2rem;">{{ $r['con_pedidos'] ?? 0 }}</div>
            </button>
            @foreach($grupos as $clave => [$titulo, $color])
                <button type="button" wire:click="verGrupo('{{ $clave }}')" style="{{ $tarjeta($this->filtro === $clave) }}">
                    <div style="font-size:.7rem;text-transform:uppercase;color:{{ $color }};">{{ $titulo }}</div>
                    <div style="font-size:1.5rem;font-weight:700;margin-top:.2rem;">{{ $r[$clave] ?? 0 }}</div>
                </button>
            @endforeach
        </div>

        <x-filament::section>
            <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem;">
                <div style="flex:1;max-width:28rem;">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="buscar" placeholder="Buscar por nombre, SKU o referencia" />
                    </x-filament::input.wrapper>
                </div>
                <span style="font-size:.8rem;color:#9ca3af;">{{ count($filas) }} filas</span>
            </div>

            @if(empty($filas))
                <p style="color:#9ca3af;">Nada en este grupo.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                        <thead>
                            <tr style="text-align:left;color:#9ca3af;border-bottom:1px solid rgba(148,163,184,.25);font-size:.75rem;text-transform:uppercase;">
                                <th style="padding:.5rem;">Producto en Dropi</th>
                                <th style="padding:.5rem;">Llave</th>
                                <th style="padding:.5rem;text-align:right;">Pedidos esperando</th>
                                <th style="padding:.5rem;text-align:right;">Stock Dropi</th>
                                <th style="padding:.5rem;text-align:right;">Precio Dropi</th>
                                <th style="padding:.5rem;">En el ERP</th>
                                <th style="padding:.5rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($filas, 0, \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::LIMITE) as $f)
                                <tr wire:key="pd-{{ $f['llave'] }}" style="border-bottom:1px solid rgba(148,163,184,.12);vertical-align:top;">
                                    <td style="padding:.5rem;">
                                        <div style="font-weight:500;">{{ $f['nombre'] }}</div>
                                        <div style="font-size:.75rem;color:#9ca3af;">
                                            {{ collect([$f['variacion'], $f['tipo'], $f['sku'] ? 'SKU ' . $f['sku'] : null])->filter()->implode(' · ') }}
                                        </div>
                                    </td>
                                    <td style="padding:.5rem;font-family:monospace;font-size:.75rem;color:#9ca3af;">{{ $f['llave'] }}</td>
                                    <td style="padding:.5rem;text-align:right;{{ $f['pedidos_esperando'] ? 'color:#ea580c;font-weight:700;' : 'color:#9ca3af;' }}">
                                        {{ $f['pedidos_esperando'] ?: '—' }}
                                    </td>
                                    <td style="padding:.5rem;text-align:right;">{{ $f['stock_dropi'] ?? '—' }}</td>
                                    <td style="padding:.5rem;text-align:right;">{{ $money($f['precio_dropi']) }}</td>
                                    <td style="padding:.5rem;">
                                        <span style="font-size:.7rem;font-weight:600;color:{{ $grupos[$f['estado']][1] }};">{{ $grupos[$f['estado']][0] }}</span>
                                        @if($f['destino_label'])<div style="font-size:.75rem;">{{ $f['destino_label'] }}</div>@endif
                                        @if($f['motivo'])<div style="font-size:.75rem;color:#9ca3af;">{{ $f['motivo'] }}</div>@endif
                                    </td>
                                    <td style="padding:.5rem;text-align:right;white-space:nowrap;">
                                        @if($f['estado'] === 'nuevo' && $f['dropi_id'])
                                            {{ ($this->crearEnErpAction)(['dropi_id' => $f['dropi_id'], 'nombre' => $f['nombre']]) }}
                                        @endif
                                        {{ ($this->vincularLlaveAction)(['llave' => $f['llave'], 'nombre' => trim($f['nombre'] . ' ' . ($f['variacion'] ?? '')), 'vinculado' => $f['estado'] === 'vinculado']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($filas) > \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::LIMITE)
                    <p style="font-size:.8rem;color:#9ca3af;margin-top:.5rem;">
                        Se muestran {{ \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::LIMITE }} de {{ count($filas) }}. Usa el buscador para encontrar uno.
                    </p>
                @endif
            @endif
        </x-filament::section>
</x-filament-panels::page>
