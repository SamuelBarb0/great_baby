<x-filament-panels::page>
    @php
        $s = $this->sesion;
        $estado = $s?->estado ?? 'sin_configurar';
        $colores = [
            'conectado' => ['#10b981', 'Conectado'],
            'esperando_codigo' => ['#f59e0b', 'Esperando el código del correo'],
            'expirado' => ['#ef4444', 'Sesión vencida'],
            'desconectado' => ['#94a3b8', 'Desconectado'],
            'sin_configurar' => ['#ef4444', 'Falta configurar la cuenta de Dropi'],
        ];
        [$color, $texto] = $colores[$estado] ?? ['#94a3b8', $estado];
    @endphp

    <x-filament::section>
        <x-slot name="heading">Estado</x-slot>
        <x-slot name="description">
            El ERP lee y escribe en Dropi con la sesión de esta cuenta, igual que si alguien la usara desde app.dropi.co.
        </x-slot>

        <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;">
            <span style="display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .8rem;border-radius:999px;background:{{ $color }}1a;color:{{ $color }};font-weight:600;">
                <span style="width:.55rem;height:.55rem;border-radius:50%;background:{{ $color }};"></span>{{ $texto }}
            </span>
            @if($s)
                <span style="font-size:.9rem;color:#9ca3af;">Cuenta: <strong>{{ $s->email }}</strong></span>
                @if($s->expira_at && $estado === 'conectado')
                    <span style="font-size:.9rem;color:#9ca3af;">Vence: {{ $s->expira_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                @endif
                @if($s->ultimo_uso_at)
                    <span style="font-size:.9rem;color:#9ca3af;">Último uso: {{ $s->ultimo_uso_at->diffForHumans() }}</span>
                @endif
            @endif
        </div>

        @if($s?->ultimo_error)
            <div style="margin-top:1rem;padding:.75rem 1rem;border-radius:.5rem;background:rgba(220,38,38,.08);color:#ef4444;font-size:.9rem;">
                {{ $s->ultimo_error }}
            </div>
        @endif

        <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
            @if($estado !== 'sin_configurar')
                <x-filament::button wire:click="conectar" icon="heroicon-o-arrow-right-end-on-rectangle">
                    {{ $estado === 'conectado' ? 'Reconectar' : 'Conectar' }}
                </x-filament::button>
            @endif
            @if($estado === 'conectado')
                <x-filament::button wire:click="desconectar" color="gray" icon="heroicon-o-x-mark">Desconectar</x-filament::button>
            @endif
        </div>
    </x-filament::section>

    @if($estado === 'esperando_codigo')
        <x-filament::section>
            <x-slot name="heading">Código de verificación</x-slot>
            <x-slot name="description">
                Dropi mandó un código al correo {{ $s->email }}. Escríbelo aquí; vence en pocos minutos.
            </x-slot>
            <form wire:submit="confirmarCodigo" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <x-filament::input.wrapper style="max-width:14rem;">
                    <x-filament::input id="codigo-dropi" type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="codigo" placeholder="Código" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit">Confirmar</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section collapsible :collapsed="$estado !== 'sin_configurar'">
        <x-slot name="heading">Cuenta de Dropi</x-slot>
        <x-slot name="description">
            @if($this->origenCuenta === 'crm')
                El ERP entra con la cuenta guardada aquí.
            @elseif($this->origenCuenta === 'env')
                El ERP entra con la cuenta del archivo .env del servidor. Si guardas una aquí, se usa esta en su lugar.
            @else
                Escribe el correo y la contraseña de la cuenta de Dropi con la que el ERP va a leer y escribir.
            @endif
            Al cambiar de cuenta se cierra la sesión actual. La contraseña se guarda cifrada y no se vuelve a mostrar.
        </x-slot>
        <form wire:submit="guardarCuenta" style="display:flex;flex-direction:column;gap:.75rem;max-width:28rem;">
            <div>
                <label for="cuenta-email" style="display:block;font-size:.875rem;font-weight:500;margin-bottom:.25rem;">Correo</label>
                <x-filament::input.wrapper :valid="! $errors->has('cuentaEmail')">
                    <x-filament::input id="cuenta-email" type="email" autocomplete="off" wire:model="cuentaEmail" />
                </x-filament::input.wrapper>
                @error('cuentaEmail')<p style="color:#ef4444;font-size:.85rem;margin-top:.25rem;">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="cuenta-password" style="display:block;font-size:.875rem;font-weight:500;margin-bottom:.25rem;">Contraseña</label>
                <x-filament::input.wrapper :valid="! $errors->has('cuentaPassword')">
                    <x-filament::input id="cuenta-password" type="password" autocomplete="new-password" wire:model="cuentaPassword"
                        placeholder="{{ $this->origenCuenta === 'crm' ? 'Déjala vacía para conservar la actual' : '' }}" />
                </x-filament::input.wrapper>
                @error('cuentaPassword')<p style="color:#ef4444;font-size:.85rem;margin-top:.25rem;">{{ $message }}</p>@enderror
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                <x-filament::button type="submit">Guardar cuenta</x-filament::button>
                @if($this->origenCuenta === 'crm' && filled(config('dropi.web.email')))
                    <x-filament::button type="button" color="gray" wire:click="usarCuentaDelEnv"
                        wire:confirm="¿Volver a la cuenta del .env? Se cerrará la sesión actual.">
                        Volver a la cuenta del .env
                    </x-filament::button>
                @endif
            </div>
        </form>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Conectar pegando el token</x-slot>
        <x-slot name="description">
            Solo si Dropi bloquea el login (por ejemplo, con un captcha). Entra a app.dropi.co en el navegador,
            abre las herramientas de desarrollador (F12) → Application → Local Storage → app.dropi.co,
            copia el valor de <code>DROPI_token</code> y pégalo aquí.
        </x-slot>
        <form wire:submit="guardarToken" style="display:flex;flex-direction:column;gap:.75rem;">
            <x-filament::input.wrapper>
                <x-filament::input id="token-dropi" type="password" wire:model="tokenManual" placeholder="eyJ..." />
            </x-filament::input.wrapper>
            <div><x-filament::button type="submit" color="gray">Usar este token</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
