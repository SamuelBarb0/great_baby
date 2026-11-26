{{-- Qué pasó con la guía de cada pedido de un reporte (26-nov-2026): primero lo
     que pide algo de Aracely, al final lo que salió bien. --}}
@php
    $tipos = \App\Modules\Dropi\Models\DropiLoteImpresion::RESULTADOS;
    $orden = ['error', 'sin_cobertura', 'en_otro_estado', 'sin_inventario', 'reemplazado', 'cancelado', 'ya_tenia', 'generada'];
    $grupos = collect($lote?->resultados ?? [])->groupBy('tipo')
        ->sortBy(fn ($g, $tipo) => array_search($tipo, $orden, true));
@endphp

@if(! $lote || $grupos->isEmpty())
    <p style="color:#6b7280;">Este reporte todavía no tiene resultados.</p>
@else
    <div style="display:flex;flex-direction:column;gap:1.25rem;">
        @foreach($grupos as $tipo => $filas)
            @php $t = $tipos[$tipo] ?? ['icono' => '•', 'titulo' => $tipo, 'hacer' => null]; @endphp
            <section>
                <h3 style="font-weight:700;font-size:1rem;margin-bottom:.15rem;">{{ $t['icono'] }} {{ $t['titulo'] }} <span style="color:#6b7280;font-weight:400;">({{ $filas->count() }})</span></h3>
                @if($t['hacer'])
                    <p style="font-size:.85rem;color:#6b7280;margin-bottom:.4rem;"><strong>Qué hacer:</strong> {{ $t['hacer'] }}</p>
                @endif

                @if($tipo === 'generada')
                    <p style="font-size:.85rem;color:#6b7280;">{{ $filas->pluck('orden')->implode(', ') }}</p>
                @else
                    <table style="width:100%;font-size:.85rem;border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left;color:#6b7280;font-size:.75rem;text-transform:uppercase;">
                                <th style="padding:.3rem .4rem;">Orden</th>
                                <th style="padding:.3rem .4rem;">Cliente</th>
                                <th style="padding:.3rem .4rem;">Qué pasó</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($filas as $f)
                                <tr style="border-top:1px solid rgba(156,163,175,.25);vertical-align:top;">
                                    <td style="padding:.35rem .4rem;font-family:ui-monospace,monospace;white-space:nowrap;">
                                        <a href="{{ \App\Modules\Dropi\Filament\Resources\DropiPedidoResource::getUrl('view', ['record' => $f['pedido_id']]) }}" target="_blank" rel="noopener" style="text-decoration:underline;">{{ $f['orden'] }}</a>
                                    </td>
                                    <td style="padding:.35rem .4rem;">{{ $f['cliente'] }}</td>
                                    <td style="padding:.35rem .4rem;">
                                        {{ $f['detalle'] ?? '' }}
                                        @if(filled($f['guia'] ?? null))<span style="color:#6b7280;"> Guía {{ $f['guia'] }}.</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @endforeach
    </div>
@endif
