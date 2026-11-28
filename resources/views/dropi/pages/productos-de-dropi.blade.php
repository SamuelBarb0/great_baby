<x-filament-panels::page>
    @php
        $previa = $this->previa;
        $r = $previa['resumen'];
        $filas = $this->filas;
        $sinDropi = $this->sinDropi;
        $packs = $this->packs;
        $money = fn ($v) => $v === null ? '—' : '$' . number_format((float) $v, 0, ',', '.');
        // Un solo vocabulario en Productos (27-nov-2026): las mismas palabras en
        //   «Buscar producto», «Mis productos en Dropi» y aquí.
        $estados = [
            'enlazar' => ['Sugerido', '#059669'],
            'vinculado' => ['Vinculado', '#0284c7'],
            'revisar' => ['Revisar a mano', '#d97706'],
            'nuevo' => ['No está en el ERP', '#6b7280'],
            'sin_catalogo' => ['Fuera del catálogo', '#7c3aed'],
        ];
        // Las pestañas del trabajo, siempre en el mismo orden; las de consulta, al lado.
        $principales = [
            null => ['⏳ Frenan pedidos', $r['con_pedidos'] ?? 0, 'Estos pedidos no pueden salir hasta que escojas su producto del ERP. Pulsa «Vincular…» y búscalo por nombre, referencia o código de barras.'],
            'enlazar' => ['✔ Sugeridos', $r['enlazar'] ?? 0, 'El ERP tiene un producto con la misma referencia o variación. Revísalos y, si están bien, «Confirmar sugeridos» arriba los vincula todos.'],
            'revisar' => ['✋ Revisar a mano', $r['revisar'] ?? 0, 'Se parecen a algo del ERP pero no es seguro. Escoge el producto correcto con «Vincular…».'],
            'nuevo' => ['➕ No están en el ERP', $r['nuevo'] ?? 0, 'Productos de Dropi que el ERP todavía no tiene. «Crear en el ERP» les arma la ficha ya vinculada.'],
        ];
        $consulta = [
            'vinculado' => ['Ya vinculados', $r['vinculado'] ?? 0],
            'sin_catalogo' => ['Fuera del catálogo', $r['sin_catalogo'] ?? 0],
            'sin_dropi' => ['Del ERP, no están en Dropi', count($sinDropi)],
            'todos' => ['Todos', null],
        ];
        $pestana = fn ($activa) => 'min-height:48px;padding:.6rem .9rem;border-radius:.75rem;text-align:left;cursor:pointer;background:transparent;border:'
            . ($activa ? '2px solid #f59e0b' : '1px solid rgba(148,163,184,.35)') . ';';
    @endphp

    @if($this->leyendo)
        <div wire:poll.10s style="padding:.75rem 1rem;border-radius:.75rem;border:1px solid #38bdf8;background:rgba(56,189,248,.08);font-size:.95rem;">
            Actualizando el catálogo desde Dropi en segundo plano. Tarda unos minutos; esta página se actualiza sola.
        </div>
    @elseif(($this->lectura['estado'] ?? null) === 'error')
        <div style="padding:.75rem 1rem;border-radius:.75rem;border:1px solid #ef4444;background:rgba(239,68,68,.08);font-size:.95rem;">
            La última actualización desde Dropi falló: {{ $this->lectura['mensaje'] ?? '' }}. Vuelve a intentarlo con «Actualizar desde Dropi».
        </div>
    @endif

    @if(! $previa['leido_at'])
        <div style="font-size:.95rem;opacity:.85;">
            Todavía no se ha leído el catálogo de Dropi («Actualizar desde Dropi», arriba). Mientras tanto se ven los productos que esperan en pedidos y se pueden vincular igual.
        </div>
    @endif

    {{-- Las pestañas del trabajo: lo que frena pedidos siempre primero. --}}
    <div role="tablist" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.6rem;">
        @foreach($principales as $clave => [$titulo, $n])
            <button type="button" role="tab" wire:click="verGrupo({{ $clave === '' ? 'null' : "'{$clave}'" }})"
                    aria-selected="{{ $this->filtro === ($clave === '' ? null : $clave) ? 'true' : 'false' }}"
                    style="{{ $pestana($this->filtro === ($clave === '' ? null : $clave)) }}">
                <div style="font-size:.95rem;font-weight:700;">{{ $titulo }}</div>
                <div style="font-size:1.5rem;font-weight:800;margin-top:.15rem;{{ $clave === '' && $n > 0 ? 'color:#ea580c;' : '' }}">{{ $n }}</div>
            </button>
        @endforeach
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;font-size:.9rem;">
        <span style="opacity:.75;">Consultar:</span>
        @foreach($consulta as $clave => [$titulo, $n])
            <button type="button" wire:click="verGrupo('{{ $clave }}')"
                    style="padding:.35rem .75rem;border-radius:999px;cursor:pointer;background:transparent;border:{{ $this->filtro === $clave ? '2px solid #f59e0b' : '1px solid rgba(148,163,184,.35)' }};">
                {{ $titulo }}@if($n !== null) · {{ $n }}@endif
            </button>
        @endforeach
    </div>

    <x-filament::section>
        @if(array_key_exists($this->filtro ?? '', $principales))
            <p style="font-size:.95rem;margin-bottom:.75rem;">{{ $principales[$this->filtro ?? ''][2] }}</p>
        @endif

        <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:16rem;max-width:30rem;">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="buscar" placeholder="Buscar por nombre, código Dropi, SKU o referencia" />
                </x-filament::input.wrapper>
            </div>
            <span style="font-size:.9rem;opacity:.8;">{{ $this->filtro === 'sin_dropi' ? count($sinDropi) : count($filas) }} productos</span>
        </div>

        @if($this->filtro === 'sin_dropi')
            <p style="font-size:.95rem;margin-bottom:.75rem;">
                Entraron en una compra de Dropi pero Dropi no los conoce: su stock no puede subir allá.
                <b>Crear en Dropi</b> si es un producto nuevo; <b>Asociar</b> si ya existe en Dropi con otro nombre o SKU.
            </p>
            @if(empty($sinDropi))
                <p style="opacity:.8;">Todo lo recibido ya está en Dropi.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:.95rem;">
                        <thead>
                            <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.25);font-size:.8rem;text-transform:uppercase;opacity:.75;">
                                <th style="padding:.5rem;">Producto en el ERP</th>
                                <th style="padding:.5rem;">Compra</th>
                                <th style="padding:.5rem;text-align:right;">Stock ERP</th>
                                <th style="padding:.5rem;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sinDropi as $p)
                                <tr wire:key="sd-{{ $p['id'] }}" style="border-bottom:1px solid rgba(148,163,184,.12);vertical-align:top;">
                                    <td style="padding:.5rem;">
                                        <div style="font-weight:600;">{{ $p['nombre'] }}</div>
                                        <div style="font-size:.85rem;opacity:.8;">
                                            {{ $p['referencia'] }}{{ $p['por_color'] ? ' · ' . collect($p['por_color'])->map(fn ($n, $c) => "{$c}: {$n}")->implode(', ') : '' }}
                                        </div>
                                    </td>
                                    <td style="padding:.5rem;font-size:.9rem;">{{ $p['oc'] ?? '—' }}</td>
                                    <td style="padding:.5rem;text-align:right;font-weight:600;">{{ $p['stock'] }}</td>
                                    <td style="padding:.5rem;text-align:right;white-space:nowrap;">
                                        {{ ($this->crearEnDropiAction)(['producto_id' => $p['id'], 'nombre' => $p['nombre']]) }}
                                        {{ ($this->asociarADropiAction)(['producto_id' => $p['id'], 'nombre' => $p['nombre']]) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif(empty($filas))
            <p style="opacity:.8;">Nada en este grupo.@if($this->buscar !== '') Prueba con otra palabra, el código Dropi o la pestaña «Todos».@endif</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.95rem;">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.25);font-size:.8rem;text-transform:uppercase;opacity:.75;">
                            <th style="padding:.5rem;">Producto en Dropi</th>
                            <th style="padding:.5rem;">Código Dropi</th>
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
                                    <div style="font-weight:600;">{{ $f['nombre'] }}</div>
                                    <div style="font-size:.85rem;opacity:.8;">
                                        {{ collect([$f['variacion'], $f['sku'] ? 'SKU ' . $f['sku'] : null])->filter()->implode(' · ') }}
                                    </div>
                                    @if($u = $packs[$f['llave']] ?? null)
                                        <span title="Cada venta descuenta {{ $u }} unidades del ERP" style="display:inline-block;margin-top:.25rem;font-size:.8rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;color:#b45309;background:rgba(217,119,6,.14);">Pack ×{{ $u }}</span>
                                    @endif
                                </td>
                                <td style="padding:.5rem;font-family:ui-monospace,monospace;font-size:.85rem;">{{ $f['llave'] }}</td>
                                <td style="padding:.5rem;text-align:right;{{ $f['pedidos_esperando'] ? 'color:#ea580c;font-weight:800;' : 'opacity:.6;' }}">
                                    {{ $f['pedidos_esperando'] ?: '—' }}
                                </td>
                                <td style="padding:.5rem;text-align:right;">{{ $f['stock_dropi'] ?? '—' }}</td>
                                <td style="padding:.5rem;text-align:right;">{{ $money($f['precio_dropi']) }}</td>
                                <td style="padding:.5rem;">
                                    <span style="font-size:.8rem;font-weight:700;color:{{ $estados[$f['estado']][1] }};">{{ $estados[$f['estado']][0] }}</span>
                                    @if($f['destino_label'])<div style="font-size:.9rem;">{{ $f['destino_label'] }}</div>@endif
                                    @if($f['motivo'])<div style="font-size:.85rem;opacity:.8;">{{ $f['motivo'] }}</div>@endif
                                </td>
                                <td style="padding:.5rem;text-align:right;white-space:nowrap;">
                                    @if($f['estado'] === 'nuevo' && $f['dropi_id'])
                                        {{ ($this->crearEnErpAction)(['dropi_id' => $f['dropi_id'], 'nombre' => $f['nombre']]) }}
                                    @endif
                                    {{ ($this->vincularLlaveAction)(['llave' => $f['llave'], 'nombre' => trim($f['nombre'] . ' ' . ($f['variacion'] ?? '')), 'vinculado' => $f['estado'] === 'vinculado', 'unidades' => $packs[$f['llave']] ?? 1]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(count($filas) > \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::LIMITE)
                <p style="font-size:.9rem;opacity:.8;margin-top:.5rem;">
                    Se muestran {{ \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::LIMITE }} de {{ count($filas) }}. Usa el buscador para encontrar uno.
                </p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
