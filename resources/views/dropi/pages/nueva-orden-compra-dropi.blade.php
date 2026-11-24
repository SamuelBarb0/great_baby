<x-filament-panels::page>
    {{-- Desde Excel (24-nov-2026): de qué archivo y cuál orden es, y qué falta revisar. --}}
    @php($imp = $this->importacion())
    @if ($imp)
        <x-filament::section icon="heroicon-o-document-arrow-up" icon-color="primary">
            <x-slot name="heading">
                Del Excel «{{ $imp['archivo'] }}» · Orden {{ $imp['n'] }} de {{ $imp['total'] }} · {{ $imp['referencia'] }}
            </x-slot>
            <x-slot name="description">
                Todavía no se ha creado nada. Revise la orden, escoja lo que esté marcado y dele «Crear».
            </x-slot>
            <x-slot name="afterHeader">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-forward" wire:click="saltar">
                    Saltar esta orden
                </x-filament::button>
            </x-slot>

            @if ($imp['avisos'] || $imp['por_revisar'])
                <ul style="margin:0;padding-left:1.1rem;color:rgb(var(--danger-600));font-weight:600;">
                    @foreach ($imp['avisos'] as $aviso)
                        <li>{{ ucfirst($aviso) }}</li>
                    @endforeach
                    @if ($imp['por_revisar'])
                        <li>{{ $imp['por_revisar'] }} {{ $imp['por_revisar'] === 1 ? 'línea marcada' : 'líneas marcadas' }} con ⚠ por revisar.</li>
                    @endif
                </ul>
            @else
                <p style="margin:0;color:rgb(var(--success-600));font-weight:600;">Todo casó con el ERP: revise y cree.</p>
            @endif
        </x-filament::section>
    @endif

    <form wire:submit="crear" style="display:flex;flex-direction:column;gap:1rem;">
        {{ $this->form }}

        <div style="display:flex;gap:.5rem;">
            <x-filament::button type="submit" icon="heroicon-o-check">
                @if ($imp && $imp['n'] < $imp['total'])
                    Crear OC y seguir con la {{ $imp['n'] + 1 }}
                @else
                    Crear OC
                @endif
            </x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Modules\Dropi\Filament\Pages\OrdenesCompraDropi::getUrl()">Cancelar</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
