<x-filament-panels::page>
    @if($this->puedeEditar)
    <form wire:submit="procesar">
        <x-filament::section>
            <x-slot name="heading">Importar pedidos de Dropi</x-slot>
            <x-slot name="description">
                Sube el Excel <strong>"Órdenes (una orden por fila)"</strong> que descargas en Dropi
                (Mis Pedidos → Acciones → Reportes → Descargas). Se cargan/actualizan por
                <strong>guía</strong>; las órdenes sin guía (pendientes) se omiten hasta que Dropi les asigne una.
                Puente manual mientras se habilita la conexión directa (MCP/API).
            </x-slot>

            {{ $this->form }}
        </x-filament::section>

        @if($resultado)
            <x-filament::section style="margin-top:1rem;">
                <x-slot name="heading">Resultado</x-slot>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;">
                    <div style="padding:1rem;border:1px solid rgba(16,185,129,.3);border-radius:.5rem;background:rgba(16,185,129,.08);">
                        <div style="font-size:.7rem;color:#10b981;text-transform:uppercase;">Nuevos</div>
                        <div style="font-size:1.75rem;font-weight:700;color:#10b981;">{{ $resultado['nuevos'] ?? 0 }}</div>
                        <div style="font-size:.72rem;color:#9ca3af;">pedidos que no existían</div>
                    </div>
                    <div style="padding:1rem;border:1px solid rgba(59,130,246,.3);border-radius:.5rem;background:rgba(59,130,246,.08);">
                        <div style="font-size:.7rem;color:#3b82f6;text-transform:uppercase;">Con cambios</div>
                        <div style="font-size:1.75rem;font-weight:700;color:#3b82f6;">{{ $resultado['actualizados'] ?? 0 }}</div>
                        <div style="font-size:.72rem;color:#9ca3af;">cambió estado o algún dato</div>
                    </div>
                    <div style="padding:1rem;border:1px solid rgba(148,163,184,.3);border-radius:.5rem;background:rgba(148,163,184,.08);">
                        <div style="font-size:.7rem;color:#94a3b8;text-transform:uppercase;">Sin cambios</div>
                        <div style="font-size:1.75rem;font-weight:700;color:#94a3b8;">{{ $resultado['sin_cambios'] ?? 0 }}</div>
                        <div style="font-size:.72rem;color:#9ca3af;">ya estaban igual</div>
                    </div>
                    <div style="padding:1rem;border:1px solid rgba(245,158,11,.3);border-radius:.5rem;background:rgba(245,158,11,.08);">
                        <div style="font-size:.7rem;color:#f59e0b;text-transform:uppercase;">Pendientes sin guía</div>
                        <div style="font-size:1.75rem;font-weight:700;color:#f59e0b;">{{ $resultado['rechazados'] ?? 0 }}</div>
                        <div style="font-size:.72rem;color:#9ca3af;">entran al despacharse</div>
                    </div>
                    <div style="padding:1rem;border:1px solid rgba(220,38,38,.3);border-radius:.5rem;background:rgba(220,38,38,.08);">
                        <div style="font-size:.7rem;color:#ef4444;text-transform:uppercase;">Errores</div>
                        <div style="font-size:1.75rem;font-weight:700;color:#ef4444;">{{ $resultado['errores'] ?? 0 }}</div>
                    </div>
                </div>
                <div style="margin-top:.75rem;font-size:.85rem;color:#9ca3af;">Total de filas leídas: {{ $resultado['total'] ?? 0 }}</div>
            </x-filament::section>
        @endif

        <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
            @foreach($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>
    @else
    <p style="font-size:.9rem;color:#9ca3af;">Tu permiso sobre esta pantalla es de solo lectura.</p>
    @endif
</x-filament-panels::page>
