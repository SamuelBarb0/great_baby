{{-- Las etiquetas armadas, en la misma ventana (27-nov-2026): antes quedaban en un
     aviso de la esquina que se perdía. «Imprimir» imprime este PDF; si el navegador
     no deja, «Abrir en otra pestaña». --}}
<div x-data style="display:grid;gap:.75rem;">
    <iframe id="gb-etiquetas-pdf" src="{{ $url }}#toolbar=0&navpanes=0&view=FitH" title="Etiquetas"
            style="width:100%;height:60vh;border:1px solid rgba(148,163,184,.35);border-radius:.5rem;background:#fff;"></iframe>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end;">
        <x-filament::button tag="a" :href="$url" target="_blank" color="gray" icon="heroicon-o-arrow-top-right-on-square">
            Abrir en otra pestaña
        </x-filament::button>
        <x-filament::button type="button" icon="heroicon-o-printer"
            x-on:click="try { const f = document.getElementById('gb-etiquetas-pdf'); f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { window.open(@js($url), '_blank'); }">
            🖨 Imprimir
        </x-filament::button>
    </div>
</div>
