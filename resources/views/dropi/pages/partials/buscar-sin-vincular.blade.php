{{-- «Buscar producto»: lo de Dropi que coincide con la búsqueda y no está vinculado
     (27-nov-2026: buscaban «abejita» y el ERP no lo encontraba). --}}
@if(! empty($coincidencias))
    <div style="margin:0 0 .75rem;padding:.85rem 1rem;border-radius:.75rem;border:1px solid rgba(217,119,6,.45);background:rgba(217,119,6,.08);">
        <div style="font-weight:700;font-size:.95rem;margin-bottom:.4rem;">En Dropi hay {{ count($coincidencias) }} producto(s) con «{{ $buscar }}» que el ERP todavía no reconoce</div>
        <div style="display:grid;gap:.35rem;">
            @foreach($coincidencias as $c)
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.5rem;">
                    <span style="font-size:.95rem;">
                        <b>{{ $c['nombre'] }}</b>
                        <span style="font-size:.85rem;font-weight:700;color:#b45309;"> · {{ $c['vinculo'] === 'parcial' ? "Falta en parte ({$c['faltan']} de {$c['total']})" : 'Sin vincular' }}</span>
                    </span>
                    @if($puedeVincular)
                        <x-filament::button tag="a" size="sm" color="warning" icon="heroicon-o-link"
                            :href="\App\Modules\Dropi\Filament\Pages\ProductosDeDropi::getUrl(['filtro' => 'todos', 'buscar' => $c['nombre']])">
                            Vincular ahora →
                        </x-filament::button>
                    @else
                        <span style="font-size:.85rem;opacity:.8;">Pídele a la oficina que lo vincule para sacar su etiqueta.</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
