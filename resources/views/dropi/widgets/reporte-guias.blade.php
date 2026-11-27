{{-- Tarjetas de los reportes de hoy (ReporteGuiasWidget). Estilos en línea: el
     panel no tiene tema de Tailwind propio. --}}
@use('App\Modules\Dropi\Models\DropiLoteImpresion', 'L')
@php
    $caja = 'border:1px solid rgba(156,163,175,.3);border-radius:.75rem;padding:.9rem 1rem;';
    $barra = fn (int $pct, string $color) => '<div style="height:.45rem;border-radius:999px;background:rgba(156,163,175,.2);overflow:hidden;margin:.45rem 0 .2rem;">'
        . '<div style="height:100%;width:' . $pct . '%;background:' . $color . ';transition:width .4s;"></div></div>';
@endphp

<x-filament-widgets::widget>
    <div @if($refrescar) wire:poll.5s @endif style="display:flex;flex-direction:column;gap:.75rem;">
        @if($sueltas)
            <div style="{{ $caja }}">
                <div style="font-weight:600;">Generando guías en Dropi: {{ $sueltas['listas'] }} de {{ $sueltas['total'] }}</div>
                {!! $barra((int) round(100 * $sueltas['listas'] / max(1, $sueltas['total'])), '#2563eb') !!}
                @if($sueltas['errores'])
                    <div style="font-size:.85rem;color:#dc2626;">⚠ {{ count($sueltas['errores']) }} con problema:</div>
                    <ul style="font-size:.82rem;color:#4b5563;margin:.2rem 0 0 1rem;list-style:disc;">
                        @foreach($sueltas['errores'] as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                @endif
            </div>
        @endif

        @foreach($lotes as $l)
            @php
                $conteo = $l->conteoResultados();
                $accion = $l->conAccion();
                $bien = collect($conteo)->only(['generada', 'ya_tenia'])->sum();
                $solos = collect($conteo)->only(['cancelado', 'reemplazado'])->sum();
                [$paso, $color] = match ($l->estado) {
                    L::GENERANDO_GUIAS => ['Generando guías en Dropi · ' . count($l->resultados ?? []) . ' de ' . $l->total, '#2563eb'],
                    L::DESCARGANDO => ["Bajando stickers · {$l->descargados} de {$l->total}", '#2563eb'],
                    L::ARMANDO => ['Armando el PDF · parte ' . (count($l->partes ?? []) + 1), '#2563eb'],
                    L::ERROR => ['Falló: ' . $l->error, '#dc2626'],
                    default => ['Listo para imprimir', '#16a34a'],
                };
            @endphp
            <div style="{{ $caja }}border-left:5px solid {{ $accion ? '#dc2626' : $color }};">
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem;align-items:center;">
                    <div>
                        <div style="font-weight:700;">{{ $l->titulo() }}</div>
                        <div style="font-size:.85rem;color:{{ $color }};font-weight:600;">{{ $paso }}</div>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                        @foreach($l->partes ?? [] as $i => $parte)
                            <x-filament::button tag="a" size="sm" icon="heroicon-o-printer" target="_blank"
                                :href="route('dropi.guias.parte', [$l, $i + 1])">
                                {{ count($l->partes) > 1 ? 'Parte ' . ($i + 1) : 'Imprimir reporte' }}
                            </x-filament::button>
                        @endforeach
                        @if($conteo)
                            {{ ($this->verDetalleAction)(['lote' => $l->id]) }}
                        @endif
                    </div>
                </div>

                @if(\App\Modules\Dropi\Filament\Widgets\ReporteGuiasWidget::enCurso($l))
                    {!! $barra($l->avance(), $color) !!}
                @endif

                @if($conteo)
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.6rem;font-size:.82rem;">
                        @if($bien)<span style="padding:.2rem .55rem;border-radius:999px;background:rgba(22,163,74,.12);color:#15803d;font-weight:600;">✅ {{ $bien }} con guía</span>@endif
                        @if($accion)
                            <span style="padding:.2rem .55rem;border-radius:999px;background:rgba(220,38,38,.12);color:#b91c1c;font-weight:600;cursor:pointer;"
                                  wire:click="mountAction('verDetalle', { lote: {{ $l->id }} })">⚠ {{ $accion }} necesitan tu acción · ver cuáles</span>
                        @endif
                        @if($solos)<span style="padding:.2rem .55rem;border-radius:999px;background:rgba(107,114,128,.12);color:#4b5563;">🚫 {{ $solos }} cancelados o reemplazados en Dropi</span>@endif
                        @if($l->sin_sticker)<span style="padding:.2rem .55rem;border-radius:999px;background:rgba(217,119,6,.12);color:#b45309;">{{ count($l->sin_sticker) }} sin sticker</span>@endif
                    </div>

                @endif
            </div>
        @endforeach
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
