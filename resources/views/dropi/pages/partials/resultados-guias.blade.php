{{-- Qué pasó con la guía de cada pedido de un reporte (26-nov-2026). Tres
     bloques: lo que pide algo de Aracely (con el motivo de Dropi y qué hacer),
     lo que el ERP resolvió solo y, plegado, lo que salió bien. --}}
@use('App\Modules\Dropi\Models\DropiLoteImpresion', 'L')
@php
    $filas = collect($lote?->resultados ?? []);
    $porTipo = $filas->groupBy('tipo');
    $conteo = $lote?->conteoResultados() ?? [];
    $tiposDe = fn (string $grupo) => collect(L::RESULTADOS)->filter(fn ($t) => $t['grupo'] === $grupo)->keys()
        ->filter(fn ($tipo) => $porTipo->has($tipo));
    $verPedido = fn ($id) => \App\Modules\Dropi\Filament\Resources\DropiPedidoResource::getUrl('view', ['record' => $id]);
    $caja = 'border:1px solid rgba(156,163,175,.3);border-radius:.6rem;';
@endphp

@if(! $lote || $filas->isEmpty())
    <p style="color:#6b7280;">Este reporte todavía no tiene resultados.</p>
@else
    {{-- Contadores --}}
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem;">
        @foreach($conteo as $tipo => $n)
            @php $t = L::RESULTADOS[$tipo]; @endphp
            <div style="{{ $caja }}border-left:4px solid {{ $t['color'] }};padding:.45rem .7rem;min-width:8.5rem;flex:1 1 8.5rem;">
                <div style="font-size:1.35rem;font-weight:700;line-height:1.1;color:{{ $t['color'] }};">{{ $n }}</div>
                <div style="font-size:.78rem;color:#6b7280;">{{ $t['icono'] }} {{ $t['titulo'] }}</div>
            </div>
        @endforeach
    </div>

    <div style="display:flex;flex-direction:column;gap:1.5rem;">
        @foreach(['accion', 'solo'] as $grupo)
            @php $tipos = $tiposDe($grupo); $g = L::GRUPOS[$grupo]; @endphp
            @continue($tipos->isEmpty())
            <section>
                <h3 style="font-weight:700;font-size:1.05rem;color:{{ $g['color'] }};margin-bottom:.6rem;">
                    {{ $g['titulo'] }} <span style="font-weight:400;color:#6b7280;">({{ $tipos->sum(fn ($tipo) => $porTipo[$tipo]->count()) }})</span>
                </h3>

                @foreach($tipos as $tipo)
                    @php $t = L::RESULTADOS[$tipo]; @endphp
                    <div style="{{ $caja }}border-left:4px solid {{ $t['color'] }};padding:.7rem .85rem;margin-bottom:.75rem;">
                        <div style="font-weight:600;">{{ $t['icono'] }} {{ $t['titulo'] }} <span style="color:#6b7280;font-weight:400;">· {{ $porTipo[$tipo]->count() }}</span></div>
                        @if($t['hacer'])
                            <div style="font-size:.85rem;margin:.35rem 0 .55rem;padding:.4rem .6rem;border-radius:.4rem;background:rgba(156,163,175,.12);">
                                <strong>Qué hacer:</strong> {{ $t['hacer'] }}
                            </div>
                        @endif
                        <div style="display:flex;flex-direction:column;">
                            @foreach($porTipo[$tipo] as $f)
                                <div style="display:flex;gap:.75rem;align-items:baseline;padding:.4rem 0;border-top:1px solid rgba(156,163,175,.2);font-size:.88rem;">
                                    <a href="{{ $verPedido($f['pedido_id']) }}" target="_blank" rel="noopener"
                                       style="font-family:ui-monospace,monospace;text-decoration:underline;white-space:nowrap;min-width:6.5rem;">{{ $f['orden'] }}</a>
                                    <span style="font-weight:600;min-width:9rem;">{{ $f['cliente'] }}</span>
                                    <span style="color:#4b5563;flex:1;">
                                        {{ $f['detalle'] ?? '' }}
                                        @if(filled($f['guia'] ?? null))<span style="color:#6b7280;"> Guía {{ $f['guia'] }}.</span>@endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>
        @endforeach

        @php $bien = $tiposDe('bien'); @endphp
        @if($bien->isNotEmpty())
            <details style="{{ $caja }}padding:.6rem .85rem;">
                <summary style="cursor:pointer;font-weight:700;color:{{ L::GRUPOS['bien']['color'] }};">
                    {{ L::GRUPOS['bien']['titulo'] }} <span style="font-weight:400;color:#6b7280;">({{ $bien->sum(fn ($tipo) => $porTipo[$tipo]->count()) }}) · ver la lista</span>
                </summary>
                @foreach($bien as $tipo)
                    <div style="margin-top:.6rem;font-size:.82rem;font-weight:600;color:#6b7280;">{{ L::RESULTADOS[$tipo]['icono'] }} {{ L::RESULTADOS[$tipo]['titulo'] }}</div>
                    <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.3rem;">
                        @foreach($porTipo[$tipo] as $f)
                            <a href="{{ $verPedido($f['pedido_id']) }}" target="_blank" rel="noopener" title="{{ $f['cliente'] }}"
                               style="font-family:ui-monospace,monospace;font-size:.78rem;padding:.15rem .45rem;border-radius:.35rem;background:rgba(22,163,74,.1);">{{ $f['orden'] }}@if(filled($f['guia'] ?? null)) · {{ $f['guia'] }}@endif</a>
                        @endforeach
                    </div>
                @endforeach
            </details>
        @endif
    </div>
@endif
