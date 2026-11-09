<x-filament-panels::page>
    @php
        $cfg = $this->config;
        $resoluciones = $this->resoluciones;
        $facturaSola = (bool) setting('dropi.facturacion_automatica');
        $tieneResolucion = (int) $cfg->tipo_documento_dropi_id > 0;
        $estado = fn (bool $ok, string $si, string $no) => $ok
            ? '<span style="color:#059669;font-weight:600;">● '.e($si).'</span>'
            : '<span style="color:#d97706;font-weight:600;">● '.e($no).'</span>';
    @endphp

    <x-filament::section>
        <x-slot name="heading">Cómo sale lo de Dropi a SIIGO</x-slot>
        <x-slot name="description">
            Dropi factura con Great Baby: el mismo NIT y la misma cuenta de SIIGO, pero con su propia resolución DIAN.
            Así SIIGO lleva su contabilidad aparte.
        </x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;font-size:.9rem;">
            <div>
                <div style="font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Cuenta de SIIGO</div>
                <div style="font-weight:600;">{{ $cfg->username ?: 'Sin configurar' }}</div>
                <div style="color:#9ca3af;">{{ $cfg->ambiente === 'sandbox' ? 'Sandbox (pruebas)' : 'Producción' }}</div>
                <a href="/app/siigo" style="font-size:.8rem;color:#f59e0b;">Credenciales en SIIGO ↗</a>
            </div>
            <div>
                <div style="font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Integración</div>
                {!! $estado((bool) $cfg->activo, 'Activa', 'Apagada') !!}
            </div>
            <div>
                <div style="font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Facturar al despachar</div>
                {!! $estado($facturaSola, 'Encendido', 'Apagado') !!}
            </div>
            <div>
                <div style="font-size:.7rem;text-transform:uppercase;color:#9ca3af;">Resolución de Dropi</div>
                {!! $estado($tieneResolucion, 'Elegida', 'Falta: no sale nada a SIIGO') !!}
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Resolución SIIGO para Dropi</x-slot>
        <x-slot name="description">
            Mientras esté vacía, las facturas de Dropi se quedan en el ERP y no se envían a SIIGO. Nunca salen con la de Great Baby
            @if($cfg->tipo_documento_id)(ID {{ $cfg->tipo_documento_id }})@endif.
        </x-slot>

        <form wire:submit="guardar" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-start;">
            <div style="flex:1;min-width:16rem;max-width:32rem;">
                @if($resoluciones->isNotEmpty())
                    <x-filament::input.wrapper :valid="! $errors->has('resolucion')">
                        <x-filament::input.select wire:model="resolucion">
                            <option value="">— Sin resolución (no sale a SIIGO) —</option>
                            @foreach($resoluciones as $r)
                                <option value="{{ $r->siigo_id }}" @disabled((int) $r->siigo_id === (int) $cfg->tipo_documento_id)>
                                    {{ $r->siigo_id }} · {{ $r->code }} · {{ $r->name }}{{ (int) $r->siigo_id === (int) $cfg->tipo_documento_id ? ' (Great Baby)' : '' }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @else
                    <x-filament::input.wrapper :valid="! $errors->has('resolucion')">
                        <x-filament::input type="text" inputmode="numeric" wire:model="resolucion" placeholder="Document Type ID de la resolución de Dropi" />
                    </x-filament::input.wrapper>
                    <p style="font-size:.78rem;color:#9ca3af;margin-top:.35rem;">
                        No hay resoluciones descargadas de SIIGO todavía: escribe el ID que te den en SIIGO.
                    </p>
                @endif
                @error('resolucion')
                    <p style="font-size:.8rem;color:#dc2626;margin-top:.35rem;">{{ $message }}</p>
                @enderror
            </div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
