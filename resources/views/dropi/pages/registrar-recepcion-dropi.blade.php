<x-filament-panels::page>
    @php $o = $this->getOrden(); @endphp
    <div style="font-size:.85rem;color:#9ca3af;">
        Proveedor: <strong>{{ $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo }}</strong>.
        Escriba cuánto llegó de cada producto; lo que no llegó déjelo en 0 o márquelo como faltante.
    </div>

    <form wire:submit="confirmar" style="display:flex;flex-direction:column;gap:1rem;">
        {{ $this->form }}

        <div style="display:flex;gap:.5rem;">
            <x-filament::button type="submit" icon="heroicon-o-check">Confirmar recepción</x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Modules\Dropi\Filament\Pages\VerOrdenCompraDropi::getUrl(['orden' => $o->id])">Cancelar</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
