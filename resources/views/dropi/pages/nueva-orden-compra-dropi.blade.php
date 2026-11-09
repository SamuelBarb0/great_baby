<x-filament-panels::page>
    <form wire:submit="crear" style="display:flex;flex-direction:column;gap:1rem;">
        {{ $this->form }}

        <div style="display:flex;gap:.5rem;">
            <x-filament::button type="submit" icon="heroicon-o-check">Crear OC</x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Modules\Dropi\Filament\Pages\OrdenesCompraDropi::getUrl()">Cancelar</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
