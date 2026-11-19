<x-filament-panels::page>
    @php($resumen = $this->resumen())

    @if ($resumen)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem;">
            @foreach ($resumen as $tarjeta)
                <x-filament::section>
                    <div style="font-size:.75rem;color:#9ca3af;">{{ $tarjeta['label'] }}</div>
                    <div style="font-size:1.35rem;font-weight:700;{{ ($tarjeta['alerta'] ?? false) ? 'color:#ef4444;' : '' }}">{{ $tarjeta['valor'] }}</div>
                </x-filament::section>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
