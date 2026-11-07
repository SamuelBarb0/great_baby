<x-filament-panels::page>
    @php
        $pagina = \App\Modules\Dropi\Filament\Pages\CambiosFinancierosDropi::class;
        $total = $this->getTotal();
        $filas = $this->getFilas();
        $campo = 'padding:.45rem .6rem;border:1px solid rgba(156,163,175,.35);border-radius:.5rem;background:transparent;font-size:.85rem;';
        $eventoColor = ['created' => '#10b981', 'updated' => '#f59e0b', 'deleted' => '#ef4444', 'restored' => '#3b82f6'];
    @endphp

    <x-filament::section>
        <x-slot name="heading">Filtros</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;">
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Qué
                <select wire:model.live="tipo" style="{{ $campo }}">
                    <option value="">Wallet, sanciones y remisiones</option>
                    @foreach($pagina::MODELOS as $clase => $nombre)
                        <option value="{{ $clase }}">{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Acción
                <select wire:model.live="evento" style="{{ $campo }}">
                    <option value="">Todas</option>
                    @foreach($pagina::EVENTOS as $valor => $nombre)
                        <option value="{{ $valor }}">{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Desde
                <input type="date" wire:model.live="desde" style="{{ $campo }}">
            </label>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:#9ca3af;">Hasta
                <input type="date" wire:model.live="hasta" style="{{ $campo }}">
            </label>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ number_format($total, 0, ',', '.') }} cambio(s) auditados</x-slot>
        <x-slot name="description">Lo que una persona creó, cambió o borró desde el ERP, con el antes y el después. Lo que llega solo de Dropi (pagos de la wallet de la sincronización) no se audita aquí: está en «Wallet Dropi».</x-slot>

        @if($filas->isEmpty())
            <div style="color:#9ca3af;">No hay cambios con esos filtros.</div>
        @else
            <div style="display:flex;flex-direction:column;gap:.6rem;">
                @foreach($filas as $a)
                    <div wire:key="aud-{{ $a->id }}" style="border:1px solid rgba(156,163,175,.2);border-radius:.6rem;padding:.6rem .8rem;">
                        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:baseline;justify-content:space-between;">
                            <div>
                                <span style="font-size:.75rem;font-weight:700;color:{{ $eventoColor[$a->event] ?? '#9ca3af' }};">{{ $pagina::EVENTOS[$a->event] ?? $a->event }}</span>
                                <b>{{ $pagina::MODELOS[$a->auditable_type] ?? class_basename($a->auditable_type) }} #{{ $a->auditable_id }}</b>
                                <span style="color:#6b7280;">· {{ $a->user?->name ?? 'Sin usuario' }}</span>
                            </div>
                            <span style="font-size:.8rem;color:#6b7280;white-space:nowrap;">{{ $a->created_at?->timezone('America/Bogota')->format('Y-m-d H:i:s') }}</span>
                        </div>
                        @php $cambios = $pagina::cambios($a); @endphp
                        @if($cambios)
                            <table style="width:100%;font-size:.8rem;margin-top:.4rem;border-collapse:collapse;">
                                @foreach($cambios as [$c, $antes, $despues])
                                    <tr style="border-top:1px solid rgba(156,163,175,.1);">
                                        <td style="padding:.25rem .4rem;color:#6b7280;width:30%;font-family:ui-monospace,monospace;">{{ $c }}</td>
                                        <td style="padding:.25rem .4rem;color:#9ca3af;text-decoration:{{ $a->event === 'updated' ? 'line-through' : 'none' }};">{{ \Illuminate\Support\Str::limit($antes, 80) }}</td>
                                        <td style="padding:.25rem .4rem;font-weight:600;">{{ \Illuminate\Support\Str::limit($despues, 80) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                @endforeach
            </div>
            @if($filas->count() < $total)
                <div style="margin-top:.8rem;display:flex;align-items:center;gap:.75rem;">
                    <x-filament::button color="gray" wire:click="verMas">Ver más</x-filament::button>
                    <span style="font-size:.8rem;color:#9ca3af;">Mostrando {{ $filas->count() }} de {{ number_format($total, 0, ',', '.') }}</span>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
