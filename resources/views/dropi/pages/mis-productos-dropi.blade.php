<x-filament-panels::page>
    @php
        $cat = $this->catalogo;
        $lista = $this->productos;
        $money = fn ($v) => $v === null ? '—' : '$' . number_format((float) $v, 0, ',', '.');
        $rango = fn ($p) => $p['precio_min'] === null ? '—'
            : ($p['precio_min'] == $p['precio_max'] ? $money($p['precio_min']) : $money($p['precio_min']) . ' – ' . $money($p['precio_max']));
        // Un solo vocabulario en Productos (27-nov-2026): las mismas palabras en
        //   «Buscar producto» y «Vincular productos».
        $chipVinculo = [
            'si' => ['Vinculado', '#059669', 'rgba(5,150,105,.1)'],
            'parcial' => ['Falta en parte', '#d97706', 'rgba(217,119,6,.1)'],
            'no' => ['Sin vincular', '#6b7280', 'rgba(107,114,128,.12)'],
        ];
        $puedeVincular = \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::canAccess();
        $urlVincular = fn ($p) => \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::getUrl(['filtro' => 'todos', 'buscar' => $p['nombre']]);
    @endphp

    @if(! $cat['leido_at'])
        <x-filament::section>
            <p style="color:#9ca3af;">
                Todavía no se ha leído el catálogo de Dropi. Ve a
                <a href="{{ \App\Modules\Dropi\Filament\Pages\ProductosDeDropi::getUrl() }}" style="color:#f59e0b;font-weight:600;">Vincular productos</a>
                y usa <b>Actualizar desde Dropi</b>.
            </p>
        </x-filament::section>
    @else
        <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;">
            <div style="flex:1;min-width:16rem;max-width:30rem;">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="buscar" placeholder="Buscar por nombre, SKU, categoría o variación" />
                </x-filament::input.wrapper>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                @foreach(\App\Modules\Dropi\Filament\Pages\MisProductosDropi::FILTROS as $clave => $titulo)
                    <button type="button" wire:click="$set('filtro', '{{ $clave }}')"
                            style="font-size:.8rem;padding:.35rem .75rem;border-radius:999px;cursor:pointer;
                                   {{ $this->filtro === $clave ? 'background:#f59e0b;color:#fff;border:1px solid #f59e0b;' : 'background:transparent;border:1px solid rgba(148,163,184,.4);' }}">
                        {{ $titulo }}
                    </button>
                @endforeach
            </div>
        </div>
        <div style="font-size:.8rem;color:#9ca3af;">
            {{ count($lista) }} de {{ count($cat['productos']) }} productos · leído de Dropi el
            {{ \Illuminate\Support\Carbon::parse($cat['leido_at'])->timezone('America/Bogota')->locale('es')->isoFormat('D MMM YYYY, h:mm a') }}
        </div>

        @if(empty($lista))
            <x-filament::section><p style="color:#9ca3af;">Nada con ese filtro.</p></x-filament::section>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1rem;">
                @foreach(array_slice($lista, 0, $this->mostrar) as $p)
                    <div wire:key="mp-{{ $p['id'] }}" x-data="{ abierto: false }"
                         style="border:1px solid rgba(148,163,184,.25);border-radius:.85rem;overflow:hidden;display:flex;flex-direction:column;">
                        <div style="padding:.75rem;display:flex;flex-direction:column;gap:.35rem;flex:1;">
                            <div style="font-weight:600;line-height:1.25;">{{ $p['nombre'] }}</div>
                            <div style="font-size:.72rem;color:#9ca3af;">
                                ID {{ $p['id'] }}@if($p['sku']) · SKU {{ $p['sku'] }}@endif · {{ $p['tipo'] }}
                                @if($p['privado']) · <b>privado</b>@endif
                                @if($p['categorias']) · {{ implode(', ', $p['categorias']) }}@endif
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:.15rem;">
                                <span style="font-weight:700;">{{ $rango($p) }}</span>
                                <span style="font-size:.8rem;{{ $p['stock'] > 0 ? '' : 'color:#dc2626;font-weight:600;' }}">Stock {{ $p['stock'] }}</span>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.4rem;">
                                @php
                                    [$tx, $co, $bg] = $chipVinculo[$p['vinculo']];
                                    $faltan = collect($p['variaciones'])->where('vinculado', false)->count();
                                @endphp
                                <span style="font-size:.8rem;font-weight:700;padding:.15rem .55rem;border-radius:999px;white-space:nowrap;color:{{ $co }};background:{{ $bg }};">{{ $p['vinculo'] === 'parcial' ? $tx.' ('.$faltan.' de '.count($p['variaciones']).')' : $tx }}</span>
                                {{-- Se abre en la ventana al lado (script de AdminPanelProvider); sin JS, en otra pestaña. --}}
                                <a href="{{ \App\Modules\Dropi\Filament\Pages\MisProductosDropi::urlEditarEnDropi($p['id']) }}"
                                   target="_blank" rel="noopener"
                                   title="Abre este producto en Dropi para editarlo"
                                   style="font-size:.78rem;font-weight:600;color:#ea580c;border:1px solid #f97316;border-radius:.5rem;padding:.2rem .6rem;white-space:nowrap;text-decoration:none;">
                                    Editar en Dropi ↗
                                </a>
                            </div>
                            {{-- La etiqueta lleva el código del ERP vinculado: es lo que reconoce la pistola de empaque. --}}
                            @php $erpId = $p['vinculo'] !== 'no' ? \App\Modules\Dropi\Filament\Pages\MisProductosDropi::productoErp($p) : null; @endphp
                            {{-- Las etiquetas, con el mismo botón en todas las tarjetas (27-nov-2026). --}}
                            @if($erpId)
                                {{-- Escoger cuáles y cuántas, como en la ficha de /app (25-nov-2026). --}}
                                <button type="button" wire:click="mountAction('imprimirEtiquetas', { producto: {{ $erpId }} })"
                                        title="Escoge cuáles etiquetas y cuántas de cada una"
                                        style="min-height:40px;font-size:.9rem;font-weight:700;color:#2563eb;border:1px solid #3b82f6;border-radius:.5rem;padding:.35rem .7rem;text-align:center;background:none;cursor:pointer;">
                                    🏷️ Imprimir etiquetas
                                </button>
                            @elseif($p['vinculo'] !== 'no')
                                {{-- Vinculado a varios productos del ERP: las de cada variación vinculada. --}}
                                <a href="{{ route('catalogo.etiquetas.dropi', $p['id']) }}" target="_blank" rel="noopener"
                                   title="Las etiquetas de cada variación vinculada al ERP"
                                   style="min-height:40px;font-size:.9rem;font-weight:700;color:#2563eb;border:1px solid #3b82f6;border-radius:.5rem;padding:.35rem .7rem;text-align:center;text-decoration:none;">
                                    🏷️ Imprimir etiquetas
                                </a>
                            @endif
                            @if($p['vinculo'] === 'parcial')
                                <span style="font-size:.85rem;color:#b45309;">Solo salen las variaciones vinculadas al ERP.@if($puedeVincular) <a href="{{ $urlVincular($p) }}" style="font-weight:700;text-decoration:underline;">Vincular las que faltan →</a>@endif</span>
                            @elseif($p['vinculo'] === 'no')
                                <span style="font-size:.85rem;opacity:.85;">
                                    Para imprimir su etiqueta hay que vincularlo con el ERP.
                                    @if($puedeVincular)
                                        <a href="{{ $urlVincular($p) }}" style="color:#d97706;font-weight:700;">Vincular →</a>
                                    @else
                                        Pídele a la oficina que lo vincule.
                                    @endif
                                </span>
                            @endif
                            @if(count($p['variaciones']) > 1 || $p['variaciones'][0]['variacion'])
                                <button type="button" x-on:click="abierto = ! abierto"
                                        style="margin-top:auto;font-size:.78rem;color:#f59e0b;text-align:left;background:none;border:none;cursor:pointer;padding:0;">
                                    <span x-text="abierto ? 'Ocultar variaciones' : 'Ver {{ count($p['variaciones']) }} variación(es)'"></span>
                                </button>
                                <div x-show="abierto" x-cloak style="font-size:.78rem;border-top:1px solid rgba(148,163,184,.2);padding-top:.4rem;">
                                    @foreach($p['variaciones'] as $v)
                                        <div style="display:flex;justify-content:space-between;gap:.5rem;padding:.15rem 0;">
                                            <span>
                                                {{ $v['variacion'] ?? '—' }}
                                                <span style="color:{{ $v['vinculado'] ? '#059669' : '#9ca3af' }};" title="{{ $v['erp'] ?? 'Sin vincular al ERP' }}">{{ $v['vinculado'] ? '● ERP' : '○' }}</span>
                                            </span>
                                            <span style="white-space:nowrap;">{{ $money($v['precio']) }} · {{ $v['stock'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if(count($lista) > $this->mostrar)
                <div style="text-align:center;">
                    <x-filament::button color="gray" wire:click="verMas">Ver más ({{ count($lista) - $this->mostrar }} restantes)</x-filament::button>
                </div>
            @endif
        @endif
    @endif
</x-filament-panels::page>
